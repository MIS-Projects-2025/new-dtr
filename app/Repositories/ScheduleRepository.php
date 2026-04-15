<?php

namespace App\Repositories;

use App\Models\EmployeeMasterlist;
use App\Models\EmployeeLeave;
use App\Models\ObRecord;
use App\Models\ShiftCode;
use App\Models\WorkScheduler;
use Carbon\Carbon;

class ScheduleRepository
{
    // ─────────────────────────────────────────────────────────────────────────
    // SHIFT SCHEDULES
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Return all WorkScheduler rows for an employee, ordered by start date,
     * with their ShiftCode details resolved via the SCHEDULE JSON.
     *
     * Result shape per row:
     *   [
     *     'payroll_date_start' => Carbon,
     *     'payroll_date_end'   => Carbon|null,
     *     'schedule'           => [ day => ShiftCode|null, … ],   // keyed by 1-based day
     *   ]
     */
    public function getWorkSchedules(string $employId): array
    {
        // Eager-load all ShiftCodes once to avoid N+1
        $shiftCodes = $this->loadShiftCodes();

        return WorkScheduler::where('EMPID', $employId)
            ->orderBy('PAYROLL_DATE_START')
            ->get()
            ->map(function (WorkScheduler $ws) use ($shiftCodes) {
                $rawSchedule = is_array($ws->SCHEDULE)
                    ? $ws->SCHEDULE
                    : (json_decode($ws->SCHEDULE, true) ?? []);

                // Resolve each day's shift code
                $resolvedSchedule = collect($rawSchedule)
                    ->map(fn ($shiftId) => $shiftCodes->get((int) $shiftId))
                    ->all();

                return [
                    'payroll_date_start' => Carbon::parse($ws->PAYROLL_DATE_START),
                    'payroll_date_end'   => $ws->PAYROLL_DATE_END
                        ? Carbon::parse($ws->PAYROLL_DATE_END)
                        : null,
                    'schedule'           => $resolvedSchedule, // [day => ShiftCode|null]
                ];
            })
            ->all();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LEAVES
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Return approved leave dates for an employee, keyed by Y-m-d date.
     *
     * Result shape per date:
     *   [
     *     'type'      => 'Sick Leave',
     *     'type_code' => 'SL',
     *     'duration'  => 'Full Day',
     *     'period'    => 'N/A',          // only relevant for half-day leaves
     *   ]
     */
    public function getLeaveDates(string $employId): array
    {
        $leaveDates = [];

        // Use the relationship defined on EmployeeMasterlist for consistency,
        // but query directly here to keep the repository self-contained.
        EmployeeLeave::where('EMPLOYID', $employId)
            ->get()
            ->each(function (EmployeeLeave $leave) use (&$leaveDates) {
                if (strtolower($leave->LEAVESTATUS) !== 'approved') {
                    return;
                }

                $start   = Carbon::parse($leave->DATESTART);
                $end     = Carbon::parse($leave->DATEEND);
                $isHalf  = in_array(
                    strtolower($leave->LEAVE_DURATION),
                    ['half-day', 'half day'],
                    true
                );

                $current = $start->copy();

                while ($current->lte($end)) {
                    $leaveDates[$current->format('Y-m-d')] = [
                        'type'      => $this->expandLeaveTypeCode($leave->TYPEOFLEAVE),
                        'type_code' => $leave->TYPEOFLEAVE,
                        'duration'  => $leave->LEAVE_DURATION,
                        'period'    => $isHalf ? $leave->PERIOD : 'N/A',
                    ];

                    $current->addDay();
                }
            });

        return $leaveDates;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // OFFICIAL / PERSONAL BUSINESS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Return approved OB / PB records for an employee, keyed by Y-m-d date.
     *
     * STATUS values treated as "approved": '1' (approved) and '2' (completed).
     *
     * Result shape per date:
     *   [
     *     'type'      => 'Official Business',
     *     'time_from' => 'HH:MM',
     *     'time_to'   => 'HH:MM',
     *     'form_type' => 'ob',
     *   ]
     */
    public function getObRecords(string $employId): array
    {
        $obRecords = [];

        ObRecord::where('EMPID', $employId)
            ->whereIn('STATUS', ['1', '2'])
            ->get()
            ->each(function (ObRecord $ob) use (&$obRecords) {
                $type = match (strtolower((string) $ob->FORM_TYPE)) {
                    'ob'    => 'Official Business',
                    'pb'    => 'Personal Business',
                    default => null,
                };

                if ($type === null) {
                    return;
                }

                $start   = Carbon::parse($ob->DATE_OB_FROM);
                $end     = Carbon::parse($ob->DATE_OB_TO);
                $current = $start->copy();

                while ($current->lte($end)) {
                    $obRecords[$current->format('Y-m-d')] = [
                        'type'      => $type,
                        'time_from' => $ob->TIME_FROM,
                        'time_to'   => $ob->TIME_TO,
                        'form_type' => $ob->FORM_TYPE,
                    ];

                    $current->addDay();
                }
            });

        return $obRecords;
    }


    private function loadShiftCodes()
    {
        static $cache = null;
        return $cache ??= ShiftCode::all()->keyBy('SHIFT_CODE_ID');
    }

    public function getWorkSchedulesFromModel(EmployeeMasterlist $emp): array
    {
        $shiftCodes = $this->loadShiftCodes();

    return $emp->workSchedules
        ->map(function (WorkScheduler $ws) use ($shiftCodes) {
            $rawSchedule = is_array($ws->SCHEDULE)
                ? $ws->SCHEDULE
                : (json_decode($ws->SCHEDULE, true) ?? []);

            return [
                'payroll_date_start' => Carbon::parse($ws->PAYROLL_DATE_START),
                'payroll_date_end'   => $ws->PAYROLL_DATE_END
                    ? Carbon::parse($ws->PAYROLL_DATE_END)
                    : null,
                'schedule' => collect($rawSchedule)
                    ->map(fn ($id) => $shiftCodes->get((int) $id))
                    ->all(),
            ];
        })
        ->all();
}

public function getLeaveDatesFromModel(EmployeeMasterlist $emp): array
{
    $leaveDates = [];

    foreach ($emp->leaves as $leave) {
        // already filtered to approved in the eager load
        $start  = Carbon::parse($leave->DATESTART);
        $end    = Carbon::parse($leave->DATEEND);
        $isHalf = in_array(strtolower($leave->LEAVE_DURATION), ['half-day', 'half day'], true);

        $current = $start->copy();
        while ($current->lte($end)) {
            $leaveDates[$current->format('Y-m-d')] = [
                'type'      => $this->expandLeaveTypeCode($leave->TYPEOFLEAVE),
                'type_code' => $leave->TYPEOFLEAVE,
                'duration'  => $leave->LEAVE_DURATION,
                'period'    => $isHalf ? $leave->PERIOD : 'N/A',
            ];
            $current->addDay();
        }
    }

    return $leaveDates;
}

public function getObRecordsFromModel(EmployeeMasterlist $emp): array
{
    $obRecords = [];

    foreach ($emp->obRecords as $ob) {
        $type = match (strtolower((string) $ob->FORM_TYPE)) {
            'ob'    => 'Official Business',
            'pb'    => 'Personal Business',
            default => null,
        };

        if ($type === null) continue;

        $current = Carbon::parse($ob->DATE_OB_FROM);
        $end     = Carbon::parse($ob->DATE_OB_TO);

        while ($current->lte($end)) {
            $obRecords[$current->format('Y-m-d')] = [
                'type'      => $type,
                'time_from' => $ob->TIME_FROM,
                'time_to'   => $ob->TIME_TO,
                'form_type' => $ob->FORM_TYPE,
            ];
            $current->addDay();
        }
    }

    return $obRecords;
}

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function expandLeaveTypeCode(string $code): string
    {
        return [
            'SL'  => 'Sick Leave',
            'VL'  => 'Vacation Leave',
            'BL'  => 'Birthday Leave',
            'BrL' => 'Bereavement Leave',
            'EL'  => 'Emergency Leave',
            'PL'  => 'Paternity Leave',
            'SPL' => 'Solo Parent Leave',
            'MiL' => 'Military Leave',
        ][$code] ?? $code;
    }
}