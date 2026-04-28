<?php

namespace Tests\Feature\Jobs;

use App\Jobs\CounterTrendJob;
use App\Jobs\MomentumJob;
use App\Jobs\PrePumpJob;
use App\Models\AiDecision;
use App\Services\AI\PerModelAiLayer;
use App\Services\Market\MarketRegimeService;
use App\Services\Market\Models\CounterTrendModelService;
use App\Services\Market\Models\ModelSignalDTO;
use App\Services\Market\Models\MomentumModelService;
use App\Services\Market\Models\PrePumpModelService;
use App\Services\Market\ModelSignalPersistenceService;
use App\Services\Notification\PerModelNotificationService;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ModelJobsIntegrationTest extends TestCase
{
    #[DataProvider('modelJobProvider')]
    public function test_model_job_persists_signal_and_sends_notification(
        string $jobClass,
        string $modelServiceClass,
        string $model,
    ): void {
        config([
            "notifications.{$model}.confidence_threshold" => 70,
        ]);

        $signal = $this->makeSignal($model, 'bitcoin');

        $modelService = Mockery::mock($modelServiceClass);
        $modelService->shouldReceive('evaluateUniverse')
            ->once()
            ->with(Mockery::type('string'))
            ->andReturn(collect([$signal]));
        $this->app->instance($modelServiceClass, $modelService);

        $marketRegimeService = Mockery::mock(MarketRegimeService::class);
        $marketRegimeService->shouldReceive('getLatestRegime')
            ->once()
            ->andReturn([
                'market_regime' => 'TRENDING_UP',
                'volatility' => 'MEDIUM',
                'risk_level' => 'LOW',
            ]);
        $this->app->instance(MarketRegimeService::class, $marketRegimeService);

        $aiLayer = Mockery::mock(PerModelAiLayer::class);
        $aiLayer->shouldReceive('interpret')
            ->once()
            ->with($signal, Mockery::type('array'), Mockery::type('string'))
            ->andReturn([
                'action' => 'BUY',
                'confidence' => 90,
                'agreement' => true,
                'ai_enabled' => false,
                'reasoning' => 'model-confirmed',
                'ai_response' => null,
            ]);
        $this->app->instance(PerModelAiLayer::class, $aiLayer);

        $persistenceService = Mockery::mock(ModelSignalPersistenceService::class);
        $persistenceService->shouldReceive('persist')
            ->once()
            ->with($signal, Mockery::type('array'), Mockery::type('array'), Mockery::type('string'))
            ->andReturn(new AiDecision);
        $this->app->instance(ModelSignalPersistenceService::class, $persistenceService);

        $notificationService = Mockery::mock(PerModelNotificationService::class);
        $notificationService->shouldReceive('notify')
            ->once()
            ->with(
                $model,
                $signal,
                Mockery::on(fn(array $decision): bool => $decision['action'] === 'BUY' && $decision['confidence'] === 90),
                Mockery::type('array'),
                Mockery::type('string')
            );
        $this->app->instance(PerModelNotificationService::class, $notificationService);

        $job = app($jobClass);
        $this->app->call([$job, 'handle']);

        $this->assertSame('models', $job->queue);
        $this->assertSame(2, $job->tries);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function modelJobProvider(): array
    {
        return [
            'counter_trend' => [CounterTrendJob::class, CounterTrendModelService::class, 'counter_trend'],
            'pre_pump' => [PrePumpJob::class, PrePumpModelService::class, 'pre_pump'],
            'momentum' => [MomentumJob::class, MomentumModelService::class, 'momentum'],
        ];
    }

    private function makeSignal(string $model, string $coin): ModelSignalDTO
    {
        return new ModelSignalDTO(
            model: $model,
            coin: $coin,
            action: 'BUY',
            score: 85,
            primaryTimeframe: '15m',
            componentScores: [
                'ema' => 0.8,
                'rsi' => 0.7,
            ],
            context: [
                'timeframe' => '15m',
            ],
            reasons: ['test-reason'],
        );
    }
}
