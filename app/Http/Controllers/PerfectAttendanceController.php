<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Services\EmployeeService;
use App\Services\DtrLogService;
use App\Models\EmployeeMasterlist;
use App\Models\WorkScheduler;
use App\Models\ShiftCode;
use App\Models\EmployeeLeave;
use App\Models\Holiday;
use App\Models\ObRecord;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class PerfectAttendanceController extends Controller
{
    public function __construct(
        private EmployeeService $employeeService,
        private DtrLogService   $dtrLogService,
    ) {}

    public function index(Request $request)
    {
        return Inertia::render('PerfectAttendance', [
            'app_name' => env('APP_NAME', ''),
        ]);
    }

    public function getEmployees(Request $request)
    {
        try {
            $search  = $request->get('search', '');
            $filters = $request->only(['company', 'prodline', 'department', 'station']);

            $employees = EmployeeMasterlist::where('ACCSTATUS', 1)
                ->whereIn('EMPPOSITION', [1, 2])
                ->where('BIOMETRIC_STATUS', 'Enabled')
                ->whereNotNull('PRODLINE')
                ->where('PRODLINE', '!=', '')
                ->when(!empty($search), fn($q) => $q->where(function ($q) use ($search) {
                    $q->where('EMPNAME',   'like', "%{$search}%")
                      ->orWhere('EMPLOYID', 'like', "%{$search}%");
                }))
                ->when(!empty($filters['department']), fn($q) => $q->where('DEPARTMENT', $filters['department']))
                ->when(!empty($filters['station']),    fn($q) => $q->where('STATION',    $filters['station']))
                ->when(!empty($filters['prodline']),   fn($q) => $q->where('PRODLINE',   $filters['prodline']))
                ->orderBy('EMPNAME')
                ->limit(100)
                ->get(['EMPLOYID', 'EMPNAME', 'DEPARTMENT', 'JOB_TITLE']);

            return response()->json($employees);
        } catch (\Throwable $e) {
            \Log::error('Perfect attendance employees error: ' . $e->getMessage());
            return response()->json([], 500);
        }
    }

    public function getDtrRows(Request $request)
    {
        try {
            $employId     = $request->get('employ_id');
            $month        = $request->get('month', now()->format('Y-m'));
            $page         = (int) $request->get('page', 1);
            $shiftFilter  = (string) $request->get('shift_filter', '');
            $statusFilter = (string) $request->get('status_filter', '');

            if (!$employId) {
                return response()->json(['error' => 'employ_id is required.'], 400);
            }

            [$year, $mon] = explode('-', $month);
            $startDate    = Carbon::create((int)$year, (int)$mon, 1)->toDateString();
            $endDate      = Carbon::create((int)$year, (int)$mon, 1)->endOfMonth()->toDateString();

            $today = now()->toDateString();
            if ($endDate > $today) $endDate = $today;

            $result = $this->employeeService->getDtrRowsForEmployee(
                $employId, $startDate, $endDate, $page, 25, $shiftFilter, $statusFilter
            );

            return response()->json($result);
        } catch (\Throwable $e) {
            \Log::error('Perfect attendance DTR error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // getPerfectAttendanceStats
    //
    // Strategy (mirrors ExportBiometricLogs job):
    //   1. One query  → employees
    //   2. One query  → schedules + shift codes
    //   3. One UNION  → ALL biometric punches for the month
    //   4. One query  → leaves
    //   5. One query  → holidays
    //   6. One query  → OB records
    //   7. Pure-PHP loop — zero additional DB calls
    // ─────────────────────────────────────────────────────────────────────────

    public function getPerfectAttendanceStats(Request $request)
    {
        $month      = $request->get('month', now()->format('Y-m'));
        $department = $request->get('department', '');
        $station    = $request->get('station', '');
        $prodline   = $request->get('prodline', '');

        $cacheKey = "perfect_attendance_v2:{$month}:{$department}:{$station}:{$prodline}";
        $ttl      = str_starts_with($month, now()->format('Y-m')) ? 300 : 3600;

        try {
            $result = Cache::remember($cacheKey, $ttl, function () use ($month, $department, $station, $prodline) {
                return $this->computePerfectAttendanceStats($month, $department, $station, $prodline);
            });
            return response()->json($result);
        } catch (\Throwable $e) {
            \Log::error('Perfect attendance stats error: ' . $e->getMessage()
                . ' ' . $e->getFile() . ':' . $e->getLine());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function computePerfectAttendanceStats(string $month, string $department, string $station, string $prodline): array
    {
        try {
            [$year, $mon] = explode('-', $month);
            $startDate    = Carbon::create((int)$year, (int)$mon, 1)->toDateString();
            $endDate      = Carbon::create((int)$year, (int)$mon, 1)->endOfMonth()->toDateString();

            $today = now()->toDateString();
            if ($endDate > $today) $endDate = $today;

            // ── 1. Employees ──────────────────────────────────────────────────
            $employees = EmployeeMasterlist::where('ACCSTATUS', 1)
                ->whereIn('EMPPOSITION', [1, 2])
                ->where('BIOMETRIC_STATUS', 'Enabled')
                ->whereNotNull('PRODLINE')
                ->where('PRODLINE', '!=', '')
                ->when(!empty($department), fn($q) => $q->where('DEPARTMENT', $department))
                ->when(!empty($station),    fn($q) => $q->where('STATION',    $station))
                ->when(!empty($prodline),   fn($q) => $q->where('PRODLINE',   $prodline))
                ->where(function ($q) use ($startDate) {
                    $q->whereNull('DATEHIRED')->orWhere('DATEHIRED', '<=', $startDate);
                })
                ->orderBy('EMPNAME')
                ->get(['EMPLOYID', 'EMPNAME', 'DEPARTMENT', 'STATION', 'PRODLINE', 'JOB_TITLE', 'DATEHIRED']);

            if ($employees->isEmpty()) {
                return [
                    'total_employees'        => 0,
                    'perfect_attendance'     => 0,
                    'perfect_attendance_pct' => 0,
                    'with_absences'          => 0,
                    'employees'              => [],
                    'filter_options'         => $this->getFilterOptions(),
                ];
            }

            $empIds   = $employees->pluck('EMPLOYID')->map(fn($id) => (string)$id)->toArray();
            $empIndex = $employees->keyBy('EMPLOYID');

            // ── 2. Schedules + shift codes ────────────────────────────────────
            $schedules = WorkScheduler::whereIn('EMPID', $empIds)
                ->whereDate('PAYROLL_DATE_START', '<=', $endDate)
                ->whereDate('PAYROLL_DATE_END',   '>=', $startDate)
                ->get(['EMPID', 'SCHEDULE', 'PAYROLL_DATE_START', 'PAYROLL_DATE_END', 'SHIFT']);

            $allShiftIds = [];
            foreach ($schedules as $sch) {
                foreach ((array)($sch->SCHEDULE ?? []) as $sid) {
                    if ($sid) $allShiftIds[(int)$sid] = true;
                }
            }

            $shiftCodeMap = [];
            if (!empty($allShiftIds)) {
                ShiftCode::whereIn('SHIFT_CODE_ID', array_keys($allShiftIds))
                    ->get(['SHIFT_CODE_ID', 'SHIFTCODE', 'TIME_WINDOWS'])
                    ->each(function ($sc) use (&$shiftCodeMap) {
                        $raw  = $sc->TIME_WINDOWS;
                        $tw   = is_array($raw) ? $raw : (json_decode((string)$raw, true) ?? []);
                        $code = strtoupper($sc->SHIFTCODE ?? '');
                        $shiftCodeMap[(int)$sc->SHIFT_CODE_ID] = [
                            'tw'    => $tw,
                            'code'  => $sc->SHIFTCODE ?? 'N/A',
                            'is_rd' => str_contains($code, 'RD'),
                        ];
                    });
            }

            // Build scheduleByEmpDate[empId][date]
            $scheduleByEmpDate = [];
            foreach ($schedules as $sch) {
                $empId      = (string)$sch->EMPID;
                $schedule   = (array)($sch->SCHEDULE ?? []);
                $shiftField = (int)($sch->SHIFT ?? 0);
                $startTs    = strtotime(substr((string)$sch->PAYROLL_DATE_START, 0, 10));
                $iterStart  = max(strtotime($startDate), strtotime(substr((string)$sch->PAYROLL_DATE_START, 0, 10)));
                $iterEnd    = min(strtotime($endDate),   strtotime(substr((string)$sch->PAYROLL_DATE_END,   0, 10)));

                for ($ts = $iterStart; $ts <= $iterEnd; $ts += 86400) {
                    $d        = date('Y-m-d', $ts);
                    $dayIndex = (int)(($ts - $startTs) / 86400) + 1;
                    $shiftId  = (int)($schedule[(string)$dayIndex] ?? $schedule[$dayIndex] ?? 0);
                    $sc       = $shiftId ? ($shiftCodeMap[$shiftId] ?? null) : null;
                    $tw       = $sc['tw'] ?? [];

                    $isShifting = false;
                    if ($shiftField === 2) {
                        $isShifting = true;
                    } elseif ($shiftField !== 1 && !empty($tw[0]) && !empty($tw[7])) {
                        $dur = $this->mins($tw[7]) - $this->mins($tw[0]);
                        if ($dur < 0) $dur += 1440;
                        if ($dur >= 720) $isShifting = true;
                    }

                    $scheduleByEmpDate[$empId][$d] = [
                        'tw'          => $tw,
                        'is_rd'       => $sc['is_rd'] ?? false,
                        'is_shifting' => $isShifting,
                        'shiftField'  => $shiftField,
                        'has_sched'   => $sc !== null,
                    ];
                }
            }
            unset($schedules, $shiftCodeMap);

            // ── 3. ALL punches — one UNION query ──────────────────────────────
            $punchFrom = Carbon::parse($startDate)->subDay()->toDateString();
            $punchTo   = Carbon::parse($endDate)->addDays(2)->toDateString();

            $punchTypeMap = [
                '0' => 'check_in', '1' => 'check_out', '2' => 'break_out', '3' => 'break_in',
                'check_in' => 'check_in', 'check_out' => 'check_out',
                'break_out' => 'break_out', 'break_in' => 'break_in',
            ];

            $preNormalizedLogs = [];
            $ph  = implode(',', array_fill(0, count($empIds), '?'));
            $sql = "
                SELECT employid, datetime, punch_type
                FROM biometric_logs
                WHERE employid IN ({$ph}) AND datetime BETWEEN ? AND ?
                UNION ALL
                SELECT employid, datetime, punch_type
                FROM biometric_logs_manual
                WHERE employid IN ({$ph}) AND datetime BETWEEN ? AND ?
                ORDER BY datetime
            ";
            $bindings = array_merge(
                $empIds, [$punchFrom . ' 00:00:00', $punchTo . ' 23:59:59'],
                $empIds, [$punchFrom . ' 00:00:00', $punchTo . ' 23:59:59']
            );

            foreach (DB::connection('dtr')->select($sql, $bindings) as $r) {
                $eid = (string)$r->employid;
                $dt  = Carbon::parse($r->datetime);
                $preNormalizedLogs[$eid][] = [
                    'employid' => $eid,
                    'datetime' => $r->datetime,
                    'date'     => $dt->toDateString(),
                    'time'     => $dt->format('H:i'),
                    'type'     => $punchTypeMap[(string)$r->punch_type] ?? 'check_in',
                    'source'   => 'mixed',
                ];
            }
            foreach ($preNormalizedLogs as &$logs) {
                usort($logs, fn($a, $b) => strcmp($a['datetime'], $b['datetime']));
            }
            unset($logs);

            // ── 4. Leaves ─────────────────────────────────────────────────────
            $leaveByEmpDate = [];
            EmployeeLeave::whereIn('EMPLOYID', $empIds)
                ->where('LEAVESTATUS', 'approved')
                ->whereDate('DATESTART', '<=', $endDate)
                ->whereDate('DATEEND',   '>=', $startDate)
                ->get(['EMPLOYID', 'DATESTART', 'DATEEND'])
                ->each(function ($lv) use (&$leaveByEmpDate, $startDate, $endDate) {
                    $eid = (string)$lv->EMPLOYID;
                    $s   = max(substr((string)$lv->DATESTART, 0, 10), $startDate);
                    $e   = min(substr((string)$lv->DATEEND,   0, 10), $endDate);
                    for ($ts = strtotime($s); $ts <= strtotime($e); $ts += 86400) {
                        $leaveByEmpDate[$eid][date('Y-m-d', $ts)] = true;
                    }
                });

            // ── 5. Holidays ───────────────────────────────────────────────────
            $holidaySet = Holiday::whereBetween('HOLIDAY_DATE', [$startDate, $endDate])
                ->pluck('HOLIDAY_DATE')
                ->mapWithKeys(fn($d) => [substr((string)$d, 0, 10) => true])
                ->all();

            // ── 6. OBs ────────────────────────────────────────────────────────
            $obByEmpDate = [];
            ObRecord::whereIn('EMPID', $empIds)
                ->whereIn('STATUS', [1, 2])
                ->whereDate('DATE_OB_FROM', '<=', $endDate)
                ->whereDate('DATE_OB_TO',   '>=', $startDate)
                ->get(['EMPID', 'TIME_FROM', 'TIME_TO', 'FORM_TYPE', 'DATE_OB_FROM', 'DATE_OB_TO'])
                ->each(function ($ob) use (&$obByEmpDate, $startDate, $endDate) {
                    $eid = (string)$ob->EMPID;
                    $s   = max(substr((string)$ob->DATE_OB_FROM, 0, 10), $startDate);
                    $e   = min(substr((string)$ob->DATE_OB_TO,   0, 10), $endDate);
                    $tf  = substr((string)($ob->TIME_FROM ?? ''), 0, 5);
                    $tt  = substr((string)($ob->TIME_TO   ?? ''), 0, 5);
                    for ($ts = strtotime($s); $ts <= strtotime($e); $ts += 86400) {
                        $obByEmpDate[$eid][date('Y-m-d', $ts)] = [
                            'time_from' => $tf,
                            'time_to'   => $tt,
                            'form_type' => $ob->FORM_TYPE,
                        ];
                    }
                });

            // ── 7. Build date list ────────────────────────────────────────
            $today = now()->toDateString(); // needed for pending check inside loop
            $dates = [];
            for ($ts = strtotime($startDate); $ts <= strtotime($endDate); $ts += 86400) {
                $dates[] = date('Y-m-d', $ts);
            }

            // ── 8. Evaluate — pure PHP, zero DB ──────────────────────────────
            $perfectList  = [];
            $withAbsences = 0;
            $scheduled    = 0;

            foreach ($empIds as $empId) {
                $emp         = $empIndex[$empId];
                $hireDateRaw = $emp->DATEHIRED ?? null;
                $hireDate    = $hireDateRaw ? substr((string)$hireDateRaw, 0, 10) : null;

                
                if (!isset($scheduleByEmpDate[$empId])) continue;

                $workingDays   = 0;
                $absentDays    = 0;
                $lateDays      = 0;
                $overBreakDays = 0;
                $presentDays   = 0;
                $onLeaveDays   = 0;
                $pendingDays   = 0;
                $hasMissing    = false;
                $hasSchedule   = false;

                foreach ($dates as $date) {
                    if ($hireDate && $date < $hireDate) continue;

                    $schInfo   = $scheduleByEmpDate[$empId][$date] ?? null;
                    $isHoliday = isset($holidaySet[$date]);
                    $isOnLeave = isset($leaveByEmpDate[$empId][$date]);
                    $isRestDay = $schInfo['is_rd'] ?? false;
                    $hasSched  = $schInfo !== null && ($schInfo['has_sched'] ?? false);
                    $tw        = $schInfo['tw'] ?? [];

                    // If it's a holiday with punches but no schedule,
                    // we still need to evaluate their logs for missing punches.
                    if (!$hasSched) {
                        if ($isHoliday) {
                            $resolvedMap = $this->dtrLogService->resolveLogsFromPreNormalized(
                                [$empId],
                                [$empId => $tw],
                                [$empId => ($isShifting ? 'Shifting' : 'Normal')],
                                $date,
                                $preNormalizedLogs
                            );
                            $slots = $resolvedMap[$empId] ?? [];

                            $slotGet = function (array $slots, array $keys): ?string {
                                foreach ($keys as $k) {
                                    $v = $slots[$k] ?? null;
                                    if ($v && $v !== '--' && $v !== '--:--' && trim($v) !== '') {
                                        return trim($v);
                                    }
                                }
                                return null;
                            };

                            $timeIn  = $slotGet($slots, ['time_in',  'TIME_IN',  'check_in',  'timein']);
                            $timeOut = $slotGet($slots, ['time_out', 'TIME_OUT', 'check_out', 'timeout']);
                            $b1Out   = $slotGet($slots, ['break_out_1', 'break_out1', 'BREAK_OUT_1', 'breakout1']);
                            $b1In    = $slotGet($slots, ['break_in_1',  'break_in1',  'BREAK_IN_1',  'breakin1']);
                            $lOut    = $slotGet($slots, ['lunch_out',   'LUNCH_OUT',  'lunchout']);
                            $lIn     = $slotGet($slots, ['lunch_in',    'LUNCH_IN',   'lunchin']);
                            $b2Out   = $slotGet($slots, ['break_out_2', 'break_out2', 'BREAK_OUT_2', 'breakout2']);
                            $b2In    = $slotGet($slots, ['break_in_2',  'break_in2',  'BREAK_IN_2',  'breakin2']);

                            $hasAnyPunch = ($timeIn || $timeOut || $lOut || $lIn || $b1Out || $b1In || $b2Out || $b2In);

                            if ($hasAnyPunch) {
                                $breakDur = function (?string $out, ?string $in): ?int {
                                    if (!$out || !$in) return null;
                                    $dur = $this->mins($in) - $this->mins($out);
                                    if ($dur < 0) $dur += 1440;
                                    return $dur;
                                };

                                $rdMissing = false;
                                if (!$timeIn)             $rdMissing = true;
                                if (!$timeOut)            $rdMissing = true;
                                if ($b1Out && !$b1In)     $rdMissing = true;
                                if (!$b1Out && $b1In)     $rdMissing = true;
                                if ($lOut  && !$lIn)      $rdMissing = true;
                                if (!$lOut  && $lIn)      $rdMissing = true;
                                if ($b2Out && !$b2In)     $rdMissing = true;
                                if (!$b2Out && $b2In)     $rdMissing = true;

                                $rdOverBreak = false;
                                $rdB2Allowed = $isShifting ? 30 : 15;
                                if (($d1 = $breakDur($b1Out, $b1In)) !== null && $d1 > 15)       $rdOverBreak = true;
                                if (($dL = $breakDur($lOut,  $lIn))  !== null && $dL > 60)       $rdOverBreak = true;
                                if (($d2 = $breakDur($b2Out, $b2In)) !== null && $d2 > $rdB2Allowed) $rdOverBreak = true;

                                if ($rdMissing)   $hasMissing = true;
                                if ($rdOverBreak) $hasMissing = true;
                            }
                        }
                        continue;
                    }
                    $hasSchedule = true;

                    $isShifting = $schInfo['is_shifting'] ?? false;

                    $resolvedMap = $this->dtrLogService->resolveLogsFromPreNormalized(
                        [$empId],
                        [$empId => $tw],
                        [$empId => ($isShifting ? 'Shifting' : 'Normal')],
                        $date,
                        $preNormalizedLogs
                    );
                    $slots = $resolvedMap[$empId] ?? [];

                    // ── Normalize slot values — handle any key name variation ──
                    $slotGet = function (array $slots, array $keys): ?string {
                        foreach ($keys as $k) {
                            $v = $slots[$k] ?? null;
                            if ($v && $v !== '--' && $v !== '--:--' && trim($v) !== '') {
                                return trim($v);
                            }
                        }
                        return null;
                    };

                    $timeIn  = $slotGet($slots, ['time_in',   'TIME_IN',   'check_in',  'timein']);
                    $timeOut = $slotGet($slots, ['time_out',  'TIME_OUT',  'check_out', 'timeout']);
                    $b1Out   = $slotGet($slots, ['break_out_1', 'break_out1', 'BREAK_OUT_1', 'breakout1']);
                    $b1In    = $slotGet($slots, ['break_in_1',  'break_in1',  'BREAK_IN_1',  'breakin1']);
                    $lOut    = $slotGet($slots, ['lunch_out',   'LUNCH_OUT',   'lunchout']);
                    $lIn     = $slotGet($slots, ['lunch_in',    'LUNCH_IN',    'lunchin']);
                    $b2Out   = $slotGet($slots, ['break_out_2', 'break_out2', 'BREAK_OUT_2', 'breakout2']);
                    $b2In    = $slotGet($slots, ['break_in_2',  'break_in2',  'BREAK_IN_2',  'breakin2']);

                    $hasAnyPunch = ($timeIn || $timeOut || $lOut || $lIn || $b1Out || $b1In || $b2Out || $b2In);
                    $expectedIn  = $tw[0] ?? null;
                    $expectedOut = $tw[7] ?? null;

                    $workingDays++;

                    // ── Break duration helper ─────────────────────────────────
                    $breakDur = function (?string $out, ?string $in): ?int {
                        if (!$out || !$in) return null;
                        $dur = $this->mins($in) - $this->mins($out);
                        if ($dur < 0) $dur += 1440;
                        return $dur;
                    };

                    // ── Missing punch / slot-due helpers ─────────────────────
                    // Extract expected slots from time windows
                    $expB1Out = !$isShifting ? ($tw[1] ?? null) : null;
                    $expB1In  = !$isShifting ? ($tw[2] ?? null) : null;
                    $expLOut  = $tw[3] ?? null;
                    $expLIn   = $tw[4] ?? null;
                    $expB2Out = $tw[5] ?? null;
                    $expB2In  = $tw[6] ?? null;

                    // For today, compute current time in minutes to skip
                    // slots whose expected time hasn't occurred yet.
                    $isToday = ($date === $today);
                    $nowMins = $isToday ? ((int)date('H') * 60 + (int)date('i')) : 99999;

                    // Helper: has this expected slot's time already passed?
                    $slotDue = function (?string $expTime) use ($isToday, $nowMins, $expectedIn): bool {
                        if (!$expTime) return false;
                        if (!$isToday) return true; // past days always due
                        $slotMins = (int)explode(':', $expTime)[0] * 60 + (int)explode(':', $expTime)[1];
                        // Handle overnight slots (e.g. night shift expected at 02:00)
                        $inMins = $expectedIn
                            ? ((int)explode(':', $expectedIn)[0] * 60 + (int)explode(':', $expectedIn)[1])
                            : 0;
                        if ($inMins > 720 && $slotMins < 720) $slotMins += 1440; // crosses midnight
                        return $nowMins >= $slotMins;
                    };

                    // ── Rest day / Holiday — not a working day ────────────────
                    // But if they punched at all, every slot they started must
                    // be complete — any missing actual disqualifies them.
                    if ($isRestDay || $isHoliday) {
                        if ($hasAnyPunch) {
                            $rdMissing = false;

                            // Must have both Time In and Time Out
                            if (!$timeIn)  $rdMissing = true;
                            if (!$timeOut) $rdMissing = true;

                            // Any started break pair must be closed
                            if ($b1Out && !$b1In) $rdMissing = true;
                            if (!$b1Out && $b1In) $rdMissing = true;
                            if ($lOut  && !$lIn)  $rdMissing = true;
                            if (!$lOut  && $lIn)  $rdMissing = true;
                            if ($b2Out && !$b2In) $rdMissing = true;
                            if (!$b2Out && $b2In) $rdMissing = true;

                            // Over-break check
                            $rdOverBreak = false;
                            $rdB2Allowed = $isShifting ? 30 : 15;
                            if (!$isShifting && ($d1 = $breakDur($b1Out, $b1In)) !== null && $d1 > 15)  $rdOverBreak = true;
                            if (($dL = $breakDur($lOut,  $lIn))  !== null && $dL > 60)                  $rdOverBreak = true;
                            if (($d2 = $breakDur($b2Out, $b2In)) !== null && $d2 > $rdB2Allowed)        $rdOverBreak = true;

                            if ($rdMissing)   $hasMissing = true;
                            if ($rdOverBreak) $hasMissing = true;
                        }
                        $workingDays--; // undo the increment above — not a working day
                        continue;
                    }

                    // ── Over-break detection ──────────────────────────────────
                    $hasOverBreak = false;
                    $b2Allowed    = $isShifting ? 30 : 15;

                    if (!$isShifting && ($d1 = $breakDur($b1Out, $b1In)) !== null && $d1 > 15)  $hasOverBreak = true;
                    if (($dL = $breakDur($lOut, $lIn))   !== null && $dL > 60)                  $hasOverBreak = true;
                    if (($d2 = $breakDur($b2Out, $b2In)) !== null && $d2 > $b2Allowed)          $hasOverBreak = true;

                    $isMissingPunch = false;

                    // Always validate if the employee punched at all,
                    // OR if it's a scheduled working day (not rest/holiday).
                    $shouldCheck = $hasAnyPunch || (!$isRestDay && !$isHoliday && $expectedIn);

                    if ($shouldCheck) {
                        // Time In: only flag missing if expected time has passed
                        if (!$timeIn && $slotDue($expectedIn))   $isMissingPunch = true;

                        // Time Out: only flag missing if expected time has passed
                        if (!$timeOut && $slotDue($expectedOut)) $isMissingPunch = true;

                        // Partial break pairs (started but never returned)
                        if ($b1Out && !$b1In) $isMissingPunch = true;
                        if ($lOut  && !$lIn)  $isMissingPunch = true;
                        if ($b2Out && !$b2In) $isMissingPunch = true;

                        // Expected slots not taken at all — only enforce if the
                        // slot time has already passed (guards against today's future slots)
                        if ($timeIn) {
                            if ($expB1Out && $slotDue($expB1Out) && (!$b1Out || !$b1In)) $isMissingPunch = true;
                            if ($expLOut  && $slotDue($expLOut)  && (!$lOut  || !$lIn))  $isMissingPunch = true;
                            if ($expB2Out && $slotDue($expB2Out) && (!$b2Out || !$b2In)) $isMissingPunch = true;
                        }
                    }

                    // ── Late detection ────────────────────────────────────────
                    $isLate = false;
                    if ($timeIn && $expectedIn) {
                        $diff = $this->mins($timeIn) - $this->mins($expectedIn);
                        if (abs($diff) > 720) $diff = $diff > 0 ? $diff - 1440 : $diff + 1440;
                        if ($diff > 0) $isLate = true;
                    }

                    // ── Absent detection ──────────────────────────────────────
                    // No punch at all on a scheduled working day = absent
                    $isAbsent = !$hasAnyPunch && !$isOnLeave && !$isRestDay && !$isHoliday && $expectedIn !== null;

                    // ── On leave detection ────────────────────────────────────
                    $isOnLeaveDay = $isOnLeave;

                    // ── Pending detection (today, punch not yet expected) ─────
                    $isPending = false;
                    // Don't flag pending on rest days or holidays
                    if (!$hasAnyPunch && $expectedIn !== null && !$isRestDay && !$isHoliday && $date === $today) {
                        $nowMins = (int)date('H') * 60 + (int)date('i');
                        $expMins = $this->mins($expectedIn);
                        $expHour = (int)explode(':', $expectedIn)[0];
                        if ($expHour >= 18 && (int)date('H') < $expHour) {
                            $isPending = true;
                            $isAbsent  = false;
                        } elseif ($nowMins < $expMins) {
                            $isPending = true;
                            $isAbsent  = false;
                        }
                    }

                    // ── Categorize ────────────────────────────────────────────
                    if ($isAbsent)                                        $absentDays++;
                    if ($isLate)                                          $lateDays++;
                    if ($isOnLeaveDay)                                    $onLeaveDays++;
                    if ($isPending)                                       $pendingDays++;
                    if ($isMissingPunch && !$isAbsent && !$isOnLeaveDay) $hasMissing = true;
                    if ($hasOverBreak   && !$isAbsent && !$isOnLeaveDay) $overBreakDays++;

                    if (!$isAbsent && !$isOnLeaveDay && !$isPending) {
                        $presentDays++;
                    }
                }

                if (!$hasSchedule || $workingDays === 0) continue;

                $scheduled++;

                // Perfect = present every day, on time, no over-breaks, no missing punches
                $isPerfect = $absentDays    === 0
                          && $lateDays      === 0
                          && $onLeaveDays   === 0
                          && $overBreakDays === 0
                          && !$hasMissing;

                if ($isPerfect) {
                    $perfectList[] = [
                        'EMPLOYID'    => $empId,
                        'EMPNAME'     => $emp->EMPNAME,
                        'DEPARTMENT'  => $emp->DEPARTMENT,
                        'STATION'     => $emp->STATION ?? '',
                        'PRODLINE'    => $emp->PRODLINE ?? '',
                        'JOB_TITLE'   => $emp->JOB_TITLE ?? '',
                        'working_days'=> $workingDays,
                        'present'     => $presentDays,
                        'on_leave'    => $onLeaveDays,
                        'pending'     => $pendingDays,
                    ];
                } else {
                    $withAbsences++;
                }
            }

            $perfectCount = count($perfectList);
            $pct = $scheduled > 0 ? round(($perfectCount / $scheduled) * 100, 1) : 0;

            return [
            'total_employees'        => $scheduled,
            'perfect_attendance'     => $perfectCount,
            'perfect_attendance_pct' => $pct,
            'with_absences'          => $withAbsences,
            'employees'              => $perfectList,
            'filter_options'         => $this->getFilterOptions(),
        ];

    } catch (\Throwable $e) {
        \Log::error('Perfect attendance stats compute error: ' . $e->getMessage()
            . ' ' . $e->getFile() . ':' . $e->getLine());
        throw $e;
    }
}

    // ── Shared private helpers ────────────────────────────────────────────────

    private function mins(string $t): int
    {
        if (!$t) return 0;
        [$h, $m] = explode(':', $t . ':0');
        return (int)$h * 60 + (int)$m;
    }

    private function fastRemarks(
        bool    $hasPunch,
        bool    $isEffectiveRest,
        bool    $isOnLeave,
        ?string $actualTimeIn,
        ?string $expectedTimeIn,
        string  $date,
        bool    $isHoliday
    ): string {
        if ($hasPunch) {
            if ($isEffectiveRest || $isHoliday) return 'Present';
            if ($isOnLeave && $actualTimeIn)    return 'On Leave (Present)';
            if ($actualTimeIn && $expectedTimeIn) {
                $diff = $this->mins($actualTimeIn) - $this->mins($expectedTimeIn);
                if (abs($diff) > 720) $diff = $diff > 0 ? $diff - 1440 : $diff + 1440;
                return $diff > 0 ? 'Late' : 'Present';
            }
            return 'Present';
        }
        if ($isHoliday)       return 'Holiday';
        if ($isEffectiveRest) return 'Rest Day';
        if ($isOnLeave)       return 'On Leave';
        if ($expectedTimeIn !== null) {
            if ($date < date('Y-m-d')) return 'Absent';
            $nowMins = (int)date('H') * 60 + (int)date('i');
            $expMins = $this->mins($expectedTimeIn);
            $expHour = (int)explode(':', $expectedTimeIn)[0];
            if ($expHour >= 18 && (int)date('H') < $expHour) return 'Pending';
            if ($expMins > 720 && $nowMins < $expMins - 720) $nowMins += 1440;
            $diff = $nowMins - $expMins;
            if ($diff < 0)    return 'Pending';
            if ($diff <= 120) return 'Late';
            return 'Absent';
        }
        return 'Absent';
    }

    public function getFilterOptions(): array
    {
        return Cache::remember('perfect_attendance:filter_options', 3600, function () {
            $base = EmployeeMasterlist::where('ACCSTATUS', 1)
                        ->whereIn('EMPPOSITION', [1, 2])
                        ->where('BIOMETRIC_STATUS', 'Enabled')
                        ->whereNotNull('PRODLINE')
                        ->where('PRODLINE', '!=', '');

            return [
                'departments' => (clone $base)->distinct()->orderBy('DEPARTMENT')->pluck('DEPARTMENT')->filter()->values(),
                'stations'    => (clone $base)->distinct()->orderBy('STATION')->pluck('STATION')->filter()->values(),
                'prodlines'   => (clone $base)->distinct()->orderBy('PRODLINE')->pluck('PRODLINE')->filter()->values(),
            ];
        });
    }
}