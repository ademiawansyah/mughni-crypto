<?php

namespace App\Services\Trading;

/**
 * DecisionGuardrailService
 *
 * Applies safety-net validation after decision fusion and before persistence.
 * This service is intentionally conservative and only blocks critical issues.
 *
 * Rules (applied in order):
 *   Rule 1 — Invalid payload hard block.
 *   Rule 2 — Missing/abnormal market data hard block.
 *   Rule 3 — Low confidence hard block.
 *
 * No strategic override is performed. BUY/SELL remains AI-owned unless one of
 * the critical safety violations above is detected.
 */
class DecisionGuardrailService
{
    private const MIN_CONFIDENCE = 45;

    /**
     * Apply guardrail checks to one fused decision.
     *
     * @param  array{action: string, confidence: int, risk_level: string, reason: string, flags?: array<int, string>}  $decision
     * @param  float  $rsi  Latest RSI value
     * @param  string  $trend  Market trend label, expected values: UP|DOWN|SIDEWAYS
     * @return array{action: string, confidence: int, risk_level: string, reason: string, flags: array<int, string>}
     */
    public function apply(array $decision, float $rsi, string $trend): array
    {
        $decision['flags'] = $this->normalizeFlags($decision['flags'] ?? []);
        $action = strtoupper((string) $decision['action']);
        $confidence = (int) $decision['confidence'];

        // Rule 1 — Invalid payload hard block.
        if (! in_array($action, ['BUY', 'SELL', 'HOLD'], true)) {
            $decision['action'] = 'HOLD';
            $decision['confidence'] = 0;
            $decision['risk_level'] = 'HIGH';
            $decision['reason'] = trim((string) $decision['reason']).' | guardrail:invalid_action';
            $decision['flags'][] = 'guardrail_invalid_action';

            return $this->finalize($decision);
        }

        // Rule 2 — Missing/abnormal market data hard block.
        if (! is_finite($rsi) || $rsi <= 0.0 || $rsi >= 100.0 || trim($trend) === '') {
            $decision['action'] = 'HOLD';
            $decision['confidence'] = 0;
            $decision['risk_level'] = 'HIGH';
            $decision['reason'] = trim((string) $decision['reason']).' | guardrail:invalid_market_data';
            $decision['flags'][] = 'guardrail_invalid_market_data';

            return $this->finalize($decision);
        }

        // Rule 3 — Low confidence hard block.
        if ($action !== 'HOLD' && $confidence < self::MIN_CONFIDENCE) {
            $decision['action'] = 'HOLD';
            $decision['reason'] = trim((string) $decision['reason']).' | guardrail:low_confidence';
            $decision['flags'][] = 'guardrail_low_confidence';
        }

        return $this->finalize($decision);
    }

    /**
     * @param  array{action: string, confidence: int, risk_level: string, reason: string, flags: array<int, string>}  $decision
     * @return array{action: string, confidence: int, risk_level: string, reason: string, flags: array<int, string>}
     */
    private function finalize(array $decision): array
    {
        $decision['flags'] = $this->normalizeFlags($decision['flags']);

        return $decision;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeFlags(mixed $flags): array
    {
        if (! is_array($flags)) {
            return [];
        }

        $normalized = [];

        foreach ($flags as $flag) {
            if (! is_string($flag)) {
                continue;
            }

            $trimmed = trim($flag);

            if ($trimmed === '') {
                continue;
            }

            $normalized[] = $trimmed;
        }

        return array_values(array_unique($normalized));
    }
}
