<?php

// app/Repositories/ScheduleRepository.php
namespace App\Repositories;

use App\Models\WorkScheduler;
use App\Models\ShiftCode;
use App\Models\Holiday;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ScheduleRepository
{
public function getScheduleForDate(string $empId, string $date): ?WorkScheduler
    {
        return WorkScheduler::where('EMPID', $empId)
            ->where('PAYROLL_DATE_START', '<=', $date)
            ->where('PAYROLL_DATE_END',   '>=', $date)
            ->select(['EMPID', 'SCHEDULE', 'PAYROLL_DATE_START', 'PAYROLL_DATE_END', 'SHIFT'])
            ->first();
    }

    // Used by admin dashboard index — loads all at once
    public function getSchedulesForToday(): Collection
    {
        $today = Carbon::today()->toDateString();

        return WorkScheduler::where('PAYROLL_DATE_START', '<=', $today)
            ->where('PAYROLL_DATE_END',   '>=', $today)
            ->get()
            ->keyBy('EMPID');
    }

    public function getShiftCodesById(array $ids): Collection
    {
        return ShiftCode::whereIn('SHIFT_CODE_ID', $ids)
            ->get()
            ->keyBy('SHIFT_CODE_ID');
    }

    public function getHolidaysBetween(string $start, string $end): Collection
    {
        return Holiday::whereBetween('HOLIDAY_DATE', [$start, $end])
            ->get()
            ->keyBy('HOLIDAY_DATE');
    }

    public function getHolidayByDate(string $date): ?Holiday
    {
        return Holiday::where('HOLIDAY_DATE', $date)->first();
    }
}