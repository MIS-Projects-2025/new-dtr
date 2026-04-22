<?php

namespace App\Services;

use App\Models\EmployeeMasterlist;
use App\Models\WorkScheduler;
use App\Models\ShiftCode;
use App\Models\AttendanceLog;
use App\Models\BiometricLog;
use App\Models\BiometricLogManual;
use App\Models\Holiday;
use App\Repositories\EmployeeRepository;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class EmployeeService
{

public function __construct(
    private EmployeeRepository $employeeRepo,  // add this if not already injected
    private DtrLogService $dtrLogService,
) {}

    /**
     * Get all employees (basic info only)
     */
    public function getEmployees(): Collection
    {
        return EmployeeMasterlist::query()
            ->where('ACCSTATUS', 1)
            ->whereIn('EMPPOSITION', [1, 2])
            ->orderBy('EMPNAME')
            ->get([
                'EMPID',
                'EMPLOYID',
                'EMPNAME',
                'JOB_TITLE',
                'DEPARTMENT',
            ])
            ->map(fn(EmployeeMasterlist $employee) => $this->mapEmployeeData($employee));
    }

    /**
     * Get paginated employees with search functionality
     * 
     * @param string|null $search
     * @param int $perPage
     * @return LengthAwarePaginator
     */
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

    /**
     * Get employees with their schedule data (paginated)
     * 
     * @param string|null $search
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getEmployeesWithScheduleData($search = null, $perPage = 50): LengthAwarePaginator
    {
        $employees = $this->getPaginatedEmployees($search, $perPage);
        
        $employees->getCollection()->transform(function($employee) {
            return $this->attachScheduleDataToEmployee($employee);
        });
        
        return $employees;
    }

    /**
     * Attach schedule data to a single employee
     * 
     * @param EmployeeMasterlist $employee
     * @return EmployeeMasterlist
     */
    public function attachScheduleDataToEmployee($employee)
{
    // Get schedule for today for this employee
    $todaySchedule = $this->getEmployeeScheduleForDate(
        $employee->EMPLOYID,
        now()->format('Y-m-d')
    );

    // Get all schedule records for this employee
    $allSchedules = $this->getEmployeeSchedule($employee->EMPLOYID);

    // Format dates in all_schedules
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

    // ── Resolve fallback shift type when employee has no active schedule ──
    $fallbackShiftType = null;

    if (!$todaySchedule) {
        if (count($formattedSchedules) > 0) {
            // Use the SHIFT value from the most recent schedule record
            $mostRecent = collect($formattedSchedules)
                ->sortByDesc('PAYROLL_DATE_END')
                ->first();
            $fallbackShiftType = $mostRecent['SHIFT'] ?? null;
        }

        if ($fallbackShiftType === null) {
            // No schedule records at all — fall back to employee masterlist SHIFT column
            $fallbackShiftType = $employee->SHIFT ?? null;
        }
    }

    // Extract time_windows from today's shift for smart break slot assignment
    $timeWindows = [];
    if ($todaySchedule && isset($todaySchedule['shift_id'])) {
        $shiftCode = ShiftCode::find($todaySchedule['shift_id']);
        if ($shiftCode?->TIME_WINDOWS) {
            $tw = $shiftCode->TIME_WINDOWS;
            $timeWindows = is_string($tw)
                ? (json_decode($tw, true) ?? [])
                : (array) $tw;
        }
    }

    // Attach schedule data to the employee object
    $employee->today_schedule      = $todaySchedule;
    $employee->all_schedules       = $formattedSchedules;
    $employee->scheduler_records   = $formattedSchedules;
    $employee->fallback_shift_type = $fallbackShiftType; // ← new
    $employee->today_logs          = $this->getEmployeeTodayLogs($employee->EMPLOYID, $timeWindows);

    return $employee;
}

    /**
     * Map employee data — always cast employee_id to string
     */
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

    /**
     * Get employee schedule for a specific employee or all employees
     * 
     * @param int|null $employId
     * @return Collection
     */
    public function getEmployeeSchedule($employId = null): Collection
{
    $query = WorkScheduler::query();
    
    if ($employId) {
        $query->where('EMPID', $employId);
    }
    
    return $query
        ->orderBy('EMPNAME')
        ->get([
            'EMPID',
            'EMPNAME',
            'SCHEDULE',
            'PAYROLL_DATE_START',
            'PAYROLL_DATE_END',
            'SHIFT',
        ]);
}

    /**
     * Get schedule for a specific date for an employee
     * 
     * @param int $employId
     * @param string $date (Y-m-d format)
     * @return array|null
     */
    public function getEmployeeScheduleForDate($employId, $date)
{
    $schedules = $this->getEmployeeSchedule($employId);
    
    foreach ($schedules as $schedule) {
        // ✅ Use startOfDay() to strip time components before comparison
$startDate = Carbon::parse($schedule->PAYROLL_DATE_START)->startOfDay();
$endDate = Carbon::parse($schedule->PAYROLL_DATE_END)->startOfDay();
$targetDate = Carbon::parse($date)->startOfDay();

if ($targetDate->between($startDate, $endDate)) {
    $dayOfPeriod = (int) abs($targetDate->diffInDays($startDate)) + 1;

            
            $raw = $schedule->SCHEDULE;
$scheduleArray = [];

if (is_string($raw)) {
    $decoded = json_decode($raw, true);
    $scheduleArray = is_array($decoded) ? $decoded : [];
} elseif (is_array($raw)) {
    $scheduleArray = $raw;
}

            // ✅ Try both string and int keys
            $shiftId = $scheduleArray[(string) $dayOfPeriod]
                ?? $scheduleArray[$dayOfPeriod]
                ?? null;
            
            if ($shiftId) {
                $shiftCode = ShiftCode::find($shiftId);
                $shiftType = $schedule->SHIFT ?? $schedule->shift ?? null;

                return [
                    'shift_id'     => $shiftId,
                    'shift_code'   => $shiftCode?->SHIFTCODE,
                    'shift_type'   => (int) $shiftType, // 1 = Normal, 2 = Shifting
                    'is_shifting'  => (int) $shiftType === 2,
                    'schedule_id'  => $schedule->id ?? null,
                    'payroll_start'=> Carbon::parse($schedule->PAYROLL_DATE_START)->format('Y-m-d'),
                    'payroll_end'  => Carbon::parse($schedule->PAYROLL_DATE_END)->format('Y-m-d'),
                    'day_of_period'=> $dayOfPeriod,
                    'full_schedule'=> $scheduleArray,
                ];
            }
        }
    }
    
    return null;
}

    /**
     * Get a single employee by ID with their schedule data
     * 
     * @param int $empId
     * @return EmployeeMasterlist|null
     */
    public function getEmployeeWithSchedule($empId)
    {
        $employee = EmployeeMasterlist::where('EMPID', $empId)
            ->where('ACCSTATUS', 1)
            ->whereIn('EMPPOSITION', [1, 2])
            ->first();
            
        if (!$employee) {
            return null;
        }
        
        return $this->attachScheduleDataToEmployee($employee);
    }

    /**
     * Get schedule summary for all employees for a specific date
     * 
     * @param string|null $date
     * @return Collection
     */
    public function getScheduleSummary($date = null)
    {
        $date = $date ?? now()->format('Y-m-d');
        $employees = $this->getPaginatedEmployees(null, 9999);
        
        $summary = $employees->getCollection()->map(function($employee) use ($date) {
            $schedule = $this->getEmployeeScheduleForDate($employee->EMPID, $date);
            
            return [
                'EMPID' => $employee->EMPID,
                'EMPLOYID' => $employee->EMPLOYID,
                'EMPNAME' => $employee->EMPNAME,
                'DEPARTMENT' => $employee->DEPARTMENT,
                'JOB_TITLE' => $employee->JOB_TITLE,
                'has_schedule' => !is_null($schedule),
                'shift_id' => $schedule['shift_id'] ?? null,
                'shift_code' => $schedule['shift_code'] ?? null,
                'is_rest_day' => isset($schedule['shift_code']) && str_contains($schedule['shift_code'], 'RD'),
            ];
        });
        
        return $summary;
    }

/**
 * Get today's attendance logs for an employee from all three sources.
 * Priority: AttendanceLog > BiometricLog > BiometricLogManual
 *
 * BiometricLog/Manual only store generic break_out/break_in — they are
 * smart-assigned to the correct slot (break_out_1, lunch_out, break_out_2,
 * break_in_1, lunch_in, break_in_2) by comparing the actual punch time
 * against the expected times from the shift's time_windows array.
 *
 * time_windows index mapping (sequential):
 *   0 → time_in      1 → break_out_1   2 → break_in_1
 *   3 → lunch_out    4 → lunch_in      5 → break_out_2
 *   6 → break_in_2   7 → time_out
 *
 * @param string $employid
 * @param array  $timeWindows  Parsed time_windows array from shift code e.g. ["07:00","10:00",...]
 * @return array
 */
public function getEmployeeTodayLogs(string $employid, array $timeWindows = []): array
{
    $today = now()->format('Y-m-d');

    $result = [
        'time_in'     => null,
        'break_out_1' => null,
        'break_in_1'  => null,
        'lunch_out'   => null,
        'lunch_in'    => null,
        'break_out_2' => null,
        'break_in_2'  => null,
        'time_out'    => null,
    ];

    // Expected time slot definitions for smart-matching
    // Each entry maps a result key to its index in the time_windows array
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

    // ── 1. AttendanceLog (most granular — already has numbered breaks & lunch) ──
    $attendanceLogs = AttendanceLog::where('employid', $employid)
        ->whereDate('logged_at', $today)
        ->orderBy('logged_at')
        ->get(['log_type', 'logged_at']);

    foreach ($attendanceLogs as $log) {
        $time = Carbon::parse($log->logged_at)->format('H:i');
        match ($log->log_type) {
            'check_in'   => $result['time_in']     ??= $time,
            'check_out'  => $result['time_out']       = $time, // always use latest
            'break_out1' => $result['break_out_1']  ??= $time,
            'break_in1'  => $result['break_in_1']   ??= $time,
            'break_out2' => $result['break_out_2']  ??= $time,
            'break_in2'  => $result['break_in_2']   ??= $time,
            'lunch_out'  => $result['lunch_out']    ??= $time,
            'lunch_in'   => $result['lunch_in']     ??= $time,
            default      => null,
        };
    }

    // ── 2. BiometricLog — smart-assign break_out/in to correct slots ─────────
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

    // ── 3. BiometricLogManual — fill any remaining gaps ──────────────────────
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

/**
 * Assign an actual punch time to the closest unfilled slot
 * from the candidate list, based on the shift's expected time_windows.
 *
 * If no time_windows are available, falls back to filling slots sequentially
 * in the order they appear in $candidateSlots.
 *
 * @param string $actualTime     e.g. "10:03"
 * @param array  $candidateSlots e.g. [['key'=>'break_out_1','twIndex'=>1], ...]
 * @param array  $timeWindows    e.g. ["07:00","10:00","10:30",...]
 * @param array  &$result        The result array being built (mutated in place)
 */
private function assignToClosestSlot(
    string $actualTime,
    array  $candidateSlots,
    array  $timeWindows,
    array  &$result
): void {
    $bestKey  = null;
    $bestDiff = PHP_INT_MAX;

    foreach ($candidateSlots as $candidate) {
        // Skip slots that already have a value
        if ($result[$candidate['key']] !== null) continue;

        $expectedTime = $timeWindows[$candidate['twIndex']] ?? null;

        if ($expectedTime) {
            // Compare actual punch time against expected time window time
            $diff = abs($this->timeToMinutes($actualTime) - $this->timeToMinutes($expectedTime));
        } else {
            // No time_windows available — use slot order as tiebreaker
            // Assign a large diff so it only wins if nothing else matches
            $diff = 9999 + array_search($candidate, $candidateSlots);
        }

        if ($diff < $bestDiff) {
            $bestDiff = $diff;
            $bestKey  = $candidate['key'];
        }
    }

    if ($bestKey !== null) {
        $result[$bestKey] = $actualTime;
    }
}



/**
 * Get today's holiday info if it exists
 *
 * @param string|null $date Y-m-d format, defaults to today
 * @return array|null
 */
public function getTodayHoliday(?string $date = null): ?array
{
    $date = $date ?? now()->format('Y-m-d');

    $holiday = Holiday::whereDate('HOLIDAY_DATE', $date)->first();

    if (!$holiday) return null;

    return [
        'name'         => $holiday->HOLIDAY_NAME,
        'date'         => Carbon::parse($holiday->HOLIDAY_DATE)->format('Y-m-d'),
        'type'         => $holiday->HOLIDAY_TYPE, // e.g. "Regular", "Special"
        'is_regular'   => strtolower($holiday->HOLIDAY_TYPE) === 'regular',
        'is_special'   => strtolower($holiday->HOLIDAY_TYPE) === 'special',
    ];
}

/**
 * Get all shift codes mapping
 * 
 * @return array
 */
public function getShiftCodesMap()
{
    $shiftCodes = ShiftCode::where('SHIFT_CODE_STATUS', 1)->get();
    
    $map = [];
    foreach ($shiftCodes as $shiftCode) {
        $map[$shiftCode->SHIFT_CODE_ID] = [
            'shiftcode' => $shiftCode->SHIFTCODE,
            'shiftcode_value' => $shiftCode->SHIFTCODE_VALUE,
            'shiftcode_desc' => $shiftCode->SHIFTCODE_DESC,
            'shift_group' => $shiftCode->SHIFT_GROUP,
            'time_windows' => $shiftCode->TIME_WINDOWS,
            'bg_color' => $shiftCode->SHIFTCODE_BG_COLOR,
            'font_color' => $shiftCode->SHIFTCODE_FONT_COLOR,
        ];
    }
    
    return $map;
}


// Append this method
public function getFilteredWithOptions(array $filters = []): array
{
    $positions = [1, 2];

    $employees       = $this->employeeRepo->getFilteredEmployees($filters, $positions);
    $employIds       = $employees->pluck('EMPLOYID')->toArray();
    $activeSchedules = $this->employeeRepo->getActiveSchedules($employIds)->keyBy('EMPID');

    $today = now()->toDateString();
    $todayCarbon = \Carbon\Carbon::parse($today);

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

        $schedule      = $activeSchedule->SCHEDULE ?? [];
        $payrollStart  = $activeSchedule->PAYROLL_DATE_START;
        $shiftCodesMap = $activeSchedule->shift_codes_map ?? collect();

        $tw           = [];
        $scheduleType = 'Normal';

        if ($payrollStart) {
            // ── Use pre-parsed payroll start, avoid repeated Carbon::parse in loop ──
            $payrollStartDate = \Carbon\Carbon::parse($payrollStart)->startOfDay();
            $dayIndex = (int) $payrollStartDate->diffInDays($todayCarbon) + 1;
            $shiftId  = $schedule[(string) $dayIndex] ?? $schedule[$dayIndex] ?? null;

            if ($shiftId) {
                $shiftCode = $shiftCodesMap->get((int) $shiftId);
                $rawTw     = $shiftCode?->TIME_WINDOWS;

                $tw = is_array($rawTw)
                    ? $rawTw
                    : (is_string($rawTw) ? (json_decode($rawTw, true) ?? []) : []);

                $firstTime = $tw[0] ?? null;
                $lastTime  = $tw[7] ?? null;

                if ($firstTime && $lastTime) {
                    $durationMins = $this->timeToMinutes($lastTime) - $this->timeToMinutes($firstTime);
                    if ($durationMins < 0) $durationMins += 24 * 60;
                    $scheduleType = $durationMins >= 12 * 60 ? 'Shifting' : 'Normal';
                }
            }
        }

        $timeWindowsMap[$employId]  = $tw;
        $scheduleTypeMap[$employId] = $scheduleType;
    }

    $actualLogsMap = $this->dtrLogService->resolveLogsForEmployees(
        $employIds,
        $timeWindowsMap,
        $scheduleTypeMap
    );

    $employees = $employees->map(function ($employee) use (
        $activeSchedules,
        $actualLogsMap,
        $timeWindowsMap,
        $scheduleTypeMap
    ) {
        $employId = $employee->EMPLOYID;

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

// In EmployeeService.php

// In EmployeeService.php - Complete getDtrRows method

public function getDtrRows(array $filters = [], int $page = 1, int $perPage = 15, string $search = '', string $date = ''): array
{
    $positions   = [1, 2];
    $today       = !empty($date) ? $date : now()->toDateString();
    $todayCarbon = \Carbon\Carbon::parse($today);

    $total     = $this->employeeRepo->countFilteredEmployees($filters, $positions, $search, $today);
    $employees = $this->employeeRepo->getFilteredEmployees($filters, $positions, $perPage, $page, $search, $today);
    $employIds = $employees->pluck('EMPLOYID')->toArray();
    
    // Get active schedules - employees without schedules will not be in this collection
    $activeSchedules = $this->employeeRepo->getActiveSchedules($employIds, $today)->keyBy('EMPID');

    $timeWindowsMap  = [];
    $scheduleTypeMap = [];

    foreach ($employees as $employee) {
        $employId       = $employee->EMPLOYID;
        $activeSchedule = $activeSchedules->get($employId);

        if (!$activeSchedule) {
            // No schedule - empty time windows, default type
            $timeWindowsMap[$employId]  = [];
            $scheduleTypeMap[$employId] = 'Normal';
            continue;
        }

        $schedule      = $activeSchedule->SCHEDULE ?? [];
        $payrollStart  = $activeSchedule->PAYROLL_DATE_START;
        $shiftCodesMap = $activeSchedule->shift_codes_map ?? collect();

        $tw           = [];
        $scheduleType = 'Normal';

        if ($payrollStart) {
            $payrollStartDate = \Carbon\Carbon::parse($payrollStart)->startOfDay();
            $dayIndex = (int) $payrollStartDate->diffInDays($todayCarbon) + 1;
            $shiftId  = $schedule[(string) $dayIndex] ?? $schedule[$dayIndex] ?? null;

            if ($shiftId) {
                $shiftCode = $shiftCodesMap->get((int) $shiftId);
                $rawTw     = $shiftCode?->TIME_WINDOWS;

                $tw = is_array($rawTw)
                    ? $rawTw
                    : (is_string($rawTw) ? (json_decode($rawTw, true) ?? []) : []);

                $firstTime = $tw[0] ?? null;
                $lastTime  = $tw[7] ?? null;

                if ($firstTime && $lastTime) {
                    $firstMins    = $this->timeToMinutes($firstTime);
                    $lastMins     = $this->timeToMinutes($lastTime);
                    $durationMins = $lastMins - $firstMins;

                    if ($durationMins < 0) $durationMins += 24 * 60;
                    $scheduleType = $durationMins >= 12 * 60 ? 'Shifting' : 'Normal';
                }
            }
        }

        $timeWindowsMap[$employId]  = $tw;
        $scheduleTypeMap[$employId] = $scheduleType;
    }

    // Resolve logs for ALL employees (including those without schedules)
    $actualLogsMap = $this->dtrLogService->resolveLogsForEmployees(
        $employIds,
        $timeWindowsMap,
        $scheduleTypeMap,
        $today
    );

    $rows = [];

    foreach ($employees as $employee) {
        $employId       = $employee->EMPLOYID;
        $activeSchedule = $activeSchedules->get($employId);
        
        // Get time windows (empty for no schedule)
        $tw           = $timeWindowsMap[$employId] ?? [];
        $scheduleType = $scheduleTypeMap[$employId] ?? 'Normal';
        $isShifting   = $scheduleType === 'Shifting';
        $actualLogs   = $actualLogsMap[$employId] ?? [];

        // Determine shift type from first time window (if exists)
        $firstTime = $tw[0] ?? null;
        $shiftType = 'N/A';
        $isNightShift = false;
        
        if ($firstTime) {
            $firstHour = (int) explode(':', $firstTime)[0];
            if ($firstHour >= 18 || $firstHour < 6) {
                $shiftType = 'Night Shift';
                $isNightShift = true;
            } elseif ($firstHour >= 12 && $firstHour < 18) {
                $shiftType = 'Afternoon Shift';
            } else {
                $shiftType = 'Day Shift';
            }
        } elseif ($activeSchedule === null) {
            // No schedule - we need to detect night shift from actual logs
            $shiftType = 'No Schedule';
            // Check if the employee has night shift logs
            if (!empty($actualLogs['time_in'])) {
                $hour = (int) explode(':', $actualLogs['time_in'])[0];
                $isNightShift = ($hour >= 18 || $hour < 6);
                $shiftType = $isNightShift ? 'Night Shift (No Schedule)' : 'Day Shift (No Schedule)';
            }
        }

        // Get shift code (only if has schedule)
        $shiftCode = 'N/A';
        if ($activeSchedule) {
            $schedule      = $activeSchedule->SCHEDULE ?? [];
            $payrollStart  = $activeSchedule->PAYROLL_DATE_START;
            $shiftCodesMap = $activeSchedule->shift_codes_map ?? collect();

            if ($payrollStart) {
                $payrollStartDate = \Carbon\Carbon::parse($payrollStart)->startOfDay();
                $dayIndex = (int) $payrollStartDate->diffInDays($todayCarbon) + 1;
                $shiftId  = $schedule[(string) $dayIndex] ?? $schedule[$dayIndex] ?? null;

                if ($shiftId) {
                    $shiftCodeObj = $shiftCodesMap->get((int) $shiftId);
                    $shiftCode = $shiftCodeObj?->SHIFTCODE ?? 'N/A';
                }
            }
        }

        // Define slots with their labels
        $slotDefs = [
            ['key' => 'time_in',     'twIndex' => 0, 'label' => 'Time In',      'disabled' => false],
            ['key' => 'break_out_1', 'twIndex' => 1, 'label' => 'Break Out 1',  'disabled' => $isShifting],
            ['key' => 'break_in_1',  'twIndex' => 2, 'label' => 'Break In 1',   'disabled' => $isShifting],
            ['key' => 'lunch_out',   'twIndex' => 3, 'label' => 'Lunch Out',     'disabled' => false],
            ['key' => 'lunch_in',    'twIndex' => 4, 'label' => 'Lunch In',      'disabled' => false],
            ['key' => 'break_out_2', 'twIndex' => 5, 'label' => 'Break Out 2',  'disabled' => false],
            ['key' => 'break_in_2',  'twIndex' => 6, 'label' => 'Break In 2',   'disabled' => false],
            ['key' => 'time_out',    'twIndex' => 7, 'label' => 'Time Out',      'disabled' => false],
        ];

        $flattened = [];
        foreach ($slotDefs as $slot) {
            $label = $slot['label'];
            if ($slot['disabled']) {
                $flattened["{$label} (actual)"]   = null;
                $flattened["{$label} (expected)"] = null;
            } else {
                $actualValue = $actualLogs[$slot['key']] ?? null;
                $flattened["{$label} (actual)"]   = $actualValue ?? ($activeSchedule ? '--:--' : null);
                $flattened["{$label} (expected)"] = ($activeSchedule && isset($tw[$slot['twIndex']])) ? $tw[$slot['twIndex']] : null;
            }
        }

        $rows[] = [
            'EMPLOYID'      => $employId,
            'EMPNAME'       => $employee->EMPNAME,
            'SHIFTCODE'     => $shiftCode,
            'SHIFT_TYPE'    => $shiftType,
            'SCHEDULE_TYPE' => $activeSchedule ? $scheduleType : 'No Schedule',
            'HAS_SCHEDULE'  => $activeSchedule !== null,
            ...$flattened,
        ];
    }

    return [
        'rows'         => $rows,
        'total'        => $total,
        'per_page'     => $perPage,
        'current_page' => $page,
        'last_page'    => (int) ceil($total / $perPage),
    ];
}

/**
 * Get counts for Day Shift, Night Shift, and No Schedule employees
 * 
 * @param array $filters
 * @param string $date
 * @return array
 */
public function getShiftCounts(array $filters = [], string $date = ''): array
{
    $positions = [1, 2];
    $today = !empty($date) ? $date : now()->toDateString();
    $todayCarbon = \Carbon\Carbon::parse($today);
    
    // Get all employees matching the criteria (no pagination)
    $employees = $this->employeeRepo->getFilteredEmployees($filters, $positions, 9999, 1, '', $today);
    $employIds = $employees->pluck('EMPLOYID')->toArray();
    
    // Get active schedules for these employees
    $activeSchedules = $this->employeeRepo->getActiveSchedules($employIds, $today)->keyBy('EMPID');
    
    $dayShiftCount = 0;
    $nightShiftCount = 0;
    $noScheduleCount = 0;
    
    foreach ($employees as $employee) {
        $employId = $employee->EMPLOYID;
        $activeSchedule = $activeSchedules->get($employId);
        
        if (!$activeSchedule) {
            // No schedule found
            $noScheduleCount++;
            continue;
        }
        
        // Get the shift for today
        $schedule = $activeSchedule->SCHEDULE ?? [];
        $payrollStart = $activeSchedule->PAYROLL_DATE_START;
        $shiftCodesMap = $activeSchedule->shift_codes_map ?? collect();
        
        $shiftType = null;
        
        if ($payrollStart) {
            $payrollStartDate = \Carbon\Carbon::parse($payrollStart)->startOfDay();
            $dayIndex = (int) $payrollStartDate->diffInDays($todayCarbon) + 1;
            $shiftId = $schedule[(string) $dayIndex] ?? $schedule[$dayIndex] ?? null;
            
            if ($shiftId) {
                $shiftCode = $shiftCodesMap->get((int) $shiftId);
                if ($shiftCode) {
                    // Determine shift type based on time windows
                    $timeWindows = $shiftCode->TIME_WINDOWS;
                    if (is_string($timeWindows)) {
                        $timeWindows = json_decode($timeWindows, true);
                    }
                    
                    $firstTime = $timeWindows[0] ?? null;
                    if ($firstTime) {
                        $hour = (int) explode(':', $firstTime)[0];
                        // Night shift: starts at 18:00 (6 PM) or later, or before 6 AM
                        if ($hour >= 18 || $hour < 6) {
                            $nightShiftCount++;
                        } else {
                            $dayShiftCount++;
                        }
                    } else {
                        // Fallback: check shift code name
                        $shiftCodeName = $shiftCode->SHIFTCODE ?? '';
                        if (str_contains(strtoupper($shiftCodeName), 'NS')) {
                            $nightShiftCount++;
                        } else {
                            $dayShiftCount++;
                        }
                    }
                } else {
                    $dayShiftCount++; // Default to day shift if no shift code found
                }
            } else {
                $dayShiftCount++; // Default to day shift if no shift ID found
            }
        } else {
            $dayShiftCount++; // Default to day shift if no payroll start date
        }
    }
    
    return [
        'day_shift' => $dayShiftCount,
        'night_shift' => $nightShiftCount,
        'no_schedule' => $noScheduleCount,
        'total' => $employees->count(),
    ];
}

/**
 * Convert "HH:MM" time string to total minutes since midnight.
 *
 * @param string $time e.g. "10:30"
 * @return int
 */
private function timeToMinutes(string $time): int
{
    [$h, $m] = explode(':', $time);
    return (int) $h * 60 + (int) $m;
}

}