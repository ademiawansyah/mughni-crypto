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

    // has many model scan result details (for supporting data)
    public function details()
    {
        return $this->hasMany(ModelScanResultDetail::class, 'model_scan_result_id');
    }
}
