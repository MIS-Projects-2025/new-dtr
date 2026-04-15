<?php

namespace App\Repositories;

use App\Models\EmployeeMasterlist;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class EmployeeRepository
{
    public function getShiftType(string $employId): int
    {
        $emp = EmployeeMasterlist::where('EMPLOYID', $employId)->first(['SHIFTTYPE']);
        return (int) ($emp?->SHIFTTYPE ?? 1);
    }

    /**
     * Load an employee with all DTR-related relations eagerly,
     * scoped to the given month (±1 day for night-shift boundaries).
     */
    public function findForDtr(string $employId, string $month): ?EmployeeMasterlist
    {
        $monthStart = Carbon::parse("{$month}-01");
        $fetchFrom  = $monthStart->copy()->subDay()->format('Y-m-d');
        $fetchTo    = $monthStart->copy()->endOfMonth()->addDay()->format('Y-m-d');

        return EmployeeMasterlist::where('EMPLOYID', $employId)
            ->with([
                'workSchedules',

                'leaves' => fn ($q) => $q->where('LEAVESTATUS', 'approved'),

                'obRecords' => fn ($q) => $q->whereIn('STATUS', ['1', '2']),

                'biometricLogs' => fn ($q) => $q
                    ->where('datetime', '>=', $fetchFrom . ' 00:00:00')
                    ->where('datetime', '<=', $fetchTo   . ' 23:59:59')
                    ->orderBy('datetime'),

                'biometricLogsManual' => fn ($q) => $q
                    ->where('datetime', '>=', $fetchFrom . ' 00:00:00')
                    ->where('datetime', '<=', $fetchTo   . ' 23:59:59')
                    ->orderBy('datetime'),

                'attendanceLogs' => fn ($q) => $q
                    ->where('logged_at', '>=', $fetchFrom . ' 00:00:00')
                    ->where('logged_at', '<=', $fetchTo   . ' 23:59:59')
                    ->orderBy('logged_at'),
            ])
            ->first();
    }

    public function findForDtrDate(string $employId, string $date): ?EmployeeMasterlist
        {
            $prev = Carbon::parse($date)->subDay()->format('Y-m-d');
            $next = Carbon::parse($date)->addDay()->format('Y-m-d');

            return EmployeeMasterlist::where('EMPLOYID', $employId)
                ->with([
                    'workSchedules',

                    'leaves' => fn ($q) => $q->where('LEAVESTATUS', 'approved')
                        ->whereDate('DATESTART', '<=', $date)
                        ->whereDate('DATEEND',   '>=', $date),

                    'obRecords' => fn ($q) => $q->whereIn('STATUS', ['1', '2'])
                        ->whereDate('DATE_OB_FROM', '<=', $date)
                        ->whereDate('DATE_OB_TO',   '>=', $date),

                    'biometricLogs' => fn ($q) => $q
                        ->where('datetime', '>=', $prev . ' 00:00:00')
                        ->where('datetime', '<=', $next . ' 23:59:59')
                        ->orderBy('datetime'),

                    'biometricLogsManual' => fn ($q) => $q
                        ->where('datetime', '>=', $prev . ' 00:00:00')
                        ->where('datetime', '<=', $next . ' 23:59:59')
                        ->orderBy('datetime'),

                    'attendanceLogs' => fn ($q) => $q
                        ->where('logged_at', '>=', $prev . ' 00:00:00')
                        ->where('logged_at', '<=', $next . ' 23:59:59')
                        ->orderBy('logged_at'),
                ])
                ->first();
        }
}