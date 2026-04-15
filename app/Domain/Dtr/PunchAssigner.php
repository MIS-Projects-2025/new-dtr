<?php

namespace App\Domain\Dtr;

class PunchAssigner
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

    /**
     * Assign raw biometric punch logs to the 8 canonical time slots.
     *
     * @param  array $shiftTimes  8-element expected times array from ShiftCode
     * @param  array $logs        Normalised punch log entries for the date
     * @param  bool  $isNightShift
     * @param  int   $empShiftType  1 = standard, 2 = compressed (longer breaks)
     * @return array<int, string>  8-element array of assigned times (empty string = not punched)
     */
    public function assign(
        array $shiftTimes,
        array $logs,
        bool  $isNightShift = false,
        int   $empShiftType = 1
    ): array {
        $assigned = array_fill(0, 8, '');

        if (empty($logs)) {
            return $assigned;
        }

        $tmFn = fn ($t) => $this->timeToMinutes($t, $isNightShift);

        $sortByTime = function (array &$arr) use ($tmFn): void {
            usort($arr, fn ($a, $b) => $tmFn($a['time']) <=> $tmFn($b['time']));
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

        // Keep only the earliest check-in; extras must not be promoted to break slots
        if (count($checkInLogs) > 1) {
            usort($checkInLogs, fn ($a, $b) => $tmFn($a['time']) <=> $tmFn($b['time']));
            $checkInLogs = [array_shift($checkInLogs)];
        }

        // Promote middle check punches to break pairs
        if (count($checkInLogs) + count($checkOutLogs) > 2) {
            $all = array_merge($checkInLogs, $checkOutLogs);
            usort($all, fn ($a, $b) => $tmFn($a['time']) <=> $tmFn($b['time']));
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

        $skipBreak1 = ($empShiftType === 2);

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

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function assignWithSchedule(
        array   &$assigned,
        array    $shiftTimes,
        array    $breakOutLogs,
        array    $breakInLogs,
        array    $lunchOutLogs,
        array    $lunchInLogs,
        bool     $skipBreak1,
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

        // Fallback: if schedule-based assignment placed nothing, use heuristic
        $anyAssigned = false;
        foreach ([
            self::BREAK_OUT1, self::BREAK_IN1, self::LUNCH_OUT,
            self::LUNCH_IN, self::BREAK_OUT2, self::BREAK_IN2,
        ] as $slot) {
            if (!empty($assigned[$slot])) {
                $anyAssigned = true;
                break;
            }
        }

        if (!$anyAssigned && (!empty($breakOutLogs) || !empty($breakInLogs))) {
            $this->assignHeuristic(
                $assigned,
                $breakOutLogs, $breakInLogs,
                [], [],
                $skipBreak1, $tmFn, false
            );
        }
    }

    private function assignHeuristic(
        array   &$assigned,
        array    $breakOutLogs,
        array    $breakInLogs,
        array    $lunchOutLogs,
        array    $lunchInLogs,
        bool     $skipBreak1,
        callable $tmFn,
        bool     $isNightShift
    ): void {
        if (!empty($lunchOutLogs)) $assigned[self::LUNCH_OUT] = $lunchOutLogs[0]['time'];
        if (!empty($lunchInLogs))  $assigned[self::LUNCH_IN]  = $lunchInLogs[0]['time'];

        $usedIns = [];
        $pairs   = [];

        foreach ($breakOutLogs as $oi => $bo) {
            $outMin  = $tmFn($bo['time']);
            $closest = null;
            $minDiff = PHP_INT_MAX;

            foreach ($breakInLogs as $ii => $bi) {
                if (in_array($ii, $usedIns)) continue;
                $diff = $tmFn($bi['time']) - $outMin;
                if ($diff < 0 && $isNightShift) $diff += 1440;
                if ($diff > 0 && $diff <= 180 && $diff < $minDiff) {
                    $minDiff = $diff;
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
            fn ($s) => empty($assigned[$s])
        ));
        $inSlots = array_values(array_filter(
            $skipBreak1
                ? [self::LUNCH_IN, self::BREAK_IN2]
                : [self::BREAK_IN1, self::LUNCH_IN, self::BREAK_IN2],
            fn ($s) => empty($assigned[$s])
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
        // B1-Out empty + B1-In filled + Lunch-Out filled + Lunch-In empty
        if (
            empty($assigned[self::BREAK_OUT1]) &&
            !empty($assigned[self::BREAK_IN1]) &&
            !empty($assigned[self::LUNCH_OUT]) &&
            empty($assigned[self::LUNCH_IN])
        ) {
            $assigned[self::LUNCH_IN]  = $assigned[self::BREAK_IN1];
            $assigned[self::BREAK_IN1] = '';
        }

        // B1-Out empty + B1-In filled + B2-Out filled + B2-In empty (no lunch)
        if (
            empty($assigned[self::BREAK_OUT1]) &&
            !empty($assigned[self::BREAK_IN1]) &&
            empty($assigned[self::LUNCH_OUT]) &&
            !empty($assigned[self::BREAK_OUT2]) &&
            empty($assigned[self::BREAK_IN2])
        ) {
            $assigned[self::BREAK_IN2] = $assigned[self::BREAK_IN1];
            $assigned[self::BREAK_IN1] = '';
        }

        // Lunch-Out empty + Lunch-In filled + B2-Out filled + B2-In empty
        if (
            empty($assigned[self::LUNCH_OUT]) &&
            !empty($assigned[self::LUNCH_IN]) &&
            !empty($assigned[self::BREAK_OUT2]) &&
            empty($assigned[self::BREAK_IN2])
        ) {
            $assigned[self::BREAK_IN2] = $assigned[self::LUNCH_IN];
            $assigned[self::LUNCH_IN]  = '';
        }
    }

    private function timeToMinutes(string $time, bool $isNightShift = false): int
    {
        if (empty($time)) return 0;
        [$h, $m] = explode(':', $time);
        $total   = ((int) $h * 60) + (int) $m;
        return ($isNightShift && (int) $h < 12) ? $total + 1440 : $total;
    }
}