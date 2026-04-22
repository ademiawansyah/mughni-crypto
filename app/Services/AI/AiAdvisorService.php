<?php

namespace App\Services\AI;

use App\Models\MarketIndicator;
use App\Services\MCP\McpResult;
use App\Services\Trading\DTO\AiAdviceDTO;
use App\Services\Trading\DTO\AiDecisionDTO;
use App\Services\Trading\DTO\IndicatorDTO;
use App\Services\Trading\DTO\MTFContextDTO;
use App\Services\Trading\MTFResultDTO;
use Illuminate\Support\Facades\Log;

/**
 * AiAdvisorService
 *
 * Orchestrates the AI-driven trading signal pipeline for a single coin/timeframe pair.
 *
 * Pipeline:
 *   1. Fetch the latest MarketIndicator for the given coin + timeframe.
 *   2. Build a structured system + user prompt from the indicator values.
 *      When an MTFResultDTO is provided the MTF-aware prompt is used; the AI
 *      is then instructed to refine confidence only, NOT change the action.
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
     * When $mtfResult is provided the prompt instructs the AI to refine confidence
     * only and MUST NOT change the preliminary_action from MTF scoring.
     *
     * @param  string  $coin  CoinGecko coin ID (e.g. 'bitcoin')
     * @param  string  $timeframe  Timeframe label (e.g. '5m', '15m', '1h')
     * @param  McpResult  $mcpResult  Structured MCP payload used to build the AI prompt
     * @param  MTFResultDTO|null  $mtfResult  Optional MTF scoring result for refinement-only mode
     * @param  string  $timeframeSummary  Human-readable summary of all TF signals (for MTF prompt)
     * @param  string  $executionId  Pipeline execution identifier for traceability.
     * @return array{
     *   indicator: MarketIndicator,
     *   decision: array{action: string, confidence: int, risk_level: string, reason: string},
     *   raw_response: array<string, mixed>|null,
     *   model_used: string,
     * }|null
     */
    public function advise(
        string $coin,
        string $timeframe,
        McpResult $mcpResult,
        ?MTFResultDTO $mtfResult = null,
        string $timeframeSummary = '',
        string $executionId = '',
    ): ?array {
        $indicator = $this->fetchLatestIndicator($coin, $timeframe);

        if ($indicator === null) {
            Log::warning('[AiAdvisorService] No indicator found, skipping AI call', [
                'execution_id' => $executionId,
                'coin' => $coin,
                'timeframe' => $timeframe,
            ]);

            return null;
        }

        $messages = $mtfResult !== null
            ? $this->buildMtfMessages($mcpResult, $mtfResult, $timeframeSummary)
            : $this->buildMessages($mcpResult);

        Log::info('[AiAdvisorService] Sending prompt to LM Studio', [
            'execution_id' => $executionId,
            'coin' => $coin,
            'timeframe' => $timeframe,
            'model' => config('ai.ollama.model'),
            // 'prompt' => $messages,
        ]);

        $rawResponse = $this->client->chat($messages);

        Log::info('[AiAdvisorService] Received AI response', [
            'execution_id' => $executionId,
            'coin' => $coin,
            'timeframe' => $timeframe,
            'raw_response' => $rawResponse,
        ]);

        $decision = $rawResponse !== null
            ? $this->parser->parse($rawResponse)
            : $this->failsafeDecision($coin, $timeframe, $executionId);

        $decision = $this->finalizeDecision($decision, $mcpResult->score);

        Log::info('[AiAdvisorService] Decision produced', [
            'execution_id' => $executionId,
            'coin' => $coin,
            'timeframe' => $timeframe,
            'action' => $decision['action'],
            'confidence' => $decision['confidence'],
        ]);

        return [
            'indicator' => $indicator,
            'decision' => $decision,
            'raw_response' => $rawResponse,
            'model_used' => (string) config('ai.ollama.model'),
        ];
    }

    /**
     * Generate an AI trading decision while including MTF context as advisory input.
     *
     * Unlike refinement mode, this path allows AI to decide action freely.
     * MTF context is provided for reasoning, while final confidence shaping happens
     * downstream in DecisionFusionService.
     *
     * @return array{
     *   indicator: MarketIndicator,
     *   decision: array{action: string, confidence: int, risk_level: string, reason: string},
     *   raw_response: array<string, mixed>|null,
     *   model_used: string,
     * }|null
     */
    public function adviseWithMtfContext(
        string $coin,
        string $timeframe,
        McpResult $mcpResult,
        MTFResultDTO $mtfResult,
        string $timeframeSummary,
        string $executionId = '',
    ): ?array {
        $indicator = $this->fetchLatestIndicator($coin, $timeframe);

        if ($indicator === null) {
            Log::warning('[AiAdvisorService] No indicator found, skipping AI call', [
                'execution_id' => $executionId,
                'coin' => $coin,
                'timeframe' => $timeframe,
            ]);

            return null;
        }

        $messages = $this->buildMtfContextMessages($mcpResult, $mtfResult, $timeframeSummary);

        Log::info('[AiAdvisorService] Sending MTF-context prompt to LM Studio', [
            'execution_id' => $executionId,
            'coin' => $coin,
            'timeframe' => $timeframe,
            'model' => config('ai.ollama.model'),
            'mtf_score' => $mtfResult->mtfScore,
            'mode' => $mtfResult->mode,
        ]);

        $rawResponse = $this->client->chat($messages);

        Log::info('[AiAdvisorService] Received AI response', [
            'execution_id' => $executionId,
            'coin' => $coin,
            'timeframe' => $timeframe,
            'raw_response' => $rawResponse,
        ]);

        $decision = $rawResponse !== null
            ? $this->parser->parse($rawResponse)
            : $this->failsafeDecision($coin, $timeframe, $executionId);

        $decision = $this->finalizeDecision($decision, $mcpResult->score);

        Log::info('[AiAdvisorService] MTF-context AI decision produced', [
            'execution_id' => $executionId,
            'coin' => $coin,
            'timeframe' => $timeframe,
            'action' => $decision['action'],
            'confidence' => $decision['confidence'],
        ]);

        return [
            'indicator' => $indicator,
            'decision' => $decision,
            'raw_response' => $rawResponse,
            'model_used' => (string) config('ai.ollama.model'),
        ];
    }

    /**
     * Refine a deterministic MTF preliminary decision using AI.
     *
     * AI is advisory-only here: it can only adjust confidence and reason.
     * Action is always enforced to preliminary_action.
     *
     * @return array{
     *   decision: array{action: string, confidence: int, risk_level: string, reason: string, flags: array<int, string>},
     *   raw_response: array<string, mixed>|null,
     *   model_used: string,
     * }|null
     */
    public function refineMtfDecision(
        string $coin,
        MTFResultDTO $mtfResult,
        string $timeframeSummary,
        float $rsi5m,
        string $trend5m,
        string $executionId = '',
    ): ?array {
        $messages = [
            [
                'role' => 'system',
                'content' => 'You are a strict crypto trading confidence refinement engine. Return ONLY valid JSON.',
            ],
            [
                'role' => 'user',
                'content' => $this->promptService->buildMtfRefinementPrompt(
                    mtfResult: $mtfResult,
                    timeframeSummary: $timeframeSummary,
                    rsi5m: $rsi5m,
                    trend5m: $trend5m,
                ),
            ],
        ];

        Log::info('[AiAdvisorService] Sending MTF refinement prompt', [
            'execution_id' => $executionId,
            'coin' => $coin,
            'model' => config('ai.ollama.model'),
            'preliminary_action' => $mtfResult->preliminaryAction,
            'mtf_score' => $mtfResult->mtfScore,
        ]);

        $rawResponse = $this->client->chat($messages);

        $parsed = $rawResponse !== null
            ? $this->parser->parse($rawResponse)
            : [
                'action' => $mtfResult->preliminaryAction,
                'confidence' => $mtfResult->baseConfidence,
                'risk_level' => $this->resolveRiskLevel($mtfResult->baseConfidence),
                'reason' => 'AI service unavailable',
            ];

        $parsed['action'] = $mtfResult->preliminaryAction;

        $minConfidence = max(0, $mtfResult->baseConfidence - 10);
        $maxConfidence = min(85, $mtfResult->baseConfidence + 10);
        $parsed['confidence'] = max($minConfidence, min($maxConfidence, (int) $parsed['confidence']));
        $parsed['risk_level'] = $this->resolveRiskLevel((int) $parsed['confidence']);
        $parsed['flags'] = $mtfResult->flags;

        return [
            'decision' => [
                'action' => (string) $parsed['action'],
                'confidence' => (int) $parsed['confidence'],
                'risk_level' => (string) $parsed['risk_level'],
                'reason' => (string) $parsed['reason'],
                'flags' => (array) $parsed['flags'],
            ],
            'raw_response' => $rawResponse,
            'model_used' => (string) config('ai.ollama.model'),
        ];
    }

    /**
     * Generate AI decision DTO from MTF context and entry timeframe indicators.
     *
     * This method wraps the legacy MTF-context pipeline to preserve behavior.
     */
    public function adviseFromContextDto(
        string $coin,
        string $entryTimeframe,
        IndicatorDTO $entryIndicator,
        MTFContextDTO $mtfContext,
        McpResult $triggerMcpResult,
        MTFResultDTO $mtfResult,
        string $timeframeSummary,
        string $executionId = '',
    ): ?AiAdviceDTO {
        $result = $this->adviseWithMtfContext(
            coin: $coin,
            timeframe: $entryTimeframe,
            mcpResult: $triggerMcpResult,
            mtfResult: $mtfResult,
            timeframeSummary: $timeframeSummary,
            executionId: $executionId,
        );

        if ($result === null) {
            return null;
        }

        $decision = $result['decision'];

        Log::info('[AiAdvisorService] AiDecisionDTO produced', [
            'execution_id' => $executionId,
            'coin' => $coin,
            'timeframe' => $entryTimeframe,
            'entry_rsi' => $entryIndicator->rsi,
            'mtf_score' => $mtfContext->mtfScore,
            'decision' => $decision,
        ]);

        return new AiAdviceDTO(
            decision: new AiDecisionDTO(
                action: (string) $decision['action'],
                confidence: (int) $decision['confidence'],
                reason: (string) $decision['reason'],
            ),
            rawResponse: $result['raw_response'],
            modelUsed: (string) $result['model_used'],
        );
    }

    /**
     * @deprecated Use adviseFromContextDto().
     *
     * @return array{decision: AiDecisionDTO, raw_response: array<string, mixed>|null, model_used: string}|null
     */
    public function adviseFromContext(
        string $coin,
        string $entryTimeframe,
        IndicatorDTO $entryIndicator,
        MTFContextDTO $mtfContext,
        McpResult $triggerMcpResult,
        MTFResultDTO $mtfResult,
        string $timeframeSummary,
        string $executionId = '',
    ): ?array {
        $result = $this->adviseFromContextDto(
            coin: $coin,
            entryTimeframe: $entryTimeframe,
            entryIndicator: $entryIndicator,
            mtfContext: $mtfContext,
            triggerMcpResult: $triggerMcpResult,
            mtfResult: $mtfResult,
            timeframeSummary: $timeframeSummary,
            executionId: $executionId,
        );

        if ($result === null) {
            return null;
        }

        return [
            'decision' => $result->decision,
            'raw_response' => $result->rawResponse,
            'model_used' => $result->modelUsed,
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
     * Build the LM Studio messages array from MCP context (legacy single-TF mode).
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
     * Build the LM Studio messages array for MTF refinement mode.
     *
     * The AI receives the preliminary_action from MTF scoring and is instructed to
     * refine confidence only, not change the action.
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function buildMtfMessages(McpResult $mcpResult, MTFResultDTO $mtfResult, string $timeframeSummary): array
    {
        return [
            [
                'role' => 'system',
                'content' => 'You are a strict crypto trading signal refinement engine. Return ONLY valid JSON.',
            ],
            [
                'role' => 'user',
                'content' => $this->promptService->buildMtfPrompt($mcpResult->toArray(), $mtfResult, $timeframeSummary),
            ],
        ];
    }

    /**
     * Build LM Studio messages where MTF is contextual, not authoritative.
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function buildMtfContextMessages(McpResult $mcpResult, MTFResultDTO $mtfResult, string $timeframeSummary): array
    {
        return [
            [
                'role' => 'system',
                'content' => 'You are a strict crypto trading signal engine. Return ONLY valid JSON.',
            ],
            [
                'role' => 'user',
                'content' => $this->promptService->buildMtfContextPrompt($mcpResult->toArray(), $mtfResult, $timeframeSummary),
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
     * Finalize AI decision by calibrating confidence against MCP score strength.
     *
     * Applies MCP-based confidence bands and weak-signal enforcement after parsing,
     * while preserving the original response shape.
     *
     * @param  array{action: string, confidence: int, risk_level: string, reason: string}  $decision
     * @return array{action: string, confidence: int, risk_level: string, reason: string}
     */
    private function finalizeDecision(array $decision, int $mcpScore): array
    {
        [$minConfidence, $maxConfidence] = $this->resolveConfidenceBand($mcpScore);

        $decision['confidence'] = max($minConfidence, min($maxConfidence, (int) $decision['confidence']));

        return $decision;
    }

    /**
     * Resolve MCP confidence band for a given score.
     *
     * @return array{0: int, 1: int}
     */
    private function resolveConfidenceBand(int $mcpScore): array
    {
        return match (true) {
            $mcpScore <= 1 => [45, 80],
            $mcpScore === 2 => [50, 85],
            $mcpScore === 3 => [55, 90],
            default => [60, 95],
        };
    }

    /**
     * Return a safe HOLD decision used when the AI service is unavailable.
     *
     * @return array{action: string, confidence: int, risk_level: string, reason: string}
     */
    private function failsafeDecision(string $coin, string $timeframe, string $executionId): array
    {
        Log::warning('[AiAdvisorService] AI call failed, defaulting to HOLD', [
            'execution_id' => $executionId,
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

    private function resolveRiskLevel(int $confidence): string
    {
        if ($confidence >= 70) {
            return 'LOW';
        }

        if ($confidence >= 40) {
            return 'MEDIUM';
        }

        return 'HIGH';
    }
}
