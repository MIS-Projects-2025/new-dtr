<?php

namespace App\Services;

use App\Models\BiometricLog;
use App\Models\BiometricLogManual;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class DtrLogService
{
    // -------------------------------------------------------------------------
    // PUBLIC API
    // -------------------------------------------------------------------------

    /**
     * Resolve actual DTR logs for a batch of employees.
     *
     * Returns: [ employid => resolved_slots ]
     *
     * Slot index → name:
     *   0 → time_in       1 → break_out_1   2 → break_in_1
     *   3 → lunch_out     4 → lunch_in       5 → break_out_2
     *   6 → break_in_2    7 → time_out
     *
     * Handled scenarios:
     *   1.  Normal day shift, all punches typed correctly
     *   2.  Close TIME_WINDOWS (slots only minutes apart)
     *   3.  Employee skipped a break entirely
     *   4.  Generic device (only check_in / check_out punch types)
     *   5.  Missing time_in (forgot to tap in)
     *   6.  Missing time_out (forgot to tap out)
     *   7.  Night shift spanning midnight
     *   8.  Night shift whose check_in fell on a previous rest day
     *   9.  Shifting schedule (no break_out_1 / break_in_1 slots)
     *   10. Duplicate taps within 3 minutes
     *   11. On-leave / unscheduled employee who still punched in
     *   12. Employee with no schedule at all
     *   13. Orphaned break_in with no matching break_out
     *   14. Extra punches beyond the expected number of slots
     *
     * @param  array  $employIds       List of EMPLOYID strings
     * @param  array  $timeWindowsMap  [ employid => TIME_WINDOWS array ]
     * @param  array  $scheduleTypeMap [ employid => 'Normal' | 'Shifting' ]
     * @param  string $date            Target date (Y-m-d), defaults to today
     * @return array
     */
    public function resolveLogsForEmployees(
        array  $employIds,
        array  $timeWindowsMap,
        array  $scheduleTypeMap,
        string $date = ''
    ): array {
        if (empty($employIds)) return [];

        $today = !empty($date) ? $date : now()->toDateString();

        // Fetch a wide window (yesterday → day after tomorrow) so night-shift
        // boundaries and late check-outs are never cut off.
        $from = Carbon::parse($today)->subDay()->toDateString();
        $to   = Carbon::parse($today)->addDays(2)->toDateString();

        $allLogs = $this->fetchAllLogs($employIds, $from, $to);

        $result = [];

        foreach ($employIds as $employId) {
            $tw           = $timeWindowsMap[$employId]  ?? [];
            $scheduleType = $scheduleTypeMap[$employId] ?? 'Normal';
            $isShifting   = $scheduleType === 'Shifting';
            $isNightShift = $this->isNightShift($tw);

            $empLogs = $allLogs[$employId] ?? [];

            // For no-schedule employees (empty tw), detect night shift from
            // ANY punch type — not just check_in — so missed check-ins don't
            // cause the employee to fall into the wrong date window.
            if (!$isNightShift && empty($tw)) {
                $isNightShift = $this->detectNightShiftFromLogs($empLogs, $today);
            }

            $logsForDate = $this->filterLogsForDate($empLogs, $today, $isNightShift, $tw);
            $logsForDate = $this->deduplicateLogs($logsForDate, $isNightShift);

            $originallyShifting = $scheduleType === 'Shifting';
            $isShifting = $this->effectiveIsShifting($isShifting, $logsForDate, $isNightShift, $tw, $originallyShifting, $today);


            $result[$employId] = $this->assignPunches($tw, $logsForDate, $isNightShift, $isShifting);
        }

        return $result;
    }

        /**
     * Same as resolveLogsForEmployees but accepts pre-fetched raw logs
     * grouped by employid to avoid repeated DB queries across date ranges.
     */
    public function resolveLogsForEmployeesFromRaw(
    array      $employIds,
    array      $timeWindowsMap,
    array      $scheduleTypeMap,
    string     $date,
    Collection $allBioLogs,
    Collection $allManualLogs
): array {
    if (empty($employIds)) return [];

    // Wide window needed for night shift boundary detection
    $from = Carbon::parse($date)->subDay()->toDateString();
    $to   = Carbon::parse($date)->addDays(2)->toDateString();

    // Build per-employee log arrays from the pre-fetched bulk collections
    $grouped = [];

    foreach ($employIds as $employId) {
        $key = (string) $employId;
        $logs = [];

        foreach ($allBioLogs->get($key, collect()) as $row) {
            $logDate = substr($row->datetime, 0, 10);
            if ($logDate < $from || $logDate > $to) continue;
            $dt    = Carbon::parse($row->datetime);
            $logs[] = [
                'employid' => $key,
                'datetime' => $row->datetime,
                'date'     => $dt->toDateString(),
                'time'     => $dt->format('H:i'),
                'type'     => $this->mapPunchType($row->punch_type),
                'source'   => 'auto',
            ];
        }

        foreach ($allManualLogs->get($key, collect()) as $row) {
            $logDate = substr($row->datetime, 0, 10);
            if ($logDate < $from || $logDate > $to) continue;
            $dt    = Carbon::parse($row->datetime);
            $logs[] = [
                'employid' => $key,
                'datetime' => $row->datetime,
                'date'     => $dt->toDateString(),
                'time'     => $dt->format('H:i'),
                'type'     => $this->mapPunchType($row->punch_type),
                'source'   => 'manual',
            ];
        }

        if (!empty($logs)) {
            usort($logs, fn($a, $b) => strcmp($a['datetime'], $b['datetime']));
        }

        $grouped[$key] = $logs;
    }

    $result = [];

    foreach ($employIds as $employId) {
        $key          = (string) $employId;
        $tw           = $timeWindowsMap[$employId]  ?? [];
        $scheduleType = $scheduleTypeMap[$employId] ?? 'Normal';
        $isShifting   = $scheduleType === 'Shifting';
        $isNightShift = $this->isNightShift($tw);
        $empLogs      = $grouped[$key] ?? [];

        if (!$isNightShift && empty($tw)) {
            $isNightShift = $this->detectNightShiftFromLogs($empLogs, $date);
        }

        $logsForDate = $this->filterLogsForDate($empLogs, $date, $isNightShift, $tw);
        $logsForDate = $this->deduplicateLogs($logsForDate, $isNightShift);

        $originallyShifting = $scheduleType === 'Shifting';
        $isShifting = $this->effectiveIsShifting($isShifting, $logsForDate, $isNightShift, $tw, $originallyShifting, $date);

        $result[$employId] = $this->assignPunches($tw, $logsForDate, $isNightShift, $isShifting);
    }

    return $result;
}

public function resolveLogsFromPreNormalized(
    array  $employIds,
    array  $timeWindowsMap,
    array  $scheduleTypeMap,
    string $date,
    array  $preNormalizedLogs
): array {
    if (empty($employIds)) return [];

    $result = [];

    foreach ($employIds as $employId) {
        $key          = (string) $employId;
        $tw           = $timeWindowsMap[$employId]  ?? [];
        $scheduleType = $scheduleTypeMap[$employId] ?? 'Normal';
        $isShifting   = $scheduleType === 'Shifting';
        $isNightShift = $this->isNightShift($tw);
        $empLogs      = $preNormalizedLogs[$key] ?? [];

        if (!$isNightShift && empty($tw)) {
            $isNightShift = $this->detectNightShiftFromLogs($empLogs, $date);
        }

        $logsForDate = $this->filterLogsForDateFast($empLogs, $date, $isNightShift, $tw);
        $logsForDate = $this->deduplicateLogs($logsForDate, $isNightShift);

        $originallyShifting = $scheduleType === 'Shifting';
        $isShifting = $this->effectiveIsShifting($isShifting, $logsForDate, $isNightShift, $tw, $originallyShifting, $date);

        $result[$employId] = $this->assignPunches($tw, $logsForDate, $isNightShift, $isShifting);
    }

    return $result;
}

private function filterLogsForDateFast(
    array  $logs,
    string $date,
    bool   $isNightShift,
    array  $tw
): array {
    if (empty($logs)) return [];

    $yesterday = date('Y-m-d', strtotime($date . ' -1 day'));
    $tomorrow  = date('Y-m-d', strtotime($date . ' +1 day'));
    $padMin    = fn(int $n) => str_pad($n, 2, '0', STR_PAD_LEFT);

    if ($isNightShift && !empty($tw[0])) {
        [$h, $m] = explode(':', $tw[0]);
        $shiftStartHour         = (int) $h;
        $hasPrevEveningCheckIn  = false;
        $hasPrevOrEarlyCheckOut = false;
        $hasTodayEveningIn      = false;

        foreach ($logs as $log) {
            $logDate = $log['date'];
            $hour    = (int) explode(':', $log['time'])[0];

            if ($logDate === $yesterday && $hour >= $shiftStartHour && $log['type'] === 'check_in') {
                $hasPrevEveningCheckIn = true;
            }
            if ($log['type'] === 'check_out' &&
                ($logDate === $yesterday || ($logDate === $date && $hour < 14))) {
                $hasPrevOrEarlyCheckOut = true;
            }
            if ($logDate === $date && $hour >= 14 && $log['type'] === 'check_in') {
                $hasTodayEveningIn = true;
            }
        }

        $prevDayWasRestDay = $hasPrevEveningCheckIn && !$hasPrevOrEarlyCheckOut && !$hasTodayEveningIn;

        if ($prevDayWasRestDay) {
    // Shift started yesterday — logs belong to yesterday, not today.
    return [];
    } else {
        $startHour   = max(0, $shiftStartHour - 2);
        $windowStart = $date     . ' ' . $padMin($startHour) . ':' . $m . ':00';
        $windowEnd   = $tomorrow . ' 13:59:59';
        $anchorDate  = $date;
    }

        $logsInWindow = array_values(array_filter(
            $logs,
            fn($log) => $log['datetime'] >= $windowStart && $log['datetime'] <= $windowEnd
        ));

        if (empty($logsInWindow)) return [];

        $hasAnchorPunch = false;
        foreach ($logsInWindow as $log) {
            if ($log['date'] === $anchorDate && (int) explode(':', $log['time'])[0] >= $shiftStartHour) {
                $hasAnchorPunch = true;
                break;
            }
        }

        return $hasAnchorPunch ? $logsInWindow : [];

    } elseif (!empty($tw[0])) {
        [$h, $m] = explode(':', $tw[0]);
        $startHour   = max(0, (int)$h - 3);
        $windowStart = $date . ' ' . $padMin($startHour) . ':' . $m . ':00';

        if (!empty($tw[7])) {
            [$ho, $mo] = explode(':', $tw[7]);
            $endHour    = (int)$ho + 4;
            $spansNight = (int)$tw[7] < (int)$tw[0];
            $endDate    = ($spansNight || $endHour >= 24) ? $tomorrow : $date;
            if ($endHour >= 24) $endHour -= 24;
            if ((int)$h < 14) {
                $windowEnd = $date . ' 23:59:59';
            } else {
                $windowEnd = min($endDate . ' ' . $padMin($endHour) . ':' . $mo . ':59',
                                 $tomorrow . ' 13:59:59');
            }
        } else {
            $windowEnd = $date . ' 23:59:59';
        }

        return array_values(array_filter(
            $logs,
            fn($log) => $log['datetime'] >= $windowStart && $log['datetime'] <= $windowEnd
        ));

    } elseif ($isNightShift) {
        $windowStart = $date     . ' 14:00:00';
        $windowEnd   = $tomorrow . ' 13:59:59';
    } else {
        $windowStart = $date . ' 00:00:00';
        $windowEnd   = $date . ' 23:59:59';
    }

    return array_values(array_filter(
        $logs,
        fn($log) => $log['datetime'] >= $windowStart && $log['datetime'] <= $windowEnd
    ));
}

    // -------------------------------------------------------------------------
    // FETCHING
    // -------------------------------------------------------------------------

    /**
     * Fetch biometric logs (automatic + manual) for all employees in bulk.
     * Returns logs grouped by employid: [ employid => [ ...logs ] ]
     */
    private function fetchAllLogs(array $employIds, string $from, string $to): array
    {
        $grouped = [];

        $autoLogs = BiometricLog::whereIn('employid', $employIds)
            ->whereBetween('datetime', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->orderBy('datetime')
            ->get(['employid', 'datetime', 'punch_type']);

        foreach ($autoLogs as $row) {
            $grouped[$row->employid][] = $this->normalizeLog(
                $row->employid, $row->datetime, $row->punch_type, 'auto'
            );
        }

        $manualLogs = BiometricLogManual::whereIn('employid', $employIds)
            ->whereBetween('datetime', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->orderBy('datetime')
            ->get(['employid', 'datetime', 'punch_type']);

        foreach ($manualLogs as $row) {
            $grouped[$row->employid][] = $this->normalizeLog(
                $row->employid, $row->datetime, $row->punch_type, 'manual'
            );
        }

        // Sort each employee's logs by datetime
        foreach ($grouped as &$logs) {
            usort($logs, fn($a, $b) => strcmp($a['datetime'], $b['datetime']));
        }
        unset($logs);

        return $grouped;
    }

    /**
     * Normalize a raw DB row into a consistent log array.
     */
    private function normalizeLog(
        string $employId,
        string $datetime,
               $rawPunchType,
        string $source
    ): array {
        $dt = Carbon::parse($datetime);

        return [
            'employid' => $employId,
            'datetime' => $datetime,
            'date'     => $dt->toDateString(),
            'time'     => $dt->format('H:i'),
            'type'     => $this->mapPunchType($rawPunchType),
            'source'   => $source,
        ];
    }

    /**
     * Map raw DB punch_type value → canonical string.
     *
     * DB values → type:
     *   0 / check_in  → check_in     1 / check_out → check_out
     *   2 / break_out → break_out    3 / break_in  → break_in
     *   4 / lunch_out → lunch_out    5 / lunch_in  → lunch_in
     */
    private function mapPunchType($raw): string
    {
        $map = [
            '0'         => 'check_in',
            '1'         => 'check_out',
            '2'         => 'break_out',
            '3'         => 'break_in',
            '4'         => 'lunch_out',
            '5'         => 'lunch_in',
            'check_in'  => 'check_in',
            'check_out' => 'check_out',
            'break_out' => 'break_out',
            'break_in'  => 'break_in',
            'lunch_out' => 'lunch_out',
            'lunch_in'  => 'lunch_in',
        ];

        return $map[(string) $raw] ?? 'check_in';
    }

    // -------------------------------------------------------------------------
    // DATE WINDOWING
    // -------------------------------------------------------------------------

    /**
     * Whether a shift is a night shift based on TIME_WINDOWS[0] being >= 18:00.
     */
    private function isNightShift(array $tw): bool
    {
        if (empty($tw[0])) return false;
        return (int) explode(':', $tw[0])[0] >= 18;
    }

    /**
     * Filter raw logs to only those that belong to the employee's shift window
     * for the target date.
     *
     * Day shift (known start):
     *   window = [expected_time_in - 3h  →  expected_time_out + 4h]
     *
     * Night shift (known start):
     *   Normal:        window = [today at shift_start - 2h  →  tomorrow 13:59]
     *   Prev rest day: window = [yesterday at shift_start - 2h  →  tomorrow 13:59]
     *   This handles Scenario 8 — employee's check_in fell on yesterday (rest day).
     *
     * No-schedule night detected:
     *   window = [today 14:00 → tomorrow 13:59]
     *
     * No-schedule day / unknown:
     *   window = [today 00:00 → today 23:59]
     */
    private function filterLogsForDate(
    array  $logs,
    string $date,
    bool   $isNightShift,
    array  $tw
): array {
    $base      = Carbon::parse($date);
    $yesterday = $base->copy()->subDay();
    $tomorrow  = $base->copy()->addDay();

    if ($isNightShift && !empty($tw[0])) {
        // Night shift: window anchored to expected check_in on either
        // today OR yesterday (when prev day was rest day).
        // Start = expected check_in - 2hr grace (on whichever day it falls)
        // End   = tomorrow 13:59
        [$h, $m] = explode(':', $tw[0]);

        // Check if there is a punch on yesterday evening at/after shift start
        // AND no check_in today evening → shift started on yesterday (rest day)
        $shiftStartHour      = (int)$h;
$hasPrevEveningCheckIn  = false;
$hasPrevOrEarlyCheckOut = false;
$hasTodayEveningIn      = false;

foreach ($logs as $log) {
    $logDate = Carbon::parse($log['datetime'])->toDateString();
    $hour    = (int) explode(':', $log['time'])[0];

    // Must be a check_in specifically on yesterday evening
    // (not just any punch type) to confirm shift actually started yesterday
    if (
        $logDate === $yesterday->toDateString() &&
        $hour >= $shiftStartHour &&
        $log['type'] === 'check_in'
    ) {
        $hasPrevEveningCheckIn = true;
    }

    // check_out on yesterday OR early today (before 14:00) =
    // previous night shift was completed
    if (
        $log['type'] === 'check_out' &&
        (
            $logDate === $yesterday->toDateString() ||
            ($logDate === $date && $hour < 14)
        )
    ) {
        $hasPrevOrEarlyCheckOut = true;
    }

    // New shift check_in this evening (today)
    if (
        $logDate === $date &&
        $hour >= 14 &&
        $log['type'] === 'check_in'
    ) {
        $hasTodayEveningIn = true;
    }
}

// Only carry over previous shift logs if ALL are true:
// 1. There was an actual check_in yesterday evening (not just any punch)
// 2. That shift has no check_out yet (still ongoing / missed tap out)
// 3. No new check_in started this evening (new shift hasn't begun)
$prevDayWasRestDay = $hasPrevEveningCheckIn
    && !$hasPrevOrEarlyCheckOut
    && !$hasTodayEveningIn;

        if ($prevDayWasRestDay) {
            // The shift started yesterday — these logs belong to yesterday's date,
            // not today's. Return empty so they don't show under today.
            return [];
        } else {
            $windowStart = $base->copy()->setTime((int)$h, (int)$m)->subHours(2);
            $windowEnd   = $tomorrow->copy()->setTime(13, 59);
        }

        // Soft guard: if no logs at all exist within the window, return empty.
        // This is safe because an employee with truly no activity (rest day)
        // will have zero logs in the window — unlike Scenario 5 (missing time_in)
        // where other punch types (break_out, check_out, etc.) still exist.
        $logsInWindow = array_values(array_filter(
            $logs,
            fn(array $log) => Carbon::parse($log['datetime'])->between($windowStart, $windowEnd)
        ));

        if (empty($logsInWindow)) {
            return [];
        }

        // Anchor guard: the shift must have at least one punch that actually
        // falls on the anchor date (yesterday if prevDayWasRestDay, today otherwise)
        // at or after the shift start hour. Without this, today's early-morning
        // day shift logs (e.g. 06:56 check-in) bleed into yesterday's night
        // shift window since $windowEnd reaches into tomorrow 13:59.
        $anchorDate = $prevDayWasRestDay
            ? $yesterday->toDateString()
            : $base->toDateString();

        $hasAnchorPunch = collect($logsInWindow)->contains(function ($log) use ($anchorDate, $shiftStartHour) {
            $logDate = Carbon::parse($log['datetime'])->toDateString();
            $hour    = (int) explode(':', $log['time'])[0];
            return $logDate === $anchorDate && $hour >= $shiftStartHour;
        });

        if (!$hasAnchorPunch) {
            return [];
        }

        return $logsInWindow;

    } elseif (!empty($tw[0])) {
    // Scheduled day/afternoon shift — anchor to expected time_in
    [$h, $m]     = explode(':', $tw[0]);
    $expectedIn  = $base->copy()->setTime((int)$h, (int)$m);
    $windowStart = $expectedIn->copy()->subHours(3);

    if (!empty($tw[7])) {
        [$ho, $mo] = explode(':', $tw[7]);
        $expectedOut = $base->copy()->setTime((int)$ho, (int)$mo);

        // If expected time_out is before expected time_in,
        // it spans midnight (e.g. afternoon shift ending past midnight)
        if ($expectedOut->lt($expectedIn)) {
            $expectedOut->addDay();
        }

        $windowEnd = $expectedOut->copy()->addHours(4);

        // Hard cap: never go beyond tomorrow at 13:59
        // This prevents day shift logs from appearing on rest day views
        $hardCap = $tomorrow->copy()->setTime(13, 59);
        if ($windowEnd->gt($hardCap)) {
            $windowEnd = $hardCap;
        }

        // Safety: if window end is still beyond midnight of the base date,
        // and the shift is a day shift (starts before 14:00), cap it at
        // base date 23:59 to avoid pulling next day's logs
        $shiftStartHour = (int)$h;
        if ($shiftStartHour < 14 && $windowEnd->gt($base->copy()->setTime(23, 59))) {
            $windowEnd = $base->copy()->setTime(23, 59);
        }
    } else {
        // No expected time_out — cap strictly at end of base date
        $windowEnd = $base->copy()->setTime(23, 59);
    }

    } elseif ($isNightShift) {
        // No-schedule night shift detected
        $windowStart = $base->copy()->setTime(14, 0);
        $windowEnd   = $tomorrow->copy()->setTime(13, 59);

    } else {
        // No-schedule day shift or unknown
        $windowStart = $base->copy()->setTime(0, 0);
        $windowEnd   = $base->copy()->setTime(23, 59);
    }

    return array_values(array_filter(
        $logs,
        fn(array $log) => Carbon::parse($log['datetime'])->between($windowStart, $windowEnd)
    ));
}

private function assignPunches(
    array $tw,
    array $logs,
    bool  $isNightShift,
    bool  $isShifting
): array {
    $slots = $this->emptySlots();
    if (empty($logs)) return $slots;

    $tm      = fn(string $t) => $this->toAbsMin($t, $isNightShift);
    $sortAsc = function (array &$arr) use ($tm): void {
        usort($arr, fn($a, $b) => $tm($a['time']) <=> $tm($b['time']));
    };

    // ── Bucket by type ────────────────────────────────────────────────────
    $checkIns  = $checkOuts = $breakOuts = $breakIns = $lunchOuts = $lunchIns = [];

    foreach ($logs as $log) {
        match ($log['type']) {
            'check_in'  => $checkIns[]  = $log,
            'check_out' => $checkOuts[] = $log,
            'break_out' => $breakOuts[] = $log,
            'break_in'  => $breakIns[]  = $log,
            'lunch_out' => $lunchOuts[] = $log,
            'lunch_in'  => $lunchIns[]  = $log,
            default     => null,
        };
    }

    $sortAsc($checkIns);
    $sortAsc($checkOuts);
    $sortAsc($breakOuts);
    $sortAsc($breakIns);
    $sortAsc($lunchOuts);
    $sortAsc($lunchIns);

    // ── Scenario 4: Generic device promotion ──────────────────────────────
    // Device only emits check_in/check_out and more than 2 exist.
    // Promote middle punches to break slots alternately.
    $genericCount   = count($checkIns) + count($checkOuts);
    $hasTypedBreaks = !empty($breakOuts) || !empty($breakIns)
                   || !empty($lunchOuts) || !empty($lunchIns);

    if ($genericCount > 2 && !$hasTypedBreaks) {
        $all = array_merge($checkIns, $checkOuts);
        usort($all, fn($a, $b) => $tm($a['time']) <=> $tm($b['time']));

        $checkIns  = [array_shift($all)]; // earliest → time_in
        $checkOuts = [array_pop($all)];   // latest   → time_out

        foreach ($all as $i => $punch) {
            if ($i % 2 === 0) $breakOuts[] = $punch;
            else              $breakIns[]  = $punch;
        }

        $sortAsc($breakOuts);
        $sortAsc($breakIns);
    }

    // ── Slot 0: time_in (earliest check_in) ──────────────────────────────
    if (!empty($checkIns)) {
        $slots['time_in'] = $checkIns[0]['time'];
    }

    // ── Slot 7: time_out (latest check_out) ──────────────────────────────
    if (!empty($checkOuts)) {
        $slots['time_out'] = end($checkOuts)['time'];
    }

    // ── Slots 3 & 4: lunch (dedicated punch types fill directly) ─────────
    if (!empty($lunchOuts)) $slots['lunch_out'] = $lunchOuts[0]['time'];
    if (!empty($lunchIns))  $slots['lunch_in']  = $lunchIns[0]['time'];

    // ── Break out candidates ──────────────────────────────────────────────
    // lunch_out included only when not already filled by a dedicated punch
    // so that break_out punches can fill it when device has no lunch type.
    $outCandidates = [];
    if (!$isShifting)                 $outCandidates[] = ['slot' => 'break_out_1', 'twIdx' => 1];
    if ($slots['lunch_out'] === null) $outCandidates[] = ['slot' => 'lunch_out',   'twIdx' => 3];
    $outCandidates[]                                   = ['slot' => 'break_out_2', 'twIdx' => 5];

    $this->distributeBreaks($slots, $breakOuts, $outCandidates, $tw, $isNightShift);

    // ── Break in candidates ───────────────────────────────────────────────
    $inCandidates = [];
    if (!$isShifting)                $inCandidates[] = ['slot' => 'break_in_1', 'twIdx' => 2];
    if ($slots['lunch_in'] === null) $inCandidates[] = ['slot' => 'lunch_in',   'twIdx' => 4];
    $inCandidates[]                                  = ['slot' => 'break_in_2', 'twIdx' => 6];

    $this->distributeBreaks($slots, $breakIns, $inCandidates, $tw, $isNightShift);

    // ── Shifting: clear short-break slots ────────────────────────────────
    if ($isShifting) {
        $slots['break_out_1'] = null;
        $slots['break_in_1']  = null;
    }

    // ── Repair misplacements ──────────────────────────────────────────────
    $this->repairSlots($slots);

    return $slots;
}

private function distributeBreaks(
    array &$slots,
    array  $logs,
    array  $candidates,
    array  $tw,
    bool   $isNightShift
): void {
    if (empty($logs)) return;

    $tm = fn(string $t) => $this->toAbsMin($t, $isNightShift);

    // Pre-compute expected minutes for all candidates
    $candidatesWithMin = array_map(fn($c) => [
        'slot'   => $c['slot'],
        'twIdx'  => $c['twIdx'],
        'expMin' => isset($tw[$c['twIdx']]) ? $tm($tw[$c['twIdx']]) : null,
    ], $candidates);

    // Strategy: assign the Nth punch to the Nth available slot.
    // Punches and slots are both in chronological order.
    // Only skip a slot when the punch is clearly too late for it (>90min past
    // expected) AND the next slot is a significantly better fit.
    // This handles Scenario 3 (skipped break) correctly.

    foreach ($logs as $log) {
        $logMin   = $tm($log['time']);
        $assigned = null;

        foreach ($candidatesWithMin as $i => $candidate) {
            $slotName = $candidate['slot'];
            if ($slots[$slotName] !== null) continue; // already filled

            $expMin = $candidate['expMin'];

            // No TIME_WINDOWS → sequential fill
            if ($expMin === null) {
                $assigned = $slotName;
                break;
            }

            // Find next candidate's expected time
            $nextExpMin = null;
            for ($j = $i + 1; $j < count($candidatesWithMin); $j++) {
                if ($candidatesWithMin[$j]['expMin'] !== null) {
                    $nextExpMin = $candidatesWithMin[$j]['expMin'];
                    break;
                }
            }

            // Skip this slot only when BOTH conditions are true:
            //   1. Punch is more than 90min past this slot's expected time
            //   2. Next slot's expected time is a closer match
            // Otherwise always assign to the current (earliest available) slot.
            $tooLate        = $logMin > ($expMin + 90);
            $nextIsBetter   = $nextExpMin !== null
                && abs($logMin - $nextExpMin) < abs($logMin - $expMin);

            if ($tooLate && $nextIsBetter) {
                continue; // skip — employee likely skipped this break
            }

            $assigned = $slotName;
            break;
        }

        // Fallback: first empty candidate (punch outside all expected windows)
        if ($assigned === null) {
            foreach ($candidatesWithMin as $candidate) {
                if ($slots[$candidate['slot']] === null) {
                    $assigned = $candidate['slot'];
                    break;
                }
            }
        }

        if ($assigned !== null) {
            $slots[$assigned] = $log['time'];
        }
    }
}

    // -------------------------------------------------------------------------
    // DEDUPLICATION
    // -------------------------------------------------------------------------

    private const DEDUP_WINDOW_MINUTES = 3;

    /**
     * Collapse accidental duplicate taps of the same punch type that occur
     * within DEDUP_WINDOW_MINUTES of each other. (Scenario 10)
     *
     * In-punches  (check_in, break_in, lunch_in)   → keep EARLIEST in cluster.
     * Out-punches (check_out, break_out, lunch_out) → keep LATEST   in cluster.
     *
     * Punches separated by more than the window are kept as distinct events,
     * so two genuine breaks separated by hours both survive.
     */
    private function deduplicateLogs(array $logs, bool $isNightShift): array
    {
        if (empty($logs)) return [];

        $outTypes = ['check_out', 'break_out', 'lunch_out'];

        // Group by type
        $byType = [];
        foreach ($logs as $log) {
            $byType[$log['type']][] = $log;
        }

        $deduped = [];

        foreach ($byType as $type => $typeLogs) {
            usort($typeLogs, fn($a, $b) =>
                $this->toAbsMin($a['time'], $isNightShift) <=>
                $this->toAbsMin($b['time'], $isNightShift)
            );

            $keepLatest = in_array($type, $outTypes);
            $cluster    = [$typeLogs[0]];

            for ($i = 1; $i < count($typeLogs); $i++) {
                $gap = $this->toAbsMin($typeLogs[$i]['time'], $isNightShift)
                     - $this->toAbsMin(end($cluster)['time'], $isNightShift);

                if ($gap <= self::DEDUP_WINDOW_MINUTES) {
                    $cluster[] = $typeLogs[$i];
                } else {
                    $deduped[] = $keepLatest ? end($cluster) : $cluster[0];
                    $cluster   = [$typeLogs[$i]];
                }
            }

            $deduped[] = $keepLatest ? end($cluster) : $cluster[0];
        }

        usort($deduped, fn($a, $b) =>
            $this->toAbsMin($a['time'], $isNightShift) <=>
            $this->toAbsMin($b['time'], $isNightShift)
        );

        return $deduped;
    }

    // -------------------------------------------------------------------------
    // SLOT REPAIR
    // -------------------------------------------------------------------------

    /**
     * Fix common slot misplacements after the main assignment.
     *
     * These cases arise when an employee has an orphaned break_in or lunch_in
     * with no matching out-punch (Scenario 13), or when break punches land in
     * slightly unexpected slots due to borderline timing.
     *
     * Case 1 — orphaned break_in_1 + lunch_out exists + lunch_in empty:
     *           break_in_1 → lunch_in
     *           (employee had no break_out_1 tap but did tap out of lunch)
     *
     * Case 2 — orphaned break_in_1 + break_out_2 exists + break_in_2 empty:
     *           break_in_1 → break_in_2
     *           (break_in landed one slot too early)
     *
     * Case 3 — orphaned lunch_in + break_out_2 exists + break_in_2 empty:
     *           lunch_in → break_in_2
     *           (lunch_in landed one slot too early)
     */
    private function repairSlots(array &$slots): void
    {
        // Case 1
        if (
            $slots['break_out_1'] === null &&
            $slots['break_in_1']  !== null &&
            $slots['lunch_out']   !== null &&
            $slots['lunch_in']    === null
        ) {
            $slots['lunch_in']   = $slots['break_in_1'];
            $slots['break_in_1'] = null;
        }

        // Case 2
        if (
            $slots['break_out_1'] === null &&
            $slots['break_in_1']  !== null &&
            $slots['break_out_2'] !== null &&
            $slots['break_in_2']  === null
        ) {
            $slots['break_in_2'] = $slots['break_in_1'];
            $slots['break_in_1'] = null;
        }

        // Case 3
        if (
            $slots['lunch_out']   === null &&
            $slots['lunch_in']    !== null &&
            $slots['break_out_2'] !== null &&
            $slots['break_in_2']  === null
        ) {
            $slots['break_in_2'] = $slots['lunch_in'];
            $slots['lunch_in']   = null;
        }
    }

    // -------------------------------------------------------------------------
    // HELPERS
    // -------------------------------------------------------------------------

    /**
     * Convert "HH:MM" to absolute minutes.
     *
     * For night shifts, times before 12:00 belong to the next calendar day,
     * so +1440 is added to keep chronological ordering across midnight.
     * Example: 22:00 = 1320 min, 02:00 next day = 120 + 1440 = 1560 min → correct order.
     */
    private function toAbsMin(string $time, bool $isNightShift = false): int
    {
        if (empty($time)) return 0;
        [$h, $m] = explode(':', $time);
        $total   = (int)$h * 60 + (int)$m;
        return ($isNightShift && (int)$h < 12) ? $total + 1440 : $total;
    }

    /**
     * For employees with no schedule, detect a night-shift pattern by examining
     * punch timestamps around the target date.
     *
     * This is used for Scenarios 11 and 12 (on-leave / no-schedule employees)
     * so their logs are windowed correctly even without TIME_WINDOWS.
     *
     * Detection priority:
     *
     *   Case 1 — check_in exists today:
     *     hour >= 12 → night shift (shift started this evening)
     *     hour <  12 → day shift
     *
     *   Case 2 — no check_in today but check_in yesterday at/after 12:00:
     *     Yesterday was a night shift start. If ALL today's punches are before
     *     noon, they are this shift's tail (check_out, break_in, etc.).
     *
     *   Case 3 — no check_in at all (missed tap — Scenario 5):
     *     Any punch today at/after 18:00 → night shift start
     *     All punches before noon + prev day had evening activity → night tail
     */
    private function detectNightShiftFromLogs(array $empLogs, string $date): bool
    {
        $base     = Carbon::parse($date);
        $prevDate = $base->copy()->subDay()->toDateString();

        $todayPunches = [];
        $prevPunches  = [];

        foreach ($empLogs as $log) {
            $logDate = Carbon::parse($log['datetime'])->toDateString();
            $hour    = (int) explode(':', $log['time'])[0];

            if ($logDate === $date)     $todayPunches[] = ['hour' => $hour, 'type' => $log['type']];
            if ($logDate === $prevDate) $prevPunches[]  = ['hour' => $hour, 'type' => $log['type']];
        }

        usort($todayPunches, fn($a, $b) => $a['hour'] <=> $b['hour']);
        usort($prevPunches,  fn($a, $b) => $a['hour'] <=> $b['hour']);

        // Case 1: check_in today — definitive
        $todayCheckIn = collect($todayPunches)->first(fn($p) => $p['type'] === 'check_in');
        if ($todayCheckIn) {
            return $todayCheckIn['hour'] >= 12;
        }

        // Case 2: check_in yesterday at/after 12:00
        $prevCheckIn = collect($prevPunches)->first(fn($p) => $p['type'] === 'check_in');
        if ($prevCheckIn && $prevCheckIn['hour'] >= 12) {
            $allTodayBeforeNoon = collect($todayPunches)->every(fn($p) => $p['hour'] < 12);
            if ($allTodayBeforeNoon || empty($todayPunches)) {
                return true;
            }
        }

        // Case 3: no check_in at all
        if (!empty($todayPunches)) {
            $anyEvening      = collect($todayPunches)->contains(fn($p) => $p['hour'] >= 18);
            $allEarlyMorning = collect($todayPunches)->every(fn($p) => $p['hour'] < 12);

            if ($anyEvening) return true;

            if ($allEarlyMorning) {
                $prevHadEvening = collect($prevPunches)->contains(fn($p) => $p['hour'] >= 12);
                return $prevHadEvening;
            }
        }

        return false;
    }

private function effectiveIsShifting(
    bool  $isShifting,
    array $logs,
    bool  $isNightShift,
    array $tw = [],
    bool  $originallyShifting = false,
    string $date = ''
): bool {
    if (!$isShifting) {
    // SHIFT = 1 (Normal): Break 1 slots are always enabled regardless
    // of schedule duration. A 12-hour Normal shift still has Break 1.
    return false;
}

    $checkIn  = null;
    $checkOut = null;
    foreach ($logs as $log) {
        if ($log['type'] === 'check_in'  && $checkIn  === null) $checkIn  = $log['time'];
        if ($log['type'] === 'check_out')                       $checkOut = $log['time'];
    }

    if ($checkIn && $checkOut) {
        $inMins   = $this->toAbsMin($checkIn,  $isNightShift);
        $outMins  = $this->toAbsMin($checkOut, $isNightShift);
        $duration = $outMins - $inMins;
        if ($duration < 0) $duration += 1440;

        if (!empty($tw[0]) && !empty($tw[7])) {
            $expectedIn       = $this->toAbsMin($tw[0], $isNightShift);
            $expectedOut      = $this->toAbsMin($tw[7], $isNightShift);
            $expectedDuration = $expectedOut - $expectedIn;
            if ($expectedDuration < 0) $expectedDuration += 1440;

            if ($duration < $expectedDuration - 60) {
                if ($originallyShifting) return true;
                return false;
            }
        } else {
            if ($duration < 660) {
                if ($originallyShifting) return true;
                return false;
            }
        }
    }

    return true;
}
private function emptySlots(): array
{
    return [
        'time_in'     => null,
        'break_out_1' => null,
        'break_in_1'  => null,
        'lunch_out'   => null,
        'lunch_in'    => null,
        'break_out_2' => null,
        'break_in_2'  => null,
        'time_out'    => null,
    ];
}
}