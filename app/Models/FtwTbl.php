<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FtwTbl extends Model
{
    use HasFactory;

    protected $connection = 'masterlist';
    protected $table = 'ftw_tbl';
    protected $primaryKey = 'tbl_id';

    public $timestamps = false; // since you're using custom datetime fields

    protected $fillable = [
        'emp_no',
        'emp_name',
        'emp_dept',
        'emp_team',
        'emp_time_in',
        'emp_time_out',
        'emp_diagnose',
        'recommendation', // values can be 1, 2, 3, 4, 5, 6
        'ftw_file_link',
        'date_created',
        'duty_nurse',
        'first_aider_name',
        'immediate_sup',
        'ack_by',
        'training_dept',
        'process_status',
        'emp_shift',
        'ftw-date', // ⚠️ special case (see note below)
        'absent_from',
        'absent_to',
        'absent_count',
        'sdh_date',
        'sdh_time',
        'rest_date',
        'rest_time_in',
        'rest_time_out',
        'disapprove_remarks'
    ];

    protected $casts = [
        'emp_time_in' => 'datetime:H:i:s',
        'emp_time_out' => 'datetime:H:i:s',
        'date_created' => 'datetime',
        'absent_from' => 'datetime',
        'absent_to' => 'datetime',
        'sdh_date' => 'datetime',
        'sdh_time' => 'datetime:H:i:s',
        'rest_date' => 'datetime',
        'rest_time_in' => 'datetime:H:i:s',
        'rest_time_out' => 'datetime:H:i:s',
    ];

    public function employee()
    {
        return $this->belongsTo(EmployeeMasterlist::class, 'emp_no', 'EMPLOYID');
    }
}