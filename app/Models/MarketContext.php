<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarketContext extends Model
{
    use HasFactory;

    protected $table = 'market_contexts';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'timestamp' => 'datetime',
            'support_level' => 'decimal:8',
            'resistance_level' => 'decimal:8',
        ];
    }
}
