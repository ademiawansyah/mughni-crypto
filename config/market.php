<?php

use App\Models\GeneralConfig;

return [

    /*
    |--------------------------------------------------------------------------
    | Tracked Coins
    |--------------------------------------------------------------------------
    |
    | Managed dynamically via the general_config table (key: 'coins').
    | Falls back to env/default if the table is not yet available.
    |
    */
    'coins' => (function (): array {
        try {
            return GeneralConfig::getCoins();
        } catch (Throwable) {
            return explode(',', env('MARKET_COINS', 'bitcoin,ethereum,solana'));
        }
    })(),

    /*
    |--------------------------------------------------------------------------
    | Active Timeframes
    |--------------------------------------------------------------------------
    |
    | Managed dynamically via the general_config table (key: 'timeframes').
    | Falls back to env/default if the table is not yet available.
    |
    */
    'timeframes' => (function (): array {
        try {
            return GeneralConfig::getTimeframes();
        } catch (Throwable) {
            return explode(',', env('MARKET_TIMEFRAMES', '5m,10m,15m'));
        }
    })(),

    /*
    |--------------------------------------------------------------------------
    | CoinGecko API Configuration
    |--------------------------------------------------------------------------
    */
    'coingecko' => [
        'base_url' => env('COINGECKO_BASE_URL', 'https://api.coingecko.com/api/v3'),
        'api_key' => env('COINGECKO_API_KEY'),
        'timeout' => (int) env('COINGECKO_TIMEOUT', 10),
        'vs_currency' => env('COINGECKO_VS_CURRENCY', 'usd'),
    ],

];
