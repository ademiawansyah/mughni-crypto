<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tracked Coins
    |--------------------------------------------------------------------------
    |
    | Comma-separated CoinGecko coin IDs to fetch on every ingestion cycle.
    | Override via MARKET_COINS in your .env file.
    |
    */
    'coins' => explode(',', env('MARKET_COINS', 'bitcoin,ethereum,solana')),
    'timeframe' => env('MARKET_TIMEFRAME', '5m'),

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
