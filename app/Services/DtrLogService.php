<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\BiometricLog;
use App\Models\BiometricLogManual;
use App\Models\VPLog;
use Carbon\Carbon;

class DtrLogService
{
    /**
     * Resolve today's actual logs for a batch of employees in one go.
     * Returns a keyed array: [ employid => resolved_slots ]
     *
     * @param  array  $employIds   List of EMPLOYID strings
     * @param  array  $timeWindowsMap  [ employid => TIME_WINDOWS array ]
     * @param  array  $scheduleTypeMap [ employid => 'Normal' | 'Shifting' ]
     * @param  string $date        The date to fetch logs for (Y-m-d)
     * @return array
     */
    public function resolveLogsForEmployees(
        array  $employIds,
        array  $timeWindowsMap,
        array  $scheduleTypeMap,
        string $date = ''
    ): array {
        $today     = !empty($date) ? $date : now()->toDateString();
        $tomorrow  = Carbon::parse($today)->addDay()->toDateString();
        $yesterday = Carbon::parse($today)->subDay()->toDateString();

        // ── Determine which employees are on night shift ──────────────────────
        $nightShiftIds = [];
        $dayShiftIds   = [];

        foreach ($employIds as $employId) {
            $tw        = $timeWindowsMap[$employId] ?? [];
            $firstTime = $tw[0] ?? null;
            $isNight   = false;

            if ($firstTime) {
                $firstHour = (int) explode(':', $firstTime)[0];
                // Night shift if start time is between 18:00-23:59
                $isNight = $firstHour >= 18;
            }

            if ($isNight) {
                $nightShiftIds[] = $employId;
            } else {
                $dayShiftIds[] = $employId;
            }
        }

        // ── Fetch logs from biometric tables ───────────────────────────────────
        $allBioLogs = $this->fetchBiometricLogs($employIds, $today, $tomorrow, $yesterday);
        
        // ── Group logs by employee ─────────────────────────────────────────────
        $logsByEmployee = [];
        foreach ($allBioLogs as $log) {
            $empId = $log['employid'];
            if (!isset($logsByEmployee[$empId])) {
                $logsByEmployee[$empId] = [];
            }
            $logsByEmployee[$empId][] = $log;
        }

        // ── Resolve per employee ──────────────────────────────────────────────
        $result = [];

        foreach ($employIds as $employId) {
            $tw           = $timeWindowsMap[$employId] ?? [];
            $scheduleType = $scheduleTypeMap[$employId] ?? 'Normal';
            $isShifting   = $scheduleType === 'Shifting';
            
            // Get logs for this employee
            $employeeLogs = $logsByEmployee[$employId] ?? [];
            
            // Determine if this is a night shift
            $firstTime = $tw[0] ?? null;
            $isNightShift = false;
            if ($firstTime) {
                $firstHour = (int) explode(':', $firstTime)[0];
                $isNightShift = $firstHour >= 18;
            }
            
            // Get logs for the date (handles night shift boundary)
            $logsForDate = $this->getLogsForDate($today, $isNightShift, $employeeLogs);
            
            // Deduplicate logs (keep within 5 minutes)
            $logsForDate = $this->deduplicateLogs($logsForDate, $isNightShift);
            
            // Suppress orphan stray punches (no check_in AND no check_out)
            $hasCI = false;
            $hasCO = false;
            foreach ($logsForDate as $log) {
                $type = strtolower($log['type']);
                if (str_contains($type, 'check_in')) $hasCI = true;
                if (str_contains($type, 'check_out')) $hasCO = true;
            }
            if (!$hasCI && !$hasCO && !empty($logsForDate)) {
                $logsForDate = [];
            }
            
            // Assign punches to slots
            $empShiftType = $scheduleType === 'Shifting' ? 2 : 1;
            $assigned = $this->assignPunches($tw, $logsForDate, $isNightShift, $empShiftType);
            
            // Build result slots
            $slots = $this->emptySlots();
            $slots['time_in'] = $assigned[0] ?? null;
            $slots['break_out_1'] = $assigned[1] ?? null;
            $slots['break_in_1'] = $assigned[2] ?? null;
            $slots['lunch_out'] = $assigned[3] ?? null;
            $slots['lunch_in'] = $assigned[4] ?? null;
            $slots['break_out_2'] = $assigned[5] ?? null;
            $slots['break_in_2'] = $assigned[6] ?? null;
            $slots['time_out'] = $assigned[7] ?? null;
            
            // Null out disabled slots for Shifting schedule (skip break 1)
            if ($isShifting) {
                $slots['break_out_1'] = null;
                $slots['break_in_1'] = null;
            }
            
            $result[$employId] = $slots;
        }

        return $result;
    }
    
