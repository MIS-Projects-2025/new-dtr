<?php

namespace App\Jobs;

use App\Services\EmployeeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class ExportBiometricLogs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries   = 1;

    private const REMARK_COLORS = [
        'Present'            => ['bg' => 'FFC6EFCE', 'fg' => 'FF375623'],
        'Late'               => ['bg' => 'FFFFCC99', 'fg' => 'FF833C00'],
        'Absent'             => ['bg' => 'FFFFC7CE', 'fg' => 'FF9C0006'],
        'Rest Day'           => ['bg' => 'FFF2F2F2', 'fg' => 'FF7F7F7F'],
        'Holiday'            => ['bg' => 'FFFFEB9C', 'fg' => 'FF9C6500'],
        'On Leave'           => ['bg' => 'FFDAEEF3', 'fg' => 'FF17375E'],
        'On Leave (Present)' => ['bg' => 'FFE4DFEC', 'fg' => 'FF403151'],
        'Pending'            => ['bg' => 'FFDDEBF7', 'fg' => 'FF1F4E79'],
    ];

    private array $timings = [];
    private float $jobStart;

    public function __construct(
        private string $jobId,
        private string $dateFrom,
        private string $dateTo,
        private string $type,
    ) {}

    private function tick(string $label): void
    {
        $elapsed = round(microtime(true) - $this->jobStart, 3);
        $this->timings[] = "[{$elapsed}s] {$label}";
        Log::channel('single')->info("[EXPORT TIMING] [{$elapsed}s] {$label}");
    }

    public function handle(EmployeeService $employeeService): void
    {
        $this->jobStart = microtime(true);
        $this->tick('Job started');

        $this->updateProgress(5, 'Initializing export...');

        try {
            $isWithBreaks = $this->type === 'with_breaks';

            if ($isWithBreaks) {
                $headers = [
                    'Employee ID', 'Employee Name', 'Department', 'Station', 'Prodline',
                    'Date', 'Day', 'Shift',
                    'Time In', 'Break Out 1', 'Break In 1',
                    'Lunch Out', 'Lunch In',
                    'Break Out 2', 'Break In 2', 'Time Out',
                    'Remarks',
                ];
                $colWidths = [12, 32, 22, 14, 14, 12, 6, 16, 10, 12, 10, 10, 10, 12, 10, 10, 16];
            } else {
                $headers   = ['Employee ID', 'Employee Name', 'Date DTR', 'Time DTR', 'Flag'];
                $colWidths = [12, 32, 12, 10, 12];
            }

            $colCount      = count($headers);
            $lastColLetter = Coordinate::stringFromColumnIndex($colCount);
            $normalize     = fn($v) => (!$v || $v === '--:--') ? '--' : $v;

            // ── Phase 1: Spreadsheet init ─────────────────────────────────
            $t = microtime(true);
            $spreadsheet = new Spreadsheet();
            $spreadsheet->getCalculationEngine()->disableCalculationCache();
            $spreadsheet->getCalculationEngine()->flushInstance();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('DTR Export');
            $sheet->fromArray($headers, null, 'A1');
            $sheet->getStyle("A1:{$lastColLetter}1")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'name' => 'Arial', 'size' => 9],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1F2937']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
            foreach ($colWidths as $idx => $width) {
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($idx + 1))->setWidth($width);
            }
            $sheet->freezePane('A2');
            $this->tick('Phase 1: Spreadsheet init — ' . round(microtime(true) - $t, 3) . 's');

            $this->updateProgress(10, 'Fetching base data...');

            // ── Phase 2: getExportDataStreamed setup (inside generator) ────
            // The generator does all DB queries on first next() call.
            // We time the FIRST yield separately from subsequent yields.
            $t           = microtime(true);
            $generator   = $employeeService->getExportDataStreamed([], $this->dateFrom, $this->dateTo);
            $firstBatch  = true;

            $dateCount    = (int) \Carbon\Carbon::parse($this->dateFrom)
                ->diffInDays(\Carbon\Carbon::parse($this->dateTo)) + 1;
            $datesWritten = 0;
            $rowIdx       = 2;
            $rowsByColor  = [];
            $totalRows    = 0;

            foreach ($generator as $dateBatch) {
                if ($firstBatch) {
                    $this->tick('Phase 2: First generator yield (all DB queries + first date PHP work) — ' . round(microtime(true) - $t, 3) . 's');
                    $firstBatch = false;
                }

                $batchT    = microtime(true);
                $batchRows = $dateBatch['rows'];
                $date      = $dateBatch['date'];

                usort($batchRows, fn($a, $b) => strcmp($a['EMPNAME'], $b['EMPNAME']));

                $buffer      = [];
                $bufferStart = $rowIdx;

                foreach ($batchRows as $row) {
                    $remarks    = $row['REMARKS'] ?? '';
                    $isShifting = $row['IS_SHIFTING'] ?? false;

                    if ($isWithBreaks) {
                        $buffer[] = [
                            $row['EMPLOYID'],
                            $row['EMPNAME'],
                            $row['DEPARTMENT'],
                            $row['STATION'],
                            $row['PRODLINE'],
                            $row['DATE'],
                            $row['DAY'],
                            $row['SHIFT_TYPE'],
                            $normalize($row['Time In (actual)']     ?? null),
                            $isShifting ? 'N/A' : $normalize($row['Break Out 1 (actual)'] ?? null),
                            $isShifting ? 'N/A' : $normalize($row['Break In 1 (actual)']  ?? null),
                            $normalize($row['Lunch Out (actual)']   ?? null),
                            $normalize($row['Lunch In (actual)']    ?? null),
                            $normalize($row['Break Out 2 (actual)'] ?? null),
                            $normalize($row['Break In 2 (actual)']  ?? null),
                            $normalize($row['Time Out (actual)']    ?? null),
                            $remarks,
                        ];
                        if (isset(self::REMARK_COLORS[$remarks])) {
                            $rowsByColor[$remarks][] = $rowIdx;
                        }
                        $rowIdx++;
                    } else {
                        foreach ([
                            [$row['Time In (actual)']  ?? null, 'check_in'],
                            [$row['Time Out (actual)'] ?? null, 'check_out'],
                        ] as [$time, $flag]) {
                            if (!$time || $time === '--:--' || $time === '--') continue;
                            $buffer[] = [$row['EMPLOYID'], $row['EMPNAME'], $row['DATE'], $time, $flag];
                            if (isset(self::REMARK_COLORS[$remarks])) {
                                $rowsByColor[$remarks][] = $rowIdx;
                            }
                            $rowIdx++;
                        }
                    }
                }

                $writeT = microtime(true);
                if (!empty($buffer)) {
                    $sheet->fromArray($buffer, null, 'A' . $bufferStart);
                }
                $totalRows += count($buffer);

                unset($buffer, $batchRows, $dateBatch);

                $datesWritten++;
                $pct = 10 + (int)(($datesWritten / max($dateCount, 1)) * 72);
                $this->updateProgress($pct, "Processing {$date}... ({$datesWritten}/{$dateCount} days)");

                $this->tick(
                    "Phase 3: Date {$date} — "
                    . round(microtime(true) - $batchT, 3) . 's total'
                    . ' | fromArray ' . round(microtime(true) - $writeT, 3) . 's'
                    . ' | rows: ' . count($buffer ?? [])
                );
            }

            $this->tick("Phase 3 complete: {$totalRows} total rows written");

            // ── Phase 4: Row coloring ─────────────────────────────────────
            $t = microtime(true);
            $this->updateProgress(85, 'Applying row colors...');
            $this->applyRowColorsFast($sheet, $rowsByColor, $lastColLetter);
            unset($rowsByColor);
            $this->tick('Phase 4: Row coloring — ' . round(microtime(true) - $t, 3) . 's');

            // ── Phase 5: File save ────────────────────────────────────────
            $t        = microtime(true);
            $filename = "biometric_dtr_{$this->dateFrom}_to_{$this->dateTo}_{$this->type}.xlsx";
            $dir      = storage_path('app/exports');
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $path   = $dir . '/' . $filename;
            $writer = new Xlsx($spreadsheet);
            $writer->setPreCalculateFormulas(false);
            $writer->save($path);
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet, $writer);
            $this->tick('Phase 5: File save — ' . round(microtime(true) - $t, 3) . 's');

            // ── Final timing summary ──────────────────────────────────────
            $totalTime = round(microtime(true) - $this->jobStart, 3);
            $summary   = implode("\n", $this->timings);
            Log::channel('single')->info(
                "[EXPORT TIMING SUMMARY] Total: {$totalTime}s\n{$summary}"
            );

            // Also store in cache so you can read it from the frontend temporarily
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
            Log::channel('single')->error('[EXPORT TIMING] FAILED: ' . $e->getMessage());

            Cache::put("export_{$this->jobId}", [
                'status'   => 'failed',
                'progress' => 0,
                'message'  => 'Export failed: ' . $e->getMessage(),
                'filename' => null,
            ], now()->addMinutes(5));

            throw $e;
        }
    }

    private function applyRowColorsFast(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        array  $rowsByColor,
        string $lastColLetter
    ): void {
        foreach ($rowsByColor as $remarks => $rowIndices) {
            $colors = self::REMARK_COLORS[$remarks] ?? null;
            if (!$colors) continue;

            foreach ($this->buildContiguousRanges($rowIndices, $lastColLetter) as $range) {
                $style = $sheet->getStyle($range);
                $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($colors['bg']);
                $font = $style->getFont();
                $font->getColor()->setARGB($colors['fg']);
                $font->setName('Arial')->setSize(9);
            }
        }
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

    private function buildContiguousRanges(array $rowIndices, string $lastCol): array
    {
        if (empty($rowIndices)) return [];
        sort($rowIndices);
        $ranges = [];
        $start  = $rowIndices[0];
        $prev   = $rowIndices[0];
        for ($i = 1; $i < count($rowIndices); $i++) {
            if ($rowIndices[$i] === $prev + 1) {
                $prev = $rowIndices[$i];
            } else {
                $ranges[] = "A{$start}:{$lastCol}{$prev}";
                $start    = $rowIndices[$i];
                $prev     = $rowIndices[$i];
            }
        }
        $ranges[] = "A{$start}:{$lastCol}{$prev}";
        return $ranges;
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