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
    string $search    = '',
    string $date      = null  // Add date parameter for DATEHIRED check
): Collection {
    $query = EmployeeMasterlist::where('ACCSTATUS', 1)
        ->whereIn('EMPPOSITION', $positions)
        ->whereNotNull('PRODLINE')
        ->where('PRODLINE', '!=', '')
        ->where('PRODLINE', '!=', 'Security')
        ->where('BIOMETRIC_STATUS', 'Enabled');
    
    // Add DATEHIRED condition
    if ($date) {
        $query->where(function($q) use ($date) {
            $q->whereNull('DATEHIRED')
              ->orWhere('DATEHIRED', '<=', $date);
        });
    }
    
    // Apply filters
    if (!empty($filters['company'])) {
        $query->where('COMPANY', $filters['company']);
    }
    if (!empty($filters['prodline'])) {
        $query->where('PRODLINE', $filters['prodline']);
    }
    if (!empty($filters['department'])) {
        $query->where('DEPARTMENT', $filters['department']);
    }
    if (!empty($filters['station'])) {
        $query->where('STATION', $filters['station']);
    }
    
    // Apply search
    if (!empty($search)) {
        $query->where(function ($q) use ($search) {
            $q->where('EMPNAME', 'like', "%{$search}%")
              ->orWhere('EMPLOYID', 'like', "%{$search}%");
        });
    }
    
    return $query->select(['EMPLOYID', 'EMPNAME', 'JOB_TITLE', 'DEPARTMENT',
            'EMPPOSITION', 'PRODLINE', 'COMPANY', 'STATION', 'DATEHIRED'])
        ->forPage($page, $perPage)
        ->get();
}

public function countFilteredEmployees(
    array  $filters   = [],
    array  $positions = [1, 2],
    string $search    = '',
    string $date      = null  // Add date parameter
): int {
    $query = EmployeeMasterlist::where('ACCSTATUS', 1)
        ->whereIn('EMPPOSITION', $positions)
        ->whereNotNull('PRODLINE')
        ->where('PRODLINE', '!=', '')
        ->where('PRODLINE', '!=', 'Security')
        ->where('BIOMETRIC_STATUS', 'Enabled');
    
    // Add DATEHIRED condition
    if ($date) {
        $query->where(function($q) use ($date) {
            $q->whereNull('DATEHIRED')
              ->orWhere('DATEHIRED', '<=', $date);
        });
    }
    
    // Apply filters
    if (!empty($filters['company'])) {
        $query->where('COMPANY', $filters['company']);
    }
    if (!empty($filters['prodline'])) {
        $query->where('PRODLINE', $filters['prodline']);
    }
    if (!empty($filters['department'])) {
        $query->where('DEPARTMENT', $filters['department']);
    }
    if (!empty($filters['station'])) {
        $query->where('STATION', $filters['station']);
    }
    
    // Apply search
    if (!empty($search)) {
        $query->where(function ($q) use ($search) {
            $q->where('EMPNAME', 'like', "%{$search}%")
              ->orWhere('EMPLOYID', 'like', "%{$search}%");
        });
    }
    
    return $query->count();
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

public function getApprovedLeaves(array $employIds, string $date): Collection
{
    return \App\Models\EmployeeLeave::whereIn('EMPLOYID', $employIds)
        ->where('LEAVESTATUS', 'approved')
        ->whereDate('DATESTART', '<=', $date)
        ->whereDate('DATEEND',   '>=', $date)
        ->get(['EMPLOYID'])
        ->keyBy('EMPLOYID');
}

public function getApprovedObs(array $employIds, string $date): Collection
{
    return \App\Models\ObRecord::whereIn('EMPID', $employIds)
        ->whereIn('STATUS', [1, 2])
        ->whereDate('DATE_OB_FROM', '<=', $date)
        ->whereDate('DATE_OB_TO',   '>=', $date)
        ->get(['EMPID', 'TIME_FROM', 'TIME_TO', 'FORM_TYPE'])
        ->map(function ($ob) {
            $ob->TIME_FROM = $ob->TIME_FROM ? substr($ob->TIME_FROM, 0, 5) : null;
            $ob->TIME_TO   = $ob->TIME_TO   ? substr($ob->TIME_TO,   0, 5) : null;
            return $ob;
        })
        ->keyBy(fn($ob) => (string) $ob->EMPID); // force string key
}
}

