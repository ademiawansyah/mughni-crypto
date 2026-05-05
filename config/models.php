<?php

/**
 * Trading Models Configuration
 *
 * Centralized configuration for all three parallel trading models,
 * global market regime detection, coin universe filtering, AI settings,
 * and notification thresholds.
 *
 * This configuration drives model scoring, signal filtering, AI prompt
 * generation, and notification decisions across the entire system.
 */

return [
    /**
     * Counter-Trend Model Configuration
     *
     * Identifies mean reversion opportunities where price reverses
     * against the prevailing market trend (e.g., bounce off support
     * in a downtrend, or pullback from resistance in an uptrend).
     */
    'counter_trend' => [
        // Scoring component weights (must sum to 1.0)
        'scoring' => [
            'sweep' => 0.30,            // Price sweep of support/resistance
            'mss' => 0.25,              // Mean Session Structure
            'oi' => 0.15,               // Open Interest changes
            'cvd' => 0.15,              // Cumulative Volume Delta
            'funding' => 0.10,          // Funding rate extremes
            'atr' => 0.05,              // Volatility (ATR)
        ],

        // Threshold for signal acceptance (0-100)
        'min_score' => 60,

        // Market regime confidence adjusters
        'market_confidence_adjusters' => [
            'TRENDING_UP' => -15,       // Counter-trend in strong uptrend is riskier
            'TRENDING_DOWN' => 10,      // Counter-trend in strong downtrend is safer
            'RANGING' => 5,             // Neutral regime, slight boost
            'CHOPPY' => -20,            // Avoid counter-trend in choppy markets
        ],

        // Risk parameters
        'max_drawdown_tolerance' => 0.08, // 8%
        'position_size_adjustment' => 0.8, // 80% of base size

        // AI Layer Configuration
        'ai_enabled' => true,  // Enable per-model AI refinement
        'notification_threshold' => 70,  // Confidence threshold for notifications

        // Job schedule: every 15 minutes
        'job_schedule' => '*/15 * * * *',
    ],

    /**
     * Pre-Pump Model Configuration
     *
     * Detects accumulation patterns and early volatility compression
     * that often precede explosive moves. Looks for indicators of
     * institutional buildup (OI, CVD) + technical compression (ATR, BB).
     */
    'pre_pump' => [
        // Scoring component weights (must sum to 1.0)
        'scoring' => [
            'funding' => 0.35,          // Funding rate = proxy for sentiment
            'atr_compression' => 0.25,  // Bollinger Band compression
            'oi' => 0.20,               // Open Interest accumulation
            'rs' => 0.10,               // Relative Strength vs market
            'cvd' => 0.10,              // Smart money flow (CVD)
        ],

        // Threshold for signal acceptance (0-100)
        'min_score' => 65,

        // Market regime confidence adjusters
        'market_confidence_adjusters' => [
            'TRENDING_UP' => 20,        // Pre-pump in uptrend is highly reliable
            'TRENDING_DOWN' => -25,     // Pre-pump in downtrend is risky
            'RANGING' => 10,            // Pre-pump in ranging market is good
            'CHOPPY' => -15,            // Pre-pump signals less reliable in chaos
        ],

        // Risk parameters
        'max_drawdown_tolerance' => 0.10, // 10%
        'position_size_adjustment' => 1.0, // Full position size (high confidence)

        // AI Layer Configuration
        'ai_enabled' => true,  // Enable per-model AI refinement
        'notification_threshold' => 75,  // Confidence threshold for notifications

        // Job schedule: every 4 hours
        'job_schedule' => '0 */4 * * *',
    ],

    /**
     * Momentum Model Configuration
     *
     * Rides established trends with confirmation from multiple
     * indicators (EMA, MACD, RSI). Focuses on continuation trades
     * with clear directional bias.
     */
    'momentum' => [
        // Scoring component weights (must sum to 1.0)
        'scoring' => [
            'ema' => 0.25,              // EMA alignment (trend confirmation)
            'macd' => 0.20,             // MACD histogram + signal line
            'rsi' => 0.15,              // RSI zone (not extreme, not flat)
            'oi' => 0.20,               // Open Interest trend
            'bos' => 0.10,              // Break of Structure
            'cvd' => 0.10,              // Volume Delta confirmation
        ],

        // Threshold for signal acceptance (0-100)
        'min_score' => 55,

        // Market regime confidence adjusters
        'market_confidence_adjusters' => [
            'TRENDING_UP' => 30,        // Momentum in uptrend = best case
            'TRENDING_DOWN' => 30,      // Momentum in downtrend = also strong
            'RANGING' => -10,           // Momentum in ranging = choppy
            'CHOPPY' => -20,            // Momentum in chaos = whipsaws
        ],

        // Risk parameters
        'max_drawdown_tolerance' => 0.07, // 7%
        'position_size_adjustment' => 0.9, // 90% of base size

        // AI Layer Configuration
        'ai_enabled' => true,  // Enable per-model AI refinement
        'notification_threshold' => 65,  // Confidence threshold for notifications

        // Job schedule: every 1 hour
        'job_schedule' => '0 * * * *',
    ],

    /**
     * Global Market Regime Detection Configuration
     *
     * Settings for MarketRegimeService which analyzes BTC across
     * multiple timeframes to determine overall market regime.
     */
    'market_regime' => [
        // BTC timeframes to analyze
        'timeframes' => ['1h', '4h', '1d'],

        // EMA periods for trend detection
        'ema_short' => 9,
        'ema_long' => 21,

        // ATR period for volatility calculation
        'atr_period' => 14,

        // Baseline ATR for volatility classification
        'baseline_atr' => 200.0,

        // Cache TTL in seconds (5 minutes)
        'cache_ttl' => 300,
    ],

    /**
     * Coin Universe Filtering Configuration
     *
     * Settings for CoinUniverseService which maintains the list
     * of coins eligible for trading.
     */
    'coin_universe' => [
        // Market filters
        'min_market_cap' => 100_000_000,        // $100M
        'min_volume_24h' => 5_000_000,          // $5M
        'max_coins' => 100,

        // Exclude stablecoins
        'exclude_coins' => [
            'tether',
            'usd-coin',
            'binance-usd',
            'paxos-standard',
            'true-usd',
            'dxchain-token',
        ],

        // Exchange filter: only Binance futures
        'require_exchange' => 'binance_futures',

        // Cache TTL in seconds (24 hours)
        'cache_ttl' => 86400,

        // Refresh schedule: daily at 00:00 UTC
        'refresh_schedule' => '0 0 * * *',
    ],

    /**
     * AI Model Configuration
     *
     * Settings for AI advisor (Ollama/LM Studio) integration,
     * including model selection, confidence adjusters per regime,
     * and guardrail thresholds.
     */
    'ai' => [
        // Ollama connection settings
        'endpoint' => env('OLLAMA_ENDPOINT', 'http://localhost:11434'),
        'model' => env('OLLAMA_MODEL', 'qwen2.5:7b'),
        'timeout' => 30, // seconds

        // Confidence score adjusters based on market regime
        'confidence_adjusters' => [
            'counter_trend' => [
                'TRENDING_UP' => -20,   // Reduce confidence in strong uptrend
                'TRENDING_DOWN' => 0,
                'RANGING' => 5,
                'CHOPPY' => -15,
            ],
            'pre_pump' => [
                'TRENDING_UP' => 15,    // Boost confidence in uptrend
                'TRENDING_DOWN' => -20,
                'RANGING' => 5,
                'CHOPPY' => -10,
            ],
            'momentum' => [
                'TRENDING_UP' => 20,    // Boost confidence in any trend
                'TRENDING_DOWN' => 20,
                'RANGING' => -15,
                'CHOPPY' => -25,
            ],
        ],

        // Guardrail thresholds
        'min_confidence' => 50,          // Reject signals below 50% confidence
        'max_confidence' => 95,          // Cap at 95%

        // Response validation
        'validate_json' => true,
        'required_fields' => ['action', 'confidence', 'type', 'reason'],

        // Fallback behavior on invalid response
        'fallback_action' => 'HOLD',
        'fallback_confidence' => 0,
    ],

    /**
     * Notification Configuration
     *
     * Settings for Telegram notifications including per-model
     * thresholds, risk level adjustments, and message formatting.
     */
    'notifications' => [
        // Telegram settings
        'telegram_enabled' => env('TELEGRAM_ENABLED', true),
        'telegram_bot_token' => env('TELEGRAM_BOT_TOKEN', ''),
        'telegram_chat_id' => env('TELEGRAM_CHAT_ID', ''),

        // Notification filters per model
        'counter_trend' => [
            'confidence_threshold' => 70,       // Only notify if AI confidence >= 70%
            'notify_on_actions' => ['BUY', 'SELL'],
            'risk_level_filter' => ['LOW', 'MEDIUM'], // Skip HIGH risk
        ],
        'pre_pump' => [
            'confidence_threshold' => 75,
            'notify_on_actions' => ['BUY', 'SELL'],
            'risk_level_filter' => ['LOW'],     // Only LOW risk
        ],
        'momentum' => [
            'confidence_threshold' => 65,
            'notify_on_actions' => ['BUY', 'SELL'],
            'risk_level_filter' => ['LOW', 'MEDIUM'],
        ],

        // Notification formatting
        'include_regime_context' => true,
        'include_risk_level' => true,
        'include_confidence_score' => true,

        // Duplicate prevention: skip if same signal sent within N minutes
        'duplicate_prevention_window' => 60, // minutes
    ],

    /**
     * Guardrail Configuration
     *
     * Final safety checks before decision acceptance.
     * Applied by TradingDecisionService.
     */
    'guardrails' => [
        // Reject signals against strong trending direction
        'reject_counter_to_strong_trend' => true,

        // Reject signals if market is in CHOPPY regime
        'reject_in_choppy_market' => true,

        // Reject low confidence signals
        'min_acceptable_confidence' => 50,

        // Reject abnormal data (missing indicators)
        'require_all_indicators' => true,

        // Skip execution if data is stale (older than N minutes)
        'max_data_age_minutes' => 10,
    ],

    /**
     * Backtesting & Historical Configuration
     *
     * Settings for backtesting runner and historical data replay.
     * Used for validating strategy performance.
     */
    'backtesting' => [
        // Historical data source
        'data_source' => 'database', // or 'csv', 'api'

        // Backtest date range
        'start_date' => null,  // Set via CLI
        'end_date' => null,    // Set via CLI

        // Slippage assumption (%)
        'slippage_percent' => 0.1,

        // Commission (%)
        'commission_percent' => 0.05,

        // Starting capital ($)
        'starting_capital' => 10000,
    ],
];
