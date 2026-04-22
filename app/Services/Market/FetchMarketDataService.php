<?php

namespace App\Services\Market;

use App\Services\External\CoinGeckoService;
use App\Services\Trading\DTO\MarketDataDTO;

/**
 * FetchMarketDataService
 *
 * Fetches market chart data and exposes it through a DTO.
 */
class FetchMarketDataService
{
    public function __construct(
        private readonly CoinGeckoService $coinGeckoService,
    ) {}

    /**
     * Fetch market chart payload and normalize it into a DTO.
     */
    public function fetch(string $coin): ?MarketDataDTO
    {
        $payload = $this->coinGeckoService->fetchMarketChart($coin);

        if ($payload === null) {
            return null;
        }

        /** @var array<int, float> $prices */
        $prices = array_values((array) ($payload['prices'] ?? []));

        /** @var array<int, float> $volumes */
        $volumes = [];
        $rawVolumes = $payload['raw_response']['total_volumes'] ?? [];

        if (is_array($rawVolumes)) {
            foreach ($rawVolumes as $item) {
                if (! is_array($item) || count($item) < 2) {
                    continue;
                }

                $volumes[] = (float) $item[1];
            }
        }

        /** @var array<int, int> $timestamps */
        $timestamps = [];
        $rawPrices = $payload['raw_response']['prices'] ?? [];

        if (is_array($rawPrices)) {
            foreach ($rawPrices as $item) {
                if (! is_array($item) || count($item) < 2) {
                    continue;
                }

                $timestamps[] = (int) $item[0];
            }
        }

        return new MarketDataDTO(
            prices: $prices,
            volumes: $volumes,
            timestamps: $timestamps,
            requestParams: (array) ($payload['request_params'] ?? []),
            rawResponse: (array) ($payload['raw_response'] ?? []),
        );
    }
}
