<?php

namespace Tests\Feature\Console;

use App\Jobs\CounterTrendJob;
use App\Jobs\MarketRegimeJob;
use App\Jobs\MomentumJob;
use App\Jobs\PrePumpJob;
use App\Jobs\UpdateCoinUniverseJob;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class TradingModelScheduleTest extends TestCase
{
    public function test_phase_two_jobs_are_registered_with_expected_cron_expressions(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->mapWithKeys(fn($event): array => [$event->getSummaryForDisplay() => $event]);

        $this->assertArrayHasKey(MarketRegimeJob::class, $events->all());
        $this->assertArrayHasKey(UpdateCoinUniverseJob::class, $events->all());
        $this->assertArrayHasKey(CounterTrendJob::class, $events->all());
        $this->assertArrayHasKey(PrePumpJob::class, $events->all());
        $this->assertArrayHasKey(MomentumJob::class, $events->all());

        $this->assertSame('*/5 * * * *', $events[MarketRegimeJob::class]->expression);
        $this->assertSame('0 0 * * *', $events[UpdateCoinUniverseJob::class]->expression);
        $this->assertSame('*/15 * * * *', $events[CounterTrendJob::class]->expression);
        $this->assertSame('*/30 * * * *', $events[PrePumpJob::class]->expression);
        $this->assertSame('0 * * * *', $events[MomentumJob::class]->expression);
    }
}
