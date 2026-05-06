<?php

namespace Tests\Feature\Jobs;

use App\Jobs\SpotMomentumGainerJob;
use App\Models\GeneralConfig;
use App\Services\Market\Models\SpotMomentumGainerService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use Tests\TestCase;

class SpotMomentumGainerJobTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_executes_spot_momentum_gainer_service_when_model_is_enabled(): void
    {
        GeneralConfig::set('spot_momentum_gainer_enabled', '1');
        $this->assertTrue(GeneralConfig::isModelEnabled('spot_momentum_gainer'));

        $service = Mockery::mock(SpotMomentumGainerService::class);
        $service->shouldReceive('execute')
            ->once()
            ->with('exec-spot-momentum-job-enabled')
            ->andReturn([
                'execution_id' => 'exec-spot-momentum-job-enabled',
                'evaluated' => 3,
                'shortlisted' => 1,
            ]);

        $job = new SpotMomentumGainerJob('exec-spot-momentum-job-enabled');
        $job->handle($service);

        $this->assertTrue(true);
    }

    public function test_it_skips_service_execution_when_model_is_disabled(): void
    {
        GeneralConfig::set('spot_momentum_gainer_enabled', '0');
        $this->assertFalse(GeneralConfig::isModelEnabled('spot_momentum_gainer'));

        $service = Mockery::mock(SpotMomentumGainerService::class);
        $service->shouldReceive('execute')->never();

        $job = new SpotMomentumGainerJob('exec-spot-momentum-job-disabled');
        $job->handle($service);

        $this->assertTrue(true);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
