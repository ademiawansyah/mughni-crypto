<?php

namespace App\Enums;

/**
 * ActionCandidate
 *
 * Represents the candidate trade direction produced by the MCP pre-filter.
 *
 * - BUY: conditions suggest a potential long entry
 * - SELL: conditions suggest a potential short/exit entry
 */
enum ActionCandidate: string
{
    case Buy = 'BUY';
    case Sell = 'SELL';
}
