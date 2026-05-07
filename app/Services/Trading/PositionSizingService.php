<?php

namespace App\Services\Trading;

/**
 * PositionSizingService
 *
 * Calculates the position size for a BUY or SELL signal based on fixed
 * fractional risk management. The calculation is entirely deterministic and
 * does not call any external APIs or AI services.
 *
 * Formula:
 *   risk_amount   = capital × risk_per_trade
 *   stop_distance = |entry − stop_loss|
 *   position_size = risk_amount / stop_distance
 *
 * The result is capped at max_position_percent × capital to prevent outsized
 * exposure when stop distance is very small.
 */
class PositionSizingService
{
    /**
     * Append position sizing fields to a confirmed BUY/SELL decision.
     *
     * Returns the decision unchanged when:
     *   - action is not BUY or SELL
     *   - entry or stop_loss is missing
     *   - stop distance is zero or negative (invalid levels)
     *
     * @param  array{action: string, confidence: int, risk_level: string, reason: string, entry?: float, take_profit?: float, stop_loss?: float}  $decision
     * @return array{action: string, confidence: int, risk_level: string, reason: string, entry?: float, take_profit?: float, stop_loss?: float, position_size?: float, risk_amount?: float}
     */
    public function calculate(array $decision): array
    {
        $action = strtoupper((string) $decision['action']);

        if (! in_array($action, ['BUY', 'SELL'], true)) {
            return $decision;
        }

        $entry = isset($decision['entry']) ? (float) $decision['entry'] : null;
        $stopLoss = isset($decision['stop_loss']) ? (float) $decision['stop_loss'] : null;

        if ($entry === null || $stopLoss === null) {
            return $decision;
        }

        $stopDistance = $action === 'BUY'
            ? $entry - $stopLoss
            : $stopLoss - $entry;

        // Guard: invalid stop placement — entry and stop_loss are on the wrong side
        if ($stopDistance <= 0) {
            return $decision;
        }

        $capital = (float) config('trading.capital', 1000.0);
        $riskPercent = (float) config('trading.risk_per_trade', 0.01);
        $maxPositionPercent = (float) config('trading.max_position_percent', 0.20);

        $riskAmount = $capital * $riskPercent;
        $positionSize = $riskAmount / $stopDistance;

        // Cap to prevent over-exposure when stop distance is very tight
        $maxPositionSize = $capital * $maxPositionPercent;

        if ($positionSize > $maxPositionSize) {
            $positionSize = $maxPositionSize;
        }

        $decision['position_size'] = round($positionSize, 8);
        $decision['risk_amount'] = round($riskAmount, 8);

        return $decision;
    }
}
