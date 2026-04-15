<?php

namespace App\Domain\Dtr;

class StatusCalculator
{
    // Slot index aliases — mirrors PunchAssigner so both classes speak
    // the same language without a shared base class.
    private const TIME_IN    = 0;
    private const BREAK_OUT1 = 1;
    private const BREAK_IN1  = 2;
    private const LUNCH_OUT  = 3;
    private const LUNCH_IN   = 4;
    private const BREAK_OUT2 = 5;
    private const BREAK_IN2  = 6;
    private const TIME_OUT   = 7;

    /**
     * Derive the display status (remarks) for a single DTR row.
     *
     * @param  array       $exp          8-element expected times
     * @param  array       $act          8-element actual times
     * @param  string      $shiftType    'Day' | 'Night' | 'Rest Day' | 'Unscheduled' | …
     * @param  array|null  $leaveInfo
     * @param  array|null  $holidayInfo
     * @param  array|null  $obInfo
     * @param  string      $date         Y-m-d
     * @param  bool        $isNightShift
     * @param  array       $shiftTimes   Raw shift time windows (for OB coverage check)
     * @param  int         $empShiftType 1 = standard, 2 = compressed
     */
    public function calculate(
        array  $exp,
        array  $act,
        string $shiftType,
        ?array $leaveInfo,
        ?array $holidayInfo,
        ?array $obInfo,
        string $date,
        bool   $isNightShift,
        array  $shiftTimes,
        int    $empShiftType
    ): string {
        if ($shiftType === 'Rest Day') return 'Rest Day';
        if ($holidayInfo)              return 'Holiday';

        if ($leaveInfo) {
            $isHalf = in_array(strtolower($leaveInfo['duration']), ['half-day', 'half day'], true);
            return $leaveInfo['type'] . ($isHalf ? ' (Half)' : '');
        }

        if ($obInfo && $this->isFullShiftOb($shiftTimes, $obInfo)) {
            return $obInfo['type'];
        }

        if ($shiftType === 'Unscheduled') {
            return $this->unscheduledStatus($act);
        }

        return $this->scheduledStatus(
            $exp, $act, $date, $isNightShift, $empShiftType
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // OB HELPERS  (used by service too — kept here to stay DRY)
    // ─────────────────────────────────────────────────────────────────────────

    public function isFullShiftOb(array $shiftTimes, ?array $obInfo): bool
    {
        if (!$obInfo || empty($obInfo['time_from']) || empty($obInfo['time_to'])) return false;
        if (empty($shiftTimes[0]) || empty($shiftTimes[7]))                       return false;

        return strtotime($obInfo['time_from']) <= strtotime($shiftTimes[0])
            && strtotime($obInfo['time_to'])   >= strtotime($shiftTimes[7]);
    }

    public function getObCoveredWindows(array $shiftTimes, ?array $obInfo): array
    {
        $keys    = [
            'check_in', 'break_out1', 'break_in1', 'lunch_out',
            'lunch_in', 'break_out2', 'break_in2', 'check_out',
        ];
        $covered = array_fill_keys($keys, false);

        if (!$obInfo || empty($obInfo['time_from']) || empty($obInfo['time_to'])) {
            return $covered;
        }

        $obFrom  = strtotime($obInfo['time_from']);
        $obTo    = strtotime($obInfo['time_to']);
        $overlap = fn ($s, $e) => ($s <= $obTo && $e >= $obFrom);

        if (!empty($shiftTimes[0])) {
            $ci   = strtotime($shiftTimes[0]);
            $next = !empty($shiftTimes[1])
                ? strtotime($shiftTimes[1])
                : (!empty($shiftTimes[7]) ? strtotime($shiftTimes[7]) : $ci);
            $covered['check_in'] = $overlap($ci, $next);
        }

        foreach ([
            [1, 2, 'break_out1', 'break_in1'],
            [3, 4, 'lunch_out',  'lunch_in'],
            [5, 6, 'break_out2', 'break_in2'],
        ] as [$oi, $ii, $ok, $ik]) {
            if (!empty($shiftTimes[$oi]) && !empty($shiftTimes[$ii])) {
                $covers       = $overlap(strtotime($shiftTimes[$oi]), strtotime($shiftTimes[$ii]));
                $covered[$ok] = $covers;
                $covered[$ik] = $covers;
            } elseif (!empty($shiftTimes[$oi])) {
                $t = strtotime($shiftTimes[$oi]);
                $covered[$ok] = ($t >= $obFrom && $t <= $obTo);
            } elseif (!empty($shiftTimes[$ii])) {
                $t = strtotime($shiftTimes[$ii]);
                $covered[$ik] = ($t >= $obFrom && $t <= $obTo);
            }
        }

        if (!empty($shiftTimes[7])) {
            $co   = strtotime($shiftTimes[7]);
            $prev = strtotime(
                $shiftTimes[6] ?? $shiftTimes[4] ?? $shiftTimes[2] ?? $shiftTimes[0] ?? $shiftTimes[7]
            );
            $covered['check_out'] = $overlap($prev, $co);
        }

        return $covered;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE
    // ─────────────────────────────────────────────────────────────────────────

    private function unscheduledStatus(array $act): string
    {
        $hasIn  = !empty($act[self::TIME_IN]);
        $hasOut = !empty($act[self::TIME_OUT]);

        if (!$hasIn && !$hasOut) return 'Absent';
        if ($hasIn  && !$hasOut) return 'No Check-Out';
        if (!$hasIn && $hasOut)  return 'No Check-In';
        return 'Present';
    }

    private function scheduledStatus(
        array  $exp,
        array  $act,
        string $date,
        bool   $isNightShift,
        int    $empShiftType
    ): string {
        $today       = date('Y-m-d');
        $currentTime = time();

        // ── Today-specific early-exit logic ───────────────────────────────
        if ($date === $today) {
            if (empty($act[self::TIME_IN]) && !empty($exp[self::TIME_IN])) {
                $expIn = strtotime("{$date} {$exp[self::TIME_IN]}");
                if ($currentTime < $expIn)             return 'Current Date';
                if ($currentTime < ($expIn + 7200))    return 'Late';
                return 'Absent';
            }

            if (!empty($act[self::TIME_IN]) && empty($act[self::TIME_OUT]) && !empty($exp[self::TIME_OUT])) {
                $adj    = $isNightShift ? ' +1 day' : '';
                $expOut = strtotime("{$date}{$adj} {$exp[self::TIME_OUT]}");
                return ($currentTime >= ($expOut + 7200)) ? 'No Check-Out' : 'Current Date';
            }
        }

        // ── Standard absent / missing punch checks ─────────────────────────
        if (empty($act[self::TIME_IN]) && empty($act[self::TIME_OUT])) return 'Absent';
        if (empty($act[self::TIME_IN]))  return 'No Check-In';
        if (empty($act[self::TIME_OUT])) return 'No Check-Out';

        // ── Lateness / early-out ───────────────────────────────────────────
        $status = 'Present';

        if (!empty($exp[self::TIME_IN]) && strtotime($act[self::TIME_IN]) > strtotime($exp[self::TIME_IN])) {
            $status = 'Late';
        }

        if (!empty($exp[self::TIME_OUT]) && strtotime($act[self::TIME_OUT]) < strtotime($exp[self::TIME_OUT])) {
            $status = ($status === 'Late') ? 'Late & Early Out' : 'Early Out';
        }

        // ── Break overruns ─────────────────────────────────────────────────
        $breakRemarks = [];

        $break1Allowed = ($empShiftType === 2) ? 60 : 15;
        $lunchAllowed  = 60;
        $break2Allowed = ($empShiftType === 2) ? 30 : 15;

        $overrun = function (int $outSlot, int $inSlot, int $allowed, string $label) use (
            $act, $isNightShift, &$breakRemarks
        ): void {
            if (empty($act[$outSlot]) || empty($act[$inSlot])) return;
            $dur = $this->timeToMinutes($act[$inSlot], $isNightShift)
                 - $this->timeToMinutes($act[$outSlot], $isNightShift);
            if ($dur > $allowed) {
                $breakRemarks[] = "{$label} (" . ($dur - $allowed) . 'm)';
            }
        };

        $overrun(self::BREAK_OUT1, self::BREAK_IN1, $break1Allowed, 'Over Break 1');
        $overrun(self::LUNCH_OUT,  self::LUNCH_IN,  $lunchAllowed,  'Over Lunch');
        $overrun(self::BREAK_OUT2, self::BREAK_IN2, $break2Allowed, 'Over Break 2');

        if (!empty($breakRemarks)) {
            $suffix = implode(' & ', $breakRemarks);
            $status = ($status === 'Present') ? $suffix : "{$status} & {$suffix}";
        }

        return $status;
    }

    private function timeToMinutes(string $time, bool $isNightShift = false): int
    {
        if (empty($time)) return 0;
        [$h, $m] = explode(':', $time);
        $total   = ((int) $h * 60) + (int) $m;
        return ($isNightShift && (int) $h < 12) ? $total + 1440 : $total;
    }
}