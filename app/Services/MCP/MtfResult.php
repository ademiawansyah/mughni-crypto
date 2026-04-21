<?php

namespace App\Services\MCP;

use App\Enums\ActionCandidate;

/**
 * MtfResult — Multi-Timeframe Evaluation Result
 *
 * Immutable value object returned by MultiTimeframeMCPService after combining
 * the 5m (entry trigger) and 15m (confirmation filter) MCP evaluations.
 *
 * Fields:
 *   symbol             — CoinGecko coin ID
 *   actionCandidate    — Trade direction inherited from the 5m signal
 *   mtfSignalStrength  — strong | moderate | weak (see MultiTimeframeMCPService)
 *   shouldSendToAi     — Whether this symbol should be forwarded to the AI service
 *   reason             — confirmed | neutral | contradiction
 *   data5m             — Raw array output from the 5m McpResult
 *   data15m            — Raw array output from the 15m McpResult (or empty array when null)
 */
readonly class MtfResult
{
    /**
     * @param  string  $symbol  CoinGecko coin ID (e.g. 'bitcoin')
     * @param  ActionCandidate  $actionCandidate  Direction from the 5m entry trigger
     * @param  string  $mtfSignalStrength  strong | moderate | weak
     * @param  bool  $shouldSendToAi  True when the signal clears both timeframes
     * @param  string  $reason  confirmed | neutral | contradiction
     * @param  array<string, mixed>  $data5m  Serialised 5m McpResult payload
     * @param  array<string, mixed>  $data15m  Serialised 15m McpResult payload (empty when unavailable)
     */
    public function __construct(
        public string $symbol,
        public ActionCandidate $actionCandidate,
        public string $mtfSignalStrength,
        public bool $shouldSendToAi,
        public string $reason,
        public array $data5m,
        public array $data15m,
    ) {}

    /**
     * Serialize to the structured array forwarded to the AI service or stored for logging.
     *
     * @return array{
     *   symbol: string,
     *   action_candidate: string,
     *   mtf_signal_strength: string,
     *   should_send_to_ai: bool,
     *   reason: string,
     *   data: array{5m: array<string, mixed>, 15m: array<string, mixed>},
     * }
     */
    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'action_candidate' => $this->actionCandidate->value,
            'mtf_signal_strength' => $this->mtfSignalStrength,
            'should_send_to_ai' => $this->shouldSendToAi,
            'reason' => $this->reason,
            'data' => [
                '5m' => $this->data5m,
                '15m' => $this->data15m,
            ],
        ];
    }
}
