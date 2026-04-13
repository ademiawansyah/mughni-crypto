<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketIndicator extends Model
{
    use HasFactory;

    protected $table = 'market_indicators';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'timestamp' => 'datetime',
            'price' => 'decimal:8',
            'volume' => 'decimal:8',
            'volume_ma' => 'decimal:8',
        ];
    }

    public function aiDecisions(): HasMany
    {
        $relation = $this->hasMany(AiDecision::class, 'coin', 'coin');

        if ($this->timeframe !== null) {
            $relation->where('timeframe', $this->timeframe);
        }

        if ($this->timestamp !== null) {
            $relation->where('timestamp', $this->timestamp);
        }

        return $relation;
    }
}
