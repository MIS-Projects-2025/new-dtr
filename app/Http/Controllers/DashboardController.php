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
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DailyTimeRecordService $dtrService,
        private readonly AttendanceCountService $attendanceCountService,
    ) {}

    // ── Main dashboard page ────────────────────────────────────────────────

    public function index(Request $request)
    {
        $employId = session('emp_data.emp_id');

        // ── Shift logs: fetch last 12 months so all filter options work ────
        // Weekly  → 8 weeks  ≈ 2 months
        // Cut-off → 6 periods ≈ 3 months
        // Monthly → 12 months
        $tableData = [];
        for ($i = 0; $i < 12; $i++) {
            $month     = now()->subMonths($i)->format('Y-m');
            $monthData = $this->dtrService->getTableData($employId, $month);
            if (! empty($monthData)) {
                $tableData = array_merge($tableData, $monthData);
            }
        }

        // Sort descending — latest log first (used as "today's" shift log card)
        usort($tableData, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));

        $shiftLogs = collect($tableData)
            ->map(fn(array $row) => [
                'date'      => $row['date']        ?? '',
                'status'    => $row['status']      ?? '',
                'timeIn'    => $row['time_in']     ?? '',
                'breakOut1' => $row['break_out_1'] ?? '',
                'breakIn1'  => $row['break_in_1']  ?? '',
                'lunchOut'  => $row['lunch_out']   ?? '',
                'lunchIn'   => $row['lunch_in']    ?? '',
                'breakOut2' => $row['break_out_2'] ?? '',
                'breakIn2'  => $row['break_in_2']  ?? '',
                'timeOut'   => $row['time_out']    ?? '',
            ])
            ->values()
            ->all();

        // ── Active employees (EMPPOSITION 2–5) ───────────────────────────
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

        $today = now()->toDateString();

        // ── Today's holiday ───────────────────────────────────────────────
        $isHoliday = Holiday::whereDate('HOLIDAY_DATE', $today)->exists();

        // ── Approved leaves covering today ────────────────────────────────
        $leaveByEmployId = EmployeeLeave::whereIn('EMPLOYID', $employIds)
            ->where('LEAVESTATUS', 'approved')
            ->whereDate('DATESTART', '<=', $today)
            ->whereDate('DATEEND',   '>=', $today)
            ->get(['EMPLOYID', 'TYPEOFLEAVE', 'LEAVE_DURATION'])
            ->keyBy('EMPLOYID');

        // ── OB records covering today ─────────────────────────────────────
        $obByEmpId = ObRecord::whereIn('EMPID', $empIds)
            ->whereIn('STATUS', ['1', '2'])
            ->whereDate('DATE_OB_FROM', '<=', $today)
            ->whereDate('DATE_OB_TO',   '>=', $today)
            ->get(['EMPID', 'FORM_TYPE', 'TIME_FROM', 'TIME_TO'])
            ->keyBy('EMPID');

        // ── Rest Day via WorkScheduler + ShiftCode ────────────────────────
        $shiftCodes = ShiftCode::all()->keyBy('SHIFT_CODE_ID');

        $restDayEmpIds = collect();

        $schedulers = WorkScheduler::whereIn('EMPID', $empIds)
            ->where('PAYROLL_DATE_START', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('PAYROLL_DATE_END')
                  ->orWhere('PAYROLL_DATE_END', '>=', $today);
            })
            ->orderBy('EMPID')
            ->orderByDesc('PAYROLL_DATE_START')
            ->get(['EMPID', 'PAYROLL_DATE_START', 'PAYROLL_DATE_END', 'SCHEDULE']);

        $latestSchedulePerEmp = $schedulers->groupBy('EMPID')->map(fn($rows) => $rows->first());
        $todayCarbon          = \Carbon\Carbon::parse($today)->startOf('day');

        foreach ($latestSchedulePerEmp as $empId => $sched) {
            $schedArr = is_array($sched->SCHEDULE)
                ? $sched->SCHEDULE
                : json_decode($sched->SCHEDULE, true);

            if (! $schedArr) continue;

            $start    = \Carbon\Carbon::parse($sched->PAYROLL_DATE_START)->startOf('day');
            $dayIndex = $start->diffInDays($todayCarbon);

            $shiftId = $schedArr[$dayIndex] ?? null;
            if (! $shiftId) continue;

            $shift = $shiftCodes->get($shiftId);
            if ($shift && str_contains($shift->SHIFTCODE, 'RD')) {
                $restDayEmpIds->push($empId);
            }
        }

        $restDayEmpIds = $restDayEmpIds->unique()->flip()->all();

        // ── Bulk presence check (biometric / vp_logs) ────────────────────
        $vipGroup    = $employees->whereBetween('EMPPOSITION', [3, 6]);
        $bioGroup    = $employees->where('EMPPOSITION', 2);
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
            $fromBiometric = BiometricLog::whereIn('employid', $bioEmployIds)
                ->whereDate('datetime', $today)
                ->pluck('employid');

            $fromManual = BiometricLogManual::whereIn('employid', $bioEmployIds)
                ->whereDate('datetime', $today)
                ->pluck('employid');

            $fromAttendance = AttendanceLog::whereIn('employid', $bioEmployIds)
                ->whereDate('logged_at', $today)
                ->pluck('employid');

            $bioPresentEmployIds = $fromBiometric
                ->merge($fromManual)
                ->merge($fromAttendance)
                ->unique()
                ->all();

            $bioPresentEmpIds = $bioGroup
                ->whereIn('EMPLOYID', $bioPresentEmployIds)
                ->pluck('EMPID');
        }

        $presentEmpIds = collect($vipPresentEmpIds)
            ->merge($bioPresentEmpIds)
            ->unique()
            ->flip()
            ->all();

        // ── Position label map ────────────────────────────────────────────
        $positionLabels = [
            2 => 'Supervisor',
            3 => 'Section Head',
            4 => 'Manager',
            5 => 'Director',
            6 => 'President',
        ];

        // ── Attach today's status to each employee ────────────────────────
        $employees = $employees->map(function ($emp) use (
            $isHoliday, $leaveByEmployId, $obByEmpId,
            $restDayEmpIds, $presentEmpIds, $positionLabels,
        ) {
            $status = match (true) {
                $isHoliday
                    => 'Rest Day',
                isset($leaveByEmployId[$emp->EMPLOYID])
                    => 'On Leave',
                isset($obByEmpId[$emp->EMPID]) && $this->isFullShiftOB($obByEmpId[$emp->EMPID])
                    => 'OB',
                isset($restDayEmpIds[$emp->EMPID])
                    => 'Rest Day',
                isset($presentEmpIds[$emp->EMPID])
                    => 'Present',
                default => 'Absent',
            };

            $emp->attendanceStatus = $status;
            $emp->positionLabel    = $positionLabels[$emp->EMPPOSITION] ?? '—';

            return $emp;
        });

        return Inertia::render('Dashboard', [
            'shiftLogs'   => $shiftLogs,
            'employees'   => $employees,
            'empPosition' => session('emp_data.emp_position'),
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

    // ── Helpers ───────────────────────────────────────────────────────────

    private function isFullShiftOB($ob): bool
    {
        if (empty($ob->TIME_FROM) || empty($ob->TIME_TO)) return true;

        $minutes = (strtotime($ob->TIME_TO) - strtotime($ob->TIME_FROM)) / 60;
        return $minutes >= 480;
    }
}