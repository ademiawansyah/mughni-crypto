<?php

namespace App\Enums;

/**
 * MarketTrend
 *
 * Represents the directional bias of a market based on EMA alignment.
 *
 * - UP: short-term EMA is above long-term EMA (bullish structure)
 * - DOWN: short-term EMA is below long-term EMA (bearish structure)
 * - SIDEWAYS: EMAs are too close to determine direction
 */
enum MarketTrend: string
{
    case Up = 'UP';
    case Down = 'DOWN';
    case Sideways = 'SIDEWAYS';
}
