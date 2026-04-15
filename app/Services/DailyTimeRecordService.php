<?php

namespace App\Services;

use App\Domain\Dtr\DtrRow;
use App\Domain\Dtr\PunchAssigner;
use App\Domain\Dtr\StatusCalculator;
use App\Repositories\AttendanceRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\HolidayRepository;
use App\Repositories\ScheduleRepository;
use Carbon\Carbon;

class DailyTimeRecordService
{
    // Slot index constants — referenced when building exp/act arrays
    private const TIME_IN    = 0;
    private const BREAK_OUT1 = 1;
    private const BREAK_IN1  = 2;
    private const LUNCH_OUT  = 3;
    private const LUNCH_IN   = 4;
    private const BREAK_OUT2 = 5;
    private const BREAK_IN2  = 6;
    private const TIME_OUT   = 7;

    public function __construct(
        private readonly EmployeeRepository   $employeeRepo,
        private readonly AttendanceRepository $attendanceRepo,
        private readonly ScheduleRepository   $scheduleRepo,
        private readonly HolidayRepository    $holidayRepo,
        private readonly PunchAssigner        $punchAssigner,
        private readonly StatusCalculator     $statusCalculator,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC ENTRY POINT
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build the full DTR rows for a given employee + month (Y-m).
     *
     * @return array<int, array>  Each element is DtrRow::toArray()
     */
    public function getTableData(string $employId, string $month): array
    {
        $emp = $this->employeeRepo->findForDtr($employId, $month);

        if (! $emp) {
            return [];
        }

        $empShiftType  = (int) ($emp->SHIFTTYPE ?? 1);
        $leaveDates    = $this->scheduleRepo->getLeaveDatesFromModel($emp);
        $obRecords     = $this->scheduleRepo->getObRecordsFromModel($emp);
        $bioLogs       = $this->attendanceRepo->getLogsFromModel($emp, $month);
        $year     = (int) substr($month, 0, 4);
        $holidays = $this->holidayRepo->getForYearsKeyedByDate([$year, $year + 1]);
        $workSchedules = $this->scheduleRepo->getWorkSchedulesFromModel($emp);

        $mapped = $this->buildScheduleMap($workSchedules, $month, $bioLogs);
        $this->mergeUnscheduledBioLogDates($mapped, $bioLogs, $month);
        $this->fillRemainingDays($mapped, $month);

        krsort($mapped);

        $rows = [];

        foreach ($mapped as $date => $info) {
            $shiftTimes   = $info['time'] ?? [];
            $isNightShift = $info['isNightShift'] ?? false;

            $expSlots = $this->buildExpSlots($shiftTimes);

            $logsForDate = $this->getLogsForDate($date, $isNightShift, $bioLogs, $mapped);
            $logsForDate = $this->deduplicateLogs($logsForDate, $isNightShift);

            // Suppress orphan break/lunch punches with no check-in or check-out
            $hasIn  = collect($logsForDate)->contains('type', 'check_in');
            $hasOut = collect($logsForDate)->contains('type', 'check_out');
            if (!$hasIn && !$hasOut && !empty($logsForDate)) {
                $logsForDate = [];
            }

            $assigned      = $this->punchAssigner->assign($shiftTimes, $logsForDate, $isNightShift, $empShiftType);
            $isUnscheduled = ($info['type'] === 'Unscheduled');

            $act = $this->buildActSlots($assigned, $isUnscheduled);

            $leaveInfo   = $leaveDates[$date] ?? null;
            $holidayInfo = $holidays[$date]   ?? null;
            $obInfo      = $obRecords[$date]  ?? null;

            $remarks = $this->statusCalculator->calculate(
                $expSlots, $act, $info['type'],
                $leaveInfo, $holidayInfo, $obInfo,
                $date, $isNightShift, $shiftTimes, $empShiftType
            );

            $rows[] = (new DtrRow(
                date:         $date,
                day:          $info['day'],
                code:         $info['shift'],
                shiftType:    $info['type'],
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
                holidayInfo:  $holidayInfo,
                obInfo:       $obInfo,
                obCovered:    $this->statusCalculator->getObCoveredWindows($shiftTimes, $obInfo),
                isFullOb:     $this->statusCalculator->isFullShiftOb($shiftTimes, $obInfo),
            ))->toArray();
        }

        return $rows;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SLOT BUILDERS
    // ─────────────────────────────────────────────────────────────────────────

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

    // ─────────────────────────────────────────────────────────────────────────
    // SCHEDULE MAP  (unchanged logic, same as before)
    // ─────────────────────────────────────────────────────────────────────────

    private function buildScheduleMap(array $workSchedules, string $month, array $bioLogs): array
    {
        $mapped = [];
        $today  = Carbon::today();

        foreach ($workSchedules as $ws) {
            $start = $ws['payroll_date_start'];
            $end   = $ws['payroll_date_end']
                ?? $start->copy()->addDays(count($ws['schedule']) - 1);

            foreach ($ws['schedule'] as $day => $shiftCode) {
                if ($shiftCode === null) continue;

                $d = $start->copy()->addDays($day - 1);

                if ($d->format('Y-m') !== $month)   continue;
                if ($d->gt($end) || $d->gt($today)) continue;

                $shiftName  = $shiftCode->SHIFTCODE;
                $shiftTimes = is_array($shiftCode->TIME_WINDOWS)
                    ? $shiftCode->TIME_WINDOWS
                    : (json_decode($shiftCode->TIME_WINDOWS, true) ?? []);

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
        }

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
            if (substr($bioDate, 0, 7) !== $month)   continue;
            if (isset($mapped[$bioDate]))              continue;
            if (Carbon::parse($bioDate)->gt($today))  continue;

            $prevDate    = Carbon::parse($bioDate)->subDay()->format('Y-m-d');
            $prevEntry   = $mapped[$prevDate] ?? null;
            $prevIsNight = $prevEntry && ($prevEntry['isNightShift'] ?? false);

            $nonEarlyLogs = array_filter(
                $logs,
                fn ($l) => (int) date('H', strtotime($l['time'])) > 7
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
        $cur   = Carbon::parse("{$month}-01");
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
    // LOG HELPERS  (unchanged logic)
    // ─────────────────────────────────────────────────────────────────────────

    private function getLogsForDate(
        string $date,
        bool   $isNightShift,
        array  $bioLogs,
        array  $mapped
    ): array {
        if ($isNightShift) {
            $nextDate = Carbon::parse($date)->addDay()->format('Y-m-d');
            $pmLogs   = array_values(array_filter(
                $bioLogs[$date] ?? [],
                fn ($l) => (int) date('H', strtotime($l['time'])) >= 12
            ));

            if (!empty($pmLogs)) {
                $amNext = array_values(array_filter(
                    $bioLogs[$nextDate] ?? [],
                    fn ($l) => (int) date('H', strtotime($l['time'])) <= 7
                ));
                return array_merge($pmLogs, $amNext);
            }

            return $pmLogs;
        }

        $prevDate    = Carbon::parse($date)->subDay()->format('Y-m-d');
        $prevEntry   = $mapped[$prevDate] ?? null;
        $prevIsNight = $prevEntry && ($prevEntry['isNightShift'] ?? false);

        if ($prevIsNight) {
            $prevPmLogs  = array_filter(
                $bioLogs[$prevDate] ?? [],
                fn ($l) => (int) date('H', strtotime($l['time'])) >= 12
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
public function getLatestShiftLog(string $employId): array
{
    $today    = now()->format('Y-m-d');
    $emp      = $this->employeeRepo->findForDtrDate($employId, $today);

    if (! $emp) return [];

    $empShiftType = (int) ($emp->SHIFTTYPE ?? 1);
    $bioLogs      = $this->attendanceRepo->getLogsFromModel($emp, now()->format('Y-m'));
    $workSchedules = $this->scheduleRepo->getWorkSchedulesFromModel($emp);
    $holidays     = $this->holidayRepo->getForYearKeyedByDate((int) now()->year);
    $leaveDates   = $this->scheduleRepo->getLeaveDatesFromModel($emp);
    $obRecords    = $this->scheduleRepo->getObRecordsFromModel($emp);

    $info = $this->resolveSingleDayInfo($workSchedules, $today, $bioLogs);

    $shiftTimes   = $info['time'] ?? [];
    $isNightShift = $info['isNightShift'] ?? false;
    $expSlots     = $this->buildExpSlots($shiftTimes);

    $logsForDate = $this->getLogsForDate($today, $isNightShift, $bioLogs, [$today => $info]);
    $logsForDate = $this->deduplicateLogs($logsForDate, $isNightShift);

    $hasIn  = collect($logsForDate)->contains('type', 'check_in');
    $hasOut = collect($logsForDate)->contains('type', 'check_out');
    if (!$hasIn && !$hasOut && !empty($logsForDate)) {
        $logsForDate = [];
    }

    $assigned      = $this->punchAssigner->assign($shiftTimes, $logsForDate, $isNightShift, $empShiftType);
    $isUnscheduled = ($info['type'] === 'Unscheduled');
    $act           = $this->buildActSlots($assigned, $isUnscheduled);

    $leaveInfo   = $leaveDates[$today]  ?? null;
    $holidayInfo = $holidays[$today]    ?? null;
    $obInfo      = $obRecords[$today]   ?? null;

    $remarks = $this->statusCalculator->calculate(
        $expSlots, $act, $info['type'],
        $leaveInfo, $holidayInfo, $obInfo,
        $today, $isNightShift, $shiftTimes, $empShiftType
    );

    return [[
        'date'      => $today,
        'status'    => $remarks,
        'timeIn'    => $act[self::TIME_IN]    ?? '',
        'breakOut1' => $act[self::BREAK_OUT1] ?? '',
        'breakIn1'  => $act[self::BREAK_IN1]  ?? '',
        'lunchOut'  => $act[self::LUNCH_OUT]  ?? '',
        'lunchIn'   => $act[self::LUNCH_IN]   ?? '',
        'breakOut2' => $act[self::BREAK_OUT2] ?? '',
        'breakIn2'  => $act[self::BREAK_IN2]  ?? '',
        'timeOut'   => $act[self::TIME_OUT]   ?? '',
    ]];
}
private function resolveSingleDayInfo(array $workSchedules, string $date, array $bioLogs): array
{
    $dateCarbon = Carbon::parse($date)->startOf('day');

    foreach (array_reverse($workSchedules) as $ws) {
        $start = $ws['payroll_date_start'];
        $end   = $ws['payroll_date_end'] ?? null;

        if ($start->gt($dateCarbon)) continue;
        if ($end && $end->lt($dateCarbon)) continue;

        $dayIndex  = $start->diffInDays($dateCarbon);
        $shiftCode = $ws['schedule'][$dayIndex] ?? null;

        if (! $shiftCode) continue;

        $shiftName  = $shiftCode->SHIFTCODE;
        $shiftTimes = is_array($shiftCode->TIME_WINDOWS)
            ? $shiftCode->TIME_WINDOWS
            : (json_decode($shiftCode->TIME_WINDOWS, true) ?? []);

        [$shiftType, $isNightShift] = $this->resolveShiftTypeMeta(
            $shiftName, $shiftTimes, $date, $bioLogs
        );

        return [
            'day'          => $dateCarbon->format('D'),
            'shift'        => $shiftName,
            'time'         => $shiftTimes,
            'type'         => $shiftType,
            'isNightShift' => $isNightShift,
        ];
    }

    // No schedule found — check if there are bio logs for this date
    $prevDate  = Carbon::parse($date)->subDay()->format('Y-m-d');
    $prevLogs  = $bioLogs[$prevDate] ?? [];
    $prevIsNight = !empty(array_filter($prevLogs, fn($l) => (int) date('H', strtotime($l['time'])) >= 12));

    $isNight = false;
    foreach ($bioLogs[$date] ?? [] as $l) {
        if ($l['type'] === 'check_in') {
            $h       = (int) date('H', strtotime($l['time']));
            $isNight = ($h >= 12 && !($prevIsNight && $h <= 7));
            break;
        }
    }

    return $this->unscheduledEntry($date, $isNight);
}
}