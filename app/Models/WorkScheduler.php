<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkScheduler extends Model
{
    use HasFactory;

    protected $connection = 'calendar'; // custom database connection
    protected $table = 'work_scheduler';
    protected $primaryKey = 'ID';

    public $timestamps = false; // we will manage datetime fields manually

    protected $fillable = [
        'EMPID',  //use this to connect to other models instead of ID since EMPID is the unique identifier for employees
        'EMPNAME',
        'SCHEDULE',
        'WORK_SCHED_STATUS',
        'PAYROLL_DATE_START',
        'PAYROLL_DATE_END',
        'SUPERVISOR_ID',
        'APPROVER2_ID',
        'DEPARTMENT',
        'SHIFT',
        'REMARKS',
        'CREATED_BY',
        'DATE_CREATED',
        'DATE_UPDATED',
    ];

    protected $casts = [
        'SCHEDULE' => 'array',          // auto convert JSON to array
        'WORK_SCHED_STATUS' => 'boolean',
        'PAYROLL_DATE_START' => 'date',
        'PAYROLL_DATE_END' => 'date',
        'DATE_CREATED' => 'datetime',
        'DATE_UPDATED' => 'datetime',
    ];

public function getScheduleWithShiftDetails()
{
    $schedule = $this->SCHEDULE ?? [];

    // Clean + normalize shift IDs
    $shiftIds = collect($schedule)
        ->filter()
        ->map(fn($id) => (int) $id)
        ->unique()
        ->values()
        ->toArray();

    $shiftCodes = ShiftCode::whereIn('SHIFT_CODE_ID', $shiftIds)
        ->get()
        ->keyBy('SHIFT_CODE_ID');

    $result = [];

    foreach ($schedule as $day => $shiftId) {
        $shiftId = (int) $shiftId;

        $result[$day] = [
            'shift_id' => $shiftId,
            'details'  => $shiftCodes[$shiftId] ?? null,
        ];
    }

    return $result;
}

    /**
     * Get all unique ShiftCodes used across this schedule's JSON.
     */
    public function shiftCodes()
    {
        $shiftIds = collect($this->SCHEDULE ?? [])
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();

        return ShiftCode::whereIn('SHIFT_CODE_ID', $shiftIds)->get();
    }

    /**
     * Get the ShiftCode for a specific day key (e.g. 'day_1', 'day_15').
     */
    public function shiftCodeForDay(string $day): ?ShiftCode
    {
        $shiftId = (int) ($this->SCHEDULE[$day] ?? 0);

        if (!$shiftId) return null;

        return ShiftCode::find($shiftId);
    }

    /**
     * EmployeeMasterlist — foreign key: EMPID → EMPLOYID
     */
    public function employee()
    {
        return $this->belongsTo(EmployeeMasterlist::class, 'EMPID', 'EMPLOYID');
    }
}