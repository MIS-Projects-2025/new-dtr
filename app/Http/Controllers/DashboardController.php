<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\BiometricLog;
use App\Models\BiometricLogManual;
use App\Models\EmployeeLeave;
use App\Models\EmployeeMasterlist;
use App\Models\Holiday;
use App\Models\ObRecord;
use App\Models\ShiftCode;
use App\Models\VPLog;
use App\Models\WorkScheduler;
use App\Services\AttendanceCountService;
use App\Services\DailyTimeRecordService;
use App\Services\DtrRowsService;
use App\Services\AllEmployeesDtrService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
        public function __construct(
        private readonly DailyTimeRecordService $dtrService,
        private readonly AttendanceCountService $attendanceCountService,
        private readonly DtrRowsService $dtrRowsService,
        private readonly AllEmployeesDtrService  $allEmployeesDtrService,
    ) {}

    // ── Main dashboard page ────────────────────────────────────────────────

    public function index(Request $request)
{
    $employId    = session('emp_data.emp_id');
    $empPosition = session('emp_data.emp_position');
    $today       = now()->toDateString();

    // Shift log is only shown to employees (position 1), cache per user per day
    $shiftLogs = [];
    if ((int) $empPosition === 1) {
        $shiftLogs = \Cache::remember(
            "shift_log_{$employId}_{$today}",
            now()->addMinutes(5),
            fn () => $this->dtrService->getLatestShiftLog($employId)
        );
    }

    // Employee statuses are shared across all users — cache once per day
    $employees = \Cache::remember(
        "dashboard_employee_statuses_{$today}",
        now()->addMinutes(5),
        function () use ($today) {
            $employees = EmployeeMasterlist::where('ACCSTATUS', 1)
                ->whereBetween('EMPPOSITION', [2, 5])
                ->where('BIOMETRIC_STATUS', 'enabled')
                ->orderBy('LASTNAME')
                ->orderBy('FIRSTNAME')
                ->get([
                    'EMPID', 'EMPLOYID', 'EMPNAME',
                    'FIRSTNAME', 'LASTNAME',
                    'JOB_TITLE', 'EMPPOSITION',
                    'DEPARTMENT', 'EMPSTATUS',
                ]);

            $employIds = $employees->pluck('EMPLOYID')->filter()->values()->all();
            $empIds    = $employees->pluck('EMPID')->filter()->values()->all();

            $isHoliday = Holiday::whereDate('HOLIDAY_DATE', $today)->exists();

            $leaveByEmployId = EmployeeLeave::whereIn('EMPLOYID', $employIds)
                ->where('LEAVESTATUS', 'approved')
                ->where('DATESTART', '<=', $today)
                ->where('DATEEND',   '>=', $today)
                ->get(['EMPLOYID', 'TYPEOFLEAVE', 'LEAVE_DURATION'])
                ->keyBy('EMPLOYID');

            $obByEmpId = ObRecord::whereIn('EMPID', $empIds)
                ->whereIn('STATUS', ['1', '2'])
                ->where('DATE_OB_FROM', '<=', $today)
                ->where('DATE_OB_TO',   '>=', $today)
                ->get(['EMPID', 'FORM_TYPE', 'TIME_FROM', 'TIME_TO'])
                ->keyBy('EMPID');

            $shiftCodes   = ShiftCode::all()->keyBy('SHIFT_CODE_ID');
            $todayCarbon  = \Carbon\Carbon::parse($today)->startOf('day');

            $latestSchedulePerEmp = WorkScheduler::whereIn('EMPID', $empIds)
                ->where('PAYROLL_DATE_START', '<=', $today)
                ->where(fn ($q) => $q->whereNull('PAYROLL_DATE_END')
                                     ->orWhere('PAYROLL_DATE_END', '>=', $today))
                ->orderBy('EMPID')
                ->orderByDesc('PAYROLL_DATE_START')
                ->get(['EMPID', 'PAYROLL_DATE_START', 'SCHEDULE'])
                ->groupBy('EMPID')
                ->map(fn ($rows) => $rows->first());

            $restDayEmpIds = collect();
            foreach ($latestSchedulePerEmp as $empId => $sched) {
                $schedArr = is_array($sched->SCHEDULE)
                    ? $sched->SCHEDULE
                    : json_decode($sched->SCHEDULE, true);
                if (! $schedArr) continue;
                $start    = \Carbon\Carbon::parse($sched->PAYROLL_DATE_START)->startOf('day');
                $dayIndex = $start->diffInDays($todayCarbon);
                $shift    = $shiftCodes->get($schedArr[$dayIndex] ?? null);
                if ($shift && str_contains($shift->SHIFTCODE, 'RD')) {
                    $restDayEmpIds->push($empId);
                }
            }
            $restDayEmpIds = $restDayEmpIds->unique()->flip()->all();

            $vipGroup     = $employees->whereBetween('EMPPOSITION', [3, 6]);
            $bioGroup     = $employees->where('EMPPOSITION', 2);
            $vipEmployIds = $vipGroup->pluck('EMPLOYID')->filter()->all();

            $vipPresentEmployIds = collect();
            if (! empty($vipEmployIds)) {
                $vipPresentEmployIds = VPLog::whereIn('employee_id', $vipEmployIds)
                    ->today()
                    ->pluck('employee_id')
                    ->unique();
            }

            $vipPresentEmpIds = $vipGroup
                ->whereIn('EMPLOYID', $vipPresentEmployIds->all())
                ->pluck('EMPID')
                ->all();

            $bioEmployIds     = $bioGroup->pluck('EMPLOYID')->filter()->all();
            $bioPresentEmpIds = collect();

            if (! empty($bioEmployIds)) {
                $bioPresentEmployIds = BiometricLog::whereIn('employid', $bioEmployIds)
                    ->where('datetime', '>=', $today . ' 00:00:00')
                    ->where('datetime', '<=', $today . ' 23:59:59')
                    ->pluck('employid')
                    ->merge(
                        BiometricLogManual::whereIn('employid', $bioEmployIds)
                            ->where('datetime', '>=', $today . ' 00:00:00')
                            ->where('datetime', '<=', $today . ' 23:59:59')
                            ->pluck('employid')
                    )
                    ->merge(
                        AttendanceLog::whereIn('employid', $bioEmployIds)
                            ->where('logged_at', '>=', $today . ' 00:00:00')
                            ->where('logged_at', '<=', $today . ' 23:59:59')
                            ->pluck('employid')
                    )
                    ->unique()
                    ->all();

                $bioPresentEmpIds = $bioGroup
                    ->whereIn('EMPLOYID', $bioPresentEmployIds)
                    ->pluck('EMPID');
            }

            $presentEmpIds = collect($vipPresentEmpIds)
                ->merge($bioPresentEmpIds)
                ->unique()->flip()->all();

            $positionLabels = [
                2 => 'Supervisor', 3 => 'Section Head',
                4 => 'Manager',    5 => 'Director', 6 => 'President',
            ];

            return $employees->map(function ($emp) use (
                $isHoliday, $leaveByEmployId, $obByEmpId,
                $restDayEmpIds, $presentEmpIds, $positionLabels,
            ) {
                $status = match (true) {
                    $isHoliday                                                          => 'Rest Day',
                    isset($leaveByEmployId[$emp->EMPLOYID])                             => 'On Leave',
                    isset($obByEmpId[$emp->EMPID]) && $this->isFullShiftOB($obByEmpId[$emp->EMPID]) => 'OB',
                    isset($restDayEmpIds[$emp->EMPID])                                  => 'Rest Day',
                    isset($presentEmpIds[$emp->EMPID])                                  => 'Present',
                    default                                                             => 'Absent',
                };
                $emp->attendanceStatus = $status;
                $emp->positionLabel    = $positionLabels[$emp->EMPPOSITION] ?? '—';
                return $emp;
            });
        }
    );

    return Inertia::render('Dashboard', [
        'shiftLogs'   => $shiftLogs,
        'employees'   => $employees,
        'empPosition' => $empPosition,
    ]);
}

    // ── Attendance counter API endpoint ───────────────────────────────────
    //
    // Called by the frontend whenever the filter type or period changes.
    // Mirrors the vanilla get_attendance_count.php endpoint exactly.
    //
    // POST /dashboard/attendance-count
    // Body: { start_date: 'YYYY-MM-DD', end_date: 'YYYY-MM-DD' }
    // Returns: { present: int, absent: int, late: int, restday: int }

