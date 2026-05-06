<?php

namespace Tests\Feature\Filament\Resources;

use App\Filament\Resources\ModelScanResults\ModelScanResultResource;
use App\Models\Coin;
use App\Models\ModelScanResult;
use App\Models\ModelScanResultDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ModelScanResultResourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_history_page_is_accessible_and_shows_scan_rows(): void
    {
        $this->withoutMiddleware();

        $user = User::factory()->create();

        ModelScanResult::query()->create([
            'model_name' => 'counter_trend',
            'execution_id' => 'exec-history-1',
            'execution_date' => now()->toDateString(),
            'result' => [
                'results' => [],
            ],
            'supporting_data' => [
                'evaluated' => 100,
                'shortlisted' => 3,
                'failed_count' => 97,
                'minimum_score' => 65,
            ],
        ]);

        $response = $this->actingAs($user)->get(ModelScanResultResource::getUrl('index'));

        $response->assertOk();
        $response->assertSee('counter_trend');
        $response->assertSee('exec-history-1');
    }

    public function test_view_page_shows_shortlisted_and_failed_coin_details(): void
    {
        $this->withoutMiddleware();

        $user = User::factory()->create();

        $coin = Coin::query()->create([
            'symbol' => 'ALTUSDT',
        ]);

        $scanResult = ModelScanResult::query()->create([
            'model_name' => 'pre_pump',
            'execution_id' => 'exec-view-1',
            'execution_date' => now()->toDateString(),
            'result' => [
                'results' => [
                    [
                        'rank' => 1,
                        'symbol' => 'BTCUSDT',
                        'price' => 95000.12,
                        'total_score' => 88.5,
                        'components' => [
                            'funding' => 91,
                            'oi' => 86,
                        ],
                        'metadata' => [
                            'stop_loss' => 92150.12,
                            'entry_timeframe' => '1H',
                            'structure_timeframe' => '4H',
                            'strategy' => 'pre_pump',
                        ],
                    ],
                ],
            ],
            'supporting_data' => [
                'evaluated' => 120,
                'shortlisted' => 1,
                'failed_count' => 119,
            ],
        ]);

        ModelScanResultDetail::query()->create([
            'model_scan_result_id' => $scanResult->id,
            'coin_id' => $coin->id,
            'rank' => 11,
            'is_passed' => false,
            'price' => 0.1234,
            'stop_loss' => 0.1190,
            'score' => 42.5,
            'data' => [
                'reason' => 'below_minimum_score',
                'context' => [
                    'minimum_score' => 65,
                    'total_score' => 42.5,
                ],
            ],
        ]);

        $response = $this->actingAs($user)->get(ModelScanResultResource::getUrl('view', ['record' => $scanResult]));

        $response->assertOk();
        $response->assertSee('BTCUSDT');
        $response->assertSee('ALTUSDT');
        $response->assertSee('below_minimum_score');
    }
}
