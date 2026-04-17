<?php
// app/Repositories/AttendanceRepository.php
namespace App\Repositories;

use App\Models\AttendanceLog;
use App\Models\BiometricLog;
use App\Models\BiometricLogManual;
use App\Models\VPLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AttendanceRepository
{
    // ── All 4 sources unified ──────────────────────────────────────────────
    public function getLogsForDate(string $empId, string $date, array $logTypes): Collection
    {
        $attendance = AttendanceLog::where('employid', $empId)
            ->whereDate('logged_at', $date)
            ->whereIn('log_type', $logTypes)
            ->orderBy('logged_at')
            ->get()
            ->map(fn($l) => [
                'log_type'  => $l->log_type,
                'time'      => $l->logged_at->format('H:i:s'),
                'timestamp' => $l->logged_at->timestamp,
                'priority'  => 4,
                'source'    => 'attendance',
            ]);

        $vp = VPLog::where('employee_id', $empId)
            ->where('log_date', $date)
            ->whereIn('log_type', $logTypes)
            ->orderBy('log_time')
            ->get()
            ->map(fn($l) => [
                'log_type'  => $l->log_type,
                'time'      => Carbon::parse($l->log_time)->format('H:i:s'),
                'timestamp' => Carbon::parse($date . ' ' . $l->log_time)->timestamp,
                'priority'  => 3,
                'source'    => 'vp',
            ]);

        $bio = BiometricLog::where('employid', $empId)
            ->whereDate('datetime', $date)
            ->whereIn('punch_type', ['check_in', 'check_out', 'break_in', 'break_out'])
            ->orderBy('datetime')
            ->get()
            ->map(fn($l) => [
                'log_type'  => $l->punch_type,
                'time'      => Carbon::parse($l->datetime)->format('H:i:s'),
                'timestamp' => Carbon::parse($l->datetime)->timestamp,
                'priority'  => 2,
                'source'    => 'biometric',
            ]);

        $manual = BiometricLogManual::where('employid', $empId)
            ->whereDate('datetime', $date)
            ->whereIn('punch_type', ['check_in', 'check_out', 'break_in', 'break_out'])
            ->orderBy('datetime')
            ->get()
            ->map(fn($l) => [
                'log_type'  => $l->punch_type,
                'time'      => Carbon::parse($l->datetime)->format('H:i:s'),
                'timestamp' => Carbon::parse($l->datetime)->timestamp,
                'priority'  => 1,
                'source'    => 'biometric_manual',
            ]);

        return $attendance->concat($vp)->concat($bio)->concat($manual);
    }

    public function hasCheckIn(string $empId, string $date): bool
    {
        return AttendanceLog::where('employid', $empId)->whereDate('logged_at', $date)->where('log_type', 'check_in')->exists()
            || BiometricLog::where('employid', $empId)->whereDate('datetime', $date)->where('punch_type', 'check_in')->exists()
            || BiometricLogManual::where('employid', $empId)->whereDate('datetime', $date)->where('punch_type', 'check_in')->exists()
            || VPLog::where('employee_id', $empId)->where('log_date', $date)->where('log_type', 'check_in')->exists();
    }

    public function hasCheckOut(string $empId, string $date): bool
    {
        return AttendanceLog::where('employid', $empId)->whereDate('logged_at', $date)->where('log_type', 'check_out')->exists()
            || BiometricLog::where('employid', $empId)->whereDate('datetime', $date)->where('punch_type', 'check_out')->exists()
            || BiometricLogManual::where('employid', $empId)->whereDate('datetime', $date)->where('punch_type', 'check_out')->exists()
            || VPLog::where('employee_id', $empId)->where('log_date', $date)->where('log_type', 'check_out')->exists();
    }

    public function getEarliestCheckIn(string $empId, string $date): ?Carbon
    {
        $times = collect([
            AttendanceLog::where('employid', $empId)->whereDate('logged_at', $date)->where('log_type', 'check_in')->orderBy('logged_at')->value('logged_at'),
            BiometricLog::where('employid', $empId)->whereDate('datetime', $date)->where('punch_type', 'check_in')->orderBy('datetime')->value('datetime'),
            BiometricLogManual::where('employid', $empId)->whereDate('datetime', $date)->where('punch_type', 'check_in')->orderBy('datetime')->value('datetime'),
        ])->filter()->map(fn($t) => Carbon::parse($t));

        $vpTime = VPLog::where('employee_id', $empId)
            ->where('log_date', $date)
            ->where('log_type', 'check_in')
            ->orderBy('log_time')
            ->first();

        if ($vpTime) {
            $times->push(Carbon::parse($date . ' ' . $vpTime->log_time));
        }

        return $times->isEmpty() ? null : $times->min();
    }

    public function getLastSeenDate(string $empId, string $beforeDate): ?string
    {
        $sources = collect([
            AttendanceLog::where('employid', $empId)->whereDate('logged_at', '<', $beforeDate)->orderByDesc('logged_at')->value('logged_at'),
            BiometricLog::where('employid', $empId)->whereDate('datetime', '<', $beforeDate)->orderByDesc('datetime')->value('datetime'),
            BiometricLogManual::where('employid', $empId)->whereDate('datetime', '<', $beforeDate)->orderByDesc('datetime')->value('datetime'),
            VPLog::where('employee_id', $empId)->where('log_date', '<', $beforeDate)->orderByDesc('log_date')->value('log_date'),
        ])->filter()->map(fn($d) => Carbon::parse($d))->sortByDesc(fn($d) => $d);

        return $sources->first()?->format('M d');
    }

    // For batch queries (Admin dashboard — all employees at once)
    public function getBatchCheckInsForDate(array $empIds, string $date): array
    {
        $attendance = AttendanceLog::whereIn('employid', $empIds)->whereDate('logged_at', $date)->where('log_type', 'check_in')->pluck('employid')->toArray();
        $bio        = BiometricLog::whereIn('employid', $empIds)->whereDate('datetime', $date)->where('punch_type', 'check_in')->pluck('employid')->toArray();
        $manual     = BiometricLogManual::whereIn('employid', $empIds)->whereDate('datetime', $date)->where('punch_type', 'check_in')->pluck('employid')->toArray();
        $vp         = VPLog::whereIn('employee_id', $empIds)->where('log_date', $date)->where('log_type', 'check_in')->pluck('employee_id')->toArray();

        return array_unique(array_merge($attendance, $bio, $manual, $vp));
    }

    public function getBatchCheckOutsForDate(array $empIds, string $date): array
    {
        $attendance = AttendanceLog::whereIn('employid', $empIds)->whereDate('logged_at', $date)->where('log_type', 'check_out')->pluck('employid')->toArray();
        $bio        = BiometricLog::whereIn('employid', $empIds)->whereDate('datetime', $date)->where('punch_type', 'check_out')->pluck('employid')->toArray();
        $manual     = BiometricLogManual::whereIn('employid', $empIds)->whereDate('datetime', $date)->where('punch_type', 'check_out')->pluck('employid')->toArray();
        $vp         = VPLog::whereIn('employee_id', $empIds)->where('log_date', $date)->where('log_type', 'check_out')->pluck('employee_id')->toArray();

        return array_unique(array_merge($attendance, $bio, $manual, $vp));
    }
}