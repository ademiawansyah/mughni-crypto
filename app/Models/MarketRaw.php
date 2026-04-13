<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarketRaw extends Model
{
    use HasFactory;

    protected $table = 'market_raw';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'request_params' => 'array',
            'response_json' => 'array',
            'timestamp' => 'datetime',
        ];
    }
}
