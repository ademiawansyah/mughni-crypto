<?php

namespace App\Services\Trading;

/**
 * DecisionGuardrailService
 *
 * Applies deterministic post-AI trading guardrails before persistence and
 * notifications. This service does not call external APIs and does not mutate
 * the decision DTO shape.
 */
class DecisionGuardrailService
{
    /**
     * Apply RSI, confidence, and reversal override guardrails to one decision.
     *
     * @param  array{action: string, confidence: int, risk_level: string, reason: string}  $decision
     * @param  float  $rsi  Latest RSI value
     * @param  string  $trend  Market trend label, expected values: UP|DOWN|SIDEWAYS
     * @return array{action: string, confidence: int, risk_level: string, reason: string}
     */
    public function apply(array $decision, float $rsi, string $trend): array
    {
        $action = strtoupper((string) $decision['action']);

        if ($action === 'BUY' && $rsi >= 25.0) {
            $decision['action'] = 'HOLD';
        }

        if ($action === 'SELL' && $rsi <= 75.0) {
            $decision['action'] = 'HOLD';
        }

        if ((int) $decision['confidence'] < 55) {
            $decision['action'] = 'HOLD';
        }

        if ($rsi > 80.0 && strtoupper(trim($trend)) === 'UP') {
            $decision['action'] = 'SELL';
            $decision['confidence'] = max((int) $decision['confidence'], 75);
            $decision['reason'] = 'overbought reversal | overridden';
        }

        return $decision;
    }
}
