<?php
// app/Repositories/EmployeeRepository.php
namespace App\Repositories;

use App\Models\EmployeeMasterlist;
use App\Models\WorkScheduler;
use Illuminate\Support\Collection;

class EmployeeRepository
{
    public function getManagementEmployees(
        array $excludeIds      = [],
        array $excludeProdlines = []
    ): Collection {
        return EmployeeMasterlist::where('ACCSTATUS', 1)
            ->whereIn('EMPPOSITION', [2, 3, 4, 5])
            ->when(!empty($excludeIds),       fn($q) => $q->whereNotIn('EMPLOYID', $excludeIds))
            ->when(!empty($excludeProdlines), fn($q) => $q->whereNotIn('PRODLINE', $excludeProdlines))
            ->select(['EMPLOYID', 'EMPNAME', 'JOB_TITLE', 'DEPARTMENT', 'EMPPOSITION', 'PRODLINE'])
            ->get();
    }

    public function findById(string $empId): ?EmployeeMasterlist
    {
        return EmployeeMasterlist::where('EMPLOYID', $empId)->first();
    }

public function getFilteredEmployees(
    array  $filters   = [],
    array  $positions = [1, 2],
    int    $perPage   = 15,
    int    $page      = 1,
    string $search    = ''
): Collection {
    return EmployeeMasterlist::where('ACCSTATUS', 1)
        ->whereIn('EMPPOSITION', $positions)
        ->when(!empty($filters['company']),    fn($q) => $q->where('COMPANY',    $filters['company']))
        ->when(!empty($filters['prodline']),   fn($q) => $q->where('PRODLINE',   $filters['prodline']))
        ->when(!empty($filters['department']), fn($q) => $q->where('DEPARTMENT', $filters['department']))
        ->when(!empty($filters['station']),    fn($q) => $q->where('STATION',    $filters['station']))
        ->when(!empty($search), fn($q) => $q->where(function ($q) use ($search) {
            $q->where('EMPNAME',  'like', "%{$search}%")
              ->orWhere('EMPLOYID', 'like', "%{$search}%");
        }))
        ->select(['EMPLOYID', 'EMPNAME', 'JOB_TITLE', 'DEPARTMENT',
                'EMPPOSITION', 'PRODLINE', 'COMPANY', 'STATION'])
        ->forPage($page, $perPage)
        ->get();
}

public function countFilteredEmployees(
    array  $filters   = [],
    array  $positions = [1, 2],
    string $search    = ''
): int {
    return EmployeeMasterlist::where('ACCSTATUS', 1)
        ->whereIn('EMPPOSITION', $positions)
        ->when(!empty($filters['company']),    fn($q) => $q->where('COMPANY',    $filters['company']))
        ->when(!empty($filters['prodline']),   fn($q) => $q->where('PRODLINE',   $filters['prodline']))
        ->when(!empty($filters['department']), fn($q) => $q->where('DEPARTMENT', $filters['department']))
        ->when(!empty($filters['station']),    fn($q) => $q->where('STATION',    $filters['station']))
        ->when(!empty($search), fn($q) => $q->where(function ($q) use ($search) {
            $q->where('EMPNAME',  'like', "%{$search}%")
              ->orWhere('EMPLOYID', 'like', "%{$search}%");
        }))
        ->count();
}

    public function getFilterOptions(array $positions = [1, 2]): array
    {
        $base = EmployeeMasterlist::where('ACCSTATUS', 1)
                    ->whereIn('EMPPOSITION', $positions);

        return [
            'companies'   => (clone $base)->distinct()->pluck('COMPANY')->filter()->values(),
            'prodlines'   => (clone $base)->distinct()->pluck('PRODLINE')->filter()->values(),
            'departments' => (clone $base)->distinct()->pluck('DEPARTMENT')->filter()->values(),
            'stations'    => (clone $base)->distinct()->pluck('STATION')->filter()->values(),
        ];
    }

public function getActiveSchedules(array $employIds, string $date = ''): Collection
{
    $today = !empty($date) ? $date : now()->toDateString();

    $schedules = WorkScheduler::whereIn('EMPID', $employIds)
        ->whereDate('PAYROLL_DATE_START', '<=', $today)
        ->whereDate('PAYROLL_DATE_END',   '>=', $today)
        ->get(['EMPID', 'EMPNAME', 'SCHEDULE', 'PAYROLL_DATE_START', 'PAYROLL_DATE_END', 'SHIFT']);

    // Collect all unique shift IDs across all schedules
    $allShiftIds = $schedules->flatMap(fn($row) =>
        collect($row->SCHEDULE ?? [])->filter()->map(fn($id) => (int) $id)
    )->unique()->values()->toArray();

    // Fetch all needed shift codes in one query
    $shiftCodes = \App\Models\ShiftCode::whereIn('SHIFT_CODE_ID', $allShiftIds)
        ->get(['SHIFT_CODE_ID', 'SHIFTCODE', 'SHIFTCODE_DESC', 'SHIFT_GROUP', 'SHIFTCODE_BG_COLOR', 'SHIFTCODE_FONT_COLOR', 'TIME_WINDOWS'])
        ->keyBy('SHIFT_CODE_ID');

    // Attach shift code map to each schedule row
    $schedules->each(function ($row) use ($shiftCodes) {
        $row->shift_codes_map = collect($row->SCHEDULE ?? [])
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->mapWithKeys(fn($id) => [
                $id => $shiftCodes->get($id) ?? null
            ]);
    });

    return $schedules;
}
}