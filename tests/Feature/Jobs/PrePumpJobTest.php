<?php

namespace Tests\Feature\Jobs;

use App\Jobs\PrePumpJob;
use App\Models\GeneralConfig;
use App\Services\Market\Models\PrePumpService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use Tests\TestCase;

class PrePumpJobTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_executes_pre_pump_service_when_model_is_enabled(): void
    {
        GeneralConfig::set('pre_pump_enabled', '1');
        $this->assertTrue(GeneralConfig::isModelEnabled('pre_pump'));

        $service = Mockery::mock(PrePumpService::class);
        $service->shouldReceive('execute')
            ->once()
            ->with('exec-pre-pump-job-enabled')
            ->andReturn([
                'execution_id' => 'exec-pre-pump-job-enabled',
                'evaluated' => 2,
                'shortlisted' => 1,
            ]);

        $job = new PrePumpJob('exec-pre-pump-job-enabled');
        $job->handle($service);

        $this->assertTrue(true);
    }

    public function test_it_skips_service_execution_when_model_is_disabled(): void
    {
        GeneralConfig::set('pre_pump_enabled', '0');
        $this->assertFalse(GeneralConfig::isModelEnabled('pre_pump'));

        $service = Mockery::mock(PrePumpService::class);
        $service->shouldReceive('execute')->never();

        $job = new PrePumpJob('exec-pre-pump-job-disabled');
        $job->handle($service);

        $this->assertTrue(true);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
