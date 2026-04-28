<?php

namespace App\Services;

use App\Models\EmployeeMasterlist;
use App\Models\WorkScheduler;
use App\Models\ShiftCode;
use App\Models\AttendanceLog;
use App\Models\BiometricLog;
use App\Models\BiometricLogManual;
use App\Models\Holiday;
use App\Models\EmployeeLeave;
use App\Repositories\EmployeeRepository;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class EmployeeService
{

    public function __construct(
        private EmployeeRepository $employeeRepo,
        private DtrLogService $dtrLogService,
    ) {}

    // =========================================================================
    // REMARKS RESOLUTION  (single source of truth)
    // =========================================================================

    /**
     * Determine the attendance remark for one employee on one date.
     *
     * Priority (highest → lowest):
     *  1. Any punch exists (time_in OR any break/lunch slot)  → Present / Late
     *  2. On Leave + no punch                                 → On Leave
     *  3. Rest Day  + no punch                                → Rest Day
     *  4. Has expected time_in but no punch yet               → Pending / Late / Absent
     *  5. No schedule, no punch, not on leave                 → Absent
     */
private function resolveRemarks(
    array   $actualLogs,
    bool    $isRestDay,
    bool    $isOnLeave,
    ?string $expectedTimeIn,
    bool    $hasSchedule,
    string  $today,
    bool    $isHoliday = false,
    ?array  $obInfo    = null,
    ?string $expectedTimeOut = null
): ?string {
    $hasTimeIn   = $this->hasTimeIn($actualLogs);
    $hasAnyPunch = $this->hasAnyPunch($actualLogs);

    // Check if OB (form_type=ob) covers the expected time in or time out
    $isObPresent = false;
    if ($obInfo && in_array(strtolower($obInfo['form_type'] ?? ''), ['ob', 'pb'])) {
        $obFrom = $this->timeToMinutes($obInfo['time_from'] ?? '');
        $obTo   = $this->timeToMinutes($obInfo['time_to']   ?? '');
        if ($obFrom > 0 && $obTo > 0) {
            if ($expectedTimeIn !== null) {
                $expIn = $this->timeToMinutes($expectedTimeIn);
                if ($expIn >= $obFrom && $expIn <= $obTo) $isObPresent = true;
            }
            if (!$isObPresent && $expectedTimeOut !== null) {
                $expOut = $this->timeToMinutes($expectedTimeOut);
                if ($expOut >= $obFrom && $expOut <= $obTo) $isObPresent = true;
            }
        }
    }

    if ($hasAnyPunch) {
        if ($isRestDay || $isHoliday) return 'Present';
        if ($isOnLeave && $hasTimeIn) return 'On Leave (Present)';

        if ($hasTimeIn && $expectedTimeIn !== null) {
            $actualMins   = $this->timeToMinutes($actualLogs['time_in']);
            $expectedMins = $this->timeToMinutes($expectedTimeIn);
            if ($actualMins < $expectedMins - 720) $actualMins += 1440;
            return $actualMins > $expectedMins ? 'Late' : 'Present';
        }

        return 'Present';
    }

    // No actual punch — but OB covers the shift → count as Present
    if ($isObPresent) return 'Present';

    if ($isHoliday) return 'Holiday';
    if ($isRestDay) return 'Rest Day';
    if ($isOnLeave) return 'On Leave';

    if ($expectedTimeIn !== null) {
        $isPastDate = $today < now()->toDateString();
        if ($isPastDate) return 'Absent';

        $nowMins      = $this->timeToMinutes(now()->format('H:i'));
$expectedMins = $this->timeToMinutes($expectedTimeIn);
$expectedHour = (int) explode(':', $expectedTimeIn)[0];

// Night shift guard: check BEFORE applying the +1440 adjustment.
// If shift starts at 18:00+ and current real hour hasn't reached it yet,
// it's always Pending regardless of minute arithmetic.
if ($expectedHour >= 18 && (int) now()->format('H') < $expectedHour) {
    return 'Pending';
}

if ($expectedMins > 720 && $nowMins < $expectedMins - 720) $nowMins += 1440;

$diffMins = $nowMins - $expectedMins;
if ($diffMins < 0)    return 'Pending';
if ($diffMins <= 120) return 'Late';
return 'Absent';
    }

    return 'Absent';
}

    // ── Punch detection helpers ───────────────────────────────────────────────

    private function hasTimeIn(array $actualLogs): bool
    {
        return !empty($actualLogs['time_in']) && $actualLogs['time_in'] !== '--:--';
    }

    private function hasTimeOut(array $actualLogs): bool
    {
        return !empty($actualLogs['time_out']) && $actualLogs['time_out'] !== '--:--';
    }

    /**
     * Returns true when ANY slot has a recorded punch.
     * Covers employees who have break/lunch punches but missed the check-in tap.
     */
    private function hasAnyPunch(array $actualLogs): bool
    {
        foreach ($actualLogs as $value) {
            if (!empty($value) && $value !== '--:--') return true;
        }
        return false;
    }

    // =========================================================================
    // EXISTING METHODS
    // =========================================================================

    public function getEmployees(): Collection
    {
        return EmployeeMasterlist::query()
            ->where('ACCSTATUS', 1)
            ->whereIn('EMPPOSITION', [1, 2])
            ->orderBy('EMPNAME')
            ->get(['EMPID', 'EMPLOYID', 'EMPNAME', 'JOB_TITLE', 'DEPARTMENT'])
            ->map(fn(EmployeeMasterlist $employee) => $this->mapEmployeeData($employee));
    }

    public function getPaginatedEmployees($search = null, $perPage = 50): LengthAwarePaginator
    {
        $query = EmployeeMasterlist::query()
            ->where('ACCSTATUS', 1)
            ->whereIn('EMPPOSITION', [1, 2])
            ->where('DEPARTMENT', '!=', 'Security');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('EMPNAME', 'like', "%{$search}%")
                    ->orWhere('EMPLOYID', 'like', "%{$search}%")
                    ->orWhere('DEPARTMENT', 'like', "%{$search}%")
                    ->orWhere('JOB_TITLE', 'like', "%{$search}%");
            });
        }

        return $query->paginate($perPage);
    }

    public function getEmployeesWithScheduleData($search = null, $perPage = 50): LengthAwarePaginator
    {
        $employees = $this->getPaginatedEmployees($search, $perPage);
        $employees->getCollection()->transform(fn($e) => $this->attachScheduleDataToEmployee($e));
        return $employees;
    }

    public function attachScheduleDataToEmployee($employee)
    {
        $todaySchedule = $this->getEmployeeScheduleForDate($employee->EMPLOYID, now()->format('Y-m-d'));
        $allSchedules  = $this->getEmployeeSchedule($employee->EMPLOYID);

        $formattedSchedules = [];
        foreach ($allSchedules as $schedule) {
            $formattedSchedules[] = [
                'EMPID'              => $schedule->EMPID,
                'EMPNAME'            => $schedule->EMPNAME,
                'PAYROLL_DATE_START' => Carbon::parse($schedule->PAYROLL_DATE_START)->format('Y-m-d'),
                'PAYROLL_DATE_END'   => Carbon::parse($schedule->PAYROLL_DATE_END)->format('Y-m-d'),
                'SCHEDULE'           => $schedule->SCHEDULE,
                'SHIFT'              => $schedule->SHIFT,
            ];
        }

        $fallbackShiftType = null;
        if (!$todaySchedule) {
            if (count($formattedSchedules) > 0) {
                $mostRecent        = collect($formattedSchedules)->sortByDesc('PAYROLL_DATE_END')->first();
                $fallbackShiftType = $mostRecent['SHIFT'] ?? null;
            }
            if ($fallbackShiftType === null) {
                $fallbackShiftType = $employee->SHIFT ?? null;
            }
        }

        $timeWindows = [];
        if ($todaySchedule && isset($todaySchedule['shift_id'])) {
            $shiftCode = ShiftCode::find($todaySchedule['shift_id']);
            if ($shiftCode?->TIME_WINDOWS) {
                $tw          = $shiftCode->TIME_WINDOWS;
                $timeWindows = is_string($tw) ? (json_decode($tw, true) ?? []) : (array) $tw;
            }
        }

        $employee->today_schedule      = $todaySchedule;
        $employee->all_schedules       = $formattedSchedules;
        $employee->scheduler_records   = $formattedSchedules;
        $employee->fallback_shift_type = $fallbackShiftType;
        $employee->today_logs          = $this->getEmployeeTodayLogs($employee->EMPLOYID, $timeWindows);

        return $employee;
    }

    private function mapEmployeeData(EmployeeMasterlist $employee): array
    {
        return [
            'id'          => $employee->EMPID,
            'employee_id' => (string) $employee->EMPLOYID,
            'name'        => trim($employee->EMPNAME),
            'job'         => $employee->JOB_TITLE,
            'dept'        => $employee->DEPARTMENT,
        ];
    }

    public function getEmployeeSchedule($employId = null): Collection
    {
        $query = WorkScheduler::query();
        if ($employId) $query->where('EMPID', $employId);
        return $query->orderBy('EMPNAME')->get([
            'EMPID', 'EMPNAME', 'SCHEDULE', 'PAYROLL_DATE_START', 'PAYROLL_DATE_END', 'SHIFT',
        ]);
    }

    public function getEmployeeScheduleForDate($employId, $date)
    {
        $schedules = $this->getEmployeeSchedule($employId);

        foreach ($schedules as $schedule) {
            $startDate  = Carbon::parse($schedule->PAYROLL_DATE_START)->startOfDay();
            $endDate    = Carbon::parse($schedule->PAYROLL_DATE_END)->startOfDay();
            $targetDate = Carbon::parse($date)->startOfDay();

            if ($targetDate->between($startDate, $endDate)) {
                $dayOfPeriod = (int) abs($targetDate->diffInDays($startDate)) + 1;

                $raw           = $schedule->SCHEDULE;
                $scheduleArray = [];
                if (is_string($raw)) {
                    $decoded       = json_decode($raw, true);
                    $scheduleArray = is_array($decoded) ? $decoded : [];
                } elseif (is_array($raw)) {
                    $scheduleArray = $raw;
                }

                $shiftId = $scheduleArray[(string) $dayOfPeriod] ?? $scheduleArray[$dayOfPeriod] ?? null;

                if ($shiftId) {
                    $shiftCode = ShiftCode::find($shiftId);
                    $shiftType = $schedule->SHIFT ?? $schedule->shift ?? null;

                    return [
                        'shift_id'      => $shiftId,
                        'shift_code'    => $shiftCode?->SHIFTCODE,
                        'shift_type'    => (int) $shiftType,
                        'is_shifting'   => (int) $shiftType === 2,
                        'schedule_id'   => $schedule->id ?? null,
                        'payroll_start' => Carbon::parse($schedule->PAYROLL_DATE_START)->format('Y-m-d'),
                        'payroll_end'   => Carbon::parse($schedule->PAYROLL_DATE_END)->format('Y-m-d'),
                        'day_of_period' => $dayOfPeriod,
                        'full_schedule' => $scheduleArray,
                    ];
                }
            }
        }

        return null;
    }

    public function getEmployeeWithSchedule($empId)
    {
        $employee = EmployeeMasterlist::where('EMPID', $empId)
            ->where('ACCSTATUS', 1)
            ->whereIn('EMPPOSITION', [1, 2])
            ->first();

        return $employee ? $this->attachScheduleDataToEmployee($employee) : null;
    }

    public function getScheduleSummary($date = null)
    {
        $date      = $date ?? now()->format('Y-m-d');
        $employees = $this->getPaginatedEmployees(null, 9999);

        return $employees->getCollection()->map(function ($employee) use ($date) {
            $schedule = $this->getEmployeeScheduleForDate($employee->EMPID, $date);
            return [
                'EMPID'        => $employee->EMPID,
                'EMPLOYID'     => $employee->EMPLOYID,
                'EMPNAME'      => $employee->EMPNAME,
                'DEPARTMENT'   => $employee->DEPARTMENT,
                'JOB_TITLE'    => $employee->JOB_TITLE,
                'has_schedule' => !is_null($schedule),
                'shift_id'     => $schedule['shift_id']   ?? null,
                'shift_code'   => $schedule['shift_code'] ?? null,
                'is_rest_day'  => isset($schedule['shift_code']) && str_contains($schedule['shift_code'], 'RD'),
            ];
        });
    }

    public function getEmployeeTodayLogs(string $employid, array $timeWindows = [], string $date = ''): array
    {
        $today  = !empty($date) ? $date : now()->format('Y-m-d');
        $result = [
            'time_in'     => null, 'break_out_1' => null, 'break_in_1'  => null,
            'lunch_out'   => null, 'lunch_in'    => null, 'break_out_2' => null,
            'break_in_2'  => null, 'time_out'    => null,
        ];

        $breakOutSlots = [
            ['key' => 'break_out_1', 'twIndex' => 1],
            ['key' => 'lunch_out',   'twIndex' => 3],
            ['key' => 'break_out_2', 'twIndex' => 5],
        ];
        $breakInSlots = [
            ['key' => 'break_in_1', 'twIndex' => 2],
            ['key' => 'lunch_in',   'twIndex' => 4],
            ['key' => 'break_in_2', 'twIndex' => 6],
        ];

        $attendanceLogs = AttendanceLog::where('employid', $employid)
            ->whereDate('logged_at', $today)
            ->orderBy('logged_at')
            ->get(['log_type', 'logged_at']);

        foreach ($attendanceLogs as $log) {
            $time = Carbon::parse($log->logged_at)->format('H:i');
            match ($log->log_type) {
                'check_in'   => $result['time_in']    ??= $time,
                'check_out'  => $result['time_out']     = $time,
                'break_out1' => $result['break_out_1'] ??= $time,
                'break_in1'  => $result['break_in_1']  ??= $time,
                'break_out2' => $result['break_out_2'] ??= $time,
                'break_in2'  => $result['break_in_2']  ??= $time,
                'lunch_out'  => $result['lunch_out']   ??= $time,
                'lunch_in'   => $result['lunch_in']    ??= $time,
                default      => null,
            };
        }

        $bioLogs = BiometricLog::where('employid', $employid)
            ->whereDate('datetime', $today)
            ->orderBy('datetime')
            ->get(['punch_type', 'datetime']);

        foreach ($bioLogs as $log) {
            $time = Carbon::parse($log->datetime)->format('H:i');
            match ($log->punch_type) {
                'check_in'  => $result['time_in'] ??= $time,
                'check_out' => $result['time_out']  = $time,
                'break_out' => $this->assignToClosestSlot($time, $breakOutSlots, $timeWindows, $result),
                'break_in'  => $this->assignToClosestSlot($time, $breakInSlots,  $timeWindows, $result),
                default     => null,
            };
        }

        $manualLogs = BiometricLogManual::where('employid', $employid)
            ->whereDate('datetime', $today)
            ->orderBy('datetime')
            ->get(['punch_type', 'datetime']);

        foreach ($manualLogs as $log) {
            $time = Carbon::parse($log->datetime)->format('H:i');
            match ($log->punch_type) {
                'check_in'  => $result['time_in'] ??= $time,
                'check_out' => $result['time_out'] ??= $time,
                'break_out' => $this->assignToClosestSlot($time, $breakOutSlots, $timeWindows, $result),
                'break_in'  => $this->assignToClosestSlot($time, $breakInSlots,  $timeWindows, $result),
                default     => null,
            };
        }

        return $result;
    }

    private function assignToClosestSlot(
        string $actualTime,
        array  $candidateSlots,
        array  $timeWindows,
        array  &$result
    ): void {
        $bestKey  = null;
        $bestDiff = PHP_INT_MAX;

        foreach ($candidateSlots as $candidate) {
            if ($result[$candidate['key']] !== null) continue;
            $expectedTime = $timeWindows[$candidate['twIndex']] ?? null;
            $diff         = $expectedTime
                ? abs($this->timeToMinutes($actualTime) - $this->timeToMinutes($expectedTime))
                : 9999 + array_search($candidate, $candidateSlots);

            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $bestKey  = $candidate['key'];
            }
        }

        if ($bestKey !== null) $result[$bestKey] = $actualTime;
    }

    public function getTodayHoliday(?string $date = null): ?array
    {
        $date    = $date ?? now()->format('Y-m-d');
        $holiday = Holiday::whereDate('HOLIDAY_DATE', $date)->first();
        if (!$holiday) return null;

        return [
            'name'       => $holiday->HOLIDAY_NAME,
            'date'       => Carbon::parse($holiday->HOLIDAY_DATE)->format('Y-m-d'),
            'type'       => $holiday->HOLIDAY_TYPE,
            'is_regular' => strtolower($holiday->HOLIDAY_TYPE) === 'regular',
            'is_special' => strtolower($holiday->HOLIDAY_TYPE) === 'special',
        ];
    }

    public function getShiftCodesMap()
    {
        $shiftCodes = ShiftCode::where('SHIFT_CODE_STATUS', 1)->get();
        $map        = [];
        foreach ($shiftCodes as $shiftCode) {
            $map[$shiftCode->SHIFT_CODE_ID] = [
                'shiftcode'       => $shiftCode->SHIFTCODE,
                'shiftcode_value' => $shiftCode->SHIFTCODE_VALUE,
                'shiftcode_desc'  => $shiftCode->SHIFTCODE_DESC,
                'shift_group'     => $shiftCode->SHIFT_GROUP,
                'time_windows'    => $shiftCode->TIME_WINDOWS,
                'bg_color'        => $shiftCode->SHIFTCODE_BG_COLOR,
                'font_color'      => $shiftCode->SHIFTCODE_FONT_COLOR,
            ];
        }
        return $map;
    }

    public function getFilteredWithOptions(array $filters = []): array
    {
        $positions       = [1, 2];
        $employees       = $this->employeeRepo->getFilteredEmployees($filters, $positions);
        $employIds       = $employees->pluck('EMPLOYID')->toArray();
        $activeSchedules = $this->employeeRepo->getActiveSchedules($employIds)->keyBy('EMPID');

        $today       = now()->toDateString();
        $todayCarbon = Carbon::parse($today);

        $timeWindowsMap  = [];
        $scheduleTypeMap = [];

        foreach ($employees as $employee) {
            $employId       = $employee->EMPLOYID;
            $activeSchedule = $activeSchedules->get($employId);

            if (!$activeSchedule) {
                $timeWindowsMap[$employId]  = [];
                $scheduleTypeMap[$employId] = 'Normal';
                continue;
            }

            [$tw, $scheduleType]          = $this->resolveTimeWindowsAndType($activeSchedule, $todayCarbon);
            $timeWindowsMap[$employId]    = $tw;
            $scheduleTypeMap[$employId]   = $scheduleType;
        }

        $actualLogsMap = $this->dtrLogService->resolveLogsForEmployees(
            $employIds, $timeWindowsMap, $scheduleTypeMap
        );

        $employees = $employees->map(function ($employee) use (
            $activeSchedules, $actualLogsMap, $timeWindowsMap, $scheduleTypeMap
        ) {
            $employId                  = $employee->EMPLOYID;
            $employee->active_schedule = $activeSchedules->get($employId) ?? null;
            $employee->actual_logs     = $actualLogsMap[$employId]        ?? [];
            $employee->time_windows    = $timeWindowsMap[$employId]       ?? [];
            $employee->schedule_type   = $scheduleTypeMap[$employId]      ?? 'Normal';
            return $employee;
        });

        return [
            'employees' => $employees,
            'filters'   => $this->employeeRepo->getFilterOptions($positions),
        ];
    }

    // =========================================================================
    // getDtrRows
    // =========================================================================

    public function getDtrRows(
        array  $filters      = [],
        int    $page         = 1,
        int    $perPage      = 15,
        string $search       = '',
        string $date         = '',
        string $shiftFilter  = '',
        string $statusFilter = ''
    ): array {
        $positions   = [1, 2];
        $today       = !empty($date) ? $date : now()->toDateString();
        $todayCarbon = Carbon::parse($today);

        $employees       = $this->employeeRepo->getFilteredEmployees($filters, $positions, 9999, 1, $search, $today);
        $employIds       = $employees->pluck('EMPLOYID')->toArray();
        $activeSchedules = $this->employeeRepo->getActiveSchedules($employIds, $today)->keyBy('EMPID');

        $timeWindowsMap  = [];
        $scheduleTypeMap = [];

        foreach ($employees as $employee) {
            $employId       = $employee->EMPLOYID;
            $activeSchedule = $activeSchedules->get($employId);

            if (!$activeSchedule) {
                $timeWindowsMap[$employId]  = [];
                $scheduleTypeMap[$employId] = 'Normal';
                continue;
            }

            [$tw, $scheduleType]        = $this->resolveTimeWindowsAndType($activeSchedule, $todayCarbon);
            $timeWindowsMap[$employId]  = $tw;
            $scheduleTypeMap[$employId] = $scheduleType;
        }

        $actualLogsMap = $this->dtrLogService->resolveLogsForEmployees(
            $employIds, $timeWindowsMap, $scheduleTypeMap, $today
        );
        $approvedLeavesMap = $this->employeeRepo->getApprovedLeaves($employIds, $today);

        $rows = [];

        foreach ($employees as $employee) {
            $employId       = $employee->EMPLOYID;
            $activeSchedule = $activeSchedules->get($employId);

            // ── SKIP employees with no active schedule ────────────────────────
            if ($activeSchedule === null) continue;

            $tw           = $timeWindowsMap[$employId]  ?? [];
            $scheduleType = $scheduleTypeMap[$employId] ?? 'Normal';
            $isShifting   = $scheduleType === 'Shifting';
            $actualLogs   = $actualLogsMap[$employId]   ?? [];

            [$shiftType, $shiftCode, $isRestDay] = $this->resolveShiftMeta(
                $activeSchedule, $tw, $actualLogs, $todayCarbon
            );

            $isOnLeave      = isset($approvedLeavesMap[$employId]);
            $expectedTimeIn = $tw[0] ?? null;
            $hasAnyPunch    = $this->hasAnyPunch($actualLogs);
            $isUnscheduledRD = ($isRestDay || $isOnLeave) && $hasAnyPunch;

            $obRecord        = null; // getDtrRows doesn't use approvedObs yet
            $obInfo          = null;
            $expectedTimeOut = $tw[7] ?? null;

            $remarks = $this->resolveRemarks(
                $actualLogs, $isRestDay, $isOnLeave,
                $expectedTimeIn, true, $today, false, $obInfo, $expectedTimeOut
            );

            // ── Missing log flags ─────────────────────────────────────────────
            $isPresent      = in_array($remarks, ['Present', 'Late', 'On Leave (Present)']);
            $missingTimeIn  = $isPresent && !$this->hasTimeIn($actualLogs);
            $missingTimeOut = $isPresent && !$this->hasTimeOut($actualLogs);

            $flattened = $this->buildFlattenedSlots($actualLogs, $tw, false, $isShifting);

            $rows[] = [
                'EMPLOYID'          => $employId,
                'EMPNAME'           => $employee->EMPNAME,
                'SHIFTCODE'         => $shiftCode,
                'SHIFT_TYPE'        => $shiftType,
                'SCHEDULE_TYPE'     => $scheduleType,
                'HAS_SCHEDULE'      => true,
                'IS_REST_DAY'       => $isRestDay,
                'IS_UNSCHEDULED_RD' => $isUnscheduledRD,
                'REMARKS'           => $remarks,
                'MISSING_TIME_IN'   => $missingTimeIn,
                'MISSING_TIME_OUT'  => $missingTimeOut,
                ...$flattened,
            ];
        }

        // ── Shift filter ──────────────────────────────────────────────────────
        if (!empty($shiftFilter)) {
            $rows = array_values(array_filter($rows, fn($row) => match ($shiftFilter) {
                'Day Shift'   => str_contains($row['SHIFT_TYPE'], 'Day'),
                'Night Shift' => str_contains($row['SHIFT_TYPE'], 'Night'),
                default       => true,
            }));
        }

        // ── Status filter ─────────────────────────────────────────────────────
        if (!empty($statusFilter)) {
            $rows = array_values(array_filter($rows, fn($row) => match ($statusFilter) {
                'On Leave' => in_array($row['REMARKS'], ['On Leave', 'On Leave (Present)']),
                default    => $row['REMARKS'] === $statusFilter,
            }));
        }

        // ── Paginate ──────────────────────────────────────────────────────────
        $filteredTotal = count($rows);
        $lastPage      = max(1, (int) ceil($filteredTotal / $perPage));
        $page          = min($page, $lastPage);
        $pagedRows     = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return [
            'rows'         => $pagedRows,
            'total'        => $filteredTotal,
            'per_page'     => $perPage,
            'current_page' => $page,
            'last_page'    => $lastPage,
        ];
    }

    // =========================================================================
    // getShiftCounts
    // =========================================================================

    public function getShiftCounts(array $filters = [], string $date = ''): array
    {
        $positions   = [1, 2];
        $today       = !empty($date) ? $date : now()->toDateString();
        $todayCarbon = Carbon::parse($today);

        $employees       = $this->employeeRepo->getFilteredEmployees($filters, $positions, 9999, 1, '', $today);
        $employIds       = $employees->pluck('EMPLOYID')->toArray();
        $activeSchedules = $this->employeeRepo->getActiveSchedules($employIds, $today)->keyBy('EMPID');

        $timeWindowsMap  = [];
        $scheduleTypeMap = [];

        foreach ($employees as $employee) {
            $employId       = $employee->EMPLOYID;
            $activeSchedule = $activeSchedules->get($employId);

            if (!$activeSchedule) {
                $timeWindowsMap[$employId]  = [];
                $scheduleTypeMap[$employId] = 'Normal';
                continue;
            }

            [$tw, $scheduleType]        = $this->resolveTimeWindowsAndType($activeSchedule, $todayCarbon);
            $timeWindowsMap[$employId]  = $tw;
            $scheduleTypeMap[$employId] = $scheduleType;
        }

        $actualLogsMap = $this->dtrLogService->resolveLogsForEmployees(
            $employIds, $timeWindowsMap, $scheduleTypeMap, $today
        );
        $approvedLeavesMap = $this->employeeRepo->getApprovedLeaves($employIds, $today);

        $dayShiftCount   = $nightShiftCount   = 0;
        $dayRestDayCount = $nightRestDayCount  = 0;
        $dayExpectedCount   = $nightExpectedCount   = 0;
        $dayPresentCount    = $nightPresentCount    = 0;
        $dayPendingCount    = $nightPendingCount    = 0;
        $dayUnscheduledRDCount   = $nightUnscheduledRDCount   = 0;
        $dayUnscheduledRDPresent = $nightUnscheduledRDPresent = 0;

        foreach ($employees as $employee) {
            $employId       = $employee->EMPLOYID;
            $activeSchedule = $activeSchedules->get($employId);

            // ── SKIP employees with no active schedule ────────────────────────
            if ($activeSchedule === null) continue;

            $actualLogs  = $actualLogsMap[$employId] ?? [];
            $isOnLeave   = isset($approvedLeavesMap[$employId]);
            $hasAnyPunch = $this->hasAnyPunch($actualLogs);

            $tw = $timeWindowsMap[$employId] ?? [];

            [,, $isRestDay] = $this->resolveShiftMeta($activeSchedule, $tw, $actualLogs, $todayCarbon);

            $expectedTimeIn = $tw[0] ?? null;
            $isNight        = !empty($tw[0]) && (int) explode(':', $tw[0])[0] >= 18;

            $remarks = $this->resolveRemarks(
                $actualLogs, $isRestDay, $isOnLeave, $expectedTimeIn, true, $today
            );
            $isPresent       = in_array($remarks, ['Present', 'Late']);
            $isPending       = $remarks === 'Pending';
            $isUnscheduledRD = in_array($remarks, ['Present', 'Late', 'On Leave (Present)'])
                               && ($isRestDay || $isOnLeave);

            if ($isNight) {
                $nightShiftCount++;
                if ($isRestDay && !$isUnscheduledRD)    { $nightRestDayCount++; }
                elseif ($isUnscheduledRD)               { $nightUnscheduledRDCount++; $nightUnscheduledRDPresent++; }
                elseif ($isOnLeave && !$hasAnyPunch)    { $nightExpectedCount++; }
                elseif ($isPending)                     { $nightExpectedCount++; $nightPendingCount++; }
                else                                    { $nightExpectedCount++; if ($isPresent) $nightPresentCount++; }
            } else {
                $dayShiftCount++;
                if ($isRestDay && !$isUnscheduledRD)    { $dayRestDayCount++; }
                elseif ($isUnscheduledRD)               { $dayUnscheduledRDCount++; $dayUnscheduledRDPresent++; }
                elseif ($isOnLeave && !$hasAnyPunch)    { $dayExpectedCount++; }
                elseif ($isPending)                     { $dayExpectedCount++; $dayPendingCount++; }
                else                                    { $dayExpectedCount++; if ($isPresent) $dayPresentCount++; }
            }
        }

        $pct = fn($n, $d) => $d > 0 ? round($n / $d * 100, 1) : 0;

        $dayScheduledAbsent   = max(0, $dayExpectedCount   - $dayPresentCount   - $dayPendingCount);
        $nightScheduledAbsent = max(0, $nightExpectedCount - $nightPresentCount - $nightPendingCount);

        $dayTotalExpected   = $dayExpectedCount   + $dayUnscheduledRDCount;
        $dayTotalPresent    = $dayPresentCount    + $dayUnscheduledRDPresent;
        $dayTotalAbsent     = $dayScheduledAbsent;
        $nightTotalExpected = $nightExpectedCount + $nightUnscheduledRDCount;
        $nightTotalPresent  = $nightPresentCount  + $nightUnscheduledRDPresent;
        $nightTotalAbsent   = $nightScheduledAbsent;

        return [
            'day_shift'                  => $dayShiftCount,
            'night_shift'                => $nightShiftCount,
            'day_rest_day'               => $dayRestDayCount,
            'night_rest_day'             => $nightRestDayCount,
            'day_expected'               => $dayExpectedCount,
            'night_expected'             => $nightExpectedCount,
            'day_present'                => $dayPresentCount,
            'night_present'              => $nightPresentCount,
            'day_present_pct'            => $pct($dayPresentCount,   $dayExpectedCount),
            'night_present_pct'          => $pct($nightPresentCount, $nightExpectedCount),
            'day_unscheduled_rd'         => $dayUnscheduledRDCount,
            'night_unscheduled_rd'       => $nightUnscheduledRDCount,
            'day_unscheduled_rd_present' => $dayUnscheduledRDPresent,
            'night_unscheduled_rd_present' => $nightUnscheduledRDPresent,
            'day_unscheduled_rd_pct'     => $pct($dayUnscheduledRDPresent,   $dayUnscheduledRDCount),
            'night_unscheduled_rd_pct'   => $pct($nightUnscheduledRDPresent, $nightUnscheduledRDCount),
            'day_total_expected'         => $dayTotalExpected,
            'day_total_present'          => $dayTotalPresent,
            'day_total_absent'           => $dayTotalAbsent,
            'day_total_present_pct'      => $pct($dayTotalPresent, $dayTotalExpected),
            'day_total_absent_pct'       => $pct($dayTotalAbsent,  $dayTotalExpected),
            'night_total_expected'       => $nightTotalExpected,
            'night_total_present'        => $nightTotalPresent,
            'night_total_absent'         => $nightTotalAbsent,
            'night_total_present_pct'    => $pct($nightTotalPresent, $nightTotalExpected),
            'night_total_absent_pct'     => $pct($nightTotalAbsent,  $nightTotalExpected),
            'total'                      => $employees->count(),
        ];
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function resolveTimeWindowsAndType($activeSchedule, Carbon $todayCarbon): array
    {
        $schedule      = $activeSchedule->SCHEDULE ?? [];
        $payrollStart  = $activeSchedule->PAYROLL_DATE_START;
        $shiftCodesMap = $activeSchedule->shift_codes_map ?? collect();
        $tw            = [];
        $scheduleType  = 'Normal';

        if ($payrollStart) {
            $payrollStartDate = Carbon::parse($payrollStart)->startOfDay();
            $dayIndex         = (int) $payrollStartDate->diffInDays($todayCarbon) + 1;
            $shiftId          = $schedule[(string) $dayIndex] ?? $schedule[$dayIndex] ?? null;

            if ($shiftId) {
                $shiftCode = $shiftCodesMap->get((int) $shiftId);
                $rawTw     = $shiftCode?->TIME_WINDOWS;
                $tw        = is_array($rawTw)
                    ? $rawTw
                    : (is_string($rawTw) ? (json_decode($rawTw, true) ?? []) : []);

                $firstTime = $tw[0] ?? null;
                $lastTime  = $tw[7] ?? null;

                if ($firstTime && $lastTime) {
                    $duration = $this->timeToMinutes($lastTime) - $this->timeToMinutes($firstTime);
                    if ($duration < 0) $duration += 1440;
                    $scheduleType = $duration >= 720 ? 'Shifting' : 'Normal';
                }
            }
        }

        return [$tw, $scheduleType];
    }

    private function resolveShiftMeta(
        $activeSchedule,
        array  $tw,
        array  $actualLogs,
        Carbon $todayCarbon
    ): array {
        $shiftType = 'N/A';
        $shiftCode = 'N/A';
        $isRestDay = false;
        $firstTime = $tw[0] ?? null;

        if ($firstTime) {
            $firstHour = (int) explode(':', $firstTime)[0];
            $shiftType = match (true) {
                $firstHour >= 18 || $firstHour < 6 => 'Night Shift',
                $firstHour >= 12                   => 'Afternoon Shift',
                default                            => 'Day Shift',
            };
        }

        if ($activeSchedule) {
            $schedule      = $activeSchedule->SCHEDULE ?? [];
            $payrollStart  = $activeSchedule->PAYROLL_DATE_START;
            $shiftCodesMap = $activeSchedule->shift_codes_map ?? collect();

            if ($payrollStart) {
                $startDate = Carbon::parse($payrollStart)->startOfDay();
                $dayIndex  = (int) $startDate->diffInDays($todayCarbon) + 1;
                $shiftId   = $schedule[(string) $dayIndex] ?? $schedule[$dayIndex] ?? null;

                if ($shiftId) {
                    $scObj     = $shiftCodesMap->get((int) $shiftId);
                    $shiftCode = $scObj?->SHIFTCODE ?? 'N/A';
                    $isRestDay = $scObj && str_contains(strtoupper($shiftCode), 'RD');
                }
            }
        }

        return [$shiftType, $shiftCode, $isRestDay];
    }

    private function buildFlattenedSlots(
        array $actualLogs,
        array $tw,
        bool  $noSchedule,
        bool  $isShifting
    ): array {
        $slotDefs = [
            ['key' => 'time_in',     'twIndex' => 0, 'label' => 'Time In',     'disabled' => false],
            ['key' => 'break_out_1', 'twIndex' => 1, 'label' => 'Break Out 1', 'disabled' => $noSchedule || $isShifting],
            ['key' => 'break_in_1',  'twIndex' => 2, 'label' => 'Break In 1',  'disabled' => $noSchedule || $isShifting],
            ['key' => 'lunch_out',   'twIndex' => 3, 'label' => 'Lunch Out',   'disabled' => $noSchedule],
            ['key' => 'lunch_in',    'twIndex' => 4, 'label' => 'Lunch In',    'disabled' => $noSchedule],
            ['key' => 'break_out_2', 'twIndex' => 5, 'label' => 'Break Out 2', 'disabled' => $noSchedule],
            ['key' => 'break_in_2',  'twIndex' => 6, 'label' => 'Break In 2',  'disabled' => $noSchedule],
            ['key' => 'time_out',    'twIndex' => 7, 'label' => 'Time Out',    'disabled' => false],
        ];

        $flattened = [];
        foreach ($slotDefs as $slot) {
            $label = $slot['label'];
            if ($slot['disabled']) {
                $flattened["{$label} (actual)"]   = null;
                $flattened["{$label} (expected)"] = null;
            } else {
                $actualValue                      = $actualLogs[$slot['key']] ?? null;
                $flattened["{$label} (actual)"]   = !empty($actualValue) ? $actualValue : '--:--';
                $flattened["{$label} (expected)"] = !empty($tw[$slot['twIndex']]) ? $tw[$slot['twIndex']] : null;
            }
        }

        return $flattened;
    }

private function isFullShiftOB(array $tw, ?array $obInfo): bool
{
    if (empty($obInfo) || empty($obInfo['time_from']) || empty($obInfo['time_to'])) return false;
    if (empty($tw[0])  || empty($tw[7])) return false;

    $base     = '1970-01-01 ';
    $obFrom   = strtotime($base . $obInfo['time_from']);
    $obTo     = strtotime($base . $obInfo['time_to']);
    $checkIn  = strtotime($base . $tw[0]);
    $checkOut = strtotime($base . $tw[7]);

    if ($obTo < $obFrom) $obTo += 86400;
    if ($checkOut < $checkIn) $checkOut += 86400;

    return ($obFrom <= $checkIn && $obTo >= $checkOut);
}

private function buildObInfo($ob): ?array
{
    if (!$ob) return null;

    $formType   = strtolower((string)($ob->FORM_TYPE ?? ''));
    $remarkType = match($formType) {
        'ob' => 'Official Business',
        'pb' => 'Personal Business',
        default => 'Other Business',
    };
    $timeFrom   = substr((string)($ob->TIME_FROM ?? ''), 0, 5);
    $timeTo     = substr((string)($ob->TIME_TO   ?? ''), 0, 5);

    if (empty($timeFrom) || empty($timeTo)) return null;

    return [
        'type'      => $remarkType,
        'time_from' => $timeFrom,
        'time_to'   => $timeTo,
        'form_type' => $ob->FORM_TYPE ?? '',
    ];
}

    public function getUnscheduledEmployees(array $filters = [], string $date = ''): array
{
    $positions = [1, 2];
    $today     = !empty($date) ? $date : now()->toDateString();

    $employees       = $this->employeeRepo->getFilteredEmployees($filters, $positions, 9999, 1, '', $today);
    $employIds       = $employees->pluck('EMPLOYID')->toArray();
    $activeSchedules = $this->employeeRepo->getActiveSchedules($employIds, $today)->keyBy('EMPID');

    $unscheduled       = $employees->filter(fn($emp) => !isset($activeSchedules[$emp->EMPLOYID]));
    $unscheduledIds    = $unscheduled->pluck('EMPLOYID')->toArray();

    if (empty($unscheduledIds)) return [];

    // Resolve all logs in ONE batch instead of per-employee queries
    $timeWindowsMap  = array_fill_keys($unscheduledIds, []);
    $scheduleTypeMap = array_fill_keys($unscheduledIds, 'Normal');

    $resolvedLogs = $this->dtrLogService->resolveLogsForEmployees(
        $unscheduledIds,
        $timeWindowsMap,
        $scheduleTypeMap,
        $today
    );

    $result = [];
    foreach ($unscheduled as $emp) {
        $actualLogs = $resolvedLogs[$emp->EMPLOYID] ?? [];

        $timeIn  = !empty($actualLogs['time_in'])  && $actualLogs['time_in']  !== '--:--'
            ? $actualLogs['time_in']  : null;
        $timeOut = !empty($actualLogs['time_out']) && $actualLogs['time_out'] !== '--:--'
            ? $actualLogs['time_out'] : null;

        $result[] = [
            'EMPLOYID'   => $emp->EMPLOYID,
            'EMPNAME'    => $emp->EMPNAME,
            'TIME_IN'    => $timeIn  ?? '--:--',
            'TIME_OUT'   => $timeOut ?? '--:--',
            'SHIFT_TYPE' => $this->determineShiftTypeFromLogs($timeIn, $timeOut),
        ];
    }

    return $result;
}

    private function timeToMinutes(string $time): int
    {
        [$h, $m] = explode(':', $time);
        return (int)$h * 60 + (int)$m;
    }

        /**
     * Get Time In, Time Out, and Shift Type from actual logs for a specific date
     * Handles night shift spanning 2 days (e.g., 6PM to 7AM next day)
     */
        public function getEmployeeShiftLogsForDate(string $employId, string $date): array
{
    // Use DtrLogService which already handles night shift windowing correctly
    // (fetches yesterday → day+2 and uses detectNightShiftFromLogs for unscheduled employees)
    $resolved = $this->dtrLogService->resolveLogsForEmployees(
        [$employId],
        [$employId => []],
        [$employId => 'Normal'],
        $date
    );

    $actualLogs = $resolved[$employId] ?? [];

    $timeIn  = !empty($actualLogs['time_in'])  && $actualLogs['time_in']  !== '--:--'
        ? $actualLogs['time_in']  : null;
    $timeOut = !empty($actualLogs['time_out']) && $actualLogs['time_out'] !== '--:--'
        ? $actualLogs['time_out'] : null;

    $shiftType = $this->determineShiftTypeFromLogs($timeIn, $timeOut);

    return [
        'time_in'    => $timeIn  ?? '--:--',
        'time_out'   => $timeOut ?? '--:--',
        'shift_type' => $shiftType,
    ];
}
    
    private function determineShiftTypeFromLogs(?string $timeIn, ?string $timeOut): string
    {
        if (!$timeIn && !$timeOut) {
            return 'Unknown';
        }
        
        // Determine from time_in first
        if ($timeIn) {
            $hourIn = (int) explode(':', $timeIn)[0];
            
            // Night shift: 6PM (18) to 11:59PM
            if ($hourIn >= 18) {
                return 'Night Shift';
            }
            // Day shift: 6AM to 5:59PM
            if ($hourIn >= 6 && $hourIn < 18) {
                return 'Day Shift';
            }
        }
        
        // Determine from time_out if no time_in
        if ($timeOut && !$timeIn) {
            $hourOut = (int) explode(':', $timeOut)[0];
            
            // Afternoon/evening time_out suggests day shift
            if ($hourOut >= 12 && $hourOut < 24) {
                return 'Day Shift';
            }
        }
        
        return 'Unknown';
    }
    /**
     * Map punch type to standard values
     */
    private function mapPunchType($raw): string
    {
        $map = [
            '0' => 'check_in',
            '1' => 'check_out',
            '2' => 'break_out',
            '3' => 'break_in',
            '4' => 'lunch_out',
            '5' => 'lunch_in',
            'check_in' => 'check_in',
            'check_out' => 'check_out',
            'break_out' => 'break_out',
            'break_in' => 'break_in',
            'lunch_out' => 'lunch_out',
            'lunch_in' => 'lunch_in',
        ];
        
        return $map[(string) $raw] ?? 'check_in';
    }


public function getOverviewData(
    array  $filters      = [],
    string $date         = '',
    int    $page         = 1,
    int    $perPage      = 15,
    string $search       = '',
    string $shiftFilter  = '',
    string $statusFilter = ''
): array {
    $positions   = [1, 2];
    $today       = !empty($date) ? $date : now()->toDateString();
    $todayCarbon = Carbon::parse($today);

    // ── Fetch holiday for this date ONCE ──────────────────────────────────
    $holiday   = $this->getTodayHoliday($today);
    $isHoliday = $holiday !== null;

    // ── Single fetch for all employees ────────────────────────────────────
    $allEmployees    = $this->employeeRepo->getFilteredEmployees($filters, $positions, 9999, 1, '', $today);
    $allEmployeeIds  = $allEmployees->pluck('EMPLOYID')->toArray();
    $activeSchedules = $this->employeeRepo->getActiveSchedules($allEmployeeIds, $today)->keyBy('EMPID');
    $approvedLeaves  = $this->employeeRepo->getApprovedLeaves($allEmployeeIds, $today);
    $approvedObs     = $this->employeeRepo->getApprovedObs($allEmployeeIds, $today);

    $timeWindowsMap  = [];
    $scheduleTypeMap = [];

    foreach ($allEmployees as $employee) {
        $employId       = $employee->EMPLOYID;
        $activeSchedule = $activeSchedules->get($employId);

        if (!$activeSchedule) {
            $timeWindowsMap[$employId]  = [];
            $scheduleTypeMap[$employId] = 'Normal';
            continue;
        }

        [$tw, $scheduleType]        = $this->resolveTimeWindowsAndType($activeSchedule, $todayCarbon);
        $timeWindowsMap[$employId]  = $tw;
        $scheduleTypeMap[$employId] = $scheduleType;
    }

    $actualLogsMap = $this->dtrLogService->resolveLogsForEmployees(
        $allEmployeeIds, $timeWindowsMap, $scheduleTypeMap, $today
    );

    // ── Pass $isHoliday into each sub-computation ─────────────────────────
    $shiftCounts = $this->computeShiftCountsFromData(
        $allEmployees, $activeSchedules, $actualLogsMap,
        $approvedLeaves, $timeWindowsMap, $todayCarbon, $today, $isHoliday, $approvedObs
    );

        $dtrResult = $this->computeDtrRowsFromData(
        $allEmployees, $activeSchedules, $actualLogsMap,
        $approvedLeaves, $timeWindowsMap, $scheduleTypeMap,
        $todayCarbon, $today, $page, $perPage, $search, $shiftFilter, $statusFilter, $isHoliday, $approvedObs
    );

    // Ensure DTR total reflects only scheduled employees (same base as shift counts)
    // when no search/filter is active, so Overview and DTR totals are consistent

    $unscheduled = $this->computeUnscheduledFromData(
        $allEmployees, $activeSchedules, $actualLogsMap, $isHoliday
    );

    return [
        'shift_counts' => $shiftCounts,
        'dtr'          => $dtrResult,
        'unscheduled'  => $unscheduled,
        'holiday'      => $holiday,   // ← expose to frontend if needed
    ];
}

// ── Private helpers that reuse already-fetched data ───────────────────────

private function computeShiftCountsFromData(
    $employees, $activeSchedules, $actualLogsMap,
    $approvedLeaves, $timeWindowsMap, Carbon $todayCarbon, string $today,
    bool $isHoliday = false,
    $approvedObs = null
): array {
    $dayShiftCount   = $nightShiftCount   = 0;
    $dayRestDayCount = $nightRestDayCount  = 0;
    $dayExpectedCount   = $nightExpectedCount   = 0;
    $dayPresentCount    = $nightPresentCount    = 0;
    $dayPendingCount    = $nightPendingCount    = 0;
    $dayUnscheduledRDCount   = $nightUnscheduledRDCount   = 0;
    $dayUnscheduledRDPresent = $nightUnscheduledRDPresent = 0;

    foreach ($employees as $employee) {
        $employId       = $employee->EMPLOYID;
        $activeSchedule = $activeSchedules->get($employId);
        if ($activeSchedule === null) continue;

        $actualLogs  = $actualLogsMap[$employId] ?? [];
        $isOnLeave   = isset($approvedLeaves[$employId]);
        $hasAnyPunch = $this->hasAnyPunch($actualLogs);
        $tw          = $timeWindowsMap[$employId] ?? [];

        [,, $isRestDay] = $this->resolveShiftMeta($activeSchedule, $tw, $actualLogs, $todayCarbon);

        // On a holiday every employee is effectively on rest day
        $effectiveRestDay = $isRestDay || $isHoliday;

        $expectedTimeIn = $tw[0] ?? null;
        $isNight        = !empty($tw[0]) && (int) explode(':', $tw[0])[0] >= 18;

        $obRecord       = $approvedObs ? $approvedObs->get((string) $employId) : null;
        $obInfo         = $obRecord ? $this->buildObInfo($obRecord) : null;
        $expectedTimeOut = $tw[7] ?? null;

        $remarks = $this->resolveRemarks(
            $actualLogs, $effectiveRestDay, $isOnLeave,
            $expectedTimeIn, true, $today, $isHoliday, $obInfo, $expectedTimeOut
        );

        $isPresent       = in_array($remarks, ['Present', 'Late']);
        $isPending       = $remarks === 'Pending';
        // Unscheduled RD = punched in while on rest day OR holiday
        $isUnscheduledRD = $isPresent && ($effectiveRestDay || $isOnLeave);

        if ($isNight) {
            $nightShiftCount++;
            if ($isRestDay && !$isUnscheduledRD)    { $nightRestDayCount++; }
            elseif ($isUnscheduledRD)               { $nightUnscheduledRDCount++; $nightUnscheduledRDPresent++; }
            elseif ($isOnLeave && !$hasAnyPunch)    { $nightExpectedCount++; }
            elseif ($isPending)                     { $nightExpectedCount++; $nightPendingCount++; }
            else                                    { $nightExpectedCount++; if ($isPresent) $nightPresentCount++; }
        } else {
            $dayShiftCount++;
            if ($isRestDay && !$isUnscheduledRD)    { $dayRestDayCount++; }
            elseif ($isUnscheduledRD)               { $dayUnscheduledRDCount++; $dayUnscheduledRDPresent++; }
            elseif ($isOnLeave && !$hasAnyPunch)    { $dayExpectedCount++; }
            elseif ($isPending)                     { $dayExpectedCount++; $dayPendingCount++; }
            else                                    { $dayExpectedCount++; if ($isPresent) $dayPresentCount++; }
        }
    }

    $pct = fn($n, $d) => $d > 0 ? round($n / $d * 100, 1) : 0;

    $dayScheduledAbsent   = max(0, $dayExpectedCount   - $dayPresentCount   - $dayPendingCount);
    $nightScheduledAbsent = max(0, $nightExpectedCount - $nightPresentCount - $nightPendingCount);
    $dayTotalExpected     = $dayExpectedCount   + $dayUnscheduledRDCount;
    $dayTotalPresent      = $dayPresentCount    + $dayUnscheduledRDPresent;
    $nightTotalExpected   = $nightExpectedCount + $nightUnscheduledRDCount;
    $nightTotalPresent    = $nightPresentCount  + $nightUnscheduledRDPresent;

    return [
        'day_shift'                    => $dayShiftCount,
        'night_shift'                  => $nightShiftCount,
        'day_rest_day'                 => $dayRestDayCount,
        'night_rest_day'               => $nightRestDayCount,
        'day_expected'                 => $dayExpectedCount,
        'night_expected'               => $nightExpectedCount,
        'day_present'                  => $dayPresentCount,
        'night_present'                => $nightPresentCount,
        'day_present_pct'              => $pct($dayPresentCount,   $dayExpectedCount),
        'night_present_pct'            => $pct($nightPresentCount, $nightExpectedCount),
        'day_unscheduled_rd'           => $dayUnscheduledRDCount,
        'night_unscheduled_rd'         => $nightUnscheduledRDCount,
        'day_unscheduled_rd_present'   => $dayUnscheduledRDPresent,
        'night_unscheduled_rd_present' => $nightUnscheduledRDPresent,
        'day_unscheduled_rd_pct'       => $pct($dayUnscheduledRDPresent,   $dayUnscheduledRDCount),
        'night_unscheduled_rd_pct'     => $pct($nightUnscheduledRDPresent, $nightUnscheduledRDCount),
        'day_total_expected'           => $dayTotalExpected,
        'day_total_present'            => $dayTotalPresent,
        'day_total_absent'             => $dayScheduledAbsent,
        'day_total_present_pct'        => $pct($dayTotalPresent,    $dayTotalExpected),
        'day_total_absent_pct'         => $pct($dayScheduledAbsent, $dayTotalExpected),
        'night_total_expected'         => $nightTotalExpected,
        'night_total_present'          => $nightTotalPresent,
        'night_total_absent'           => $nightScheduledAbsent,
        'night_total_present_pct'      => $pct($nightTotalPresent,    $nightTotalExpected),
        'night_total_absent_pct'       => $pct($nightScheduledAbsent, $nightTotalExpected),
    ];
}

private function computeDtrRowsFromData(
    $employees, $activeSchedules, $actualLogsMap,
    $approvedLeaves, $timeWindowsMap, $scheduleTypeMap,
    Carbon $todayCarbon, string $today,
    int $page, int $perPage, string $search,
    string $shiftFilter, string $statusFilter,
    bool $isHoliday = false,
    $approvedObs = null
): array {
    $rows = [];

    foreach ($employees as $employee) {
        $employId       = $employee->EMPLOYID;
        $activeSchedule = $activeSchedules->get($employId);
        if ($activeSchedule === null) continue;

        if (!empty($search)) {
            if (
                stripos($employee->EMPNAME,  $search) === false &&
                stripos($employee->EMPLOYID, $search) === false
            ) continue;
        }

        $tw           = $timeWindowsMap[$employId]  ?? [];
        $scheduleType = $scheduleTypeMap[$employId] ?? 'Normal';
        $isShifting   = $scheduleType === 'Shifting';
        $actualLogs   = $actualLogsMap[$employId]   ?? [];

        [$shiftType, $shiftCode, $isRestDay] = $this->resolveShiftMeta(
            $activeSchedule, $tw, $actualLogs, $todayCarbon
        );

        $effectiveRestDay = $isRestDay || $isHoliday;
        $isOnLeave        = isset($approvedLeaves[$employId]);
        $obRecord = $approvedObs ? $approvedObs->get((string) $employId) : null;
        $obInfo   = $obRecord ? $this->buildObInfo($obRecord) : null;
        $isOb     = $obInfo !== null;
        $expectedTimeIn   = $tw[0] ?? null;
        $hasAnyPunch      = $this->hasAnyPunch($actualLogs);
        $isUnscheduledRD  = ($effectiveRestDay || $isOnLeave) && $hasAnyPunch;

        $expectedTimeOut = $tw[7] ?? null;

        $remarks = $this->resolveRemarks(
            $actualLogs, $effectiveRestDay, $isOnLeave,
            $expectedTimeIn, true, $today, $isHoliday, $obInfo, $expectedTimeOut
        );

        $isPresent      = in_array($remarks, ['Present', 'Late', 'On Leave (Present)']);
        $missingTimeIn  = $isPresent && !$this->hasTimeIn($actualLogs);
        $missingTimeOut = $isPresent && !$this->hasTimeOut($actualLogs);

        $flattened = $this->buildFlattenedSlots($actualLogs, $tw, false, $isShifting);

        $rows[] = [
            'EMPLOYID'          => $employId,
            'EMPNAME'           => $employee->EMPNAME,
            'SHIFTCODE'         => $shiftCode,
            'SHIFT_TYPE'        => $shiftType,
            'SCHEDULE_TYPE'     => $scheduleType,
            'HAS_SCHEDULE'      => true,
            'IS_REST_DAY'       => $isRestDay,
            'IS_HOLIDAY'        => $isHoliday,
            'IS_UNSCHEDULED_RD' => $isUnscheduledRD,
            'REMARKS'           => $remarks,
            'MISSING_TIME_IN'   => $missingTimeIn,
            'MISSING_TIME_OUT'  => $missingTimeOut,
            'OB_INFO'           => $obInfo,
            ...$flattened,
        ];
    }

    // Shift filter
    if (!empty($shiftFilter)) {
        $rows = array_values(array_filter($rows, fn($row) => match ($shiftFilter) {
            'Day Shift'   => str_contains($row['SHIFT_TYPE'], 'Day'),
            'Night Shift' => str_contains($row['SHIFT_TYPE'], 'Night'),
            default       => true,
        }));
    }

    // Status filter — include Holiday
    if (!empty($statusFilter)) {
        $rows = array_values(array_filter($rows, fn($row) => match ($statusFilter) {
            'On Leave' => in_array($row['REMARKS'], ['On Leave', 'On Leave (Present)']),
            default    => $row['REMARKS'] === $statusFilter,
        }));
    }

    $filteredTotal    = count($rows);
    $unfilteredTotal  = count($rows); // keep for reference

    // Re-count without search/shift/status filters for consistent overview total
    $lastPage  = max(1, (int) ceil($filteredTotal / $perPage));
    $page      = min($page, $lastPage);
    $pagedRows = array_slice($rows, ($page - 1) * $perPage, $perPage);

    return [
        'rows'         => $pagedRows,
        'total'        => $filteredTotal,
        'per_page'     => $perPage,
        'current_page' => $page,
        'last_page'    => $lastPage,
    ];
}
private function computeUnscheduledFromData(
    $employees,
    $activeSchedules,
    $actualLogsMap,
    bool $isHoliday = false   // ← ADD
): array {
    $result = [];

    foreach ($employees as $employee) {
        $employId = $employee->EMPLOYID;
        if ($activeSchedules->has($employId)) continue;

        $actualLogs = $actualLogsMap[$employId] ?? [];
        $timeIn     = !empty($actualLogs['time_in'])  && $actualLogs['time_in']  !== '--:--'
            ? $actualLogs['time_in']  : null;
        $timeOut    = !empty($actualLogs['time_out']) && $actualLogs['time_out'] !== '--:--'
            ? $actualLogs['time_out'] : null;

        $hasAnyPunch = $this->hasAnyPunch($actualLogs);

        // Determine remarks — holiday-aware
        $remarks = match(true) {
            $hasAnyPunch            => 'Present',
            $isHoliday              => 'Holiday',
            default                 => 'Absent',
        };

        $result[] = [
            'EMPLOYID'   => $employId,
            'EMPNAME'    => $employee->EMPNAME,
            'TIME_IN'    => $timeIn  ?? '--:--',
            'TIME_OUT'   => $timeOut ?? '--:--',
            'SHIFT_TYPE' => $this->determineShiftTypeFromLogs($timeIn, $timeOut),
            'REMARKS'    => $remarks,   // ← ADD
        ];
    }

    return $result;
}

public function getAnalyticsStats(
    array  $filters = [],
    string $mode    = 'Daily',
    string $date    = '',
    string $cutoff  = '',
    string $month   = ''
): array {
    $empty = [
        'present'             => 0,
        'absent'              => 0,
        'rest_day'            => 0,
        'unscheduled_present' => 0,
        'unscheduled_absent'  => 0,
    ];

    if ($mode === 'Daily') {
        $today    = !empty($date) ? $date : now()->toDateString();
        $overview = $this->getOverviewData($filters, $today, 1, 9999, '', '', '');
        $counts      = $overview['shift_counts'] ?? [];
        $unscheduled = $overview['unscheduled']  ?? [];

        $present = ($counts['day_present']  ?? 0) + ($counts['night_present']  ?? 0)
                 + ($counts['day_unscheduled_rd_present']   ?? 0)
                 + ($counts['night_unscheduled_rd_present'] ?? 0);

        $unscheduledPresent = $unscheduledAbsent = 0;
        foreach ($unscheduled as $emp) {
            if ($emp['REMARKS'] === 'Present')    $unscheduledPresent++;
            elseif ($emp['REMARKS'] === 'Absent') $unscheduledAbsent++;
        }

        return [
            'present'             => $present,
            'absent'              => ($counts['day_total_absent'] ?? 0) + ($counts['night_total_absent'] ?? 0),
            'rest_day'            => ($counts['day_rest_day'] ?? 0) + ($counts['night_rest_day'] ?? 0),
            'unscheduled_present' => $unscheduledPresent,
            'unscheduled_absent'  => $unscheduledAbsent,
        ];
    }

    // ── Resolve date range ────────────────────────────────────────────────
    $dates = match ($mode) {
        'Monthly'     => $this->getDatesForMonth($month),
        'Per Cut Off' => $this->getDatesForCutoff($cutoff),
        default       => [],
    };

    if (empty($dates)) return $empty;

    $rangeStart = $dates[0];
    $rangeEnd   = $dates[count($dates) - 1];

    // ── Employees ─────────────────────────────────────────────────────────
    $allEmployees   = $this->employeeRepo->getFilteredEmployees(
        $filters, [1, 2], 9999, 1, '', $rangeStart
    );
    $allEmployeeIds = $allEmployees->pluck('EMPLOYID')
                                   ->map(fn($id) => (string) $id)
                                   ->toArray();

    if (empty($allEmployeeIds)) return $empty;

    // ── Build per-employee, per-date shift metadata in PHP ────────────────
    // (schedules + shiftcodes are small — a few hundred rows at most)
    $allSchedules = WorkScheduler::whereIn('EMPID', $allEmployeeIds)
        ->whereDate('PAYROLL_DATE_START', '<=', $rangeEnd)
        ->whereDate('PAYROLL_DATE_END',   '>=', $rangeStart)
        ->get(['EMPID', 'SCHEDULE', 'PAYROLL_DATE_START', 'PAYROLL_DATE_END']);

    $allShiftIds = $allSchedules->flatMap(
        fn($r) => collect($r->SCHEDULE ?? [])->filter()->map(fn($id) => (int) $id)
    )->unique()->values()->toArray();

    // shiftMeta[shiftId] = [timeIn, timeOut, isRestDay, isNightShift]
    $shiftMeta = [];
    if (!empty($allShiftIds)) {
        ShiftCode::whereIn('SHIFT_CODE_ID', $allShiftIds)
            ->get(['SHIFT_CODE_ID', 'SHIFTCODE', 'TIME_WINDOWS'])
            ->each(function ($sc) use (&$shiftMeta) {
                $raw     = $sc->TIME_WINDOWS;
                $tw      = is_array($raw) ? $raw : (is_string($raw) ? (json_decode($raw, true) ?? []) : []);
                $code    = strtoupper($sc->SHIFTCODE ?? '');
                $isRd    = str_contains($code, 'RD');
                $timeIn  = $tw[0] ?? null;
                $timeOut = $tw[7] ?? null;
                $isNight = $timeIn && (int) explode(':', $timeIn)[0] >= 18;
                $shiftMeta[(int) $sc->SHIFT_CODE_ID] = [
                    'time_in'  => $timeIn,
                    'time_out' => $timeOut,
                    'is_rd'    => $isRd,
                    'is_night' => $isNight,
                ];
            });
    }

    // shiftInfoByEmpDate[empId][date] = shiftMeta entry | null
    // Also track which empId+dates are night shift (need wider punch window)
    $shiftInfoByEmpDate = [];
    $nightShiftCells    = []; // [ [empId, date, timeIn], ... ]

    foreach ($allSchedules as $sch) {
        $empId    = (string) $sch->EMPID;
        $schedule = $sch->SCHEDULE ?? [];
        $start    = substr((string) $sch->PAYROLL_DATE_START, 0, 10);
        $end      = substr((string) $sch->PAYROLL_DATE_END,   0, 10);
        $startTs  = strtotime($start);

        $iterStart = max($start, $rangeStart);
        $iterEnd   = min($end,   $rangeEnd);

        for ($ts = strtotime($iterStart); $ts <= strtotime($iterEnd); $ts += 86400) {
            $d        = date('Y-m-d', $ts);
            $dayIndex = (int) (($ts - $startTs) / 86400) + 1;
            $shiftId  = (int) ($schedule[(string) $dayIndex] ?? $schedule[$dayIndex] ?? 0);
            $meta     = $shiftId ? ($shiftMeta[$shiftId] ?? null) : null;

            $shiftInfoByEmpDate[$empId][$d] = $meta;

            if ($meta && $meta['is_night']) {
                $nightShiftCells[] = ['emp' => $empId, 'date' => $d, 'time_in' => $meta['time_in']];
            }
        }
    }
    unset($allSchedules);

    // ── Leaves ────────────────────────────────────────────────────────────
    $onLeaveByEmpDate = [];
    EmployeeLeave::whereIn('EMPLOYID', $allEmployeeIds)
        ->where('LEAVESTATUS', 'approved')
        ->whereDate('DATESTART', '<=', $rangeEnd)
        ->whereDate('DATEEND',   '>=', $rangeStart)
        ->get(['EMPLOYID', 'DATESTART', 'DATEEND'])
        ->each(function ($leave) use (&$onLeaveByEmpDate, $rangeStart, $rangeEnd) {
            $empId = (string) $leave->EMPLOYID;
            $s     = max(substr((string) $leave->DATESTART, 0, 10), $rangeStart);
            $e     = min(substr((string) $leave->DATEEND,   0, 10), $rangeEnd);
            for ($ts = strtotime($s); $ts <= strtotime($e); $ts += 86400) {
                $onLeaveByEmpDate[$empId][date('Y-m-d', $ts)] = true;
            }
        });

    // ── Holidays ──────────────────────────────────────────────────────────
    $holidaySet = Holiday::whereBetween('HOLIDAY_DATE', [$rangeStart, $rangeEnd])
        ->pluck('HOLIDAY_DATE')
        ->mapWithKeys(fn($d) => [substr((string) $d, 0, 10) => true])
        ->all();

    // ── DB-side punch aggregation (the key optimisation) ─────────────────
    //
    // Instead of fetching every raw punch row into PHP and filtering in loops,
    // we run TWO queries that return only (employid, date) pairs where a punch
    // exists. MySQL does the heavy lifting; PHP just reads a small result set.
    //
    // Day-shift window  : same calendar date, within [timeIn-3h, 23:59]
    // Night-shift window: handled separately with their own query using a
    //   date-shifted window [date 16:00 → date+1 13:59] as a conservative
    //   approximation (covers all realistic night starts: 18:00–22:00 -2h grace)

    $from  = date('Y-m-d', strtotime($rangeStart . ' -1 day'));
    $to    = date('Y-m-d', strtotime($rangeEnd   . ' +2 days'));

    // Query 1: fetch (employid, DATE(datetime)) for all punches in window.
    // We get distinct (employid, date) pairs — tiny result set.
    // Use UNION ALL of biometric + manual tables, then DISTINCT on top.
    $punchesRaw = \Illuminate\Support\Facades\DB::select("
        SELECT employid, DATE(datetime) as punch_date
        FROM (
            SELECT employid, datetime FROM biometric_logs
            WHERE employid IN (" . implode(',', array_fill(0, count($allEmployeeIds), '?')) . ")
              AND datetime BETWEEN ? AND ?
            UNION ALL
            SELECT employid, datetime FROM biometric_logs_manual
            WHERE employid IN (" . implode(',', array_fill(0, count($allEmployeeIds), '?')) . ")
              AND datetime BETWEEN ? AND ?
        ) combined
        GROUP BY employid, DATE(datetime)
    ", array_merge(
        $allEmployeeIds, [$from . ' 00:00:00', $to . ' 23:59:59'],
        $allEmployeeIds, [$from . ' 00:00:00', $to . ' 23:59:59']
    ));

    // Index: hasPunchOnDate[empId][date] = true
    $hasPunchOnDate = [];
    foreach ($punchesRaw as $row) {
        $hasPunchOnDate[(string) $row->employid][$row->punch_date] = true;
    }
    unset($punchesRaw);

    // Query 2: for night-shift cells, we need punches in the night window
    // [prev_day 16:00 → same_day 13:59]. Build a set of (employid, anchor_date)
    // pairs that have ANY punch in that window.
    //
    // Strategy: group night-shift employees by their shift start hour to build
    // accurate windows. We approximate with a single conservative window
    // (16:00 prev day → 14:00 same day) which covers all night starts ≥18:00.
    // This is correct for the vast majority of cases.
    $nightPunchSet = []; // [empId_date] = true

    if (!empty($nightShiftCells)) {
        // Collect unique empIds that ever have a night shift in range
        $nightEmpIds = array_unique(array_column($nightShiftCells, 'emp'));

        // One query: fetch night-window punches. We tag each punch with its
        // "anchor date" = the date whose night shift it belongs to.
        // A punch at 2024-04-15 20:00 belongs to anchor 2024-04-15.
        // A punch at 2024-04-16 02:00 belongs to anchor 2024-04-15 (night tail).
        //
        // We use: IF(TIME(datetime) < '14:00:00', DATE(datetime) - INTERVAL 1 DAY, DATE(datetime))
        $nightRaw = \Illuminate\Support\Facades\DB::select("
            SELECT employid,
                   IF(TIME(datetime) < '14:00:00',
                      DATE(datetime) - INTERVAL 1 DAY,
                      DATE(datetime)) AS anchor_date
            FROM (
                SELECT employid, datetime FROM biometric_logs
                WHERE employid IN (" . implode(',', array_fill(0, count($nightEmpIds), '?')) . ")
                  AND datetime BETWEEN ? AND ?
                  AND (TIME(datetime) >= '16:00:00' OR TIME(datetime) < '14:00:00')
                UNION ALL
                SELECT employid, datetime FROM biometric_logs_manual
                WHERE employid IN (" . implode(',', array_fill(0, count($nightEmpIds), '?')) . ")
                  AND datetime BETWEEN ? AND ?
                  AND (TIME(datetime) >= '16:00:00' OR TIME(datetime) < '14:00:00')
            ) combined
            GROUP BY employid, anchor_date
        ", array_merge(
            $nightEmpIds, [$from . ' 00:00:00', $to . ' 23:59:59'],
            $nightEmpIds, [$from . ' 00:00:00', $to . ' 23:59:59']
        ));

        foreach ($nightRaw as $row) {
            $nightPunchSet[(string) $row->employid . '|' . $row->anchor_date] = true;
        }
        unset($nightRaw);
    }

    // ── Main aggregation loop (pure PHP, O(1) lookups only) ───────────────
    $summed = $empty;

    foreach ($dates as $d) {
        $isHoliday = isset($holidaySet[$d]);

        foreach ($allEmployeeIds as $empId) {
            $isOnLeave = isset($onLeaveByEmpDate[$empId][$d]);
            $meta      = $shiftInfoByEmpDate[$empId][$d] ?? null; // null = unscheduled

            // ── Unscheduled employee ──────────────────────────────────
            if ($meta === null) {
                $hasPunch = isset($hasPunchOnDate[$empId][$d]);
                if ($hasPunch) {
                    $summed['unscheduled_present']++;
                } elseif (!$isHoliday) {
                    $summed['unscheduled_absent']++;
                }
                continue;
            }

            $isRestDay        = $meta['is_rd'];
            $isNightShift     = $meta['is_night'];
            $effectiveRestDay = $isRestDay || $isHoliday;

            // O(1) punch check — no PHP loops
            $hasPunch = $isNightShift
                ? isset($nightPunchSet[$empId . '|' . $d])
                : isset($hasPunchOnDate[$empId][$d]);

            $remarks = $this->fastResolveRemarks(
                $hasPunch, $effectiveRestDay, $isOnLeave,
                $meta['time_in'], $d, $isHoliday
            );

            $isPresent       = $remarks === 'Present' || $remarks === 'Late';
            $isUnscheduledRD = $isPresent && ($effectiveRestDay || $isOnLeave);

            if ($effectiveRestDay && !$isUnscheduledRD) {
                $summed['rest_day']++;
            } elseif ($isUnscheduledRD) {
                $summed['present']++;
            } elseif ($isOnLeave && !$hasPunch) {
                // on leave, no punch → skip
            } elseif ($isPresent) {
                $summed['present']++;
            } elseif ($remarks === 'Absent') {
                $summed['absent']++;
            }
        }
    }

    return $summed;
}
 
