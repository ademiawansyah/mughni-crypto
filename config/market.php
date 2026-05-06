<?php

use App\Models\GeneralConfig;

return [

    /*
    |--------------------------------------------------------------------------
    | Cron Controls
    |--------------------------------------------------------------------------
    |
    | Default fallback used when 'cron_enabled' is not yet available in
    | general_config.
    |
    */
    'cron_enabled_default' => (bool) env('TRADING_CRON_ENABLED', true),

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

    'binance' => [
        'base_url' => env('BINANCE_BASE_URL', 'https://fapi.binance.com/fapi/v1'),
        'api_key' => env('BINANCE_API_KEY'),
        'api_secret' => env('BINANCE_API_SECRET'),
        'timeout' => (int) env('BINANCE_TIMEOUT', 10),
    ],

    'binance_futures' => [
        'enabled' => (bool) env('BINANCE_FUTURES_ENABLED', true),
        'base_url' => env('BINANCE_FUTURES_BASE_URL', 'https://fapi.binance.com/fapi/v1'),
        'timeout' => (int) env('BINANCE_FUTURES_TIMEOUT', 10),
        'cache_ttl_seconds' => (int) env('BINANCE_FUTURES_CACHE_TTL_SECONDS', 120),
    ],

];
