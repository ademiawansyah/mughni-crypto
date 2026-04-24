<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarketContext extends Model
{
    use HasFactory;

    protected $table = 'market_contexts';

    protected $fillable = [
        'coin',
        'timeframe',
        'market_regime',
        'support_level',
        'resistance_level',
        'sentiment',
        'source',
        'execution_id',
        'mcp_passed',
        'mcp_score',
        'mcp_candidate',
        'mcp_timeframe',
        'mcp_reason',
        'mtf_score',
        'preliminary_action',
        'base_confidence',
        'role_timeframes',
        'timeframe_summary',
        'fusion_ai_action',
        'fusion_ai_confidence',
        'fusion_final_action',
        'fusion_confidence_adjusted',
        'final_action',
        'final_confidence',
        'decision_status',
        'timestamp',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'timestamp' => 'datetime',
            'support_level' => 'decimal:8',
            'resistance_level' => 'decimal:8',
            'mcp_passed' => 'boolean',
            'mcp_score' => 'integer',
            'mtf_score' => 'decimal:4',
            'base_confidence' => 'integer',
            'role_timeframes' => 'array',
            'fusion_ai_confidence' => 'integer',
            'fusion_confidence_adjusted' => 'integer',
            'final_confidence' => 'integer',
        ];
    }
}
