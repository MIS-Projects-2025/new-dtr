<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\EmployeeStatusService;
use App\Services\ScheduleService;
use App\Services\AttendanceService;
use App\Services\EmployeeService;
use App\Repositories\ScheduleRepository;
use App\Repositories\AttendanceRepository;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function __construct(
        private EmployeeStatusService $employeeStatusService,
        private ScheduleService $scheduleService,
        private AttendanceService $attendanceService,
        private ScheduleRepository $scheduleRepo,
        private AttendanceRepository $attendanceRepo,
        private EmployeeService $employeeService, 
    ) {}

    public function index(Request $request)
    {
        $emp_data = session('emp_data');

        // employees is no longer passed here — loaded lazily via API after page renders
        return Inertia::render('Dashboard', [
            'emp_data' => $emp_data,
            'app_name' => env('APP_NAME', '')
        ]);
    }

    public function getManagementPresence(Request $request)
    {
        try {
            $employees = $this->employeeStatusService->getManagementPresence();
            return response()->json($employees);
        } catch (\Exception $e) {
            \Log::error('Management presence error: ' . $e->getMessage());
            return response()->json([], 500);
        }
    }

    public function getWorkSchedule(Request $request)
    {
        $empId = $request->emp_id;
        $selectedDate = $request->selected_date ?? now()->toDateString();
        
        $payload = $this->scheduleService->buildSchedulePayload($empId, $selectedDate);
        
        return response()->json($payload);
    }

    public function getShiftLogs(Request $request)
    {
        try {
            $empId = $request->emp_id;
            $date = $request->date ?? now()->toDateString();
            
            // Get the shift for this date
            $shift = $this->scheduleService->getShiftForDate($empId, $date);
            
            // Use the service to resolve shift logs
            $logs = $this->attendanceService->resolveShiftLogs($empId, $date, $shift);
            
            return response()->json($logs);
        } catch (\Exception $e) {
            \Log::error('Shift logs error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getAttendanceCounter(Request $request)
    {
        try {
            $empId     = $request->emp_id;
            $startDate = $request->start_date;
            $endDate   = $request->end_date;

            // Build shift map for the date range
            $shiftMap = $this->buildShiftMapForDateRange($empId, $startDate, $endDate);

            // Use the service to compute counter
            $counter = $this->attendanceService->computeCounter(
                $empId,
                $startDate,
                $endDate,
                $shiftMap
            );

            return response()->json($counter);
        } catch (\Exception $e) {
            \Log::error('Attendance counter error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    
    private function buildShiftMapForDateRange(string $empId, string $startDate, string $endDate): array
    {
        $shiftMap = [];
        
        // Get the work schedule that covers this range
        $workSchedule = $this->scheduleRepo->getScheduleForDate($empId, $startDate);
        if (!$workSchedule) {
            // Try to get schedule that covers the end date
            $workSchedule = $this->scheduleRepo->getScheduleForDate($empId, $endDate);
        }
        
        if ($workSchedule) {
            $payrollStart = Carbon::parse($workSchedule->PAYROLL_DATE_START);
            $scheduleData = $workSchedule->SCHEDULE ?? [];
            
            $shiftIds = collect($scheduleData)->filter()->map(fn($id) => (int)$id)->unique()->toArray();
            $shiftCodes = $this->scheduleRepo->getShiftCodesById($shiftIds);
            
            $start = Carbon::parse($startDate);
            $end = Carbon::parse($endDate);
            $current = $start->copy();
            
            while ($current->lte($end)) {
                $dateKey = $current->format('Y-m-d');
                $dayIndex = $payrollStart->diffInDays($current) + 1;
                $shiftId = (int)($scheduleData[$dayIndex] ?? 0);
                $shiftMap[$dateKey] = $shiftCodes[$shiftId] ?? null;
                $current->addDay();
            }
        }
        
        return $shiftMap;
    }
    public function getFilteredEmployees(Request $request)
        {
            try {
                $filters = $request->only(['company', 'prodline', 'department', 'station']);
                $result  = $this->employeeService->getFilteredWithOptions($filters);

                return response()->json($result);
            } catch (\Exception $e) {
                \Log::error('Filtered employees error: ' . $e->getMessage());
                return response()->json(['error' => $e->getMessage()], 500);
            }
        }

public function getDtrRows(Request $request)
{
    try {
        $filters      = $request->only(['company', 'prodline', 'department', 'station']);
        $page         = (int) $request->get('page', 1);
        $search       = (string) $request->get('search', '');
        $date         = $request->get('date', now()->toDateString());
        $shiftFilter  = (string) $request->get('shift_filter', '');
        $statusFilter = (string) $request->get('status_filter', '');
        $result       = $this->employeeService->getDtrRows($filters, $page, 15, $search, $date, $shiftFilter, $statusFilter);

        return response()->json($result);
    } catch (\Exception $e) {
        \Log::error('DTR rows error: ' . $e->getMessage());
        return response()->json(['error' => $e->getMessage()], 500);
    }
}

public function getShiftCounts(Request $request)
{
    try {
        $filters = $request->only(['company', 'prodline', 'department', 'station']);
        $date = $request->get('date', now()->toDateString());
        
        $counts = $this->employeeService->getShiftCounts($filters, $date);
        
        return response()->json($counts);
    } catch (\Exception $e) {
        \Log::error('Shift counts error: ' . $e->getMessage());
        return response()->json(['error' => $e->getMessage()], 500);
    }
}

public function getUnscheduledEmployees(Request $request)
{
    try {
        $filters = $request->only(['company', 'prodline', 'department', 'station']);
        $date = $request->get('date', now()->toDateString());
        
        $employees = $this->employeeService->getUnscheduledEmployees($filters, $date);
        
        return response()->json(['employees' => $employees]);
    } catch (\Exception $e) {
        \Log::error('Unscheduled employees error: ' . $e->getMessage());
        return response()->json(['error' => $e->getMessage(), 'employees' => []], 500);
    }
}

    public function getEmployeeShiftLogs(Request $request)
    {
        try {
            $employId = $request->get('employ_id');
            $date = $request->get('date', now()->toDateString());
            
            if (!$employId) {
                return response()->json(['error' => 'employ_id required'], 400);
            }
            
            $logs = $this->employeeService->getEmployeeShiftLogsForDate($employId, $date);
            
            return response()->json($logs);
        } catch (\Exception $e) {
            \Log::error('Employee shift logs error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    
    public function getOverviewData(Request $request)
        {
            try {
                $filters      = $request->only(['company', 'prodline', 'department', 'station']);
                $date         = $request->get('date', now()->toDateString());
                $page         = (int) $request->get('page', 1);
                $search       = (string) $request->get('search', '');
                $shiftFilter  = (string) $request->get('shift_filter', '');
                $statusFilter = (string) $request->get('status_filter', '');

                $result = $this->employeeService->getOverviewData(
                    $filters, $date, $page, 15, $search, $shiftFilter, $statusFilter
                );

                return response()->json($result);
            } catch (\Exception $e) {
                \Log::error('Overview data error: ' . $e->getMessage());
                return response()->json(['error' => $e->getMessage()], 500);
            }
        }

    public function getAnalyticsStats(Request $request)
        {
            try {
                $filters = $request->only(['company', 'prodline', 'department', 'station']);
                $mode    = $request->get('mode', 'Daily');
                $date    = $request->get('date', now()->toDateString());
                $cutoff  = $request->get('cutoff') ?? '';   // ← was: get('cutoff', '')
                $month   = $request->get('month')  ?? '';   // ← was: get('month', now()->format('Y-m'))

                $result = $this->employeeService->getAnalyticsStats($filters, $mode, $date, $cutoff, $month);

                return response()->json($result);
            } catch (\Throwable $e) {
                \Log::error('Analytics stats error: ' . $e->getMessage()
                    . ' in ' . $e->getFile() . ':' . $e->getLine());
                return response()->json(['error' => $e->getMessage()], 500);
            }
        }
}