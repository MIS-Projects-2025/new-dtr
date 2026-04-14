<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShiftCode extends Model
{
    use HasFactory;

    protected $connection = 'calendar'; // same custom connection
    protected $table = 'shift_codes';
    protected $primaryKey = 'SHIFT_CODE_ID';  // use this Shift Code ID to connect to WorkScheduler's SCHEDULE field which is a JSON that has the shift code id as value for each day of the month

    public $timestamps = false; // we'll map custom timestamp columns

    protected $fillable = [
        'SHIFT_CODE_STATUS',
        'SHIFTCODE',
        'SHIFTCODE_VALUE',
        'SHIFTCODE_DESC',
        'SHIFT_GROUP',
        'SHIFTCODE_BG_COLOR',
        'SHIFTCODE_FONT_COLOR',
        'TIME_WINDOWS',
        'OT_HRS',
        'CREATED_AT',
        'CREATED_BY',
        'UPDATED_AT',
        'UPDATED_BY',
    ];

    protected $casts = [
        'TIME_WINDOWS' => 'array', // JSON → array
        'CREATED_AT' => 'datetime',
        'UPDATED_AT' => 'datetime',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // RELATIONSHIPS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * WorkSchedulers that reference this ShiftCode in their SCHEDULE JSON.
     * Uses MySQL JSON_CONTAINS — requires MySQL 5.7+.
     * Tries both string and integer variants since JSON encoding may vary.
     */
    public function workSchedulers()
    {
        return WorkScheduler::whereJsonContains('SCHEDULE', (string) $this->SHIFT_CODE_ID)
            ->orWhereJsonContains('SCHEDULE', $this->SHIFT_CODE_ID)
            ->get();
    }
}