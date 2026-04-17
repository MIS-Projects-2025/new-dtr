<?php
// app/Services/AttendanceService.php
namespace App\Services;

use App\Repositories\AttendanceRepository;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AttendanceService
{
    public function __construct(
        private AttendanceRepository $attendanceRepo
    ) {}

    // ── Reused by Employee + Admin attendance counter ─────────────────────
    public function computeCounter(
        string $empId,
        string $startDate,
        string $endDate,
        array  $shiftMap   // dateKey => ShiftCode model|null
    ): array {
        $start   = Carbon::parse($startDate)->startOfDay();
        $end     = Carbon::parse($endDate)->endOfDay();
        $today   = Carbon::today();

        if ($end->gt($today)) {
            $end = $today->copy()->endOfDay();
        }

        $result = [
            'present' => 0, 'absent'  => 0,
            'late'    => 0, 'restday' => 0,
            'present_dates' => [], 'absent_dates' => [],
            'late_dates'    => [], 'restday_dates' => [],
        ];

        $current = $start->copy();
        while ($current->lte($end)) {
            $dateKey  = $current->format('Y-m-d');
            $label    = $current->format('M d, Y');
            $shift    = $shiftMap[$dateKey] ?? null;
            $isRestDay = $this->isRestDayShift($shift);

            $hasIn  = $this->attendanceRepo->hasCheckIn($empId,  $dateKey);
            $hasOut = $this->attendanceRepo->hasCheckOut($empId, $dateKey);
            $hasAny = $hasIn || $hasOut;

            if ($isRestDay) {
                if ($hasAny) {
                    $result['present']++;
                    $result['present_dates'][] = $label;
                } else {
                    $result['restday']++;
                    $result['restday_dates'][] = $label;
                }
            } elseif ($hasAny) {
                $result['present']++;
                $result['present_dates'][] = $label;

                // Late check
                if ($hasIn && $shift && !empty($shift->TIME_WINDOWS[0])) {
                    $earliest  = $this->attendanceRepo->getEarliestCheckIn($empId, $dateKey);
                    $expected  = Carbon::parse($dateKey . ' ' . $shift->TIME_WINDOWS[0]);
                    if ($earliest && $earliest->gt($expected)) {
                        $result['late']++;
                        $result['late_dates'][] = $label;
                    }
                }
            } else {
                $result['absent']++;
                $result['absent_dates'][] = $label;
            }

            $current->addDay();
        }

        return $result;
    }

    // ── Shift log resolution (used by getShiftLogs endpoint) ─────────────
    public function resolveShiftLogs(
        string  $empId,
        string  $date,
        ?object $shiftCode  // ShiftCode model or null
    ): array {
        $logTypes    = ['check_in', 'check_out', 'break_out1', 'break_in1', 'break_out2', 'break_in2', 'lunch_out', 'lunch_in'];
        $result      = array_fill_keys($logTypes, null);
        $timeWindows = $shiftCode?->TIME_WINDOWS ?? [];
        $targetDate  = Carbon::parse($date);

        $isRestDay    = $this->isRestDayShift($shiftCode);
        $isNightShift = $this->detectNightShift($timeWindows);

        $queryDates = $isRestDay
            ? [$date]
            : ($isNightShift ? [$date, $targetDate->copy()->addDay()->toDateString()] : [$date]);

        $windowFilter = $this->buildWindowFilter($date, $timeWindows, $isNightShift, $isRestDay);

        $allLogs = $this->attendanceRepo->getLogsForDate($empId, $date, $logTypes);

        // For multi-date (night shift), also fetch next day
        if ($isNightShift) {
            $nextDate    = $targetDate->copy()->addDay()->toDateString();
            $nextDayLogs = $this->attendanceRepo->getLogsForDate($empId, $nextDate, $logTypes);
            $allLogs     = $allLogs->concat($nextDayLogs);
        }

        $allLogs = $allLogs->filter(fn($l) => $windowFilter($l['timestamp']));

        // Deduplicate biometric within 1 hour buckets
        $deduped = $this->deduplicateLogs($allLogs);

        // Assign biometric break/lunch logs to named slots
        $assigned = $this->assignBreakSlots($deduped, $timeWindows, $isNightShift, $isRestDay);

        $final = $assigned
            ->groupBy('log_type')
            ->map(fn($group) => $group->sortByDesc('priority')->first()['time']);

        return array_merge($result, $final->toArray());
    }

    // ── Helpers ───────────────────────────────────────────────────────────
    private function isRestDayShift(?object $shift): bool
    {
        return $shift && str_contains($shift->SHIFTCODE ?? '', 'RD');
    }

    private function detectNightShift(array $timeWindows): bool
    {
        $start = $this->toMinutes($timeWindows[0] ?? null);
        $end   = $this->toMinutes($timeWindows[7] ?? null);
        return $start !== null && $end !== null && $end < $start;
    }

    private function buildWindowFilter(string $date, array $tw, bool $isNight, bool $isRestDay): \Closure
    {
        $start = $this->toMinutes($tw[0] ?? null);
        $end   = $this->toMinutes($tw[7] ?? null);

        if ($isRestDay || $start === null || $end === null) {
            return fn($ts) => Carbon::createFromTimestamp($ts)->toDateString() === $date;
        }

        $target      = Carbon::parse($date);
        $windowStart = $target->copy()->startOfDay()->addMinutes($start)->subMinutes(60);
        $windowEnd   = $isNight
            ? $target->copy()->addDay()->startOfDay()->addMinutes($end)->addMinutes(60)
            : $target->copy()->startOfDay()->addMinutes($end)->addMinutes(60);

        return fn($ts) => Carbon::createFromTimestamp($ts)->between($windowStart, $windowEnd);
    }

    private function deduplicateLogs(Collection $logs): Collection
    {
        $seen = [];
        return $logs->sortBy('timestamp')->filter(function ($log) use (&$seen) {
            $bucket = $log['log_type'] . '_' . round($log['timestamp'] / 3600);
            if (isset($seen[$bucket]) && $log['priority'] <= $seen[$bucket]['priority']) return false;
            $seen[$bucket] = $log;
            return true;
        })->values();
    }

    private function assignBreakSlots(Collection $logs, array $tw, bool $isNight, bool $isRestDay): Collection
    {
        if ($isRestDay || empty($tw)) return $logs;

        $normalize = fn(?int $m) => ($isNight && $m !== null && $m < ($this->toMinutes($tw[0] ?? null) ?? 0))
            ? $m + 1440
            : $m;

        $slotPools = [
            'break_out' => [
                'break_out1' => $normalize($this->toMinutes($tw[1] ?? null)),
                'lunch_out'  => $normalize($this->toMinutes($tw[3] ?? null)),
                'break_out2' => $normalize($this->toMinutes($tw[5] ?? null)),
            ],
            'break_in' => [
                'break_in1' => $normalize($this->toMinutes($tw[2] ?? null)),
                'lunch_in'  => $normalize($this->toMinutes($tw[4] ?? null)),
                'break_in2' => $normalize($this->toMinutes($tw[6] ?? null)),
            ],
        ];

        $result = $logs->filter(fn($l) => in_array($l['log_type'], ['check_in', 'check_out']))->values();

        foreach (['break_out', 'break_in'] as $type) {
            $group = $logs->filter(fn($l) => $l['log_type'] === $type)->sortBy('timestamp')->values();
            $slots = collect($slotPools[$type])->filter()->sortBy(fn($v) => $v);

            $group->each(function ($log, $i) use ($slots, &$result) {
                $slotKey = $slots->keys()->get($i);
                if (!$slotKey) return;
                $result->push(array_merge($log, ['log_type' => $slotKey]));
            });
        }

        return $result;
    }

    private function toMinutes(?string $time): ?int
    {
        if (!$time) return null;
        [$h, $m] = explode(':', $time);
        return ((int) $h * 60) + (int) $m;
    }
}