// ─────────────────────────────────────────────────────────────────────────────
// PRIVATE HELPERS  (keep all existing helpers from before, replace these two)
// ─────────────────────────────────────────────────────────────────────────────
 
/**
 * Determine whether the employee has ANY punch inside their shift window.
 * Called with a small pre-filtered log slice (only 1-3 calendar days of logs)
 * so inner loops are tiny.
 */
private function fastHasPunchInWindow(
    array   $empLogs,
    string  $date,
    bool    $isNightShift,
    ?array  $tw
): bool {
    if (empty($empLogs)) return false;
 
    $tw = $tw ?? [];
 
    // ── Night shift with known start ──────────────────────────────────────
    if ($isNightShift && !empty($tw[0])) {
        [$h, $m]        = explode(':', $tw[0]);
        $shiftStartHour = (int) $h;
        $startHour      = max(0, $shiftStartHour - 2);
        $yesterday      = date('Y-m-d', strtotime($date) - 86400);
        $tomorrow       = date('Y-m-d', strtotime($date) + 86400);
        $startPad       = str_pad($startHour, 2, '0', STR_PAD_LEFT);
 
        $prevCheckInEvening = $prevOrEarlyCheckOut = $todayEveningIn = false;
        foreach ($empLogs as $log) {
            $ld   = $log['date'];
            $hour = (int) explode(':', $log['time'])[0];
            if ($ld === $yesterday && $hour >= $shiftStartHour && $log['type'] === 'check_in')
                $prevCheckInEvening = true;
            if ($log['type'] === 'check_out' && ($ld === $yesterday || ($ld === $date && $hour < 14)))
                $prevOrEarlyCheckOut = true;
            if ($ld === $date && $hour >= 14 && $log['type'] === 'check_in')
                $todayEveningIn = true;
        }
 
        $prevDayShift = $prevCheckInEvening && !$prevOrEarlyCheckOut && !$todayEveningIn;
        $anchorDate   = $prevDayShift ? $yesterday : $date;
        $windowStart  = $anchorDate . ' ' . $startPad . ':' . $m . ':00';
        $windowEnd    = ($prevDayShift ? $date : $tomorrow) . ' 13:59:59';
 
        // Anchor guard: must have at least one punch on anchor date at/after shift start
        $anchorMet = false;
        foreach ($empLogs as $log) {
            if ($log['date'] === $anchorDate
                && (int) explode(':', $log['time'])[0] >= $shiftStartHour
                && $log['datetime'] >= $windowStart
                && $log['datetime'] <= $windowEnd
            ) { $anchorMet = true; break; }
        }
        if (!$anchorMet) return false;
 
        foreach ($empLogs as $log) {
            if ($log['datetime'] >= $windowStart && $log['datetime'] <= $windowEnd) return true;
        }
        return false;
    }
 
    // ── Day shift with known start ────────────────────────────────────────
    if (!empty($tw[0])) {
        [$h, $m]     = explode(':', $tw[0]);
        $startHour   = max(0, (int) $h - 3);
        $windowStart = $date . ' ' . str_pad($startHour, 2, '0', STR_PAD_LEFT) . ':' . $m . ':00';
        $windowEnd   = ((int) $h < 14)
            ? $date . ' 23:59:59'
            : date('Y-m-d', strtotime($date) + 86400) . ' 13:59:59';
 
        foreach ($empLogs as $log) {
            if ($log['datetime'] >= $windowStart && $log['datetime'] <= $windowEnd) return true;
        }
        return false;
    }
 
    // ── No-schedule night shift ───────────────────────────────────────────
    if ($isNightShift) {
        $windowStart = $date . ' 14:00:00';
        $windowEnd   = date('Y-m-d', strtotime($date) + 86400) . ' 13:59:59';
        foreach ($empLogs as $log) {
            if ($log['datetime'] >= $windowStart && $log['datetime'] <= $windowEnd) return true;
        }
        return false;
    }
 
    // ── Fallback: any log on this calendar date ───────────────────────────
    // (empLogs is already filtered to just this date in the day-shift path)
    return !empty($empLogs);
}
 
