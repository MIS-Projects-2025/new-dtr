<?php
// app/Models/AreaEmployee.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AreaEmployee extends Model
{
    protected $connection = 'dtr';

    protected $fillable = [
        'area_id',
        'employee_id',
        'created_by'
    ];

    public function area()
    {
        return $this->belongsTo(Area::class);
    }
}