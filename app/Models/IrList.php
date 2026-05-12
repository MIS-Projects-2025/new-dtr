<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IrList extends Model
{
    protected $connection = 'masterlist';

    protected $table = 'ir_list';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $fillable = [
        'ir_no',
        'emp_no',
        'code_no',
        'violation',
        'da_type',
        'date_commited',
        'offense_no',
        'disposition',
        'date_of_suspension',
        'days_no',
        'valid',
        'why1',
        'why2',
        'why3',
        'why4',
        'why5',
        'cleansed',
        'appeal_da_type',
        'appeal_days',
        'appeal_date',
        'date_of_LOE',
    ];

    protected $casts = [
        'id' => 'integer',
        'emp_no' => 'integer',
        'da_type' => 'integer',
        'disposition' => 'integer',
        'days_no' => 'integer',
        'valid' => 'integer',
        'cleansed' => 'integer',
        'appeal_da_type' => 'integer',
        'appeal_days' => 'integer',
        'date_of_LOE' => 'datetime',
    ];
}