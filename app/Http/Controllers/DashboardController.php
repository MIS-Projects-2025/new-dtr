<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\EmployeeStatusService;
use App\Services\ScheduleService;
use App\Services\AttendanceService;
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
        private AttendanceRepository $attendanceRepo
    ) {}

    public function index(Request $request)
    {
        $emp_data = session('emp_data');

        // employees is no longer passed here — loaded lazily via API after page renders
        return Inertia::render('Dashboard', [
            'emp_data' => $emp_data,
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
}