public function attendanceCount(Request $request)
{
    try {
        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date'   => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $employId = session('emp_data.emp_id');

        if (!$employId) {
            return response()->json(['error' => 'Session emp_id is null'], 500);
        }

        $counts = $this->attendanceCountService->countForEmployee(
            $employId,
            $validated['start_date'],
            $validated['end_date'],
        );

        return response()->json($counts);

    } catch (\Throwable $e) {
        return response()->json([
            'error'   => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
        ], 500);
    }
}

// ── DTR rows for management dashboard table ───────────────────────────
    //
    // GET /dashboard/dtr-rows
    // Returns: array of { empName, shiftCode, shiftType, timeIn, ... status }

    public function dtrRows(Request $request)
    {
        try {
            $today    = now()->toDateString();
            $cacheKey = "dashboard_dtr_rows_{$today}";

            $rows = \Cache::remember($cacheKey, now()->addMinutes(5),
                fn() => $this->dtrRowsService->getRowsForDate($today)
            );

            return response()->json($rows);

        } catch (\Throwable $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ], 500);
        }
    }

    public function allEmployeesDtr(Request $request)
{
    try {
        $date = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ])['date'] ?? now()->toDateString();

        $cacheKey = "all_employees_dtr_{$date}";

        $rows = \Cache::remember($cacheKey, now()->addMinutes(5),
            fn () => $this->allEmployeesDtrService->getRowsForDate($date)
        );

        return response()->json($rows);

    } catch (\Throwable $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
        ], 500);
    }
}

    // ── Helpers ───────────────────────────────────────────────────────────

    private function isFullShiftOB($ob): bool
    {
        if (empty($ob->TIME_FROM) || empty($ob->TIME_TO)) return true;

        $minutes = (strtotime($ob->TIME_TO) - strtotime($ob->TIME_FROM)) / 60;
        return $minutes >= 480;
    }
}