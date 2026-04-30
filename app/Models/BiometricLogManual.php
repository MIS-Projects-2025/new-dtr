<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BiometricLogManual extends Model
{
    protected $connection = 'dtr';
    protected $table = 'biometric_logs_manual';
    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $fillable = [
        'employid', //use this to connect to other models instead of id since employid is the unique identifier for employees
        'datetime',
        'device_ip',
        'device_number',
        'punch_type', // <= this can be check_in, check_out, break_in, break_out
        'employee_category',  
        'auth_mode',
        'work_code',
        'state',
    ];

    protected $casts = [
        'datetime' => 'datetime',
        'auth_mode' => 'integer',
        'work_code' => 'integer',
        'state' => 'integer',
    ];
    public function employee()
    {
        return $this->belongsTo(EmployeeMasterlist::class, 'employid', 'EMPLOYID');
        //                                                   ^ their FK   ^ master PK (EMPLOYID)
    }
}
