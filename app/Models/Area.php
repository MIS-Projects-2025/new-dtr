<?php
// app/Models/Area.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Area extends Model
{
    protected $connection = 'dtr';

    protected $fillable = [
        'name',
        'required_hc',
        'category',
        'created_by'
    ];

    public function areaEmployees()
    {
        return $this->hasMany(AreaEmployee::class);
    }
}