<?php
// app/Services/EmployeeStatusService.php
namespace App\Services;

use App\Models\EmployeeLeave;
use App\Repositories\AttendanceRepository;
use App\Repositories\ScheduleRepository;
use App\Repositories\EmployeeRepository;
use Carbon\Carbon;

class EmployeeStatusService
{
    public function __construct(
        private AttendanceRepository $attendanceRepo,
        private ScheduleRepository   $scheduleRepo,
        private EmployeeRepository   $employeeRepo
    ) {}

    // ── Used by Admin dashboard index (batch, efficient) ──────────────────
    public function getManagementPresence(): array
    {
        $today    = Carbon::today();
        $todayStr = $today->toDateString();

        $excludedIds      = ['50400'];
        $excludedProdline = ['PL2 (AD/OS)', 'PL8 (AMS O/S)'];

        $employees = $this->employeeRepo->getManagementEmployees($excludedIds, $excludedProdline);
        $empIds    = $employees->pluck('EMPLOYID')->toArray();

        // Batch load — 2 queries instead of N*2
        $checkIns  = $this->attendanceRepo->getBatchCheckInsForDate($empIds, $todayStr);
        $checkOuts = $this->attendanceRepo->getBatchCheckOutsForDate($empIds, $todayStr);

        // Batch load schedules
        $schedulesMap = $this->scheduleRepo->getSchedulesForToday();

        $allShiftIds = $schedulesMap->flatMap(
            fn($ws) => collect($ws->SCHEDULE ?? [])->filter()->map(fn($id) => (int) $id)
        )->unique()->values()->toArray();
        $allShiftCodes = $this->scheduleRepo->getShiftCodesById($allShiftIds);

        // Batch load leaves
        $leavesToday = EmployeeLeave::whereIn('EMPLOYID', $empIds)
            ->whereDate('DATESTART', '<=', $today)
            ->whereDate('DATEEND',   '>=', $today)
            ->whereIn('LEAVESTATUS', ['Approved', 'APPROVED', 2])
            ->get()
            ->keyBy('EMPLOYID');

        return $employees->map(function ($emp) use (
            $todayStr, $checkIns, $checkOuts,
            $schedulesMap, $allShiftCodes, $leavesToday
        ) {
            $empId = $emp->EMPLOYID;

            // Rest day
            $isRestDay = false;
            $ws = $schedulesMap[$empId] ?? null;
            if ($ws) {
                $dayIndex  = Carbon::parse($ws->PAYROLL_DATE_START)->diffInDays(Carbon::today()) + 1;
                $shiftId   = (int) ($ws->SCHEDULE[$dayIndex] ?? 0);
                $shift     = $shiftId ? ($allShiftCodes[$shiftId] ?? null) : null;
                $isRestDay = $shift && str_contains($shift->SHIFTCODE ?? '', 'RD');
            }

            $isOnLeave = isset($leavesToday[$empId]);
            $hasIn     = in_array($empId, $checkIns);
            $hasOut    = in_array($empId, $checkOuts);

            $status = match(true) {
                $hasIn && $hasOut => 'out',
                $hasIn            => 'in',
                $isOnLeave        => 'leave',
                $isRestDay        => 'restday',
                default           => 'absent',
            };

            $lastSeen = $status === 'absent'
                ? $this->attendanceRepo->getLastSeenDate($empId, $todayStr)
                : null;

            return [
                'EMPLOYID'          => $empId,
                'EMPNAME'           => $emp->EMPNAME,
                'JOB_TITLE'         => $emp->JOB_TITLE,
                'DEPARTMENT'        => $emp->DEPARTMENT,
                'EMPPOSITION'       => $emp->EMPPOSITION,
                'POSITION_LABEL'    => match((int) $emp->EMPPOSITION) {
                    2 => 'Supervisor', 3 => 'Section Head',
                    4 => 'Manager',    5 => 'Director',
                    default => 'Unknown',
                },
                'attendance_status' => $status,
                'last_seen_date'    => $lastSeen,
                'is_rest_day'       => $isRestDay,
                'is_on_leave'       => $isOnLeave,
                'leave_type'        => $isOnLeave ? ($leavesToday[$empId]->TYPEOFLEAVE ?? 'On Leave') : null,
            ];
        })->values()->toArray();
    }
}