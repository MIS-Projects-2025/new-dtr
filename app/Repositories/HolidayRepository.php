<?php

namespace App\Repositories;

use App\Models\Holiday;
use Carbon\Carbon;

class HolidayRepository
{
    public function getAllKeyedByDate(): array
    {
        return $this->getForYearKeyedByDate((int) now()->year);
    }

    public function getForYearKeyedByDate(int $year): array
    {
        return $this->getForYearsKeyedByDate([$year]);
    }

    public function getForYearsKeyedByDate(array $years): array
    {
        $holidays = [];

        Holiday::whereIn(\DB::raw('YEAR(HOLIDAY_DATE)'), $years)
            ->get()
            ->each(function (Holiday $holiday) use (&$holidays) {
                $key = Carbon::parse($holiday->HOLIDAY_DATE)->format('Y-m-d');
                $holidays[$key] = [
                    'name' => $holiday->HOLIDAY_NAME,
                    'type' => $holiday->HOLIDAY_TYPE,
                ];
            });

        return $holidays;
    }
}