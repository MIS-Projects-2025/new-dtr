<?php

namespace App\Services;

use App\Domain\Dtr\DtrRow;
use App\Domain\Dtr\PunchAssigner;
use App\Domain\Dtr\StatusCalculator;
use App\Models\AttendanceLog;
use App\Models\BiometricLog;
use App\Models\BiometricLogManual;
use App\Models\EmployeeLeave;
use App\Models\EmployeeMasterlist;
use App\Models\Holiday;
use App\Models\ObRecord;
use App\Models\ShiftCode;
use App\Models\WorkScheduler;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AllEmployeesDtrService
{
    private const TIME_IN    = 0;
    private const BREAK_OUT1 = 1;
    private const BREAK_IN1  = 2;
    private const LUNCH_OUT  = 3;
    private const LUNCH_IN   = 4;
    private const BREAK_OUT2 = 5;
    private const BREAK_IN2  = 6;
    private const TIME_OUT   = 7;

    private const TYPE_MAP = [
        '0' => 'check_in',  '1' => 'check_out',
        '2' => 'break_out', '3' => 'break_in',
        '4' => 'lunch_out', '5' => 'lunch_in',
        'check_in'  => 'check_in',  'check_out' => 'check_out',
        'break_out' => 'break_out', 'break_in'  => 'break_in',
        'lunch_out' => 'lunch_out', 'lunch_in'  => 'lunch_in',
    ];

    public function __construct(
        private readonly PunchAssigner    $punchAssigner,
        private readonly StatusCalculator $statusCalculator,
    ) {}

    public function getRowsForDate(string $date): array
    {
        $dateCarbon = Carbon::parse($date)->startOf('day');
        $prevDate   = $dateCarbon->copy()->subDay()->format('Y-m-d');
        $nextDate   = $dateCarbon->copy()->addDay()->format('Y-m-d');

        // ── Employees ─────────────────────────────────────────────────────
        $employees = EmployeeMasterlist::whereIn('ACCSTATUS', [1, 2])
            ->where('BIOMETRIC_STATUS', 'enabled')
            ->orderBy('LASTNAME')->orderBy('FIRSTNAME')
            ->get(['EMPID', 'EMPLOYID', 'LASTNAME', 'FIRSTNAME', 'SHIFTTYPE']);

        $employIds = $employees->pluck('EMPLOYID')->filter()->values()->all();
        $empIds    = $employees->pluck('EMPID')->filter()->values()->all();

        // ── Shift codes ───────────────────────────────────────────────────
        $shiftCodes = ShiftCode::all()->keyBy('SHIFT_CODE_ID');

        // ── Latest schedule per employee covering this date ────────────────
        $latestSchedules = WorkScheduler::whereIn('EMPID', $empIds)
            ->where('PAYROLL_DATE_START', '<=', $date)
            ->where(fn ($q) => $q->whereNull('PAYROLL_DATE_END')
                                 ->orWhere('PAYROLL_DATE_END', '>=', $date))
            ->orderBy('EMPID')->orderByDesc('PAYROLL_DATE_START')
            ->get(['EMPID', 'PAYROLL_DATE_START', 'SCHEDULE'])
            ->groupBy('EMPID')
            ->map(fn ($rows) => $rows->first());

        // ── Bio logs (±1 day window for night shift boundaries) ────────────
        $allLogs = []; // [employid][date] = [logs]

        $addLog = function (string $employid, mixed $datetime, mixed $punchType) use (&$allLogs): void {
            if (empty($datetime) || empty($punchType)) return;
            $typeKey = strtolower(trim((string) $punchType));
            $type    = self::TYPE_MAP[$typeKey] ?? null;
            if (!$type) return;
            $dt = Carbon::parse($datetime);
            $allLogs[$employid][$dt->format('Y-m-d')][] = [
                'time'     => $dt->format('H:i'),
                'type'     => $type,
                'datetime' => (string) $datetime,
            ];
        };

        AttendanceLog::whereIn('employid', $employIds)
            ->where('logged_at', '>=', $prevDate . ' 00:00:00')
            ->where('logged_at', '<=', $nextDate . ' 23:59:59')
            ->orderBy('logged_at')
            ->get(['employid', 'logged_at', 'log_type'])
            ->each(fn ($l) => $addLog($l->employid, $l->logged_at, $l->log_type));

        BiometricLog::whereIn('employid', $employIds)
            ->where('datetime', '>=', $prevDate . ' 00:00:00')
            ->where('datetime', '<=', $nextDate . ' 23:59:59')
            ->orderBy('datetime')
            ->get(['employid', 'datetime', 'punch_type'])
            ->each(fn ($l) => $addLog($l->employid, $l->datetime, $l->punch_type));

        BiometricLogManual::whereIn('employid', $employIds)
            ->where('datetime', '>=', $prevDate . ' 00:00:00')
            ->where('datetime', '<=', $nextDate . ' 23:59:59')
            ->orderBy('datetime')
            ->get(['employid', 'datetime', 'punch_type'])
            ->each(fn ($l) => $addLog($l->employid, $l->datetime, $l->punch_type));

        // ── Leaves covering this date ──────────────────────────────────────
        $leaveByEmployId = EmployeeLeave::whereIn('EMPLOYID', $employIds)
            ->where('LEAVESTATUS', 'approved')
            ->whereDate('DATESTART', '<=', $date)
            ->whereDate('DATEEND',   '>=', $date)
            ->get(['EMPLOYID', 'TYPEOFLEAVE', 'LEAVE_DURATION', 'PERIOD'])
            ->keyBy('EMPLOYID');

        // ── OB records covering this date ──────────────────────────────────
        $obByEmpId = ObRecord::whereIn('EMPID', $empIds)
            ->whereIn('STATUS', ['1', '2'])
            ->whereDate('DATE_OB_FROM', '<=', $date)
            ->whereDate('DATE_OB_TO',   '>=', $date)
            ->get(['EMPID', 'FORM_TYPE', 'TIME_FROM', 'TIME_TO'])
            ->keyBy('EMPID');

        // ── Holiday ────────────────────────────────────────────────────────
        $holiday    = Holiday::whereDate('HOLIDAY_DATE', $date)->first(['HOLIDAY_NAME', 'HOLIDAY_TYPE']);
        $holidayArr = $holiday
            ? ['name' => $holiday->HOLIDAY_NAME, 'type' => $holiday->HOLIDAY_TYPE]
            : null;

        // ── Build one row per employee ─────────────────────────────────────
        $rows = [];

        foreach ($employees as $emp) {
            $empShiftType = (int) ($emp->SHIFTTYPE ?? 1);
            $empLogs      = $allLogs[$emp->EMPLOYID] ?? [];

            [$shiftName, $shiftTimes, $shiftType, $isNightShift] = $this->resolveShift(
                $latestSchedules->get($emp->EMPID),
                $shiftCodes,
                $dateCarbon,
                $empLogs,
                $date
            );

            $logsForDate = $this->getLogsForDate($date, $isNightShift, $empLogs, $prevDate, $nextDate);
            $logsForDate = $this->deduplicateLogs($logsForDate, $isNightShift);

            $hasIn  = collect($logsForDate)->contains('type', 'check_in');
            $hasOut = collect($logsForDate)->contains('type', 'check_out');
            if (!$hasIn && !$hasOut && !empty($logsForDate)) {
                $logsForDate = [];
            }

            $assigned      = $this->punchAssigner->assign($shiftTimes, $logsForDate, $isNightShift, $empShiftType);
            $isUnscheduled = ($shiftType === 'Unscheduled');

            $expSlots = $this->buildExpSlots($shiftTimes);
            $act      = $this->buildActSlots($assigned, $isUnscheduled);

            // Leave info
            $leaveRaw  = $leaveByEmployId[$emp->EMPLOYID] ?? null;
            $leaveInfo = $leaveRaw ? [
                'type'      => $this->expandLeaveTypeCode($leaveRaw->TYPEOFLEAVE),
                'type_code' => $leaveRaw->TYPEOFLEAVE,
                'duration'  => $leaveRaw->LEAVE_DURATION,
                'period'    => $leaveRaw->PERIOD ?? 'N/A',
            ] : null;

            // OB info
            $obRaw      = $obByEmpId[$emp->EMPID] ?? null;
            $obType     = match (strtolower((string) ($obRaw?->FORM_TYPE ?? ''))) {
                'ob'    => 'Official Business',
                'pb'    => 'Personal Business',
                default => null,
            };
            $obInfo = ($obRaw && $obType) ? [
                'type'      => $obType,
                'time_from' => $obRaw->TIME_FROM,
                'time_to'   => $obRaw->TIME_TO,
                'form_type' => $obRaw->FORM_TYPE,
            ] : null;

            $remarks = $this->statusCalculator->calculate(
                $expSlots, $act, $shiftType,
                $leaveInfo, $holidayArr, $obInfo,
                $date, $isNightShift, $shiftTimes, $empShiftType
            );

            $rows[] = array_merge(
                (new DtrRow(
                    date:         $date,
                    day:          $dateCarbon->format('D'),
                    code:         $shiftName ?? 'N/A',
                    shiftType:    $shiftType,
                    isNight:      $isNightShift,
                    timeIn:       $act[self::TIME_IN],
                    breakOut1:    $act[self::BREAK_OUT1],
                    breakIn1:     $act[self::BREAK_IN1],
                    lunchOut:     $act[self::LUNCH_OUT],
                    lunchIn:      $act[self::LUNCH_IN],
                    breakOut2:    $act[self::BREAK_OUT2],
                    breakIn2:     $act[self::BREAK_IN2],
                    timeOut:      $act[self::TIME_OUT],
                    expTimeIn:    $expSlots[self::TIME_IN],
                    expBreakOut1: $expSlots[self::BREAK_OUT1],
                    expBreakIn1:  $expSlots[self::BREAK_IN1],
                    expLunchOut:  $expSlots[self::LUNCH_OUT],
                    expLunchIn:   $expSlots[self::LUNCH_IN],
                    expBreakOut2: $expSlots[self::BREAK_OUT2],
                    expBreakIn2:  $expSlots[self::BREAK_IN2],
                    expTimeOut:   $expSlots[self::TIME_OUT],
                    remarks:      $remarks,
                    leaveInfo:    $leaveInfo,
                    holidayInfo:  $holidayArr,
                    obInfo:       $obInfo,
                    obCovered:    $this->statusCalculator->getObCoveredWindows($shiftTimes, $obInfo),
                    isFullOb:     $this->statusCalculator->isFullShiftOb($shiftTimes, $obInfo),
                ))->toArray(),
                ['emp_name' => "{$emp->LASTNAME}, {$emp->FIRSTNAME}", 'employid' => $emp->EMPLOYID]
            );
        }

        return $rows;
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function resolveShift(
        ?object $schedRow,
        $shiftCodes,
        Carbon  $dateCarbon,
        array   $empLogs,
        string  $date
    ): array {
        if (!$schedRow) return [null, [], 'Unscheduled', false];

        $schedArr = is_array($schedRow->SCHEDULE)
            ? $schedRow->SCHEDULE
            : json_decode($schedRow->SCHEDULE, true);

        if (!$schedArr) return [null, [], 'Unscheduled', false];

        $dayIndex = Carbon::parse($schedRow->PAYROLL_DATE_START)->startOf('day')->diffInDays($dateCarbon);
        $shift    = $shiftCodes->get($schedArr[$dayIndex] ?? null);

        if (!$shift) return [null, [], 'Unscheduled', false];

        $shiftName  = $shift->SHIFTCODE;
        $shiftTimes = is_array($shift->TIME_WINDOWS)
            ? $shift->TIME_WINDOWS
            : (json_decode($shift->TIME_WINDOWS, true) ?? []);

        $ciTime = !empty($shiftTimes[0]) ? strtotime($shiftTimes[0]) : 0;
        $coTime = !empty($shiftTimes[7]) ? strtotime($shiftTimes[7]) : 0;

        $detectNight = function () use ($empLogs, $date): bool {
            foreach ($empLogs[$date] ?? [] as $log) {
                if ($log['type'] === 'check_in') {
                    return ((int) date('H', strtotime($log['time'])) >= 12);
                }
            }
            return false;
        };

        if (str_contains($shiftName, 'RD')) {
            $isNight = ($ciTime > 0 && $coTime > 0 && $ciTime !== $coTime)
                ? ($ciTime > $coTime) : $detectNight();
            return [$shiftName, $shiftTimes, 'Rest Day', $isNight];
        }

        if (str_contains($shiftName, 'BL')) {
            return [$shiftName, $shiftTimes, 'Birthday Leave', false];
        }

        $isNight = ($ciTime > 0 && $coTime > 0 && $ciTime !== $coTime)
            ? ($ciTime > $coTime) : $detectNight();

        return [$shiftName, $shiftTimes, $isNight ? 'Night' : 'Day', $isNight];
    }

    private function getLogsForDate(
        string $date,
        bool   $isNightShift,
        array  $empLogs,
        string $prevDate,
        string $nextDate
    ): array {
        if ($isNightShift) {
            $pmLogs = array_values(array_filter(
                $empLogs[$date] ?? [],
                fn ($l) => (int) date('H', strtotime($l['time'])) >= 12
            ));

            if (!empty($pmLogs)) {
                $amNext = array_values(array_filter(
                    $empLogs[$nextDate] ?? [],
                    fn ($l) => (int) date('H', strtotime($l['time'])) <= 7
                ));
                return array_merge($pmLogs, $amNext);
            }

            return $pmLogs;
        }

        // Detect if previous day had night shift PM logs
        $prevPmLogs  = array_filter(
            $empLogs[$prevDate] ?? [],
            fn ($l) => (int) date('H', strtotime($l['time'])) >= 12
        );
        $prevIsNight = !empty($prevPmLogs);

        $dayLogs = [];
        foreach ($empLogs[$date] ?? [] as $log) {
            $hour = (int) date('H', strtotime($log['time']));
            if ($prevIsNight && $hour <= 7) continue;
            $dayLogs[] = $log;
        }

        return $dayLogs;
    }

    private function deduplicateLogs(array $logs, bool $isNightShift = false): array
    {
        if (empty($logs)) return $logs;

        $toMin = function ($time) use ($isNightShift): int {
            if (empty($time)) return 0;
            [$h, $m] = explode(':', $time);
            $total   = ((int) $h * 60) + (int) $m;
            return ($isNightShift && (int) $h < 12) ? $total + 1440 : $total;
        };

        $outTypes = ['break_out', 'lunch_out', 'check_out'];
        $inTypes  = ['break_in',  'lunch_in',  'check_in'];

        $groups = [];
        foreach ($logs as $log) {
            $groups[$log['type']][] = $log;
        }

        $deduped = [];

        foreach ($groups as $type => $typeLogs) {
            usort($typeLogs, fn ($a, $b) => $toMin($a['time']) <=> $toMin($b['time']));

            $keepLatest   = in_array($type, $outTypes);
            $keepEarliest = in_array($type, $inTypes);

            if (!$keepLatest && !$keepEarliest) {
                foreach ($typeLogs as $l) $deduped[] = $l;
                continue;
            }

            $merged = [];
            $window = [$typeLogs[0]];

            for ($i = 1; $i < count($typeLogs); $i++) {
                $diff = $toMin($typeLogs[$i]['time']) - $toMin(end($window)['time']);
                if ($diff <= 5) {
                    $window[] = $typeLogs[$i];
                } else {
                    $merged[] = $keepLatest ? end($window) : $window[0];
                    $window   = [$typeLogs[$i]];
                }
            }
            $merged[] = $keepLatest ? end($window) : $window[0];

            foreach ($merged as $l) $deduped[] = $l;
        }

        return $deduped;
    }

    private function buildExpSlots(array $shiftTimes): array
    {
        return [
            self::TIME_IN    => $shiftTimes[0] ?? '',
            self::BREAK_OUT1 => $shiftTimes[1] ?? '',
            self::BREAK_IN1  => $shiftTimes[2] ?? '',
            self::LUNCH_OUT  => $shiftTimes[3] ?? '',
            self::LUNCH_IN   => $shiftTimes[4] ?? '',
            self::BREAK_OUT2 => $shiftTimes[5] ?? '',
            self::BREAK_IN2  => $shiftTimes[6] ?? '',
            self::TIME_OUT   => $shiftTimes[7] ?? '',
        ];
    }

    private function buildActSlots(array $assigned, bool $isUnscheduled): array
    {
        return [
            self::TIME_IN    => $assigned[self::TIME_IN],
            self::BREAK_OUT1 => $isUnscheduled ? '' : $assigned[self::BREAK_OUT1],
            self::BREAK_IN1  => $isUnscheduled ? '' : $assigned[self::BREAK_IN1],
            self::LUNCH_OUT  => $isUnscheduled ? '' : $assigned[self::LUNCH_OUT],
            self::LUNCH_IN   => $isUnscheduled ? '' : $assigned[self::LUNCH_IN],
            self::BREAK_OUT2 => $isUnscheduled ? '' : $assigned[self::BREAK_OUT2],
            self::BREAK_IN2  => $isUnscheduled ? '' : $assigned[self::BREAK_IN2],
            self::TIME_OUT   => $assigned[self::TIME_OUT],
        ];
    }

    private function expandLeaveTypeCode(string $code): string
    {
        return [
            'SL'  => 'Sick Leave',   'VL'  => 'Vacation Leave',
            'BL'  => 'Birthday Leave', 'BrL' => 'Bereavement Leave',
            'EL'  => 'Emergency Leave', 'PL' => 'Paternity Leave',
            'SPL' => 'Solo Parent Leave', 'MiL' => 'Military Leave',
        ][$code] ?? $code;
    }
}