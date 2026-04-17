<?php
// app/Services/ScheduleService.php
namespace App\Services;

use App\Models\EmployeeLeave;
use App\Repositories\ScheduleRepository;
use Carbon\Carbon;

class ScheduleService
{
    public function __construct(
        private ScheduleRepository $scheduleRepo
    ) {}

    // ── Reused by both Employee + Admin APIs ──────────────────────────────
    public function buildSchedulePayload(string $empId, string $selectedDate): array
    {
        $schedule = $this->scheduleRepo->getScheduleForDate($empId, $selectedDate);
        $leave    = $this->getLeaveForDate($empId, $selectedDate);

        if (!$schedule) {
            return [
                'schedule'             => null,
                'schedule_with_shifts' => [],
                'shift_codes'          => [],
                'leave'                => $leave,
            ];
        }

        $payrollStart = Carbon::parse($schedule->PAYROLL_DATE_START);
        $payrollEnd   = Carbon::parse($schedule->PAYROLL_DATE_END);
        $scheduleData = $schedule->SCHEDULE ?? [];

        $shiftIds = collect($scheduleData)->filter()->map(fn($id) => (int) $id)->unique()->values()->toArray();
        $shiftCodes = $this->scheduleRepo->getShiftCodesById($shiftIds);
        $holidays   = $this->scheduleRepo->getHolidaysBetween(
            $payrollStart->format('Y-m-d'),
            $payrollEnd->format('Y-m-d')
        );

        $scheduleWithShifts = [];
        foreach ($scheduleData as $dayIndex => $shiftId) {
            $date    = $payrollStart->copy()->addDays((int) $dayIndex - 1);
            if ($date->gt($payrollEnd)) break;

            $dateKey = $date->format('Y-m-d');
            $shiftId = (int) $shiftId;

            $scheduleWithShifts[$dateKey] = [
                'day_index' => (int) $dayIndex,
                'shift_id'  => $shiftId,
                'details'   => $shiftCodes[$shiftId] ?? null,
                'holiday'   => isset($holidays[$dateKey]) ? [
                    'name' => $holidays[$dateKey]->HOLIDAY_NAME,
                    'type' => $holidays[$dateKey]->HOLIDAY_TYPE,
                ] : null,
            ];
        }

        // Catch holidays outside payroll period for the selected date
        if (!isset($scheduleWithShifts[$selectedDate])) {
            $holiday = $this->scheduleRepo->getHolidayByDate($selectedDate);
            if ($holiday) {
                $scheduleWithShifts[$selectedDate] = [
                    'day_index' => null,
                    'shift_id'  => null,
                    'details'   => null,
                    'holiday'   => [
                        'name' => $holiday->HOLIDAY_NAME,
                        'type' => $holiday->HOLIDAY_TYPE,
                    ],
                ];
            }
        }

        ksort($scheduleWithShifts);

        return [
            'schedule' => [
                'PAYROLL_DATE_START' => $payrollStart->format('M d, Y'),
                'PAYROLL_DATE_END'   => $payrollEnd->format('M d, Y'),
                'SHIFT'              => $schedule->SHIFT,
            ],
            'schedule_with_shifts' => $scheduleWithShifts,
            'shift_codes'          => $shiftCodes->values(),
            'leave'                => $leave,
        ];
    }

    // Checks if an employee is on rest day for a given date
    // Used by admin index (pass pre-loaded maps) OR solo queries
    public function isRestDay(?object $shift): bool
    {
        return $shift && str_contains($shift->SHIFTCODE ?? '', 'RD');
    }

    public function getShiftForDate(string $empId, string $date): ?object
    {
        $schedule = $this->scheduleRepo->getScheduleForDate($empId, $date);
        if (!$schedule) return null;

        $payrollStart = Carbon::parse($schedule->PAYROLL_DATE_START);
        $dayIndex     = $payrollStart->diffInDays(Carbon::parse($date)) + 1;
        $shiftId      = (int) ($schedule->SCHEDULE[$dayIndex] ?? 0);
        if (!$shiftId) return null;

        $codes = $this->scheduleRepo->getShiftCodesById([$shiftId]);
        return $codes[$shiftId] ?? null;
    }

    public function getLeaveForDate(string $empId, string $date): ?array
    {
        $leave = EmployeeLeave::where('EMPLOYID', $empId)
            ->whereDate('DATESTART', '<=', $date)
            ->whereDate('DATEEND',   '>=', $date)
            ->whereIn('LEAVESTATUS', ['Approved', 'APPROVED', 2])
            ->first();

        return $leave ? ['type' => $leave->TYPEOFLEAVE, 'status' => $leave->LEAVESTATUS] : null;
    }
}