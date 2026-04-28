<?php

namespace App\Jobs;

use App\Services\AI\PerModelAiLayer;
use App\Services\Market\MarketRegimeService;
use App\Services\Market\Models\PrePumpModelService;
use App\Services\Market\ModelSignalPersistenceService;
use App\Services\Notification\PerModelNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PrePumpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 2;

    public function __construct()
    {
        $this->onQueue('models');
    }

    public function handle(
        PrePumpModelService $prePumpModelService,
        MarketRegimeService $marketRegimeService,
        PerModelAiLayer $perModelAiLayer,
        ModelSignalPersistenceService $persistenceService,
        PerModelNotificationService $notificationService,
    ): void {
        $executionId = Str::uuid()->toString();

        Log::info('[PrePumpJob] Started', [
            'execution_id' => $executionId,
        ]);

        try {
            // Get global market context
            $marketRegime = $marketRegimeService->getLatestRegime();

            Log::debug('[PrePumpJob] Market regime retrieved', [
                'execution_id' => $executionId,
                'regime' => $marketRegime['market_regime'] ?? 'UNKNOWN',
            ]);

            // Evaluate universe and get signals
            $signals = $prePumpModelService->evaluateUniverse($executionId);

            Log::info('[PrePumpJob] Signals generated', [
                'execution_id' => $executionId,
                'signal_count' => $signals->count(),
            ]);

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

                Log::debug('[PrePumpJob] AI decision received', [
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
                    Log::debug('[PrePumpJob] Signal not persisted (duplicate or error)', [
                        'execution_id' => $executionId,
                        'coin' => $signal->coin,
                    ]);

                    return; // Skip notification for non-persisted signals
                }

                // Send notification if action is BUY/SELL and confidence meets threshold
                $notificationThreshold = (int) config('notifications.pre_pump.confidence_threshold', 75);

                if (
                    in_array($aiDecision['action'], ['BUY', 'SELL'], true)
                    && $aiDecision['confidence'] >= $notificationThreshold
                ) {
                    $notificationService->notify(
                        'pre_pump',
                        $signal,
                        $aiDecision,
                        $marketRegime,
                        $executionId,
                    );
                }
            });

            Log::info('[PrePumpJob] Completed', [
                'execution_id' => $executionId,
                'signal_count' => $signals->count(),
                'top_coins' => $signals->pluck('coin')->all(),
            ]);
        } catch (\Throwable $e) {
            Log::error('[PrePumpJob] Job failed', [
                'execution_id' => $executionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