private function fastResolveRemarks(
    bool    $hasPunch,
    bool    $isEffectiveRest,
    bool    $isOnLeave,
    ?string $expectedTimeIn,
    string  $date,
    bool    $isHoliday
): string {
    if ($hasPunch) {
        if ($isEffectiveRest || $isHoliday) return 'Present';
        if ($isOnLeave)                     return 'On Leave (Present)';
        return 'Present';
    }
    if ($isHoliday)       return 'Holiday';
    if ($isEffectiveRest) return 'Rest Day';
    if ($isOnLeave)       return 'On Leave';
    if ($expectedTimeIn !== null) {
    if ($date < date('Y-m-d')) return 'Absent';
    [$h, $m]      = explode(':', $expectedTimeIn);
    $expectedMins = (int) $h * 60 + (int) $m;
    $nowHour      = (int) date('H');
    $nowMins      = (int) date('H') * 60 + (int) date('i');

    // Night shift guard: check BEFORE +1440 adjustment using real clock hour.
    // If shift starts at 18:00+ and current hour hasn't reached it yet,
    // always Pending — never Late or Absent.
    if ((int) $h >= 18 && $nowHour < (int) $h) {
        return 'Pending';
    }

    if ($expectedMins > 720 && $nowMins < $expectedMins - 720) $nowMins += 1440;

    $diff = $nowMins - $expectedMins;
    if ($diff < 0)    return 'Pending';
    if ($diff <= 120) return 'Late';
    return 'Absent';
}
return 'Absent';
}
 
