<?php

namespace App\Services;

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
use Illuminate\Support\Collection;


class DailyTimeRecordService
{
    // ── Slot index constants ──────────────────────────────────────────────────
    const TIME_IN    = 0;
    const BREAK_OUT1 = 1;
    const BREAK_IN1  = 2;
    const LUNCH_OUT  = 3;
    const LUNCH_IN   = 4;
    const BREAK_OUT2 = 5;
    const BREAK_IN2  = 6;
    const TIME_OUT   = 7;

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC ENTRY POINT
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build the full DTR rows for a given employee + month (Y-m).
     *
     * @param  string $employId   e.g. "00123"
     * @param  string $month      e.g. "2025-04"
     * @return array
     */
    public function getTableData(string $employId, string $month): array
    {
        $empShiftType = $this->fetchShiftType($employId);
        $leaveDates   = $this->fetchLeaveDates($employId);
        $obRecords    = $this->fetchObRecords($employId);
        $bioLogs      = $this->fetchBioLogs($employId, $month);
        $holidays     = $this->fetchHolidays();
        $mapped       = $this->buildScheduleMap($employId, $month, $bioLogs);

        // Fill in days that have bio logs but no schedule
        $this->mergeUnscheduledBioLogDates($mapped, $bioLogs, $month);

        // Fill remaining calendar days with no schedule & no logs
        $this->fillRemainingDays($mapped, $month);

        krsort($mapped);

        $rows = [];

        foreach ($mapped as $date => $info) {
            $times        = $info['time'] ?? [];
            $isNightShift = $info['isNightShift'] ?? false;

            // Expected times from shift schedule (8-slot)
            $expSlots = [
                self::TIME_IN    => $times[0] ?? '',
                self::BREAK_OUT1 => $times[1] ?? '',
                self::BREAK_IN1  => $times[2] ?? '',
                self::LUNCH_OUT  => $times[3] ?? '',
                self::LUNCH_IN   => $times[4] ?? '',
                self::BREAK_OUT2 => $times[5] ?? '',
                self::BREAK_IN2  => $times[6] ?? '',
                self::TIME_OUT   => $times[7] ?? '',
            ];

            $logsForDate = $this->getLogsForDate($date, $isNightShift, $bioLogs, $mapped);
            $logsForDate = $this->deduplicateLogs($logsForDate, $isNightShift);

            // Suppress orphan break/lunch punches with no check-in or check-out
            $hasIn  = collect($logsForDate)->contains('type', 'check_in');
            $hasOut = collect($logsForDate)->contains('type', 'check_out');
            if (!$hasIn && !$hasOut && !empty($logsForDate)) {
                $logsForDate = [];
            }

            $assigned = $this->assignPunches($times, $logsForDate, $isNightShift, $empShiftType);

            $isUnscheduled = ($info['type'] === 'Unscheduled');

            $act = [
                self::TIME_IN    => $assigned[self::TIME_IN],
                self::BREAK_OUT1 => $isUnscheduled ? '' : $assigned[self::BREAK_OUT1],
                self::BREAK_IN1  => $isUnscheduled ? '' : $assigned[self::BREAK_IN1],
                self::LUNCH_OUT  => $isUnscheduled ? '' : $assigned[self::LUNCH_OUT],
                self::LUNCH_IN   => $isUnscheduled ? '' : $assigned[self::LUNCH_IN],
                self::BREAK_OUT2 => $isUnscheduled ? '' : $assigned[self::BREAK_OUT2],
                self::BREAK_IN2  => $isUnscheduled ? '' : $assigned[self::BREAK_IN2],
                self::TIME_OUT   => $assigned[self::TIME_OUT],
            ];

            $leaveInfo   = $leaveDates[$date]  ?? null;
            $holidayInfo = $holidays[$date]     ?? null;
            $obInfo      = $obRecords[$date]    ?? null;
            $obCovered   = $this->getObCoveredWindows($times, $obInfo);
            $isFullOB    = $this->isFullShiftOB($times, $obInfo);

            $status = $this->calculateStatus(
                $expSlots, $act, $info['type'],
                $leaveInfo, $holidayInfo, $obInfo,
                $date, $isNightShift, $times, $empShiftType
            );

            $rows[] = [
                'date'         => $date,
                'day'          => $info['day'],
                'code'         => $info['shift'],
                'shift_type'   => $info['type'],
                'is_night'     => $isNightShift,
                // Actual punches
                'time_in'      => $act[self::TIME_IN],
                'break_out_1'  => $act[self::BREAK_OUT1],
                'break_in_1'   => $act[self::BREAK_IN1],
                'lunch_out'    => $act[self::LUNCH_OUT],
                'lunch_in'     => $act[self::LUNCH_IN],
                'break_out_2'  => $act[self::BREAK_OUT2],
                'break_in_2'   => $act[self::BREAK_IN2],
                'time_out'     => $act[self::TIME_OUT],
                // Expected times (for "exp:" labels in the UI)
                'exp_time_in'      => $expSlots[self::TIME_IN],
                'exp_break_out_1'  => $expSlots[self::BREAK_OUT1],
                'exp_break_in_1'   => $expSlots[self::BREAK_IN1],
                'exp_lunch_out'    => $expSlots[self::LUNCH_OUT],
                'exp_lunch_in'     => $expSlots[self::LUNCH_IN],
                'exp_break_out_2'  => $expSlots[self::BREAK_OUT2],
                'exp_break_in_2'   => $expSlots[self::BREAK_IN2],
                'exp_time_out'     => $expSlots[self::TIME_OUT],
                // Remarks / status
                'remarks'      => $status,
                'leave_info'   => $leaveInfo,
                'holiday_info' => $holidayInfo,
                'ob_info'      => $obInfo,
                'ob_covered'   => $obCovered,
                'is_full_ob'   => $isFullOB,
            ];
        }

        return $rows;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DATA FETCHERS
    // ─────────────────────────────────────────────────────────────────────────

    private function fetchShiftType(string $employId): int
    {
        $emp = EmployeeMasterlist::where('EMPLOYID', $employId)->first();
        return (int) ($emp->SHIFTTYPE ?? 1);
    }

    private function fetchLeaveDates(string $employId): array
    {
        $leaveDates = [];

        EmployeeLeave::where('EMPLOYID', $employId)
            ->get()
            ->each(function ($leave) use (&$leaveDates) {
                if (strtolower($leave->LEAVESTATUS) !== 'approved') return;

                $start   = Carbon::parse($leave->DATESTART);
                $end     = Carbon::parse($leave->DATEEND);
                $current = $start->copy();

                while ($current->lte($end)) {
                    $key              = $current->format('Y-m-d');
                    $isHalf           = in_array(strtolower($leave->LEAVE_DURATION), ['half-day', 'half day']);
                    $leaveDates[$key] = [
                        'type'      => $this->fullLeaveType($leave->TYPEOFLEAVE),
                        'type_code' => $leave->TYPEOFLEAVE,
                        'duration'  => $leave->LEAVE_DURATION,
                        'period'    => $isHalf ? $leave->PERIOD : 'N/A',
                    ];
                    $current->addDay();
                }
            });

        return $leaveDates;
    }

    private function fetchObRecords(string $employId): array
    {
        $obRecords = [];

        ObRecord::where('EMPID', $employId)
            ->whereIn('STATUS', ['1', '2'])
            ->get()
            ->each(function ($ob) use (&$obRecords) {
                $type = match (strtolower($ob->FORM_TYPE)) {
                    'ob'    => 'Official Business',
                    'pb'    => 'Personal Business',
                    default => '',
                };
                if (!$type) return;

                $start   = Carbon::parse($ob->DATE_OB_FROM);
                $end     = Carbon::parse($ob->DATE_OB_TO);
                $current = $start->copy();

                while ($current->lte($end)) {
                    $obRecords[$current->format('Y-m-d')] = [
                        'type'      => $type,
                        'time_from' => $ob->TIME_FROM,
                        'time_to'   => $ob->TIME_TO,
                        'form_type' => $ob->FORM_TYPE,
                    ];
                    $current->addDay();
                }
            });

        return $obRecords;
    }

    /**
     * Fetch all bio logs (auto + manual) for the month (±1 day for night-shift boundary).
     * Returns [ 'Y-m-d' => [ ['time'=>'HH:MM','type'=>'check_in',...], ... ], ... ]
     */
    private function fetchBioLogs(string $employId, string $month): array
{
    $monthStart = Carbon::parse($month . '-01');
    $monthEnd   = Carbon::parse($month . '-01')->endOfMonth();
    $fetchFrom  = $monthStart->copy()->subDay()->format('Y-m-d');
    $fetchTo    = $monthEnd->copy()->addDay()->format('Y-m-d');

    // String punch types as stored in BiometricLog/BiometricLogManual
    // Numeric codes kept for AttendanceLog legacy compatibility
    $typeMap = [
        // Numeric codes (legacy / AttendanceLog)
        '0' => 'check_in',
        '1' => 'check_out',
        '2' => 'break_out',
        '3' => 'break_in',
        '4' => 'lunch_out',
        '5' => 'lunch_in',
        // String codes (BiometricLog / BiometricLogManual)
        'check_in'  => 'check_in',
        'check_out' => 'check_out',
        'break_out' => 'break_out',
        'break_in'  => 'break_in',
        'lunch_out' => 'lunch_out',
        'lunch_in'  => 'lunch_in',
    ];

    $bioLogs = [];

    $addLog = function ($datetime, $punchType, $source) use (&$bioLogs, $typeMap) {
        if (empty($datetime) || empty($punchType)) return;

        $dt      = Carbon::parse($datetime);
        $date    = $dt->format('Y-m-d');
        $time    = $dt->format('H:i');
        $typeKey = strtolower(trim((string) $punchType));
        $type    = $typeMap[$typeKey] ?? null;

        if (!$type) return; // skip unknown punch types

        $bioLogs[$date][] = [
            'time'     => $time,
            'type'     => $type,
            'datetime' => $datetime,
            'source'   => $source,
        ];
    };

    // ── Source 1: AttendanceLog ───────────────────────────────────────────────
    // Uses log_type (may be numeric string or named string)
    AttendanceLog::where('employid', $employId)
        ->whereBetween(\DB::raw('DATE(logged_at)'), [$fetchFrom, $fetchTo])
        ->orderBy('logged_at')
        ->each(fn($r) => $addLog($r->logged_at, $r->log_type, 'attendance'));

    // ── Source 2: BiometricLog (MAIN source — was missing!) ──────────────────
    // This is what EmployeeService uses and it works. Same model, same columns.
    BiometricLog::where('employid', $employId)
        ->whereBetween(\DB::raw('DATE(datetime)'), [$fetchFrom, $fetchTo])
        ->orderBy('datetime')
        ->each(fn($r) => $addLog($r->datetime, $r->punch_type, 'biometric'));

    // ── Source 3: BiometricLogManual ─────────────────────────────────────────
    BiometricLogManual::where('employid', $employId)
        ->whereBetween(\DB::raw('DATE(datetime)'), [$fetchFrom, $fetchTo])
        ->orderBy('datetime')
        ->each(fn($r) => $addLog($r->datetime, $r->punch_type, 'manual'));

    return $bioLogs;
}

    private function fetchHolidays(): array
    {
        $holidays = [];
        Holiday::all()->each(function ($h) use (&$holidays) {
            $holidays[Carbon::parse($h->HOLIDAY_DATE)->format('Y-m-d')] = [
                'name' => $h->HOLIDAY_NAME,
                'type' => $h->HOLIDAY_TYPE,
            ];
        });
        return $holidays;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SCHEDULE MAP
    // ─────────────────────────────────────────────────────────────────────────

    private function buildScheduleMap(string $employId, string $month, array $bioLogs): array
    {
        $mapped     = [];
        $today      = Carbon::today();
        $shiftCodes = ShiftCode::all()->keyBy('SHIFT_CODE_ID');

        WorkScheduler::where('EMPID', $employId)
            ->orderBy('PAYROLL_DATE_START')
            ->get()
            ->each(function ($sched) use (&$mapped, $month, $today, $shiftCodes, $bioLogs) {
                $schedArr = is_array($sched->SCHEDULE)
                    ? $sched->SCHEDULE
                    : json_decode($sched->SCHEDULE, true);

                if (!$schedArr || !$sched->PAYROLL_DATE_START) return;

                $start = Carbon::parse($sched->PAYROLL_DATE_START);
                $end   = $sched->PAYROLL_DATE_END
                    ? Carbon::parse($sched->PAYROLL_DATE_END)
                    : $start->copy()->addDays(count($schedArr) - 1);

                foreach ($schedArr as $day => $shiftId) {
                    $d = $start->copy()->addDays($day - 1);
                    if ($d->format('Y-m') !== $month) continue;
                    if ($d->gt($end) || $d->gt($today)) continue;

                    $shift = $shiftCodes->get($shiftId);
                    if (!$shift) continue;

                    $shiftName  = $shift->SHIFTCODE;
                    $shiftTimes = is_array($shift->TIME_WINDOWS)
                        ? $shift->TIME_WINDOWS
                        : json_decode($shift->TIME_WINDOWS, true);

                    [$shiftType, $isNightShift] = $this->resolveShiftTypeMeta(
                        $shiftName, $shiftTimes, $d->format('Y-m-d'), $bioLogs
                    );

                    $mapped[$d->format('Y-m-d')] = [
                        'day'          => $d->format('D'),
                        'shift'        => $shiftName,
                        'time'         => $shiftTimes,
                        'type'         => $shiftType,
                        'isNightShift' => $isNightShift,
                    ];
                }
            });

        krsort($mapped);
        return $mapped;
    }

    private function resolveShiftTypeMeta(
        string $shiftName,
        array  $shiftTimes,
        string $date,
        array  $bioLogs
    ): array {
        $ciTime = !empty($shiftTimes[0]) ? strtotime($shiftTimes[0]) : 0;
        $coTime = !empty($shiftTimes[7]) ? strtotime($shiftTimes[7]) : 0;

        $detectNight = function () use ($date, $bioLogs): bool {
            foreach ($bioLogs[$date] ?? [] as $bl) {
                if ($bl['type'] === 'check_in') {
                    return ((int) date('H', strtotime($bl['time'])) >= 12);
                }
            }
            return false;
        };

        if (str_contains($shiftName, 'RD')) {
            $isNight = ($ciTime > 0 && $coTime > 0 && $ciTime !== $coTime)
                ? ($ciTime > $coTime)
                : $detectNight();
            return ['Rest Day', $isNight];
        }

        if (str_contains($shiftName, 'BL')) {
            return ['Birthday Leave', false];
        }

        $isNight = ($ciTime > 0 && $coTime > 0 && $ciTime !== $coTime)
            ? ($ciTime > $coTime)
            : $detectNight();

        return [$isNight ? 'Night' : 'Day', $isNight];
    }

    private function mergeUnscheduledBioLogDates(array &$mapped, array $bioLogs, string $month): void
    {
        $today = Carbon::today();

        foreach ($bioLogs as $bioDate => $logs) {
            if (substr($bioDate, 0, 7) !== $month)  continue;
            if (isset($mapped[$bioDate]))             continue;
            if (Carbon::parse($bioDate)->gt($today)) continue;

            $prevDate    = Carbon::parse($bioDate)->subDay()->format('Y-m-d');
            $prevEntry   = $mapped[$prevDate] ?? null;
            $prevIsNight = $prevEntry && ($prevEntry['isNightShift'] ?? false);

            $nonEarlyLogs = array_filter(
                $logs,
                fn($l) => (int) date('H', strtotime($l['time'])) > 7
            );

            if ($prevIsNight && empty($nonEarlyLogs)) {
                $mapped[$bioDate] = $this->unscheduledEntry($bioDate, false);
                continue;
            }

            $isNight = false;
            foreach ($logs as $l) {
                if ($l['type'] === 'check_in') {
                    $h       = (int) date('H', strtotime($l['time']));
                    $isNight = ($h >= 12 && !($prevIsNight && $h <= 7));
                    break;
                }
            }

            $mapped[$bioDate] = $this->unscheduledEntry($bioDate, $isNight);
        }
    }

    private function fillRemainingDays(array &$mapped, string $month): void
    {
        $today = Carbon::today();
        $cur   = Carbon::parse($month . '-01');
        $end   = $cur->copy()->endOfMonth();

        while ($cur->lte($end) && $cur->lte($today)) {
            $key = $cur->format('Y-m-d');
            if (!isset($mapped[$key])) {
                $mapped[$key] = $this->unscheduledEntry($key, false);
            }
            $cur->addDay();
        }
    }

    private function unscheduledEntry(string $date, bool $isNight): array
    {
        return [
            'day'          => Carbon::parse($date)->format('D'),
            'shift'        => 'N/A',
            'time'         => [],
            'type'         => 'Unscheduled',
            'isNightShift' => $isNight,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LOG HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function getLogsForDate(
        string $date,
        bool   $isNightShift,
        array  $bioLogs,
        array  $mapped
    ): array {
        if ($isNightShift) {
            $nextDate = Carbon::parse($date)->addDay()->format('Y-m-d');
            $pmLogs   = array_filter(
                $bioLogs[$date] ?? [],
                fn($l) => (int) date('H', strtotime($l['time'])) >= 12
            );
            $pmLogs = array_values($pmLogs);

            if (!empty($pmLogs)) {
                $amNext = array_values(array_filter(
                    $bioLogs[$nextDate] ?? [],
                    fn($l) => (int) date('H', strtotime($l['time'])) <= 7
                ));
                return array_merge($pmLogs, $amNext);
            }
            return $pmLogs;
        }

        // Day shift
        $prevDate    = Carbon::parse($date)->subDay()->format('Y-m-d');
        $prevEntry   = $mapped[$prevDate] ?? null;
        $prevIsNight = $prevEntry && ($prevEntry['isNightShift'] ?? false);

        if ($prevIsNight) {
            $prevPmLogs = array_filter(
                $bioLogs[$prevDate] ?? [],
                fn($l) => (int) date('H', strtotime($l['time'])) >= 12
            );
            $prevIsNight = !empty($prevPmLogs);
        }

        $dayLogs = [];
        foreach ($bioLogs[$date] ?? [] as $log) {
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

        $groups  = [];
        foreach ($logs as $log) {
            $groups[$log['type']][] = $log;
        }

        $deduped = [];

        foreach ($groups as $type => $typeLogs) {
            usort($typeLogs, fn($a, $b) => $toMin($a['time']) <=> $toMin($b['time']));

            $keepLatest   = in_array($type, $outTypes);
            $keepEarliest = in_array($type, $inTypes);

            if (!$keepLatest && !$keepEarliest) {
                foreach ($typeLogs as $l) $deduped[] = $l;
                continue;
            }

            $merged = [];
            $window = [$typeLogs[0]];

            for ($i = 1; $i < count($typeLogs); $i++) {
                $prev = end($window);
                $diff = $toMin($typeLogs[$i]['time']) - $toMin($prev['time']);
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

    // ─────────────────────────────────────────────────────────────────────────
    // PUNCH ASSIGNMENT
    // ─────────────────────────────────────────────────────────────────────────

    private function assignPunches(
        array $shiftTimes,
        array $logs,
        bool  $isNightShift = false,
        int   $shiftType    = 1
    ): array {
        $assigned   = array_fill(0, 8, '');
        if (empty($logs)) return $assigned;

        $tmFn = fn($t) => $this->timeToMinutes($t, $isNightShift);

        $sortByTime = function (array &$arr) use ($tmFn): void {
            usort($arr, fn($a, $b) => $tmFn($a['time']) <=> $tmFn($b['time']));
        };

        $checkInLogs = $checkOutLogs = $breakOutLogs = [];
        $breakInLogs = $lunchOutLogs = $lunchInLogs  = [];

        foreach ($logs as $log) {
            match ($log['type']) {
                'check_in'  => $checkInLogs[]  = $log,
                'check_out' => $checkOutLogs[] = $log,
                'break_out' => $breakOutLogs[] = $log,
                'break_in'  => $breakInLogs[]  = $log,
                'lunch_out' => $lunchOutLogs[] = $log,
                'lunch_in'  => $lunchInLogs[]  = $log,
                default     => null,
            };
        }

        // Deduplicate check-ins: keep only the earliest, discard the rest entirely.
        // Duplicate check-ins must NOT be promoted to break slots.
        if (count($checkInLogs) > 1) {
            usort($checkInLogs, fn($a, $b) => $tmFn($a['time']) <=> $tmFn($b['time']));
            $checkInLogs = [array_shift($checkInLogs)]; // keep earliest only
        }

        // Promote middle check punches to break pairs (check-outs only now)
        if (count($checkInLogs) + count($checkOutLogs) > 2) {
            $all = array_merge($checkInLogs, $checkOutLogs);
            usort($all, fn($a, $b) => $tmFn($a['time']) <=> $tmFn($b['time']));
            $checkInLogs  = [array_shift($all)];
            $checkOutLogs = [array_pop($all)];

            foreach ($all as $punch) {
                $pMin         = $tmFn($punch['time']);
                $unmatchedOut = 0;
                foreach ($breakOutLogs as $bo) {
                    if ($tmFn($bo['time']) < $pMin) $unmatchedOut++;
                }
                foreach ($breakInLogs as $bi) {
                    if ($tmFn($bi['time']) < $pMin) $unmatchedOut--;
                }
                if ($unmatchedOut > 0) {
                    $breakInLogs[] = $punch;
                } else {
                    $breakOutLogs[] = $punch;
                }
            }
        }

        $sortByTime($checkInLogs);
        $sortByTime($checkOutLogs);
        $sortByTime($breakOutLogs);
        $sortByTime($breakInLogs);
        $sortByTime($lunchOutLogs);
        $sortByTime($lunchInLogs);

        if (!empty($checkInLogs))  $assigned[self::TIME_IN]  = $checkInLogs[0]['time'];
        if (!empty($checkOutLogs)) $assigned[self::TIME_OUT] = end($checkOutLogs)['time'];

        $skipBreak1 = ($shiftType === 2);

        if (!empty($shiftTimes)) {
            $this->assignWithSchedule(
                $assigned, $shiftTimes,
                $breakOutLogs, $breakInLogs,
                $lunchOutLogs, $lunchInLogs,
                $skipBreak1, $tmFn
            );
        } else {
            $this->assignHeuristic(
                $assigned,
                $breakOutLogs, $breakInLogs,
                $lunchOutLogs, $lunchInLogs,
                $skipBreak1, $tmFn, $isNightShift
            );
        }

        $this->repairOrphans($assigned);

        return $assigned;
    }

    private function assignWithSchedule(
        array &$assigned,
        array  $shiftTimes,
        array  $breakOutLogs,
        array  $breakInLogs,
        array  $lunchOutLogs,
        array  $lunchInLogs,
        bool   $skipBreak1,
        callable $tmFn
    ): void {
        if (!empty($lunchOutLogs) && !empty($shiftTimes[self::LUNCH_OUT]))
            $assigned[self::LUNCH_OUT] = $lunchOutLogs[0]['time'];
        if (!empty($lunchInLogs) && !empty($shiftTimes[self::LUNCH_IN]))
            $assigned[self::LUNCH_IN] = $lunchInLogs[0]['time'];

        $outSlots = [];
        if (!$skipBreak1 && !empty($shiftTimes[self::BREAK_OUT1]))
            $outSlots[] = ['slot' => self::BREAK_OUT1, 'exp' => $tmFn($shiftTimes[self::BREAK_OUT1])];
        if (!empty($shiftTimes[self::LUNCH_OUT]))
            $outSlots[] = ['slot' => self::LUNCH_OUT,  'exp' => $tmFn($shiftTimes[self::LUNCH_OUT])];
        if (!empty($shiftTimes[self::BREAK_OUT2]))
            $outSlots[] = ['slot' => self::BREAK_OUT2, 'exp' => $tmFn($shiftTimes[self::BREAK_OUT2])];

        $inSlots = [];
        if (!$skipBreak1 && !empty($shiftTimes[self::BREAK_IN1]))
            $inSlots[] = ['slot' => self::BREAK_IN1, 'exp' => $tmFn($shiftTimes[self::BREAK_IN1])];
        if (!empty($shiftTimes[self::LUNCH_IN]))
            $inSlots[] = ['slot' => self::LUNCH_IN,  'exp' => $tmFn($shiftTimes[self::LUNCH_IN])];
        if (!empty($shiftTimes[self::BREAK_IN2]))
            $inSlots[] = ['slot' => self::BREAK_IN2, 'exp' => $tmFn($shiftTimes[self::BREAK_IN2])];

        $assignSeq = function (array $logs, array $slots) use (&$assigned, $tmFn): void {
            $si    = 0;
            $total = count($slots);
            foreach ($logs as $log) {
                $lMin = $tmFn($log['time']);
                while ($si < $total && !empty($assigned[$slots[$si]['slot']])) $si++;
                if ($si >= $total) break;

                $best = null;
                for ($s = $si; $s < $total; $s++) {
                    if (!empty($assigned[$slots[$s]['slot']])) continue;
                    if ($lMin >= $slots[$s]['exp']) $best = $s;
                    else break;
                }

                if ($best !== null) {
                    $assigned[$slots[$best]['slot']] = $log['time'];
                    $si = $best + 1;
                } else {
                    for ($s = $si; $s < $total; $s++) {
                        if (empty($assigned[$slots[$s]['slot']])) {
                            $assigned[$slots[$s]['slot']] = $log['time'];
                            $si = $s + 1;
                            break;
                        }
                    }
                }
            }
        };

        $assignSeq($breakOutLogs, $outSlots);
        $assignSeq($breakInLogs,  $inSlots);

        // Fallback to heuristic if PATH A assigned nothing for breaks
        $pathAAssigned = false;
        foreach ([self::BREAK_OUT1, self::BREAK_IN1, self::LUNCH_OUT,
                  self::LUNCH_IN, self::BREAK_OUT2, self::BREAK_IN2] as $s) {
            if (!empty($assigned[$s])) { $pathAAssigned = true; break; }
        }

        if (!$pathAAssigned && (!empty($breakOutLogs) || !empty($breakInLogs))) {
            $this->assignHeuristic(
                $assigned,
                $breakOutLogs, $breakInLogs,
                [], [], // lunch already assigned above
                $skipBreak1,
                $tmFn,
                false
            );
        }
    }

    private function assignHeuristic(
        array &$assigned,
        array  $breakOutLogs,
        array  $breakInLogs,
        array  $lunchOutLogs,
        array  $lunchInLogs,
        bool   $skipBreak1,
        callable $tmFn,
        bool   $isNightShift
    ): void {
        if (!empty($lunchOutLogs)) $assigned[self::LUNCH_OUT] = $lunchOutLogs[0]['time'];
        if (!empty($lunchInLogs))  $assigned[self::LUNCH_IN]  = $lunchInLogs[0]['time'];

        $usedIns   = [];
        $pairs     = [];

        foreach ($breakOutLogs as $oi => $bo) {
            $outMin  = $tmFn($bo['time']);
            $closest = null;
            $minD    = PHP_INT_MAX;

            foreach ($breakInLogs as $ii => $bi) {
                if (in_array($ii, $usedIns)) continue;
                $diff = $tmFn($bi['time']) - $outMin;
                if ($diff < 0 && $isNightShift) $diff += 1440;
                if ($diff > 0 && $diff <= 180 && $diff < $minD) {
                    $minD    = $diff;
                    $closest = $ii;
                }
            }

            if ($closest !== null) {
                $pairs[$oi] = $closest;
                $usedIns[]  = $closest;
            }
        }

        $outSlots = array_values(array_filter(
            $skipBreak1
                ? [self::LUNCH_OUT, self::BREAK_OUT2]
                : [self::BREAK_OUT1, self::LUNCH_OUT, self::BREAK_OUT2],
            fn($s) => empty($assigned[$s])
        ));
        $inSlots = array_values(array_filter(
            $skipBreak1
                ? [self::LUNCH_IN, self::BREAK_IN2]
                : [self::BREAK_IN1, self::LUNCH_IN, self::BREAK_IN2],
            fn($s) => empty($assigned[$s])
        ));

        $pi = 0;
        foreach ($pairs as $oi => $ii) {
            if (!isset($outSlots[$pi], $inSlots[$pi])) break;
            $assigned[$outSlots[$pi]] = $breakOutLogs[$oi]['time'];
            $assigned[$inSlots[$pi]]  = $breakInLogs[$ii]['time'];
            $pi++;
        }

        foreach (array_diff_key($breakOutLogs, $pairs) as $bo) {
            foreach ($outSlots as $s) {
                if (empty($assigned[$s])) { $assigned[$s] = $bo['time']; break; }
            }
        }

        $usedInIdxs = array_values($pairs);
        foreach ($breakInLogs as $ii => $bi) {
            if (in_array($ii, $usedInIdxs)) continue;
            foreach ($inSlots as $s) {
                if (empty($assigned[$s])) { $assigned[$s] = $bi['time']; break; }
            }
        }
    }

    private function repairOrphans(array &$assigned): void
    {
        // Break Out 1 empty + Break In 1 filled + Lunch Out filled + Lunch In empty
        if (empty($assigned[self::BREAK_OUT1]) &&
            !empty($assigned[self::BREAK_IN1]) &&
            !empty($assigned[self::LUNCH_OUT]) &&
            empty($assigned[self::LUNCH_IN])) {
            $assigned[self::LUNCH_IN]  = $assigned[self::BREAK_IN1];
            $assigned[self::BREAK_IN1] = '';
        }

        // Break Out 1 empty + Break In 1 filled + Break Out 2 filled + Break In 2 empty
        if (empty($assigned[self::BREAK_OUT1]) &&
            !empty($assigned[self::BREAK_IN1]) &&
            empty($assigned[self::LUNCH_OUT]) &&
            !empty($assigned[self::BREAK_OUT2]) &&
            empty($assigned[self::BREAK_IN2])) {
            $assigned[self::BREAK_IN2] = $assigned[self::BREAK_IN1];
            $assigned[self::BREAK_IN1] = '';
        }

        // Lunch Out empty + Lunch In filled + Break Out 2 filled + Break In 2 empty
        if (empty($assigned[self::LUNCH_OUT]) &&
            !empty($assigned[self::LUNCH_IN]) &&
            !empty($assigned[self::BREAK_OUT2]) &&
            empty($assigned[self::BREAK_IN2])) {
            $assigned[self::BREAK_IN2] = $assigned[self::LUNCH_IN];
            $assigned[self::LUNCH_IN]  = '';
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // STATUS CALCULATION
    // ─────────────────────────────────────────────────────────────────────────

    private function calculateStatus(
        array  $exp,
        array  $act,
        string $shiftType,
        ?array $leaveInfo,
        ?array $holidayInfo,
        ?array $obInfo,
        string $date,
        bool   $isNightShift,
        array  $times,
        int    $empShiftType
    ): string {
        if ($shiftType === 'Rest Day')   return 'Rest Day';
        if ($holidayInfo)                return 'Holiday';

        if ($leaveInfo) {
            $isHalf = in_array(strtolower($leaveInfo['duration']), ['half-day', 'half day']);
            return $leaveInfo['type'] . ($isHalf ? ' (Half)' : '');
        }

        if ($obInfo && $this->isFullShiftOB($times, $obInfo)) {
            return $obInfo['type'];
        }

        if ($shiftType === 'Unscheduled') {
            if (empty($act[self::TIME_IN]) && empty($act[self::TIME_OUT])) return 'Absent';
            if (!empty($act[self::TIME_IN]) && empty($act[self::TIME_OUT])) return 'No Check-Out';
            if (empty($act[self::TIME_IN]) && !empty($act[self::TIME_OUT])) return 'No Check-In';
            return 'Present';
        }

        $today       = date('Y-m-d');
        $currentTime = time();

        if ($date === $today) {
            if (empty($act[self::TIME_IN]) && !empty($exp[self::TIME_IN])) {
                $expIn     = strtotime($date . ' ' . $exp[self::TIME_IN]);
                $twoHrsAfter = $expIn + 7200;
                if ($currentTime < $expIn)       return 'Current Date';
                if ($currentTime < $twoHrsAfter) return 'Late';
                return 'Absent';
            }
            if (!empty($act[self::TIME_IN]) && empty($act[self::TIME_OUT]) && !empty($exp[self::TIME_OUT])) {
                $adj    = $isNightShift ? ' +1 day' : '';
                $expOut = strtotime($date . $adj . ' ' . $exp[self::TIME_OUT]);
                return ($currentTime >= ($expOut + 7200)) ? 'No Check-Out' : 'Current Date';
            }
        }

        if (empty($act[self::TIME_IN]) && empty($act[self::TIME_OUT])) return 'Absent';

        $hasIn  = !empty($act[self::TIME_IN]);
        $hasOut = !empty($act[self::TIME_OUT]);

        if (!$hasIn && $hasOut) return 'No Check-In';
        if ($hasIn && !$hasOut) return 'No Check-Out';

        $status       = 'Present';
        $breakRemarks = [];

        if (!empty($exp[self::TIME_IN]) && !empty($act[self::TIME_IN])) {
            if (strtotime($act[self::TIME_IN]) > strtotime($exp[self::TIME_IN]))
                $status = 'Late';
        }

        if (!empty($exp[self::TIME_OUT]) && !empty($act[self::TIME_OUT])) {
            if (strtotime($act[self::TIME_OUT]) < strtotime($exp[self::TIME_OUT])) {
                $status = ($status === 'Late') ? 'Late & Early Out' : 'Early Out';
            }
        }

        $break1Allowed = ($empShiftType === 2) ? 60 : 15;
        $lunchAllowed  = 60;
        $break2Allowed = ($empShiftType === 2) ? 30 : 15;

        if (!empty($act[self::BREAK_OUT1]) && !empty($act[self::BREAK_IN1])) {
            $dur = $this->timeToMinutes($act[self::BREAK_IN1], $isNightShift)
                 - $this->timeToMinutes($act[self::BREAK_OUT1], $isNightShift);
            if ($dur > $break1Allowed)
                $breakRemarks[] = 'Over Break 1 (' . ($dur - $break1Allowed) . 'm)';
        }

        if (!empty($act[self::LUNCH_OUT]) && !empty($act[self::LUNCH_IN])) {
            $dur = $this->timeToMinutes($act[self::LUNCH_IN], $isNightShift)
                 - $this->timeToMinutes($act[self::LUNCH_OUT], $isNightShift);
            if ($dur > $lunchAllowed)
                $breakRemarks[] = 'Over Lunch (' . ($dur - $lunchAllowed) . 'm)';
        }

        if (!empty($act[self::BREAK_OUT2]) && !empty($act[self::BREAK_IN2])) {
            $dur = $this->timeToMinutes($act[self::BREAK_IN2], $isNightShift)
                 - $this->timeToMinutes($act[self::BREAK_OUT2], $isNightShift);
            if ($dur > $break2Allowed)
                $breakRemarks[] = 'Over Break 2 (' . ($dur - $break2Allowed) . 'm)';
        }

        if (!empty($breakRemarks)) {
            $suffix = implode(' & ', $breakRemarks);
            $status = ($status === 'Present') ? $suffix : $status . ' & ' . $suffix;
        }

        return $status;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // OB HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function isFullShiftOB(array $times, ?array $obInfo): bool
    {
        if (!$obInfo || empty($obInfo['time_from']) || empty($obInfo['time_to'])) return false;
        if (empty($times[0]) || empty($times[7])) return false;

        $obFrom   = strtotime($obInfo['time_from']);
        $obTo     = strtotime($obInfo['time_to']);
        $checkIn  = strtotime($times[0]);
        $checkOut = strtotime($times[7]);

        return ($obFrom <= $checkIn && $obTo >= $checkOut);
    }

    private function getObCoveredWindows(array $times, ?array $obInfo): array
    {
        $keys    = ['check_in', 'break_out1', 'break_in1', 'lunch_out',
                    'lunch_in', 'break_out2', 'break_in2', 'check_out'];
        $covered = array_fill_keys($keys, false);

        if (!$obInfo || empty($obInfo['time_from']) || empty($obInfo['time_to']))
            return $covered;

        $obFrom  = strtotime($obInfo['time_from']);
        $obTo    = strtotime($obInfo['time_to']);
        $overlap = fn($s, $e) => ($s <= $obTo && $e >= $obFrom);

        if (!empty($times[0])) {
            $ci   = strtotime($times[0]);
            $next = !empty($times[1]) ? strtotime($times[1])
                  : (!empty($times[7]) ? strtotime($times[7]) : $ci);
            $covered['check_in'] = $overlap($ci, $next);
        }

        foreach ([
            [1, 2, 'break_out1', 'break_in1'],
            [3, 4, 'lunch_out',  'lunch_in'],
            [5, 6, 'break_out2', 'break_in2'],
        ] as [$oi, $ii, $ok, $ik]) {
            if (!empty($times[$oi]) && !empty($times[$ii])) {
                $covers = $overlap(strtotime($times[$oi]), strtotime($times[$ii]));
                $covered[$ok] = $covered[$ik] = $covers;
            } elseif (!empty($times[$oi])) {
                $t = strtotime($times[$oi]);
                $covered[$ok] = ($t >= $obFrom && $t <= $obTo);
            } elseif (!empty($times[$ii])) {
                $t = strtotime($times[$ii]);
                $covered[$ik] = ($t >= $obFrom && $t <= $obTo);
            }
        }

        if (!empty($times[7])) {
            $co   = strtotime($times[7]);
            $prev = strtotime($times[6] ?? $times[4] ?? $times[2] ?? $times[0] ?? $times[7]);
            $covered['check_out'] = $overlap($prev, $co);
        }

        return $covered;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // UTILITIES
    // ─────────────────────────────────────────────────────────────────────────

    private function timeToMinutes(string $time, bool $isNightShift = false): int
    {
        if (empty($time)) return 0;
        [$h, $m]     = explode(':', $time);
        $total       = ((int) $h * 60) + (int) $m;
        return ($isNightShift && (int) $h < 12) ? $total + 1440 : $total;
    }

    private function fullLeaveType(string $code): string
    {
        return [
            'SL'  => 'Sick Leave',
            'VL'  => 'Vacation Leave',
            'BL'  => 'Birthday Leave',
            'BrL' => 'Bereavement Leave',
            'EL'  => 'Emergency Leave',
            'PL'  => 'Paternity Leave',
            'SPL' => 'Solo Parent Leave',
            'MiL' => 'Military Leave',
        ][$code] ?? $code;
    }
}