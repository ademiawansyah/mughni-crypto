<?php

namespace App\Filament\Widgets;

use App\Models\AiDecision;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class MarketRegimeStatusWidget extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $latest = AiDecision::query()
            ->whereNotNull('market_regime')
            ->orderByDesc('timestamp')
            ->first();

        $context = is_array($latest?->market_regime) ? $latest->market_regime : [];

        $regime = (string) ($context['market_regime'] ?? 'UNKNOWN');
        $btcDirection = (string) ($context['btc_direction'] ?? 'SIDEWAYS');
        $volatility = (string) ($context['volatility'] ?? 'MEDIUM');
        $riskLevel = (string) ($context['risk_level'] ?? 'MEDIUM');

        $timestamp = $latest?->timestamp?->format('Y-m-d H:i') ?? 'N/A';

        return [
            Stat::make('Market Regime', $regime)
                ->description('Latest context update: ' . $timestamp)
                ->color(match ($regime) {
                    'TRENDING_UP' => 'success',
                    'TRENDING_DOWN' => 'danger',
                    'RANGING' => 'warning',
                    'CHOPPY' => 'gray',
                    default => 'gray',
                }),

            Stat::make('BTC Direction', $btcDirection)
                ->description('Global directional context')
                ->color(match ($btcDirection) {
                    'UP' => 'success',
                    'DOWN' => 'danger',
                    default => 'warning',
                }),

            Stat::make('Volatility / Risk', $volatility . ' / ' . $riskLevel)
                ->description('Market environment risk meter')
                ->color(match ($riskLevel) {
                    'LOW' => 'success',
                    'MEDIUM' => 'warning',
                    'HIGH' => 'danger',
                    default => 'gray',
                }),
        ];
    }
}
