<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceLog extends Model
{
    protected $connection = 'dtr';
    protected $table      = 'attendance_logs';
    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $fillable = [
        'employid',
        'employee_name',
        'department',
        'log_type',
        'finger_label',
        'finger_index',
        'match_score',
        'quality',
        'matched',
        'device_type',
        'recorded_by',
        'logged_at',
    ];

    protected $casts = [
        'matched'      => 'boolean',
        'match_score'  => 'integer',
        'quality'      => 'integer',
        'finger_index' => 'integer',
        'logged_at'    => 'datetime',
        'log_date'     => 'date',
    ];

    // log_date is a STORED GENERATED column — never write to it
    protected $guarded = ['id', 'log_date'];

    // ─────────────────────────────────────────────────────────────────────────
    // LOG TYPE DEFINITIONS
    // ─────────────────────────────────────────────────────────────────────────

    /** Ordered sequence of log types for a single work day. */
    public const TYPE_SEQUENCE = [
        1 => 'time_in',
        2 => 'break_out_1',
        3 => 'break_in_1',
        4 => 'lunch_out',
        5 => 'lunch_in',
        6 => 'break_out_2',
        7 => 'break_in_2',
        8 => 'time_out',
    ];

    /** Human-readable labels per log type. */
    public const TYPE_LABELS = [
        'time_in'     => 'Time In',
        'break_out_1' => 'Break Out 1',
        'break_in_1'  => 'Break In 1',
        'lunch_out'   => 'Lunch Out',
        'lunch_in'    => 'Lunch In',
        'break_out_2' => 'Break Out 2',
        'break_in_2'  => 'Break In 2',
        'time_out'    => 'Time Out',
    ];

    /** DaisyUI badge colour per log type. */
    public const TYPE_COLORS = [
        'time_in'     => 'success',
        'break_out_1' => 'warning',
        'break_in_1'  => 'info',
        'lunch_out'   => 'warning',
        'lunch_in'    => 'info',
        'break_out_2' => 'warning',
        'break_in_2'  => 'info',
        'time_out'    => 'error',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // HELPER — next log type for an employee today
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Counts today's successful logs for $employid and returns
     * the next log_type in the sequence. Steps beyond 8 stay at 'time_out'.
     */
    public static function nextLogTypeFor(string $employid): string
    {
        $todayCount = static::where('employid', $employid)
            ->whereDate('logged_at', today())
            ->where('matched', true)
            ->count();

        $step = min($todayCount + 1, 8);

        return static::TYPE_SEQUENCE[$step];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ACCESSORS
    // ─────────────────────────────────────────────────────────────────────────

    public function getLogTypeLabelAttribute(): string
    {
        return static::TYPE_LABELS[$this->log_type] ?? $this->log_type;
    }

    public function getLogTypeColorAttribute(): string
    {
        return static::TYPE_COLORS[$this->log_type] ?? 'ghost';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RELATIONSHIPS
    // ─────────────────────────────────────────────────────────────────────────

    public function employee()
    {
        return $this->belongsTo(EmployeeMasterlist::class, 'employid', 'EMPLOYID');
    }
}