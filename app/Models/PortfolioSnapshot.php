<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PortfolioSnapshot extends Model
{
    use HasFactory;

    protected $table = 'portfolio_snapshots';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'timestamp' => 'datetime',
            'total_balance' => 'decimal:8',
            'cash_balance' => 'decimal:8',
            'asset_value' => 'decimal:8',
            'unrealized_pnl' => 'decimal:8',
            'realized_pnl' => 'decimal:8',
            'drawdown' => 'decimal:8',
            'positions' => 'array',
        ];
    }
}
