<?php

namespace App\Services\AI;

use App\Models\MarketIndicator;
use Illuminate\Support\Facades\Log;

/**
 * AiAdvisorService
 *
 * Orchestrates the AI-driven trading signal pipeline for a single coin/timeframe pair.
 *
 * Pipeline:
 *   1. Fetch the latest MarketIndicator for the given coin + timeframe.
 *   2. Build a structured system + user prompt from the indicator values.
 *   3. Send the prompt to LM Studio via LmStudioClient.
 *   4. Pass the raw response through AiResponseParser to produce a structured decision.
 *   5. Return the decision together with the raw response and indicator snapshot.
 *
 * This service is stateless — it does not persist anything to the database.
 * All persistence is the responsibility of the caller (job).
 */
class AiAdvisorService
{
    public function __construct(
        private readonly LmStudioClient $client,
        private readonly AiResponseParser $parser,
    ) {}

    /**
     * Generate an AI trading decision for a given coin and timeframe.
     *
     * Returns a result array containing the parsed decision, the raw LM Studio
     * response, the indicator snapshot used as input, and the model that was used.
     * Returns null if no indicator data is available or the AI call fails completely.
     *
     * @param  string  $coin  CoinGecko coin ID (e.g. 'bitcoin')
     * @param  string  $timeframe  Timeframe label (e.g. '5m', '15m', '1h')
     * @return array{
     *   indicator: MarketIndicator,
     *   decision: array{action: string, confidence: int, risk_level: string, reason: string},
     *   raw_response: array<string, mixed>|null,
     *   model_used: string,
     * }|null
     */
    public function advise(string $coin, string $timeframe): ?array
    {
        $indicator = $this->fetchLatestIndicator($coin, $timeframe);

        if ($indicator === null) {
            Log::warning('[AiAdvisorService] No indicator found, skipping AI call', [
                'coin' => $coin,
                'timeframe' => $timeframe,
            ]);

            return null;
        }

        $messages = $this->buildMessages($indicator);

        // Log::info('[AiAdvisorService] Sending prompt to LM Studio', [
        //     'coin' => $coin,
        //     'timeframe' => $timeframe,
        //     'model' => config('ai.lm_studio.model'),
        // ]);

        $rawResponse = $this->client->chat($messages);

        $decision = $rawResponse !== null
            ? $this->parser->parse($rawResponse)
            : $this->failsafeDecision($coin, $timeframe);

        // Log::info('[AiAdvisorService] Decision produced', [
        //     'coin' => $coin,
        //     'timeframe' => $timeframe,
        //     'action' => $decision['action'],
        //     'confidence' => $decision['confidence'],
        // ]);

        return [
            'indicator' => $indicator,
            'decision' => $decision,
            'raw_response' => $rawResponse,
            'model_used' => (string) config('ai.lm_studio.model'),
        ];
    }

    /**
     * Retrieve the most recent MarketIndicator row for the given coin and timeframe.
     */
    private function fetchLatestIndicator(string $coin, string $timeframe): ?MarketIndicator
    {
        return MarketIndicator::query()
            ->where('coin', $coin)
            ->where('timeframe', $timeframe)
            ->orderByDesc('timestamp')
            ->first();
    }

    /**
     * Build the LM Studio messages array from a MarketIndicator instance.
     *
     * Returns two messages:
     *   - system: strict JSON-output instructions for the AI
     *   - user: human-readable indicator snapshot with the decision prompt
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function buildMessages(MarketIndicator $indicator): array
    {
        return [
            [
                'role' => 'system',
                'content' => $this->buildSystemMessage(),
            ],
            [
                'role' => 'user',
                'content' => $this->buildUserMessage($indicator),
            ],
        ];
    }

    /**
     * Return the system-level instruction that constrains AI output to strict JSON.
     */
    private function buildSystemMessage(): string
    {
        return <<<'SYSTEM'
        You are a disciplined crypto trading advisor focused on capital preservation and small consistent gains.

        STRICT RULES:
        - Capital protection is the top priority.
        - If there is NO clear setup, return HOLD.
        - Do NOT force trades.

        TRADING RULES:
        - RSI < 35 AND price near support → BUY
        - RSI between 35–65 → HOLD (no trade zone)
        - RSI > 70 AND price near resistance → SELL

        TREND RULE:
        - If trend is DOWN → avoid BUY unless strong reversal
        - If trend is UP → avoid SELL unless overbought

        RISK MANAGEMENT:
        - ALWAYS include:
        - entry
        - take_profit (2–5% from entry)
        - stop_loss (2–3% from entry)

        CONFIDENCE RULE:
        - 0–39 = LOW
        - 40–69 = MEDIUM
        - 70–100 = HIGH

        OUTPUT FORMAT (STRICT JSON ONLY, NO EXTRA TEXT):

        {
        "action": "BUY | SELL | HOLD",
        "confidence": number,
        "entry": number | null,
        "take_profit": number | null,
        "stop_loss": number | null,
        "risk_level": "LOW | MEDIUM | HIGH",
        "reason": "max 1 short sentence"
        }

        FAIL-SAFE:
        If conditions are unclear, return:
        {
        "action": "HOLD",
        "confidence": 0,
        "entry": null,
        "take_profit": null,
        "stop_loss": null,
        "risk_level": "LOW",
        "reason": "No clear setup"
        }
        SYSTEM;
    }

    /**
     * Build the user message containing indicator values and strict evaluation instruction.
     */
    private function buildUserMessage(MarketIndicator $indicator): string
    {
        $price = number_format((float) $indicator->price, 2, '.', '');
        $ema9 = number_format((float) $indicator->ema9, 2, '.', '');
        $ema21 = number_format((float) $indicator->ema21, 2, '.', '');
        $rsi = round((float) $indicator->rsi, 2);
        $trend = $indicator->trend ?? 'unknown';

        return <<<MSG
        Coin: {$indicator->coin}
        Price: {$price}
        RSI: {$rsi}
        EMA9: {$ema9}
        EMA21: {$ema21}
        Trend: {$trend}

        Evaluate strictly based on the defined rules and return JSON only.
        MSG;
    }

    /**
     * Return a safe HOLD decision used when the AI service is unavailable.
     *
     * @return array{action: string, confidence: int, risk_level: string, reason: string}
     */
    private function failsafeDecision(string $coin, string $timeframe): array
    {
        Log::warning('[AiAdvisorService] AI call failed, defaulting to HOLD', [
            'coin' => $coin,
            'timeframe' => $timeframe,
        ]);

        return [
            'action' => 'HOLD',
            'confidence' => 0,
            'risk_level' => 'HIGH',
            'reason' => 'AI service unavailable',
        ];
    }
}
