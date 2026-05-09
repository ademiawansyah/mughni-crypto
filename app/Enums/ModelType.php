<?php

namespace App\Enums;

/**
 * ModelType
 *
 * Represents one of the independent trading signal models.
 * Each model operates independently and produces its own Top 10 signal list.
 */
enum ModelType: string
{
    case CounterTrend = 'counter_trend';
    case PrePump = 'pre_pump';
    case Momentum = 'momentum';
    case SpotMomentumGainer = 'spot_momentum_gainer';
    case DailySafeMomentum = 'daily_safe_momentum';
}
