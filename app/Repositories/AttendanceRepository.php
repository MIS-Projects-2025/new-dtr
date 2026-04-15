<?php

namespace App\Repositories;

use App\Models\EmployeeMasterlist;
use App\Models\AttendanceLog;
use App\Models\BiometricLog;
use App\Models\BiometricLogManual;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceRepository
{
    // ── Punch-type normalisation map ─────────────────────────────────────────
    // Both numeric legacy codes (AttendanceLog) and named string codes
    // (BiometricLog / BiometricLogManual) are mapped to a canonical type.

    private const TYPE_MAP = [
        // Legacy numeric codes stored in AttendanceLog.log_type
        '0' => 'check_in',
        '1' => 'check_out',
        '2' => 'break_out',
        '3' => 'break_in',
        '4' => 'lunch_out',
        '5' => 'lunch_in',
        // Named codes stored in BiometricLog / BiometricLogManual
        'check_in'  => 'check_in',
        'check_out' => 'check_out',
        'break_out' => 'break_out',
        'break_in'  => 'break_in',
        'lunch_out' => 'lunch_out',
        'lunch_in'  => 'lunch_in',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Return all punch logs for an employee in a given month, keyed by date.
     *
     * The fetch window is extended by ±1 day to handle night-shift boundary
     * punches that fall on the adjacent calendar day.
     *
     * Result shape:
     *   [
     *     'Y-m-d' => [
     *       ['time' => 'HH:MM', 'type' => 'check_in', 'datetime' => '…', 'source' => 'biometric'],
     *       …
     *     ],
     *     …
     *   ]
     */
    public function getLogsForMonth(string $employId, string $month): array
    {
        $monthStart = Carbon::parse("{$month}-01");
        $monthEnd   = $monthStart->copy()->endOfMonth();
        $fetchFrom  = $monthStart->copy()->subDay()->format('Y-m-d');
        $fetchTo    = $monthEnd->copy()->addDay()->format('Y-m-d');

        $bioLogs = [];

        $this->collectAttendanceLogs($employId, $fetchFrom, $fetchTo, $bioLogs);
        $this->collectBiometricLogs($employId, $fetchFrom, $fetchTo, $bioLogs);
        $this->collectBiometricManualLogs($employId, $fetchFrom, $fetchTo, $bioLogs);

        return $bioLogs;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE COLLECTORS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Collect from attendance_logs (legacy, numeric punch types).
     */
    private function collectAttendanceLogs(
        string $employId,
        string $from,
        string $to,
        array &$bioLogs
    ): void {
        AttendanceLog::where('employid', $employId)
    ->where('logged_at', '>=', $from . ' 00:00:00')
    ->where('logged_at', '<=', $to   . ' 23:59:59')
    ->orderBy('logged_at')
    ->each(function ($record) use (&$bioLogs) {
        $this->addLog(
            $record->logged_at,
            $record->log_type,
            'attendance',
            $bioLogs
        );
    });
    }

    /**
     * Collect from biometric_logs (primary source).
     * Uses the model relationship to benefit from the Eloquent connection config.
     */
    private function collectBiometricLogs(
        string $employId,
        string $from,
        string $to,
        array &$bioLogs
    ): void {
        BiometricLog::where('employid', $employId)
    ->where('datetime', '>=', $from . ' 00:00:00')
    ->where('datetime', '<=', $to   . ' 23:59:59')
    ->orderBy('datetime')
    ->each(function ($record) use (&$bioLogs) {
        $this->addLog(
            $record->datetime,
            $record->punch_type,
            'biometric',
            $bioLogs
        );
    });
    }

    /**
     * Collect from biometric_logs_manual (HR-entered overrides).
     */
    private function collectBiometricManualLogs(
        string $employId,
        string $from,
        string $to,
        array &$bioLogs
    ): void {
        BiometricLogManual::where('employid', $employId)
    ->where('datetime', '>=', $from . ' 00:00:00')
    ->where('datetime', '<=', $to   . ' 23:59:59')
    ->orderBy('datetime')
    ->each(function ($record) use (&$bioLogs) {
        $this->addLog(
            $record->datetime,
            $record->punch_type,
            'manual',
            $bioLogs
        );
    });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Normalise and push a single punch into the $bioLogs accumulator.
     *
     * @param  mixed  $datetime  Carbon instance, datetime string, or null
     * @param  mixed  $punchType Raw punch type value (numeric string or named string)
     * @param  string $source    'attendance' | 'biometric' | 'manual'
     */
    private function addLog(
        mixed  $datetime,
        mixed  $punchType,
        string $source,
        array &$bioLogs
    ): void {
        if (empty($datetime) || empty($punchType)) {
            return;
        }

        $dt      = Carbon::parse($datetime);
        $date    = $dt->format('Y-m-d');
        $time    = $dt->format('H:i');
        $typeKey = strtolower(trim((string) $punchType));
        $type    = self::TYPE_MAP[$typeKey] ?? null;

        if ($type === null) {
            return; // unknown punch type — silently skip
        }

        $bioLogs[$date][] = [
            'time'     => $time,
            'type'     => $type,
            'datetime' => (string) $datetime,
            'source'   => $source,
        ];
    }

    /**
 * Build the same date-keyed log array from already-loaded relations
 * instead of hitting the DB again.
 */
public function getLogsFromModel(EmployeeMasterlist $emp, string $month): array
{
    $bioLogs = [];

    foreach ($emp->attendanceLogs as $record) {
        $this->addLog($record->logged_at, $record->log_type, 'attendance', $bioLogs);
    }

    foreach ($emp->biometricLogs as $record) {
        $this->addLog($record->datetime, $record->punch_type, 'biometric', $bioLogs);
    }

    foreach ($emp->biometricLogsManual as $record) {
        $this->addLog($record->datetime, $record->punch_type, 'manual', $bioLogs);
    }

    return $bioLogs;
}


}