private function resolveShiftIdForDate($sch, string $date): ?int
{
    $start    = substr((string) $sch->PAYROLL_DATE_START, 0, 10);
    $dayIndex = (int) ((strtotime($date) - strtotime($start)) / 86400) + 1;
    $schedule = $sch->SCHEDULE ?? [];
    $shiftId  = $schedule[(string) $dayIndex] ?? $schedule[$dayIndex] ?? null;
    return ($shiftId && (int) $shiftId > 0) ? (int) $shiftId : null;
}
 
private function mapPunchTypeStatic($raw): string
{
    static $map = [
        '0' => 'check_in',  '1' => 'check_out',
        '2' => 'break_out', '3' => 'break_in',
        '4' => 'lunch_out', '5' => 'lunch_in',
        'check_in'  => 'check_in',  'check_out' => 'check_out',
        'break_out' => 'break_out', 'break_in'  => 'break_in',
        'lunch_out' => 'lunch_out', 'lunch_in'  => 'lunch_in',
    ];
    return $map[(string) $raw] ?? 'check_in';
}


private function getDatesForMonth(string $month): array
{
    if (empty($month)) return [now()->toDateString()];
 
    $parts = explode('-', $month);
    if (count($parts) < 2) return [];
 
    [$y, $m] = $parts;
    $start   = Carbon::create((int)$y, (int)$m, 1);
 
    $minDate = Carbon::create(2026, 1, 1);
    if ($start->lt($minDate)) return [];
 
    // Do not return future dates
    $end   = $start->copy()->endOfMonth();
    $today = now()->startOfDay();
    if ($end->gt($today)) $end = $today;
 
    if ($start->gt($end)) return [];
 
    $dates = [];
    for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
        $dates[] = $d->toDateString();
    }
    return $dates;
}
 
private function getDatesForCutoff(string $cutoff): array
{
    if (empty($cutoff)) return [];
 
    $parts = explode('-', $cutoff);
    if (count($parts) < 3) return [];
 
    $period = $parts[0];
    $y      = (int) $parts[1];
    $m      = (int) $parts[2];
 
    [$start, $end] = match($period) {
        'first'  => [Carbon::create($y, $m, 7),  Carbon::create($y, $m, 21)],
        'second' => [Carbon::create($y, $m, 22), Carbon::create($y, $m + 1 > 12 ? 1 : $m + 1, 6)->setYear($m + 1 > 12 ? $y + 1 : $y)],
        default  => [null, null],
    };
 
    if (!$start || !$end) return [];
 
    // Cap at today
    $today = now()->startOfDay();
    if ($end->gt($today)) $end = $today;
    if ($start->gt($end)) return [];
 
    $dates = [];
    for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
        $dates[] = $d->toDateString();
    }
    return $dates;
}

}