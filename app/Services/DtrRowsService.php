<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\BiometricLog;
use App\Models\BiometricLogManual;
use App\Models\EmployeeLeave;
use App\Models\EmployeeMasterlist;
use App\Models\Holiday;
use App\Models\ObRecord;
use App\Models\ShiftCode;
use App\Models\WorkScheduler;
use Carbon\Carbon;

class DtrRowsService
{
    public function getRowsForDate(string $today): array
    {
        $todayCarbon = Carbon::parse($today)->startOf('day');

        $allEmployees = EmployeeMasterlist::where('ACCSTATUS', 1)
            ->where('BIOMETRIC_STATUS', 'enabled')
            ->orderBy('LASTNAME')
            ->orderBy('FIRSTNAME')
            ->get(['EMPID', 'EMPLOYID', 'LASTNAME', 'FIRSTNAME']);

        $employIds = $allEmployees->pluck('EMPLOYID')->filter()->values()->all();
        $empIds    = $allEmployees->pluck('EMPID')->filter()->values()->all();

        // ── Bulk queries ──────────────────────────────────────────────────

        $bioLogs = BiometricLog::whereIn('employid', $employIds)
            ->whereDate('datetime', $today)
            ->orderBy('datetime')
            ->get(['employid', 'datetime'])
            ->groupBy('employid');

        $manualLogs = BiometricLogManual::whereIn('employid', $employIds)
            ->whereDate('datetime', $today)
            ->orderBy('datetime')
            ->get(['employid', 'datetime'])
            ->groupBy('employid');

        $attendanceLogs = AttendanceLog::whereIn('employid', $employIds)
            ->whereDate('logged_at', $today)
            ->orderBy('logged_at')
            ->get(['employid', 'logged_at'])
            ->groupBy('employid');

        $shiftCodes = ShiftCode::all()->keyBy('SHIFT_CODE_ID');

        $latestSchedulePerEmp = WorkScheduler::whereIn('EMPID', $empIds)
            ->where('PAYROLL_DATE_START', '<=', $today)
            ->where(fn($q) => $q->whereNull('PAYROLL_DATE_END')
                                ->orWhere('PAYROLL_DATE_END', '>=', $today))
            ->orderBy('EMPID')
            ->orderByDesc('PAYROLL_DATE_START')
            ->get(['EMPID', 'PAYROLL_DATE_START', 'SCHEDULE'])
            ->groupBy('EMPID')
            ->map(fn($rows) => $rows->first());

        $leaveByEmployId = EmployeeLeave::whereIn('EMPLOYID', $employIds)
            ->where('LEAVESTATUS', 'approved')
            ->whereDate('DATESTART', '<=', $today)
            ->whereDate('DATEEND',   '>=', $today)
            ->get(['EMPLOYID'])
            ->keyBy('EMPLOYID');

        $obByEmpId = ObRecord::whereIn('EMPID', $empIds)
            ->whereIn('STATUS', ['1', '2'])
            ->whereDate('DATE_OB_FROM', '<=', $today)
            ->whereDate('DATE_OB_TO',   '>=', $today)
            ->get(['EMPID', 'TIME_FROM', 'TIME_TO'])
            ->keyBy('EMPID');

        $isHoliday = Holiday::whereDate('HOLIDAY_DATE', $today)->exists();

        // ── Rest day lookup ───────────────────────────────────────────────

        $restDayEmpIds = $this->resolveRestDays(
            $latestSchedulePerEmp, $shiftCodes, $todayCarbon
        );

        // ── Map each employee to a DTR row ────────────────────────────────

        $rows = [];

        foreach ($allEmployees as $emp) {
            [$shiftCode, $shiftType] = $this->resolveShift(
                $emp->EMPID, $latestSchedulePerEmp, $shiftCodes, $todayCarbon
            );

            $logTimes = $this->mergeAndSortLogs(
                $emp->EMPLOYID,
                $bioLogs,
                $manualLogs,
                $attendanceLogs
            );

            $timeIn    = $logTimes[0] ?? '';
            $breakOut1 = $logTimes[1] ?? '';
            $breakIn1  = $logTimes[2] ?? '';
            $lunchOut  = $logTimes[3] ?? '';
            $lunchIn   = $logTimes[4] ?? '';
            $breakOut2 = $logTimes[5] ?? '';
            $breakIn2  = $logTimes[6] ?? '';
            $timeOut   = $logTimes[7] ?? '';

            $status = $this->resolveStatus(
                emp: $emp,
                timeIn: $timeIn,
                shiftType: $shiftType,
                isHoliday: $isHoliday,
                leaveByEmployId: $leaveByEmployId,
                obByEmpId: $obByEmpId,
                restDayEmpIds: $restDayEmpIds,
                logTimes: $logTimes,
            );

            $rows[] = [
                'empName'   => "{$emp->LASTNAME}, {$emp->FIRSTNAME}",
                'shiftCode' => $shiftCode ?? '—',
                'shiftType' => $shiftType ?? 'Day',
                'timeIn'    => $timeIn,
                'breakOut1' => $breakOut1,
                'breakIn1'  => $breakIn1,
                'lunchOut'  => $lunchOut,
                'lunchIn'   => $lunchIn,
                'breakOut2' => $breakOut2,
                'breakIn2'  => $breakIn2,
                'timeOut'   => $timeOut,
                'status'    => $status,
            ];
        }

        return $rows;
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function resolveShift(int $empId, $latestSchedulePerEmp, $shiftCodes, Carbon $todayCarbon): array
    {
        $schedRow = $latestSchedulePerEmp->get($empId);
        if (! $schedRow) return [null, null];

        $schedArr = is_array($schedRow->SCHEDULE)
            ? $schedRow->SCHEDULE
            : json_decode($schedRow->SCHEDULE, true);

        if (! $schedArr) return [null, null];

        $dayIndex = Carbon::parse($schedRow->PAYROLL_DATE_START)->startOf('day')->diffInDays($todayCarbon);
        $shift    = $shiftCodes->get($schedArr[$dayIndex] ?? null);

        if (! $shift) return [null, null];

        $shiftType = str_contains(strtolower($shift->SHIFTCODE ?? ''), 'ns') ? 'Night' : 'Day';

        return [$shift->SHIFTCODE, $shiftType];
    }

    private function resolveRestDays($latestSchedulePerEmp, $shiftCodes, Carbon $todayCarbon): array
    {
        $restDayEmpIds = collect();

        foreach ($latestSchedulePerEmp as $empId => $sched) {
            $schedArr = is_array($sched->SCHEDULE)
                ? $sched->SCHEDULE
                : json_decode($sched->SCHEDULE, true);

            if (! $schedArr) continue;

            $dayIndex = Carbon::parse($sched->PAYROLL_DATE_START)->startOf('day')->diffInDays($todayCarbon);
            $shift    = $shiftCodes->get($schedArr[$dayIndex] ?? null);

            if ($shift && str_contains($shift->SHIFTCODE, 'RD')) {
                $restDayEmpIds->push($empId);
            }
        }

        return $restDayEmpIds->unique()->flip()->all();
    }

    private function mergeAndSortLogs(string $employId, $bioLogs, $manualLogs, $attendanceLogs): array
    {
        return $bioLogs->get($employId, collect())
            ->merge($manualLogs->get($employId, collect()))
            ->merge(
                $attendanceLogs->get($employId, collect())
                    ->map(fn($l) => (object) ['datetime' => $l->logged_at])
            )
            ->sortBy('datetime')
            ->values()
            ->pluck('datetime')
            ->map(fn($d) => Carbon::parse($d)->format('H:i'))
            ->values()
            ->all();
    }

    private function resolveStatus($emp, string $timeIn, ?string $shiftType, bool $isHoliday, $leaveByEmployId, $obByEmpId, array $restDayEmpIds, array $logTimes): string
    {
        $isPresent = count($logTimes) > 0;

        $isLate = $isPresent && $timeIn && ($shiftType === 'Night'
            ? Carbon::parse($timeIn)->gt(Carbon::parse('22:15'))
            : Carbon::parse($timeIn)->gt(Carbon::parse('08:15')));

        $ob = $obByEmpId[$emp->EMPID] ?? null;
        $isFullOB = $ob && (
            empty($ob->TIME_FROM) || empty($ob->TIME_TO) ||
            (strtotime($ob->TIME_TO) - strtotime($ob->TIME_FROM)) / 60 >= 480
        );

        return match (true) {
            $isHoliday                           => 'Rest Day',
            isset($leaveByEmployId[$emp->EMPLOYID]) => 'On Leave',
            $isFullOB                            => 'OB',
            isset($restDayEmpIds[$emp->EMPID])   => 'Rest Day',
            $isPresent && $isLate                => 'Late',
            $isPresent                           => 'Present',
            default                              => 'Absent',
        };
    }
}