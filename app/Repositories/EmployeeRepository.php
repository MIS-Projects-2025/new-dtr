<?php
// app/Repositories/EmployeeRepository.php
namespace App\Repositories;

use App\Models\EmployeeMasterlist;
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
}