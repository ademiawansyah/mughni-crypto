<?php

namespace App\Jobs;

use App\Models\GeneralConfig;
use App\Services\AI\PerModelAiLayer;
use App\Services\Market\MarketRegimeService;
use App\Services\Market\Models\CounterTrendModelService;
use App\Services\Market\ModelSignalPersistenceService;
use App\Services\Notification\PerModelNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CounterTrendJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 2;

    public function __construct()
    {
        $this->onQueue('models');
    }

    public function handle(
        CounterTrendModelService $counterTrendModelService,
        MarketRegimeService $marketRegimeService,
        PerModelAiLayer $perModelAiLayer,
        ModelSignalPersistenceService $persistenceService,
        PerModelNotificationService $notificationService,
    ): void {
        $executionId = Str::uuid()->toString();

        Log::info('[CounterTrendJob] Started', [
            'execution_id' => $executionId,
        ]);

        $cronEnabled = GeneralConfig::isCronEnabled();
        $modelEnabled = GeneralConfig::isModelEnabled('counter_trend');

        if (! $cronEnabled || ! $modelEnabled) {
            Log::info('[CounterTrendJob] Skipped due to cron/model disable flag', [
                'execution_id' => $executionId,
                'cron_enabled' => $cronEnabled,
                'model_enabled' => $modelEnabled,
            ]);

            return;
        }

        try {
            // Get global market context
            $marketRegime = $marketRegimeService->getLatestRegime();

            Log::debug('[CounterTrendJob] Market regime retrieved', [
                'execution_id' => $executionId,
                'regime' => $marketRegime['market_regime'] ?? 'UNKNOWN',
            ]);

            // Evaluate universe and get signals
            $signals = $counterTrendModelService->evaluateUniverse($executionId);

            Log::info('[CounterTrendJob] Signals generated', [
                'execution_id' => $executionId,
                'signal_count' => $signals->count(),
            ]);

            if ($signals->isEmpty()) {
                $persistenceService->persistNoSignalDecision(
                    model: 'counter_trend',
                    marketRegime: $marketRegime,
                    executionId: $executionId,
                    timeframe: '15m',
                );

                Log::info('[CounterTrendJob] Persisted fallback HOLD decision', [
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

                Log::debug('[CounterTrendJob] AI decision received', [
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
                    Log::debug('[CounterTrendJob] Signal not persisted (duplicate or error)', [
                        'execution_id' => $executionId,
                        'coin' => $signal->coin,
                    ]);

                    return; // Skip notification for non-persisted signals
                }

                // Send notification if action is BUY/SELL and confidence meets threshold
                $notificationThreshold = (int) config('models.notifications.counter_trend.confidence_threshold', 70);

                if (
                    in_array($aiDecision['action'], ['BUY', 'SELL'], true)
                    && $aiDecision['confidence'] >= $notificationThreshold
                ) {
                    $notificationService->notify(
                        'counter_trend',
                        $signal,
                        $aiDecision,
                        $marketRegime,
                        $executionId,
                    );
                }
            });

            Log::info('[CounterTrendJob] Completed', [
                'execution_id' => $executionId,
                'signal_count' => $signals->count(),
                'top_coins' => $signals->pluck('coin')->all(),
            ]);
        } catch (\Throwable $e) {
            Log::error('[CounterTrendJob] Job failed', [
                'execution_id' => $executionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
