<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModelScanResultDetail extends Model
{
    protected $fillable = [
        'model_scan_result_id',
        'coin_id',
        'rank',
        'is_passed',
        'price',
        'stop_loss',
        'score',
        'data',
    ];

    protected $casts = [
        'is_passed' => 'boolean',
        'price' => 'float',
        'stop_loss' => 'float',
        'score' => 'float',
        'data' => 'array',
    ];

    // belongs to model scan result
    public function modelScanResult(): BelongsTo
    {
        return $this->belongsTo(ModelScanResult::class, 'model_scan_result_id');
    }

    // belongs to coin for symbol/name details
    public function coin(): BelongsTo
    {
        return $this->belongsTo(Coin::class, 'coin_id');
    }
}
