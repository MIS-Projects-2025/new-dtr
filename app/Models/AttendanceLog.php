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
        'log_type', // <= this can be check_in, check_out, break_in1, break_out1, break_in2, break_out2, lunch_in, lunch_out
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

    // ─────────────────────────────────────────────────────────────────────────
    // RELATIONSHIPS
    // ─────────────────────────────────────────────────────────────────────────

    public function employee()
    {
        return $this->belongsTo(EmployeeMasterlist::class, 'employid', 'EMPLOYID');
    }
}