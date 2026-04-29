<?php

namespace App\Jobs;

use App\Models\GeneralConfig;
use App\Services\AI\PerModelAiLayer;
use App\Services\Market\MarketRegimeService;
use App\Services\Market\Models\MomentumModelService;
use App\Services\Market\ModelSignalPersistenceService;
use App\Services\Notification\PerModelNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MomentumJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 2;

    public function __construct()
    {
        $this->onQueue('models');
    }

    public function handle(
        MomentumModelService $momentumModelService,
        MarketRegimeService $marketRegimeService,
        PerModelAiLayer $perModelAiLayer,
        ModelSignalPersistenceService $persistenceService,
        PerModelNotificationService $notificationService,
    ): void {
        $executionId = Str::uuid()->toString();

        Log::info('[MomentumJob] Started', [
            'execution_id' => $executionId,
        ]);

        $cronEnabled = GeneralConfig::isCronEnabled();
        $modelEnabled = GeneralConfig::isModelEnabled('momentum');

        if (! $cronEnabled || ! $modelEnabled) {
            Log::info('[MomentumJob] Skipped due to cron/model disable flag', [
                'execution_id' => $executionId,
                'cron_enabled' => $cronEnabled,
                'model_enabled' => $modelEnabled,
            ]);

            return;
        }

        try {
            // Get global market context
            $marketRegime = $marketRegimeService->getLatestRegime();

            Log::debug('[MomentumJob] Market regime retrieved', [
                'execution_id' => $executionId,
                'regime' => $marketRegime['market_regime'] ?? 'UNKNOWN',
            ]);

            // Evaluate universe and get signals
            $signals = $momentumModelService->evaluateUniverse($executionId);

            Log::info('[MomentumJob] Signals generated', [
                'execution_id' => $executionId,
                'signal_count' => $signals->count(),
            ]);

            if ($signals->isEmpty()) {
                $persistenceService->persistNoSignalDecision(
                    model: 'momentum',
                    marketRegime: $marketRegime,
                    executionId: $executionId,
                    timeframe: '1h',
                );

                Log::info('[MomentumJob] Persisted fallback HOLD decision', [
                    'execution_id' => $executionId,
                    'reason' => 'no_candidates_passed',
                ]);
            }

            // Process each signal through AI layer + persistence + notification
            $signals->each(function ($signal) use (
                $executionId,
                $marketRegime,
                $perModelAiLayer,
                $persistenceService,
                $notificationService,
            ) {
                // Call AI layer (optional, based on config)
                $aiDecision = $perModelAiLayer->interpret($signal, $marketRegime, $executionId);

                Log::debug('[MomentumJob] AI decision received', [
                    'execution_id' => $executionId,
                    'coin' => $signal->coin,
                    'action' => $aiDecision['action'],
                    'confidence' => $aiDecision['confidence'],
                    'ai_enabled' => $aiDecision['ai_enabled'],
                ]);

                // Persist signal + market regime + AI decision
                $persistedRecord = $persistenceService->persist(
                    $signal,
                    $marketRegime,
                    $aiDecision,
                    $executionId,
                );

                if ($persistedRecord === null) {
                    Log::debug('[MomentumJob] Signal not persisted (duplicate or error)', [
                        'execution_id' => $executionId,
                        'coin' => $signal->coin,
                    ]);

                    return; // Skip notification for non-persisted signals
                }

                // Send notification if action is BUY/SELL and confidence meets threshold
                $notificationThreshold = (int) config('models.notifications.momentum.confidence_threshold', 65);

                if (
                    in_array($aiDecision['action'], ['BUY', 'SELL'], true)
                    && $aiDecision['confidence'] >= $notificationThreshold
                ) {
                    $notificationService->notify(
                        'momentum',
                        $signal,
                        $aiDecision,
                        $marketRegime,
                        $executionId,
                    );
                }
            });

            Log::info('[MomentumJob] Completed', [
                'execution_id' => $executionId,
                'signal_count' => $signals->count(),
                'top_coins' => $signals->pluck('coin')->all(),
            ]);
        } catch (\Throwable $e) {
            Log::error('[MomentumJob] Job failed', [
                'execution_id' => $executionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
