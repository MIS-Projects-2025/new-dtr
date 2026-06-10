<?php

namespace App\Jobs;

use App\Services\EmployeeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExportBiometricLogs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;
    public int $tries   = 3;
    public int $backoff = 10;

    private const REMARK_COLORS = [
        'Present'            => ['FFC6EFCE', 'FF375623'],
        'Present (OB)'       => ['FFD9EAD3', 'FF1F4E2E'],
        'Late'               => ['FFFFCC99', 'FF833C00'],
        'Absent'             => ['FFFFC7CE', 'FF9C0006'],
        'Rest Day'           => ['FFF2F2F2', 'FF7F7F7F'],
        'Holiday'            => ['FFFFEB9C', 'FF9C6500'],
        'On Leave'           => ['FFDAEEF3', 'FF17375E'],
        'On Leave (Present)' => ['FFE4DFEC', 'FF403151'],
        'Pending'            => ['FFDDEBF7', 'FF1F4E79'],
    ];

    // Light-red background + dark-red text for slot cells with no punch recorded
    private const MISSING_SLOT_BG  = 'FFFFC7CE';
    private const MISSING_SLOT_FG  = 'FF9C0006';
    private const MISSING_SLOT_KEY = '__missing_slot__';
    private const OVER_BREAK_SLOT_BG  = 'FFFFCC99';
    private const OVER_BREAK_SLOT_FG  = 'FF833C00';
    private const OVER_BREAK_SLOT_KEY = '__over_break_slot__';


    private array $timings  = [];
    private float $jobStart = 0.0;

    public function __construct(
        private string $jobId,
        private string $dateFrom,
        private string $dateTo,
        private string $type,
    ) {}

    private function tick(string $label): void
    {
        $elapsed         = round(microtime(true) - $this->jobStart, 3);
        $this->timings[] = "[{$elapsed}s] {$label}";
        Log::channel('single')->info("[EXPORT TIMING] [{{$elapsed}}s] {$label}");
    }

    public function handle(EmployeeService $employeeService, \App\Services\DtrLogService $dtrLogService): void
    {
        ini_set('memory_limit', '512M');
        $this->jobStart = microtime(true);
        $this->tick('Job started');
        $this->updateProgress(5, 'Initializing export...');

        try {
            $isWithBreaks = $this->type === 'with_breaks';

            $headers = $isWithBreaks
                ? ['Employee ID','Employee Name','Department','Station','Prodline',
                   'Date','Day','Shift','Time In','Break Out 1','Break In 1',
                   'Lunch Out','Lunch In','Break Out 2','Break In 2','Time Out','Remarks']
                : ['Employee ID','Employee Name','Date DTR','Time DTR','Flag'];

            $colWidths = $isWithBreaks
                ? [12,32,22,14,14,12,6,16,10,12,10,10,10,12,10,10,16]
                : [12,32,12,10,12];

            $this->updateProgress(20, 'Fetching employees and building metadata...');

            $filename = "biometric_dtr_{$this->dateFrom}_to_{$this->dateTo}_{$this->type}.xlsx";
            $dir      = storage_path('app/exports');
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $path = $dir . '/' . $filename;

            $wt      = microtime(true);
            $written = $this->writeXlsxStreamed($path, $headers, $colWidths, $isWithBreaks, $employeeService, $dtrLogService);

            $writeSecs = round(microtime(true) - $wt, 2);
            $this->tick("Write complete — {$writeSecs}s, {$written} rows -> {$filename}");

            $totalTime = round(microtime(true) - $this->jobStart, 2);
            $this->updateProgress(100, 'Export complete!');

            Cache::put("export_timing_{$this->jobId}", [
                'total_seconds' => $totalTime,
                'timings'       => $this->timings,
            ], now()->addMinutes(30));

            Cache::put("export_{$this->jobId}", [
                'status'   => 'done',
                'progress' => 100,
                'message'  => "Export complete! ({$totalTime}s)",
                'filename' => $filename,
            ], now()->addMinutes(10));

        } catch (\Throwable $e) {
            $this->tick('FAILED: ' . $e->getMessage());
            Log::channel('single')->error('[EXPORT] FAILED: ' . $e->getMessage());
            Cache::put("export_{$this->jobId}", [
                'status'   => 'failed', 'progress' => 0,
                'message'  => 'Export failed: ' . $e->getMessage(),
                'filename' => null,
            ], now()->addMinutes(5));
            throw $e;
        }
    }

    public function writeXlsxStreamed(
        string          $path,
        array           $headers,
        array           $colWidths,
        bool            $isWithBreaks,
        EmployeeService $employeeService,
        \App\Services\DtrLogService $dtrLogService
    ): int {
        $stylesXml        = $this->buildStylesXml();
        $styleIdxMap      = $this->styleIdxMap();
        // Style index reserved for missing-log slot cells
        $missingSlotStyle   = $styleIdxMap[self::MISSING_SLOT_KEY];
        $overBreakSlotStyle = $styleIdxMap[self::OVER_BREAK_SLOT_KEY];

        $legends = [
    ['label' => 'Present',            'key' => 'Present'],
    ['label' => 'Present (OB)',        'key' => 'Present (OB)'],
    ['label' => 'Late',                'key' => 'Late'],
    ['label' => 'Absent',              'key' => 'Absent'],
    ['label' => 'Rest Day',            'key' => 'Rest Day'],
    ['label' => 'Holiday',             'key' => 'Holiday'],
    ['label' => 'On Leave',            'key' => 'On Leave'],
    ['label' => 'On Leave (Present)',  'key' => 'On Leave (Present)'],
    ['label' => 'Pending',             'key' => 'Pending'],
    ['label' => 'Missing Punch',       'key' => '__missing_slot__'],
    ['label' => 'Over Break',          'key' => '__over_break_slot__'],
];

$sheetTmp = tempnam(sys_get_temp_dir(), 'xlsx_sheet_');
$fh       = fopen($sheetTmp, 'wb');

fwrite($fh, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>');
fwrite($fh, '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
          . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">');
// With breaks: Row 1=legend, Row 2=separator, Row 3=header, Row 4=data start
// Without breaks: Row 1=header, Row 2=data start
$freezeYSplit  = $isWithBreaks ? 3 : 1;
$freezeTopLeft = $isWithBreaks ? 'A4' : 'A2';
fwrite($fh, '<sheetViews><sheetView tabSelected="1" workbookViewId="0">'
          . "<pane ySplit=\"{$freezeYSplit}\" topLeftCell=\"{$freezeTopLeft}\" activePane=\"bottomLeft\" state=\"frozen\"/>"
          . '</sheetView></sheetViews>');

fwrite($fh, '<cols>');
foreach ($colWidths as $i => $w) {
    $col = $i + 1;
    fwrite($fh, "<col min=\"{$col}\" max=\"{$col}\" width=\"{$w}\" customWidth=\"1\"/>");
}
fwrite($fh, '</cols><sheetData>');

if ($isWithBreaks) {
    // Legend row (all in one row: "Legend:" label + colored cells)
    fwrite($fh, '<row r="1">');

    // Column A: "Legend:" label
    fwrite($fh, "<c r=\"A1\" s=\"1\" t=\"inlineStr\"><is><t>Legend:</t></is></c>");

    // Column B onwards: one colored cell per legend item
    foreach ($legends as $li => $legend) {
        $col         = $this->colLetter($li + 1);
        $legendStyle = $styleIdxMap[$legend['key']] ?? 0;
        $labelEsc    = htmlspecialchars($legend['label'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
        fwrite($fh, "<c r=\"{$col}1\" s=\"{$legendStyle}\" t=\"inlineStr\"><is><t>{$labelEsc}</t></is></c>");
    }

    fwrite($fh, '</row>');

    // Blank separator row
    fwrite($fh, '<row r="2">');
    for ($ci = 0; $ci < count($headers); $ci++) {
        $col = $this->colLetter($ci);
        fwrite($fh, "<c r=\"{$col}2\" s=\"0\" t=\"inlineStr\"><is><t></t></is></c>");
    }
    fwrite($fh, '</row>');

    // Header row
    fwrite($fh, '<row r="3">');
    foreach ($headers as $ci => $h) {
        $col = $this->colLetter($ci);
        $val = htmlspecialchars($h, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        fwrite($fh, "<c r=\"{$col}3\" s=\"1\" t=\"inlineStr\"><is><t>{$val}</t></is></c>");
    }
    fwrite($fh, '</row>');

    $rowNum = 4;
} else {
    // Without breaks: no legend, header on row 1, data starts row 2
    fwrite($fh, '<row r="1">');
    foreach ($headers as $ci => $h) {
        $col = $this->colLetter($ci);
        $val = htmlspecialchars($h, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        fwrite($fh, "<c r=\"{$col}1\" s=\"1\" t=\"inlineStr\"><is><t>{$val}</t></is></c>");
    }
    fwrite($fh, '</row>');

    $rowNum = 2;
}
        $written = 0;
        $n = static fn($v): string => ($v && $v !== '--:--') ? $v : '--';

        $toMins = static fn(?string $t): ?int => ($t && $t !== '--' && $t !== '--:--')
            ? ((int) explode(':', $t)[0] * 60 + (int) explode(':', $t)[1])
            : null;

        $breakDuration = static function (?string $out, ?string $in) use ($toMins): ?int {
            $o = $toMins($out);
            $i = $toMins($in);
            if ($o === null || $i === null) return null;
            $dur = $i - $o;
            if ($dur < 0) $dur += 1440;
            return $dur;
        };

        // ── Load static context once ─────────────────────────────────────
        $context           = $employeeService->buildExportContext($this->dateFrom, $this->dateTo);
        $empIds            = $context['empIds'];
        $empIndex          = $context['empIndex'];
        $scheduleByEmpDate = $context['scheduleByEmpDate'];
        $leaveMap          = $context['leaveMap'];
        $holidayMap        = $context['holidayMap'];
        $obMap             = $context['obMap'];

        \Log::info('[EXPORT HOLIDAY MAP]', ['count' => count($holidayMap), 'sample' => array_slice($holidayMap, 0, 3, true)]);

        $dayNames     = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $punchTypeMap = [
            '0'=>'check_in','1'=>'check_out','2'=>'break_out','3'=>'break_in',
            'check_in'=>'check_in','check_out'=>'check_out',
            'break_out'=>'break_out','break_in'=>'break_in',
        ];

        // Build date list
        $dates = [];
        $cur   = strtotime($this->dateFrom);
        $endTs = strtotime($this->dateTo);
        while ($cur <= $endTs) {
            $dates[] = date('Y-m-d', $cur);
            $cur    += 86400;
        }

        $this->updateProgress(40, 'Writing rows...');
        $totalEmp = count($empIds);
        $empFrom  = date('Y-m-d', strtotime($this->dateFrom . ' -1 day')) . ' 00:00:00';
        $empTo    = date('Y-m-d', strtotime($this->dateTo   . ' +1 day')) . ' 23:59:59';
        $pdo      = DB::connection('dtr')->getPdo();

        foreach ($empIds as $empIdx => $empId) {
            $pct = (int) min(90, 40 + (($empIdx + 1) / $totalEmp) * 50);
            $this->updateProgress($pct, "Writing employee " . ($empIdx + 1) . "/{$totalEmp}...");
            $this->touchJobReservation();

            $hireDateRaw = $empIndex[$empId]->DATEHIRED ?? null;
            $hireDate    = $hireDateRaw ? substr((string) $hireDateRaw, 0, 10) : null;

            // Fetch all punches for this employee once
            $preNormalizedLogs = [$empId => []];
            $sql = "
    SELECT CONVERT(datetime USING utf8mb4) COLLATE utf8mb4_general_ci AS datetime,
           CONVERT(punch_type USING utf8mb4) COLLATE utf8mb4_general_ci AS punch_type
    FROM biometric_logs
    WHERE employid = ? AND datetime BETWEEN ? AND ?
    UNION ALL
    SELECT CONVERT(datetime USING utf8mb4) COLLATE utf8mb4_general_ci,
           CONVERT(punch_type USING utf8mb4) COLLATE utf8mb4_general_ci
    FROM biometric_logs_manual
    WHERE employid = ? AND datetime BETWEEN ? AND ?
    UNION ALL
    SELECT CONVERT(CONCAT(log_date, ' ', log_time) USING utf8mb4) COLLATE utf8mb4_general_ci,
           CONVERT(log_type USING utf8mb4) COLLATE utf8mb4_general_ci
    FROM vp_logs
    WHERE employee_id = ? AND log_date BETWEEN ? AND ?
    ORDER BY datetime
";
$stmt = $pdo->prepare($sql);
$stmt->execute([
    $empId, $empFrom, $empTo,
    $empId, $empFrom, $empTo,
    $empId, $this->dateFrom, $this->dateTo,
]);
            $stmt->setFetchMode(\PDO::FETCH_OBJ);
            while ($r = $stmt->fetch()) {
                $dt = \Carbon\Carbon::parse($r->datetime);
                $preNormalizedLogs[$empId][] = [
                    'employid' => $empId,
                    'datetime' => $r->datetime,
                    'date'     => $dt->toDateString(),
                    'time'     => $dt->format('H:i:s'),
                    'type'     => $punchTypeMap[(string)$r->punch_type] ?? 'check_in',
                    'source'   => 'mixed',
                ];
            }
            $stmt->closeCursor();
            unset($stmt);

            foreach ($dates as $date) {
                if ($hireDate && $date < $hireDate) continue;

                $isHoliday = isset($holidayMap[$date]);
                $dayName   = $dayNames[(int) date('w', strtotime($date))];

                $schInfo          = $scheduleByEmpDate[$empId][$date] ?? null;
                $tw               = $schInfo['tw']          ?? [];
                $isRestDay        = $schInfo['is_rd']        ?? false;
                $isShifting       = $schInfo['is_shifting']  ?? false;
                $effectiveRestDay = $isRestDay || $isHoliday;
                $isOnLeave        = isset($leaveMap[$empId][$date]);
                $obData           = $obMap[$empId][$date]   ?? null;

                $scheduleType = $isShifting ? 'Shifting' : 'Normal';
                $resolvedMap  = $dtrLogService->resolveLogsFromPreNormalized(
                    [$empId],
                    [$empId => $tw],
                    [$empId => $scheduleType],
                    $date,
                    $preNormalizedLogs
                );
                $slots = $resolvedMap[$empId] ?? [
                    'time_in'     => null, 'break_out_1' => null, 'break_in_1' => null,
                    'lunch_out'   => null, 'lunch_in'    => null, 'break_out_2' => null,
                    'break_in_2'  => null, 'time_out'    => null,
                ];

                $hasAnyPunch = array_filter($slots) !== [];

                $isObPresent = false;
                if ($obData && in_array(strtolower($obData['form_type'] ?? ''), ['ob', 'pb'])) {
                    $obFrom = EmployeeService::minsPublic($obData['time_from'] ?? '');
                    $obTo   = EmployeeService::minsPublic($obData['time_to']   ?? '');
                    if ($obFrom > 0 && $obTo > 0) {
                        if (!empty($tw[0]) && ($e = EmployeeService::minsPublic($tw[0])) >= $obFrom && $e <= $obTo) $isObPresent = true;
                        if (!$isObPresent && !empty($tw[7]) && ($e = EmployeeService::minsPublic($tw[7])) >= $obFrom && $e <= $obTo) $isObPresent = true;
                    }
                }

                $isOnLeave = isset($leaveMap[$empId][$date]);

                $remarks = EmployeeService::fastRemarksPublic(
                    $hasAnyPunch || $isObPresent,
                    $effectiveRestDay,
                    $isOnLeave,
                    $slots['time_in'],
                    $tw[0] ?? null,
                    $date,
                    $isHoliday
                );

                if ($isObPresent && in_array($remarks, ['Present', 'Late'])) {
                    $remarks = 'Present (OB)';
                }

                if ($isHoliday) {
                    if ($hasAnyPunch) {
                        $remarks = 'Present';
                    } elseif ($remarks === 'Absent') {
                        $remarks = 'Holiday';
                    }
                }

                // Row-level remark style index (applied to slot + remarks columns by default)
                $s = $styleIdxMap[$remarks] ?? 0;

                $noSchedule = $schInfo === null;

                // Trim seconds from slot time strings
                $t = static fn($v) => ($v && $v !== '--') ? substr($v, 0, 5) : $v;

                if ($isWithBreaks) {
                    // ── Resolve the 8 slot display values ──────────────────────
                    // Index 0=Time In, 1=Break Out 1, 2=Break In 1, 3=Lunch Out,
                    //       4=Lunch In, 5=Break Out 2, 6=Break In 2, 7=Time Out
                    $slotValues = [
                        $t($n($slots['time_in'])),
                        ($noSchedule || $isShifting) ? 'N/A' : $t($n($slots['break_out_1'])),
                        ($noSchedule || $isShifting) ? 'N/A' : $t($n($slots['break_in_1'])),
                        $noSchedule ? 'N/A' : $t($n($slots['lunch_out'])),
                        $noSchedule ? 'N/A' : $t($n($slots['lunch_in'])),
                        $noSchedule ? 'N/A' : $t($n($slots['break_out_2'])),
                        $noSchedule ? 'N/A' : $t($n($slots['break_in_2'])),
                        $t($n($slots['time_out'])),
                    ];

                    // A slot cell is highlighted red when:
                    //   - the employee was expected to be present (Present/Late/OB/On Leave Present)
                    //   - AND the slot resolved to '--' (no punch recorded)
                    //   - AND the slot is not disabled ('N/A')
                    $isExpected = in_array($remarks, [
                        'Present', 'Late', 'Present (OB)', 'On Leave (Present)',
                    ]);

                    $vals = [
                        (string) $empId,
                        (string) ($empIndex[$empId]->EMPNAME    ?? ''),
                        (string) ($empIndex[$empId]->DEPARTMENT ?? ''),
                        (string) ($empIndex[$empId]->STATION    ?? ''),
                        (string) ($empIndex[$empId]->PRODLINE   ?? ''),
                        $date,
                        $dayName,
                        (string) ($schInfo['shift_type'] ?? 'N/A'),
                        ...$slotValues,
                        $remarks,
                    ];

                    fwrite($fh, "<row r=\"{$rowNum}\">");
                    foreach ($vals as $ci => $val) {
                        $col = $this->colLetter($ci);
                        $esc = htmlspecialchars($val, ENT_XML1 | ENT_QUOTES, 'UTF-8');

                        if ($ci < 8) {
                            // Columns 0-7: employee info — no colour
                            $cellStyle = 0;
                        } elseif ($ci === 16) {
                            // Column 16: Remarks — remark colour
                            $cellStyle = $s;
                        } else {
                            // Columns 8-15: slot values
                            $slotVal   = $slotValues[$ci - 8];
                            $isMissing = $isExpected && $slotVal === '--';

                            // Over-break detection per slot index (ci - 8):
                            // Break 1 enabled (not shifting): break1=15min, lunch=60min, break2=15min
                            // Break 1 disabled (shifting):    lunch=60min, break2=30min
                            $isOverBreak = false;
                            if (!$isMissing && $slotVal !== '--' && $slotVal !== 'N/A') {
                                $slotIdx = $ci - 8; // 0=TimeIn,1=BO1,2=BI1,3=LO,4=LI,5=BO2,6=BI2,7=TimeOut
                                $isOverBreak = match($slotIdx) {
                                    2 => !$isShifting && (($d = $breakDuration($slotValues[1], $slotValues[2])) !== null && $d > 15),
                                    4 => ($d = $breakDuration($slotValues[3], $slotValues[4])) !== null && $d > 60,
                                    6 => ($d = $breakDuration($slotValues[5], $slotValues[6])) !== null && $d > ($isShifting ? 30 : 15),
                                    default => false,
                                };
                            }

                            $cellStyle = match(true) {
                                $isMissing   => $missingSlotStyle,
                                $isOverBreak => $overBreakSlotStyle,
                                default      => $s,
                            };
                        }

                        fwrite($fh, "<c r=\"{$col}{$rowNum}\" s=\"{$cellStyle}\" t=\"inlineStr\"><is><t>{$esc}</t></is></c>");
                    }
                    fwrite($fh, '</row>');
                    $rowNum++; $written++;

                } else {
                    // Without-breaks export: two punch rows per employee per day
                    foreach ([[$slots['time_in'],'IN'],[$slots['time_out'],'OUT']] as [$time,$flag]) {
                        if (!$time) continue;
                        $formattedDate = date('m/d/Y', strtotime($date));
                        $formattedTime = date('g:i:s A', strtotime($time));
                        $vals = [(string)$empId, (string)($empIndex[$empId]->EMPNAME ?? ''), $formattedDate, $formattedTime, $flag];
                        fwrite($fh, "<row r=\"{$rowNum}\">");
                        foreach ($vals as $ci => $val) {
                            $col = $this->colLetter($ci);
                            $esc = htmlspecialchars($val, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                            fwrite($fh, "<c r=\"{$col}{$rowNum}\" s=\"0\" t=\"inlineStr\"><is><t>{$esc}</t></is></c>");
                        }
                        fwrite($fh, '</row>');
                        $rowNum++; $written++;
                    }
                }
            }
        }

        unset($empLogIndex);

        fwrite($fh, '</sheetData></worksheet>');
        fclose($fh);

        if (file_exists($path)) unlink($path);
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml',        $this->contentTypesXml());
        $zip->addFromString('_rels/.rels',                $this->relsXml());
        $zip->addFromString('xl/workbook.xml',            $this->workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml());
        $zip->addFromString('xl/styles.xml',              $stylesXml);
        $zip->addFile($sheetTmp, 'xl/worksheets/sheet1.xml');
        $zip->close();
        unset($sheetTmp);

        return $written;
    }

    // ── Column letter cache ───────────────────────────────────────────────
    private static array $colLetterCache = [];
    private function colLetter(int $idx): string
    {
        if (isset(self::$colLetterCache[$idx])) return self::$colLetterCache[$idx];
        $letter = '';
        $n      = $idx;
        do {
            $letter = chr(65 + ($n % 26)) . $letter;
            $n      = intdiv($n, 26) - 1;
        } while ($n >= 0);
        return self::$colLetterCache[$idx] = $letter;
    }

    // ── Style index map ───────────────────────────────────────────────────
    // Indices: 0=default, 1=header, 2..N=one per remark, N+1=missing-slot
    private function styleIdxMap(): array
    {
        $map = [];
        $idx = 2;
        foreach (self::REMARK_COLORS as $remark => $_) {
            $map[$remark] = $idx++;
        }
        $map[self::MISSING_SLOT_KEY]    = $idx++;
        $map[self::OVER_BREAK_SLOT_KEY] = $idx;
        return $map;
    }

    // ── styles.xml ───────────────────────────────────────────────────────
    private function buildStylesXml(): string
    {
        $remarkCount = count(self::REMARK_COLORS);

        // ── fonts ──────────────────────────────────────────────────────────
        // 0: default, 1: header (white bold), 2..(1+remarkCount): remark fonts,
        // (2+remarkCount): missing-slot font (dark red)
        $fonts  = '<font><sz val="9"/><name val="Arial"/></font>';
        $fonts .= '<font><b/><sz val="9"/><name val="Arial"/><color rgb="FFFFFFFF"/></font>';
        foreach (self::REMARK_COLORS as [$bg, $fg]) {
            $fonts .= "<font><sz val=\"9\"/><name val=\"Arial\"/><color rgb=\"{$fg}\"/></font>";
        }
        $fonts .= '<font><sz val="9"/><name val="Arial"/><color rgb="' . self::MISSING_SLOT_FG  . '"/></font>';
        $fonts .= '<font><sz val="9"/><name val="Arial"/><color rgb="' . self::OVER_BREAK_SLOT_FG . '"/></font>';
        $fontCount = 2 + $remarkCount + 2;

        // ── fills ──────────────────────────────────────────────────────────
        // 0: none, 1: gray125 (required by spec), 2: header dark,
        // 3..(2+remarkCount): remark fills, (3+remarkCount): missing-slot fill
        $fills  = '<fill><patternFill patternType="none"/></fill>';
        $fills .= '<fill><patternFill patternType="gray125"/></fill>';
        $fills .= '<fill><patternFill patternType="solid"><fgColor rgb="FF1F2937"/></patternFill></fill>';
        foreach (self::REMARK_COLORS as [$bg, $fg]) {
            $fills .= "<fill><patternFill patternType=\"solid\"><fgColor rgb=\"{$bg}\"/></patternFill></fill>";
        }
        $fills .= '<fill><patternFill patternType="solid"><fgColor rgb="' . self::MISSING_SLOT_BG    . '"/></patternFill></fill>';
        $fills .= '<fill><patternFill patternType="solid"><fgColor rgb="' . self::OVER_BREAK_SLOT_BG . '"/></patternFill></fill>';
        $fillCount = 3 + $remarkCount + 2;

        $borders = '<border><left/><right/><top/><bottom/><diagonal/></border>';

        // ── cellXfs ────────────────────────────────────────────────────────
        // 0: default, 1: header, 2..(1+remarkCount): remark xfs,
        // (2+remarkCount): missing-slot xf
        $xfs  = '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>';
        $xfs .= '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>';
        $xfCount = 2;
        foreach (array_values(self::REMARK_COLORS) as $i => $_) {
            $fi = $i + 2;            // font index
            $fl = $i + 3;            // fill index (offset by 3 because of none/gray125/header)
            $xfs .= "<xf numFmtId=\"0\" fontId=\"{$fi}\" fillId=\"{$fl}\" borderId=\"0\" xfId=\"0\" applyFont=\"1\" applyFill=\"1\"/>";
            $xfCount++;
        }
        // Missing-slot xf
        $missingFontIdx = 2 + $remarkCount;
        $missingFillIdx = 3 + $remarkCount;
        $xfs .= "<xf numFmtId=\"0\" fontId=\"{$missingFontIdx}\" fillId=\"{$missingFillIdx}\" borderId=\"0\" xfId=\"0\" applyFont=\"1\" applyFill=\"1\"/>";
        $xfCount++;

        // Over-break xf
        $overBreakFontIdx = 2 + $remarkCount + 1;
        $overBreakFillIdx = 3 + $remarkCount + 1;
        $xfs .= "<xf numFmtId=\"0\" fontId=\"{$overBreakFontIdx}\" fillId=\"{$overBreakFillIdx}\" borderId=\"0\" xfId=\"0\" applyFont=\"1\" applyFill=\"1\"/>";
        $xfCount++;

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . "<fonts count=\"{$fontCount}\">{$fonts}</fonts>"
            . "<fills count=\"{$fillCount}\">{$fills}</fills>"
            . "<borders count=\"1\">{$borders}</borders>"
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . "<cellXfs count=\"{$xfCount}\">{$xfs}</cellXfs>"
            . '</styleSheet>';
    }

    private function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private function relsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private function workbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private function touchJobReservation(): void
    {
        try {
            $jobId = $this->job?->getJobId();
            if (!$jobId) return;
            DB::table('job_dtr')
                ->where('id', $jobId)
                ->update(['reserved_at' => now()->addMinutes(10)->timestamp]);
        } catch (\Throwable) {}
    }

    private function updateProgress(int $pct, string $message): void
    {
        Cache::put("export_{$this->jobId}", [
            'status'   => 'processing',
            'progress' => $pct,
            'message'  => $message,
            'filename' => null,
        ], now()->addMinutes(10));
    }

    public function failed(\Throwable $exception): void
    {
        Cache::put("export_{$this->jobId}", [
            'status'   => 'failed',
            'progress' => 0,
            'message'  => 'Export failed: ' . $exception->getMessage(),
            'filename' => null,
        ], now()->addMinutes(5));
    }
}