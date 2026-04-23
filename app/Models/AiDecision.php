<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiDecision extends Model
{
    use HasFactory;

    protected $table = 'ai_decisions';

    public $timestamps = false;

    protected $fillable = [
        'execution_id',
        'coin',
        'timeframe',
        'timestamp',
        'input_data',
        'action',
        'confidence',
        'is_trade_candidate',
        'risk_level',
        'reason',
        'price_at_decision',
        'position_size',
        'risk_amount',
        'raw_response',
        'model_used',
        'latency_ms',
        'market_trend',
        'price_after_5m',
        'price_after_15m',
        'max_profit',
        'max_drawdown',
        'result',
    ];

    protected function casts(): array
    {
        return [
            'input_data' => 'array',
            'raw_response' => 'array',
            'is_trade_candidate' => 'boolean',
            'timestamp' => 'datetime',
            'price_at_decision' => 'decimal:8',
            'position_size' => 'decimal:8',
            'risk_amount' => 'decimal:8',
            'price_after_5m' => 'decimal:8',
            'price_after_15m' => 'decimal:8',
            'price_after_1h' => 'decimal:8',
            'max_profit' => 'decimal:8',
            'max_drawdown' => 'decimal:8',
        ];
    }

    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class);
    }

    public function scopeForIndicator(Builder $query, MarketIndicator $indicator): Builder
    {
        return $query
            ->where('coin', $indicator->coin)
            ->where('timeframe', $indicator->timeframe)
            ->where('timestamp', $indicator->timestamp);
    }
}
