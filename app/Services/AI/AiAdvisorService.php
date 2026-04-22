<?php

namespace App\Services\AI;

use App\Models\MarketIndicator;
use App\Services\MCP\McpResult;
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
        private readonly AIPromptService $promptService,
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
     * @param  McpResult  $mcpResult  Structured MCP payload used to build the AI prompt
     * @return array{
     *   indicator: MarketIndicator,
     *   decision: array{action: string, confidence: int, risk_level: string, reason: string},
     *   raw_response: array<string, mixed>|null,
     *   model_used: string,
     * }|null
     */
    public function advise(string $coin, string $timeframe, McpResult $mcpResult): ?array
    {
        $indicator = $this->fetchLatestIndicator($coin, $timeframe);

        if ($indicator === null) {
            Log::warning('[AiAdvisorService] No indicator found, skipping AI call', [
                'coin' => $coin,
                'timeframe' => $timeframe,
            ]);

            return null;
        }

        $messages = $this->buildMessages($mcpResult);

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
            'model_used' => (string) config('ai.ollama.model'),
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
     * Build the LM Studio messages array from a MarketIndicator and MCP context.
     *
     * Returns two messages:
     *   - system: MCP-enriched prompt built by AIPromptService (role, strategy, rules, context)
     *   - system: static trading constraints and output format rules
     *   - user: MCP-enriched prompt built by AIPromptService (context, indicators, decision rules)
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function buildMessages(McpResult $mcpResult): array
    {
        return [
            [
                'role' => 'system',
                'content' => $this->buildSystemMessage(),
            ],
            [
                'role' => 'user',
                'content' => $this->promptService->buildPrompt($mcpResult->toArray()),
            ],
        ];
    }

    /**
     * Return a minimal system-level role declaration.
     *
     * Full trading rules and output format are embedded directly in the user prompt
     * built by AIPromptService, making this message intentionally brief.
     */
    private function buildSystemMessage(): string
    {
        return 'You are a strict crypto trading signal engine. Return ONLY valid JSON.';
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
