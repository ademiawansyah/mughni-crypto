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

];