    /**
     * Fetch biometric logs for employees from both automatic and manual tables
     */
    private function fetchBiometricLogs(array $employIds, string $today, string $tomorrow, string $yesterday): array
    {
        $logs = [];
        
        // For night shifts, we need logs from yesterday through tomorrow
        // For day shifts, just today is enough
        $startDate = $yesterday;
        $endDate = $tomorrow;
        
        // Query automatic biometric logs
        $autoLogs = BiometricLog::whereIn('employid', $employIds)
            ->whereBetween('datetime', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->orderBy('datetime', 'asc')
            ->get(['employid', 'datetime', 'punch_type']);
            
        foreach ($autoLogs as $log) {
            $datetime = Carbon::parse($log->datetime);
            $logs[] = [
                'employid' => $log->employid,
                'datetime' => $log->datetime,
                'date' => $datetime->toDateString(),
                'time' => $datetime->format('H:i'),
                'type' => $this->mapPunchType($log->punch_type),
                'source' => 'automatic',
            ];
        }
        
        // Query manual biometric logs
        $manualLogs = BiometricLogManual::whereIn('employid', $employIds)
            ->whereBetween('datetime', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->orderBy('datetime', 'asc')
            ->get(['employid', 'datetime', 'punch_type']);
            
        foreach ($manualLogs as $log) {
            $datetime = Carbon::parse($log->datetime);
            $logs[] = [
                'employid' => $log->employid,
                'datetime' => $log->datetime,
                'date' => $datetime->toDateString(),
                'time' => $datetime->format('H:i'),
                'type' => $this->mapPunchType($log->punch_type),
                'source' => 'manual',
            ];
        }
        
        // Sort by datetime
        usort($logs, function($a, $b) {
            return strtotime($a['datetime']) - strtotime($b['datetime']);
        });
        
        return $logs;
    }
    
    /**
     * Map punch type from database to standard type
     */
    private function mapPunchType($punchType): string
    {
        $mapping = [
            '0' => 'check_in',
            '1' => 'check_out',
            '2' => 'break_out',
            '3' => 'break_in',
            '4' => 'lunch_out',
            '5' => 'lunch_in',
        ];
        
        $type = strtolower($punchType);
        return $mapping[$type] ?? $type;
    }
    
    /**
     * Get logs for a specific date, handling night shift boundary
     */
    private function getLogsForDate(string $date, bool $isNightShift, array $logs): array
    {
        $result = [];
        
        if ($isNightShift) {
            $nextDate = date('Y-m-d', strtotime($date . ' +1 day'));
            
            // Get logs from current date (after 12:00)
            foreach ($logs as $log) {
                if ($log['date'] === $date) {
                    $hour = (int) date('H', strtotime($log['time']));
                    if ($hour >= 12) {
                        $result[] = $log;
                    }
                }
            }
            
            // Get logs from next date (before 07:00, or before 08:00 for check_out)
            foreach ($logs as $log) {
                if ($log['date'] === $nextDate) {
                    $hour = (int) date('H', strtotime($log['time']));
                    $type = strtolower($log['type']);
                    $isCheckout = str_contains($type, 'check_out');
                    if ($hour <= 5 || ($hour <= 7 && $isCheckout)) {
                        $result[] = $log;
                    }
                }
            }
        } else {
            // Day shift - only logs from the current date
            // Exclude early morning logs that belong to previous night shift
            $prevDate = date('Y-m-d', strtotime($date . ' -1 day'));
            $prevDayHasNightShift = $this->checkPreviousDayNightShift($logs, $prevDate);
            
            foreach ($logs as $log) {
                if ($log['date'] === $date) {
                    $hour = (int) date('H', strtotime($log['time']));
                    // Skip early morning logs if previous day was a night shift
                    if ($prevDayHasNightShift && $hour <= 7) {
                        continue;
                    }
                    $result[] = $log;
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Check if previous day had a night shift
     */
    private function checkPreviousDayNightShift(array $logs, string $prevDate): bool
    {
        foreach ($logs as $log) {
            if ($log['date'] === $prevDate) {
                $hour = (int) date('H', strtotime($log['time']));
                $type = strtolower($log['type']);
                $isCheckIn = str_contains($type, 'check_in');
                if ($isCheckIn && $hour >= 18) {
                    return true;
                }
            }
        }
        return false;
    }
    
    /**
     * Deduplicate logs - keep earliest for check_in/break_in/lunch_in,
     * keep latest for check_out/break_out/lunch_out
     */
    private function deduplicateLogs(array $logs, bool $isNightShift): array
    {
        if (empty($logs)) return $logs;
        
        $toMin = function($time) use ($isNightShift) {
            if (empty($time)) return 0;
            $parts = explode(':', $time);
            $total = ((int)$parts[0] * 60) + (int)$parts[1];
            return ($isNightShift && (int)$parts[0] < 12) ? $total + 1440 : $total;
        };
        
        $groups = [];
        foreach ($logs as $log) {
            $groups[$log['type']][] = $log;
        }
        
        $outTypes = ['break_out', 'lunch_out', 'check_out'];
        $inTypes = ['break_in', 'lunch_in', 'check_in'];
        
        $deduped = [];
        
        foreach ($groups as $type => $typeLogs) {
            usort($typeLogs, fn($a, $b) => $toMin($a['time']) <=> $toMin($b['time']));
            
            $keepLatest = in_array($type, $outTypes);
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
                    $window = [$typeLogs[$i]];
                }
            }
            $merged[] = $keepLatest ? end($window) : $window[0];
            
            foreach ($merged as $l) $deduped[] = $l;
        }
        
        return $deduped;
    }
    
    /**
     * Assign punches to 8 slots based on schedule times and actual logs
     * Slots: 0=check_in, 1=break_out1, 2=break_in1, 3=lunch_out, 4=lunch_in,
     *        5=break_out2, 6=break_in2, 7=check_out
     */
    private function assignPunches(array $shiftTimes, array $logs, bool $isNightShift, int $shiftType = 1): array
    {
        $assigned = array_fill(0, 8, '');
        if (empty($logs)) return $assigned;
        
        $checkInLogs = [];
        $checkOutLogs = [];
        $breakOutLogs = [];
        $breakInLogs = [];
        $lunchOutLogs = [];
        $lunchInLogs = [];
        
        foreach ($logs as $log) {
            switch ($log['type']) {
                case 'check_in': $checkInLogs[] = $log; break;
                case 'check_out': $checkOutLogs[] = $log; break;
                case 'break_out': $breakOutLogs[] = $log; break;
                case 'break_in': $breakInLogs[] = $log; break;
                case 'lunch_out': $lunchOutLogs[] = $log; break;
                case 'lunch_in': $lunchInLogs[] = $log; break;
            }
        }
        
        $tmFn = fn($t) => $this->timeToMinutes($t, $isNightShift);
        
        $sortByTime = function (&$arr) use ($tmFn) {
            usort($arr, fn($a, $b) => $tmFn($a['time']) <=> $tmFn($b['time']));
        };
        
        // If device emits everything as check_in/check_out, promote middle punches
        if (count($checkInLogs) + count($checkOutLogs) > 2) {
            $allCheckLogs = array_merge($checkInLogs, $checkOutLogs);
            usort($allCheckLogs, fn($a, $b) => $tmFn($a['time']) <=> $tmFn($b['time']));
            
            $checkInLogs = [array_shift($allCheckLogs)];
            $checkOutLogs = [array_pop($allCheckLogs)];
            
            foreach ($allCheckLogs as $i => $punch) {
                if ($i % 2 === 0) {
                    $breakOutLogs[] = $punch;
                } else {
                    $breakInLogs[] = $punch;
                }
            }
        }
        
        // Slot 0: earliest check_in
        $sortByTime($checkInLogs);
        if (!empty($checkInLogs)) $assigned[0] = $checkInLogs[0]['time'];
        
        // Slot 7: latest check_out
        $sortByTime($checkOutLogs);
        if (!empty($checkOutLogs)) $assigned[7] = end($checkOutLogs)['time'];
        
        $sortByTime($breakOutLogs);
        $sortByTime($breakInLogs);
        $sortByTime($lunchOutLogs);
        $sortByTime($lunchInLogs);
        
        $skipBreak1 = ($shiftType === 2);
        
        // PATH A: TIME_WINDOWS-aware assignment
        if (!empty($shiftTimes)) {
            // Assign dedicated lunch punches
            if (!empty($lunchOutLogs) && !empty($shiftTimes[3]))
                $assigned[3] = $lunchOutLogs[0]['time'];
            if (!empty($lunchInLogs) && !empty($shiftTimes[4]))
                $assigned[4] = $lunchInLogs[0]['time'];
            
            // Build ordered slot lists
            $outSlots = [];
            if (!$skipBreak1 && !empty($shiftTimes[1]))
                $outSlots[] = ['slot' => 1, 'exp' => $tmFn($shiftTimes[1])];
            if (!empty($shiftTimes[3]))
                $outSlots[] = ['slot' => 3, 'exp' => $tmFn($shiftTimes[3])];
            if (!empty($shiftTimes[5]))
                $outSlots[] = ['slot' => 5, 'exp' => $tmFn($shiftTimes[5])];
            
            $inSlots = [];
            if (!$skipBreak1 && !empty($shiftTimes[2]))
                $inSlots[] = ['slot' => 2, 'exp' => $tmFn($shiftTimes[2])];
            if (!empty($shiftTimes[4]))
                $inSlots[] = ['slot' => 4, 'exp' => $tmFn($shiftTimes[4])];
            if (!empty($shiftTimes[6]))
                $inSlots[] = ['slot' => 6, 'exp' => $tmFn($shiftTimes[6])];
            
            // Assign break_out slots
            $slotIndex = 0;
            $total = count($outSlots);
            foreach ($breakOutLogs as $log) {
                $logMin = $tmFn($log['time']);
                while ($slotIndex < $total && !empty($assigned[$outSlots[$slotIndex]['slot']])) {
                    $slotIndex++;
                }
                if ($slotIndex >= $total) break;
                
                $bestSlot = null;
                for ($s = $slotIndex; $s < $total; $s++) {
                    if (!empty($assigned[$outSlots[$s]['slot']])) continue;
                    if ($logMin >= $outSlots[$s]['exp']) {
                        $bestSlot = $s;
                    } else {
                        break;
                    }
                }
                if ($bestSlot !== null) {
                    $assigned[$outSlots[$bestSlot]['slot']] = $log['time'];
                    $slotIndex = $bestSlot + 1;
                } else {
                    for ($s = $slotIndex; $s < $total; $s++) {
                        if (empty($assigned[$outSlots[$s]['slot']])) {
                            $assigned[$outSlots[$s]['slot']] = $log['time'];
                            $slotIndex = $s + 1;
                            break;
                        }
                    }
                }
            }
            
            // Assign break_in slots
            $slotIndex = 0;
            $total = count($inSlots);
            foreach ($breakInLogs as $log) {
                $logMin = $tmFn($log['time']);
                while ($slotIndex < $total && !empty($assigned[$inSlots[$slotIndex]['slot']])) {
                    $slotIndex++;
                }
                if ($slotIndex >= $total) break;
                
                $bestSlot = null;
                for ($s = $slotIndex; $s < $total; $s++) {
                    if (!empty($assigned[$inSlots[$s]['slot']])) continue;
                    if ($logMin >= $inSlots[$s]['exp']) {
                        $bestSlot = $s;
                    } else {
                        break;
                    }
                }
                if ($bestSlot !== null) {
                    $assigned[$inSlots[$bestSlot]['slot']] = $log['time'];
                    $slotIndex = $bestSlot + 1;
                } else {
                    for ($s = $slotIndex; $s < $total; $s++) {
                        if (empty($assigned[$inSlots[$s]['slot']])) {
                            $assigned[$inSlots[$s]['slot']] = $log['time'];
                            $slotIndex = $s + 1;
                            break;
                        }
                    }
                }
            }
            
            // Check if PATH A assigned anything
            $pathAAssigned = false;
            foreach ([1,2,3,4,5,6] as $s) {
                if (!empty($assigned[$s])) { $pathAAssigned = true; break; }
            }
            
            // Fallback to heuristic pairing if PATH A assigned nothing
            if (!$pathAAssigned && (!empty($breakOutLogs) || !empty($breakInLogs))) {
                $this->heuristicPairing($assigned, $breakOutLogs, $breakInLogs, $skipBreak1, $tmFn, $isNightShift);
            }
        } else {
            // PATH B: No schedule - heuristic assignment
            if (!empty($lunchOutLogs)) $assigned[3] = $lunchOutLogs[0]['time'];
            if (!empty($lunchInLogs)) $assigned[4] = $lunchInLogs[0]['time'];
            $this->heuristicPairing($assigned, $breakOutLogs, $breakInLogs, $skipBreak1, $tmFn, $isNightShift);
        }
        
        // Re-pair orphaned in-punches
        $this->repairOrphanedPunches($assigned);
        
        return $assigned;
    }
    
    /**
     * Heuristic pairing for break_out and break_in
     */
    private function heuristicPairing(array &$assigned, array $breakOutLogs, array $breakInLogs, bool $skipBreak1, callable $tmFn, bool $isNightShift): void
    {
        // Pair each break_out with nearest subsequent break_in
        $usedBreakIns = [];
        $breakPairs = [];
        
        foreach ($breakOutLogs as $oIdx => $bOut) {
            $outMins = $tmFn($bOut['time']);
            $closest = null;
            $minD = PHP_INT_MAX;
            foreach ($breakInLogs as $iIdx => $bIn) {
                if (in_array($iIdx, $usedBreakIns)) continue;
                $inMins = $tmFn($bIn['time']);
                $diff = $inMins - $outMins;
                if ($diff < 0 && $isNightShift) $diff += 1440;
                if ($diff > 0 && $diff <= 180 && $diff < $minD) {
                    $minD = $diff;
                    $closest = $iIdx;
                }
            }
            if ($closest !== null) {
                $breakPairs[$oIdx] = $closest;
                $usedBreakIns[] = $closest;
            }
        }
        
        // Define slot order
        $outSlots = [];
        if (!$skipBreak1) $outSlots[] = 1;
        $outSlots[] = 3;
        $outSlots[] = 5;
        
        $inSlots = [];
        if (!$skipBreak1) $inSlots[] = 2;
        $inSlots[] = 4;
        $inSlots[] = 6;
        
        // Filter out already-filled slots
        $outSlots = array_values(array_filter($outSlots, fn($s) => empty($assigned[$s])));
        $inSlots = array_values(array_filter($inSlots, fn($s) => empty($assigned[$s])));
        
        // Assign paired punches
        $pairIndex = 0;
        foreach ($breakPairs as $oIdx => $iIdx) {
            if (!isset($outSlots[$pairIndex]) || !isset($inSlots[$pairIndex])) break;
            $assigned[$outSlots[$pairIndex]] = $breakOutLogs[$oIdx]['time'];
            $assigned[$inSlots[$pairIndex]] = $breakInLogs[$iIdx]['time'];
            $pairIndex++;
        }
        
        // Unpaired break_outs - fill remaining out slots
        foreach (array_diff_key($breakOutLogs, $breakPairs) as $bOut) {
            foreach ($outSlots as $slot) {
                if (empty($assigned[$slot])) {
                    $assigned[$slot] = $bOut['time'];
                    break;
                }
            }
        }
        
        // Unpaired break_ins - fill remaining in slots
        $usedInIdxs = array_values($breakPairs);
        foreach ($breakInLogs as $iIdx => $bIn) {
            if (in_array($iIdx, $usedInIdxs)) continue;
            foreach ($inSlots as $slot) {
                if (empty($assigned[$slot])) {
                    $assigned[$slot] = $bIn['time'];
                    break;
                }
            }
        }
    }
    
    /**
     * Repair orphaned punches (e.g., break_in without break_out)
     */
    private function repairOrphanedPunches(array &$assigned): void
    {
        // Break Out 1 empty + Break In 1 filled + Lunch Out filled + Lunch In empty
        // → move Break In 1 to Lunch In
        if (empty($assigned[1]) && !empty($assigned[2]) && !empty($assigned[3]) && empty($assigned[4])) {
            $assigned[4] = $assigned[2];
            $assigned[2] = '';
        }
        
        // Break Out 1 empty + Break In 1 filled + Lunch Out empty + Break Out 2 filled + Break In 2 empty
        // → move Break In 1 to Break In 2
        if (empty($assigned[1]) && !empty($assigned[2]) && empty($assigned[3]) && !empty($assigned[5]) && empty($assigned[6])) {
            $assigned[6] = $assigned[2];
            $assigned[2] = '';
        }
        
        // Lunch Out empty + Lunch In filled + Break Out 2 filled + Break In 2 empty
        // → move Lunch In to Break In 2
        if (empty($assigned[3]) && !empty($assigned[4]) && !empty($assigned[5]) && empty($assigned[6])) {
            $assigned[6] = $assigned[4];
            $assigned[4] = '';
        }
    }
    
    /**
     * Empty slot template
     */
    private function emptySlots(): array
    {
        return [
            'time_in' => null,
            'break_out_1' => null,
            'break_in_1' => null,
            'lunch_out' => null,
            'lunch_in' => null,
            'break_out_2' => null,
            'break_in_2' => null,
            'time_out' => null,
        ];
    }
    
    /**
     * Convert time string to minutes, with night shift awareness
     */
    private function timeToMinutes(string $time, bool $isNightShift = false): int
    {
        if (empty($time)) return 0;
        $parts = explode(':', $time);
        $total = ((int)$parts[0] * 60) + (int)$parts[1];
        return ($isNightShift && (int)$parts[0] < 12) ? $total + 1440 : $total;
    }
}