<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    // Use the 'calendar' connection
    protected $connection = 'calendar';

    protected $table = 'holidays';

    protected $fillable = [
        'HOLIDAY_NAME',
        'HOLIDAY_DATE', // format is 2025-12-31
        'HOLIDAY_TYPE',
        'CREATED_BY_EMP_NAME',
        'CREATED_BY_EMP_NUM',
        'CREATED_BY_EMP_DEPT',
        'DATE_CREATED',
    ];

    public $timestamps = false; // if your table doesn't use created_at/updated_at
}
