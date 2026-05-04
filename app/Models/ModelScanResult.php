<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModelScanResult extends Model
{
    protected $fillable = [
        'model_name',
        'execution_id',
        'execution_date',
        'result',
        'supporting_data',
    ];

    protected $casts = [
        'execution_date' => 'date',
        'result' => 'array',
        'supporting_data' => 'array',
    ];
}
