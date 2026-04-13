<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Trade extends Model
{
    use HasFactory;

    protected $table = 'trades';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'timestamp' => 'datetime',
            'price' => 'decimal:8',
            'amount' => 'decimal:8',
            'total_value' => 'decimal:8',
            'fee' => 'decimal:8',
            'profit_loss' => 'decimal:8',
            'profit_loss_pct' => 'decimal:8',
        ];
    }

    public function aiDecision(): BelongsTo
    {
        return $this->belongsTo(AiDecision::class);
    }
}
