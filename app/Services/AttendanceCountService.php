<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\BiometricLog;
use App\Models\BiometricLogManual;
use App\Models\EmployeeLeave;
use App\Models\Holiday;
use App\Models\ObRecord;
use App\Models\ShiftCode;
use App\Models\WorkScheduler;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class AttendanceCountService
{
    public function countForEmployee(string $employId, string $startDate, string $endDate): array
    {
        $counters = ['present' => 0, 'absent' => 0, 'late' => 0, 'restday' => 0];

        // ── Shift codes ───────────────────────────────────────────────────
        $shiftCodes      = ShiftCode::all();
        $shiftCodeById   = $shiftCodes->keyBy('SHIFT_CODE_ID');
        $shiftCodeByCode = $shiftCodes->keyBy('SHIFTCODE');

        // ── Holidays ──────────────────────────────────────────────────────
        $holidays = Holiday::whereDate('HOLIDAY_DATE', '>=', $startDate)
            ->whereDate('HOLIDAY_DATE', '<=', $endDate)
            ->pluck('HOLIDAY_DATE')
            ->map(fn($d) => Carbon::parse($d)->toDateString())
            ->flip()
            ->all();

        // ── Approved leaves ───────────────────────────────────────────────
        $leaveDates = [];
        EmployeeLeave::where('EMPLOYID', $employId)
            ->where('LEAVESTATUS', 'approved')
            ->whereDate('DATESTART', '<=', $endDate)
            ->whereDate('DATEEND',   '>=', $startDate)
            ->get(['DATESTART', 'DATEEND'])
            ->each(function ($leave) use (&$leaveDates) {
                $cur = Carbon::parse($leave->DATESTART);
                $end = Carbon::parse($leave->DATEEND);
                while ($cur->lte($end)) {
                    $leaveDates[$cur->toDateString()] = true;
                    $cur->addDay();
                }
            });

        // ── OB records ────────────────────────────────────────────────────
        $obDates = [];
        ObRecord::where('EMPID', $employId)
            ->whereIn('STATUS', ['1', '2'])
            ->whereDate('DATE_OB_FROM', '<=', $endDate)
            ->whereDate('DATE_OB_TO',   '>=', $startDate)
            ->get(['DATE_OB_FROM', 'DATE_OB_TO'])
            ->each(function ($ob) use (&$obDates) {
                $cur = Carbon::parse($ob->DATE_OB_FROM);
                $end = Carbon::parse($ob->DATE_OB_TO);
                while ($cur->lte($end)) {
                    $obDates[$cur->toDateString()] = true;
                    $cur->addDay();
                }
            });

        // ── Scheduled dates (Rest Day vs Working Day) ─────────────────────
        // Mirrors vanilla: checks SHIFTCODE_DESC for 'RD' or 'REST'
        $scheduledDates = [];
        WorkScheduler::where('EMPID', $employId)
            ->where('PAYROLL_DATE_START', '<=', $endDate)
            ->where(function ($q) use ($startDate) {
                $q->whereNull('PAYROLL_DATE_END')
                  ->orWhere('PAYROLL_DATE_END', '>=', $startDate);
            })
            ->orderBy('PAYROLL_DATE_START')
            ->get(['PAYROLL_DATE_START', 'PAYROLL_DATE_END', 'SCHEDULE'])
            ->each(function ($sched) use (&$scheduledDates, $startDate, $endDate, $shiftCodeById, $shiftCodeByCode) {
                $schedArr = is_array($sched->SCHEDULE)
                    ? $sched->SCHEDULE
                    : json_decode($sched->SCHEDULE, true);

                if (!$schedArr) return;

                $start = Carbon::parse($sched->PAYROLL_DATE_START);

                foreach ($schedArr as $day => $shiftId) {
                    $date    = $start->copy()->addDays($day - 1);
                    $dateStr = $date->toDateString();

                    if ($dateStr < $startDate || $dateStr > $endDate) continue;

                    $shift = $shiftCodeById->get((string) $shiftId)
                          ?? $shiftCodeByCode->get((string) $shiftId);

                    if (!$shift) continue;

                    // Mirror vanilla: check SHIFTCODE (name) for RD
                    $code = strtoupper($shift->SHIFTCODE ?? '');
                    $desc = strtoupper($shift->SHIFTCODE_DESC ?? '');

                    $isRestDay = str_contains($code, 'RD')
                              || str_contains($desc, 'RD')
                              || str_contains($desc, 'REST');

                    $scheduledDates[$dateStr] = $isRestDay ? 'Rest Day' : 'Working Day';
                }
            });

        // ── Bio logs grouped by date ───────────────────────────────────────
        $bioLogs = BiometricLog::where('employid', $employId)
            ->whereDate('datetime', '>=', $startDate)
            ->whereDate('datetime', '<=', $endDate)
            ->orderBy('datetime')
            ->get(['datetime', 'punch_type'])
            ->concat(
                BiometricLogManual::where('employid', $employId)
                    ->whereDate('datetime', '>=', $startDate)
                    ->whereDate('datetime', '<=', $endDate)
                    ->orderBy('datetime')
                    ->get(['datetime', 'punch_type'])
            )
            ->concat(
                AttendanceLog::where('employid', $employId)
                    ->whereDate('logged_at', '>=', $startDate)
                    ->whereDate('logged_at', '<=', $endDate)
                    ->orderBy('logged_at')
                    ->get(['logged_at as datetime', 'log_type as punch_type'])
            )
            ->groupBy(fn($log) => Carbon::parse($log->datetime)->toDateString());

        // ── Walk each day ─────────────────────────────────────────────────
        $today = Carbon::today();

        foreach (CarbonPeriod::create($startDate, $endDate) as $day) {
            $dateStr = $day->toDateString();

            // Skip unscheduled days entirely — mirrors vanilla behaviour
            if (!isset($scheduledDates[$dateStr])) continue;

            // Rest Day
            if ($scheduledDates[$dateStr] === 'Rest Day') {
                if (!$day->isFuture()) $counters['restday']++;
                continue;
            }

            // Holiday → Rest Day bucket
            if (isset($holidays[$dateStr])) {
                if (!$day->isFuture()) $counters['restday']++;
                continue;
            }

            // Approved Leave → Present
            if (isset($leaveDates[$dateStr])) {
                $counters['present']++;
                continue;
            }

            // OB / PB → Present
            if (isset($obDates[$dateStr])) {
                $counters['present']++;
                continue;
            }

            // Bio logs
            $dayLogs    = $bioLogs->get($dateStr, collect());
            $hasCheckIn = $dayLogs->contains(
                fn($l) => in_array(strtolower($l->punch_type), ['check_in', '0'])
            );
            $hasCheckOut = $dayLogs->contains(
                fn($l) => in_array(strtolower($l->punch_type), ['check_out', '1'])
            );

            if (!$hasCheckIn && !$hasCheckOut) {
                // No logs — absent only if date already passed
                if (!$day->isFuture()) $counters['absent']++;
                continue;
            }

            if ($hasCheckIn && $hasCheckOut) {
                // Check for late
                $shift = $scheduledDates[$dateStr] ?? null;
                $expectedCheckIn = $this->getExpectedCheckIn($dateStr, $employId, $shiftCodeById, $shiftCodeByCode);

                if ($expectedCheckIn) {
                    $firstIn = $dayLogs
                        ->filter(fn($l) => in_array(strtolower($l->punch_type), ['check_in', '0']))
                        ->sortBy('datetime')
                        ->first();

                    $actualTime   = strtotime(Carbon::parse($firstIn->datetime)->format('H:i'));
                    $expectedTime = strtotime($expectedCheckIn);

                    if ($actualTime > $expectedTime) {
                        $counters['late']++;
                    } else {
                        $counters['present']++;
                    }
                } else {
                    $counters['present']++;
                }
                continue;
            }

            // Only check-in or only check-out → Present with issues
            $counters['present']++;
        }

        return $counters;
    }

    /**
     * Get expected check-in time for a specific date.
     * Returns HH:MM string or null.
     */
    private function getExpectedCheckIn(
        string $dateStr,
        string $employId,
        $shiftCodeById,
        $shiftCodeByCode
    ): ?string {
        $sched = WorkScheduler::where('EMPID', $employId)
            ->where('PAYROLL_DATE_START', '<=', $dateStr)
            ->where(function ($q) use ($dateStr) {
                $q->whereNull('PAYROLL_DATE_END')
                  ->orWhere('PAYROLL_DATE_END', '>=', $dateStr);
            })
            ->orderByDesc('PAYROLL_DATE_START')
            ->first(['PAYROLL_DATE_START', 'SCHEDULE']);

        if (!$sched) return null;

        $schedArr = is_array($sched->SCHEDULE)
            ? $sched->SCHEDULE
            : json_decode($sched->SCHEDULE, true);

        if (!$schedArr) return null;

        $dayIndex = Carbon::parse($sched->PAYROLL_DATE_START)
            ->startOf('day')
            ->diffInDays(Carbon::parse($dateStr)->startOf('day')) + 1;

        $shiftId = (string) ($schedArr[$dayIndex] ?? '');
        if (!$shiftId || $shiftId === '0') return null;

        $shift = $shiftCodeById->get($shiftId) ?? $shiftCodeByCode->get($shiftId);
        if (!$shift || empty($shift->TIME_WINDOWS)) return null;

        $times = is_array($shift->TIME_WINDOWS)
            ? $shift->TIME_WINDOWS
            : json_decode($shift->TIME_WINDOWS, true);

        return !empty($times[0]) ? $times[0] : null;
    }
}