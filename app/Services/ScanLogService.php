<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\EmployeeMasterlist;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service class for handling VIP scan log operations
 * 
 * This service encapsulates business logic for scan logs,
 * keeping controllers thin and focused on HTTP concerns.
 */
class ScanLogService
{
    /**
     * Get all VIP employees with their latest log information
     *
     * @return Collection
     */
    public function getVipEmployeesWithLogs(): Collection
    {
        $employees = $this->getVipEmployees();

        return $employees->map(function ($employee) {
            return $this->enrichEmployeeWithLogData($employee);
        });
    }

    /**
     * Create a new scan log entry
     *
     * @param array $data
     * @return VPLog
     * @throws \Exception
     */
    public function createScanLog(array $data): AttendanceLog
    {
        try {
            DB::connection('dtr')->beginTransaction();

            $log = $this->storeScanLog($data);

            DB::connection('dtr')->commit();

            $this->logScanLogCreation($log, $data);

            return $log;

        } catch (\Exception $e) {
            DB::connection('dtr')->rollBack();
            
            $this->logScanLogError($e, $data);
            
            throw $e;
        }
    }

    /**
     * Get latest log for an employee
     *
     * @param string $employeeId
     * @return VPLog|null
     */
    public function getLatestLog(string $employeeId): ?AttendanceLog
    {
        return AttendanceLog::where('employid', $employeeId)
            ->where('matched', true)
            ->latest('logged_at')
            ->first();
    }

    /**
     * Get logs for an employee on a specific date
     *
     * @param string $employeeId
     * @param Carbon|string $date
     * @return Collection
     */
    public function getEmployeeLogsForDate(string $employeeId, $date): Collection
    {
        $dateString = $date instanceof Carbon ? $date->toDateString() : $date;

        return AttendanceLog::where('employid', $employeeId)
            ->whereDate('logged_at', $dateString)
            ->where('matched', true)
            ->latest('logged_at')
            ->get();
    }

    /**
     * Get all logs for today
     *
     * @return Collection
     */
    public function getTodayLogs(): Collection
    {
        return AttendanceLog::whereDate('logged_at', today())
            ->where('matched', true)
            ->latest('logged_at')
            ->get();
    }

    /**
     * Get VIP employees from database
     *
     * @return Collection
     */
    private function getVipEmployees(): Collection
    {
        return EmployeeMasterlist::query()
            ->whereIn('EMPPOSITION', [1, 2])
            ->where('ACCSTATUS', 1)
            ->orderBy('EMPNAME')
            ->get([
                'EMPID',
                'EMPLOYID',
                'EMPNAME',
                'DEPARTMENT',
                'PRODLINE',
                'STATION',
                'JOB_TITLE',
            ]);
    }

    /**
     * Enrich employee data with latest log information
     *
     * @param EmployeeMasterlist $employee
     * @return EmployeeMasterlist
     */
    private function enrichEmployeeWithLogData($employee)
    {
        $latestLog = $this->getLatestLog($employee->EMPLOYID);

        $employee->latest_log_type = $latestLog?->log_type;
        $employee->latest_log_time = $latestLog?->logged_at?->format('Y-m-d H:i:s');

        return $employee;
    }

    /**
     * Store scan log in database
     *
     * @param array $data
     * @return VPLog
     */
    private function storeScanLog(array $data): AttendanceLog
    {
        $nextLogType = AttendanceLog::nextLogTypeFor($data['employee_id']);

        return AttendanceLog::create([
            'employid'      => $data['employee_id'],
            'employee_name' => $data['employee_name'],
            'department'    => $data['department'] ?? null,
            'log_type'      => $nextLogType,
            'matched'       => true,
            'device_type'   => 'secugen',
            'recorded_by'   => session('emp_data.emp_id') ?? 'system',
            'logged_at'     => now(),
        ]);
    }

    /**
     * Log successful scan log creation
     *
     * @param VPLog $log
     * @param array $data
     * @return void
     */
    private function logScanLogCreation(AttendanceLog $log, array $data): void
    {
        Log::info('Attendance log created', [
            'log_id'        => $log->id,
            'employee_id'   => $data['employee_id'],
            'employee_name' => $data['employee_name'],
            'log_type'      => $log->log_type,
            'timestamp'     => now()->toDateTimeString(),
        ]);
    }

    /**
     * Log scan log creation error
     *
     * @param \Exception $e
     * @param array $data
     * @return void
     */
    private function logScanLogError(\Exception $e, array $data): void
    {
        Log::error('Failed to create scan log', [
            'error_message' => $e->getMessage(),
            'error_code' => $e->getCode(),
            'data' => $data,
            'trace' => $e->getTraceAsString(),
            'timestamp' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Get formatted log type label
     *
     * @param string $logType
     * @return string
     */
    public function getLogTypeLabel(string $logType): string
    {
        return ucwords(str_replace('_', ' ', $logType));
    }

    /**
     * Get today's check-in and check-out times for a list of employee IDs
     *
     * @param array $employeeIds
     * @return \Illuminate\Support\Collection  keyed by employee_id
     */
    public function getTodayCheckInOutTimes(array $employeeIds): \Illuminate\Support\Collection
    {
        $today = now()->toDateString();

        return AttendanceLog::whereIn('employid', $employeeIds)
            ->whereDate('logged_at', $today)
            ->whereIn('log_type', ['time_in', 'time_out'])
            ->where('matched', true)
            ->orderBy('logged_at')
            ->get(['employid', 'log_type', 'logged_at'])
            ->groupBy('employid')
            ->map(function ($logs) {
                $checkIn  = $logs->first(fn($l) => $l->log_type === 'time_in');
                $checkOut = $logs->last(fn($l)  => $l->log_type === 'time_out');

                return [
                    'today_checkin_time'  => $checkIn?->logged_at?->format('H:i:s'),
                    'today_checkout_time' => $checkOut?->logged_at?->format('H:i:s'),
                ];
            });
    }

    /**
     * Check if log type is valid
     *
     * @param string $logType
     * @return bool
     */
    public function isValidLogType(string $logType): bool
    {
        return in_array($logType, array_values(AttendanceLog::TYPE_SEQUENCE));
    }
}