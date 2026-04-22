<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trading Capital
    |--------------------------------------------------------------------------
    |
    | The total capital (in USD) available for position sizing calculations.
    | This is used to compute the dollar risk per trade.
    |
    */
    'capital' => (float) env('TRADING_CAPITAL', 1000.0),

    /*
    |--------------------------------------------------------------------------
    | Risk Per Trade
    |--------------------------------------------------------------------------
    |
    | The fraction of capital to risk on each trade (e.g. 0.01 = 1%).
    | Applied against `capital` to derive the maximum dollar loss per trade.
    |
    */
    'risk_per_trade' => (float) env('TRADING_RISK_PER_TRADE', 0.01),

    /*
    |--------------------------------------------------------------------------
    | Maximum Position Size (% of Capital)
    |--------------------------------------------------------------------------
    |
    | Hard cap on position size expressed as a fraction of total capital
    | (e.g. 0.20 = 20%). Prevents outsized exposure even when stop distance
    | is very small.
    |
    */
    'max_position_percent' => (float) env('TRADING_MAX_POSITION_PERCENT', 0.20),

    /*
    |--------------------------------------------------------------------------
    | Timeframe Weights (MTF Scoring Engine)
    |--------------------------------------------------------------------------
    |
    | Weight assigned to each timeframe when computing the aggregate MTF score.
    | Higher values give more influence to larger timeframes. Add or remove
    | entries to support any set of timeframes without code changes.
    |
    */
    'timeframe_weights' => [
        '1m' => 0.5,
        '5m' => 1.0,
        '15m' => 1.5,
        '30m' => 2.0,
        '60m' => 2.5,
    ],

    /*
    |--------------------------------------------------------------------------
    | MTF Score Thresholds
    |--------------------------------------------------------------------------
    |
    | mtf_buy_threshold  — aggregate score at or above which preliminary_action = BUY
    | mtf_sell_threshold — aggregate score at or below which preliminary_action = SELL
    |
    */
    'mtf_buy_threshold' => (float) env('MTF_BUY_THRESHOLD', 2.0),
    'mtf_sell_threshold' => (float) env('MTF_SELL_THRESHOLD', -2.0),

];
