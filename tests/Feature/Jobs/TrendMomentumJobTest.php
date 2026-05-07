<?php

namespace Tests\Feature\Jobs;

use App\Jobs\TrendMomentumJob;
use App\Models\GeneralConfig;
use App\Services\Market\Models\TrendMomentumService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use Tests\TestCase;

class TrendMomentumJobTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_executes_trend_momentum_service_when_model_is_enabled(): void
    {
        GeneralConfig::set('momentum_enabled', '1');
        $this->assertTrue(GeneralConfig::isModelEnabled('momentum'));

        $service = Mockery::mock(TrendMomentumService::class);
        $service->shouldReceive('execute')
            ->once()
            ->with('exec-trend-momentum-job-enabled')
            ->andReturn([
                'execution_id' => 'exec-trend-momentum-job-enabled',
                'evaluated' => 2,
                'shortlisted' => 1,
            ]);

        $job = new TrendMomentumJob('exec-trend-momentum-job-enabled');
        $job->handle($service);

        $this->assertTrue(true);
    }

    public function test_it_skips_service_execution_when_model_is_disabled(): void
    {
        GeneralConfig::set('momentum_enabled', '0');
        $this->assertFalse(GeneralConfig::isModelEnabled('momentum'));

        $service = Mockery::mock(TrendMomentumService::class);
        $service->shouldReceive('execute')->never();

        $job = new TrendMomentumJob('exec-trend-momentum-job-disabled');
        $job->handle($service);

        $this->assertTrue(true);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
