<?php

namespace App\Services\Trading;

/**
 * DecisionGuardrailService
 *
 * Applies deterministic post-AI trading guardrails before persistence and
 * notifications. This service does not call external APIs and does not mutate
 * the decision DTO shape.
 *
 * Rules (applied in order):
 *   Rule 1 — MTF enforcement: if action differs from preliminary_action, revert it.
 *   Rule 2 — RSI enforcement: BUY blocked when RSI >= 25, SELL when RSI <= 75.
 *   Rule 3 — Confidence enforcement: action forced to HOLD when confidence < 55.
 *   Rule 4 — Strong SELL override: RSI > 80 + UP trend → forced SELL, confidence ≥ 75.
 */
class DecisionGuardrailService
{
    /**
     * Apply all guardrails to one decision.
     *
     * Pass $mtfResult to enable Rule 1 (MTF enforcement). When null, Rule 1 is skipped
     * and the service behaves identically to the pre-MTF version.
     *
     * @param  array{action: string, confidence: int, risk_level: string, reason: string}  $decision
     * @param  float  $rsi  Latest RSI value
     * @param  string  $trend  Market trend label, expected values: UP|DOWN|SIDEWAYS
     * @param  MTFResultDTO|null  $mtfResult  Optional MTF result for preliminary_action enforcement
     * @return array{action: string, confidence: int, risk_level: string, reason: string}
     */
    public function apply(array $decision, float $rsi, string $trend, ?MTFResultDTO $mtfResult = null): array
    {
        // Rule 1 — MTF enforcement: AI must not override the preliminary action.
        if ($mtfResult !== null) {
            $preliminaryAction = strtoupper($mtfResult->preliminaryAction);
            $currentAction = strtoupper((string) $decision['action']);

            if ($currentAction !== $preliminaryAction) {
                $decision['action'] = $preliminaryAction;
                $decision['reason'] = trim($decision['reason']).' | mtf_enforced';
            }
        }

        $action = strtoupper((string) $decision['action']);

        // Rule 2 — RSI enforcement.
        if ($action === 'BUY' && $rsi >= 25.0) {
            $decision['action'] = 'HOLD';
        }

        if ($action === 'SELL' && $rsi <= 75.0) {
            $decision['action'] = 'HOLD';
        }

        // Rule 3 — Confidence enforcement.
        if ((int) $decision['confidence'] < 55) {
            $decision['action'] = 'HOLD';
        }

        // Rule 4 — Strong SELL override (re-read action after earlier rules may have changed it).
        if ($rsi > 80.0 && strtoupper(trim($trend)) === 'UP') {
            $decision['action'] = 'SELL';
            $decision['confidence'] = max((int) $decision['confidence'], 75);
            $decision['reason'] = 'overbought reversal | overridden';
        }

        return $decision;
    }
}
