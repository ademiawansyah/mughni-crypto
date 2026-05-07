# Configuration Reference

All model, regime, AI, and notification settings are centralized in `config/models.php`.

---

## Counter-Trend (`models.counter_trend`)

```php
'counter_trend' => [
    'scoring' => [
        'sweep'   => 0.30,  // Liquidity sweep detection
        'mss'     => 0.25,  // Market structure shift
        'oi'      => 0.15,  // Open Interest
        'cvd'     => 0.15,  // CVD divergence
        'funding' => 0.10,  // Funding rate extreme
        'atr'     => 0.05,  // ATR volatility spike
    ],
    'min_score' => 60,          // Reject signals below this (0–100)
    'market_confidence_adjusters' => [
        'TRENDING_UP'   => -15,
        'TRENDING_DOWN' => +10,
        'RANGING'       =>  +5,
        'CHOPPY'        => -20,
    ],
    'ai_enabled'             => false, // Enable AI refinement
    'notification_threshold' => 70,    // Min confidence to notify
    'job_schedule'           => '*/15 * * * *',
]
```

---

## Pre-Pump (`models.pre_pump`)

```php
'pre_pump' => [
    'scoring' => [
        'funding'         => 0.35,  // Funding rate extreme
        'atr_compression' => 0.25,  // Volatility compression
        'oi'              => 0.20,  // OI expansion
        'rs'              => 0.10,  // Relative strength vs BTC
        'cvd'             => 0.10,  // CVD momentum
    ],
    'min_score' => 65,
    'market_confidence_adjusters' => [
        'TRENDING_UP'   => +20,
        'TRENDING_DOWN' => -25,
        'RANGING'       => +10,
        'CHOPPY'        => -15,
    ],
    'ai_enabled'             => false,
    'notification_threshold' => 75,
    'job_schedule'           => '*/30 * * * *',
]
```

---

## Momentum (`models.momentum`)

```php
'momentum' => [
    'scoring' => [
        'ema'  => 0.25,  // EMA alignment
        'macd' => 0.20,  // MACD momentum
        'rsi'  => 0.15,  // RSI zone
        'oi'   => 0.20,  // OI expansion
        'bos'  => 0.10,  // Break of structure
        'cvd'  => 0.10,  // CVD momentum
    ],
    'min_score' => 55,
    'market_confidence_adjusters' => [
        'TRENDING_UP'   => +30,
        'TRENDING_DOWN' => +30,
        'RANGING'       => -10,
        'CHOPPY'        => -20,
    ],
    'ai_enabled'             => false,
    'notification_threshold' => 65,
    'job_schedule'           => '0 * * * *',
]
```

---

## Market Regime (`models.market_regime`)

```php
'market_regime' => [
    'timeframes'   => ['1h', '4h', '1d'],  // BTC timeframes analyzed
    'ema_short'    => 9,
    'ema_long'     => 21,
    'atr_period'   => 14,
    'baseline_atr' => 200.0,               // Baseline for LOW/MEDIUM/HIGH classification
    'cache_ttl'    => 300,                 // 5 minutes
]
```

**Volatility classification:**
- ATR ratio < 0.8 → `LOW`
- ATR ratio 0.8–1.5 → `MEDIUM`
- ATR ratio > 1.5 → `HIGH`

---

## Coin Universe (`models.coin_universe`)

```php
'coin_universe' => [
    'min_market_cap' => 100_000_000,  // $100M
    'min_volume_24h' => 5_000_000,    // $5M
    'max_coins'      => 100,
    'exclude_coins'  => ['tether', 'usd-coin', 'binance-usd', ...],
    'require_exchange' => 'binance_futures',
    'cache_ttl'      => 86400,        // 24 hours
    'refresh_schedule' => '0 0 * * *',
]
```

---

## AI (`models.ai`)

```php
'ai' => [
    'endpoint' => env('OLLAMA_ENDPOINT', 'http://localhost:11434'),
    'model'    => env('OLLAMA_MODEL', 'qwen2.5:7b'),
    'timeout'  => 30,

    'confidence_adjusters' => [
        'counter_trend' => [
            'TRENDING_UP' => -20, 'TRENDING_DOWN' => 0, 'RANGING' => 5, 'CHOPPY' => -15,
        ],
        'pre_pump' => [
            'TRENDING_UP' => 15, 'TRENDING_DOWN' => -20, 'RANGING' => 5, 'CHOPPY' => -10,
        ],
        'momentum' => [
            'TRENDING_UP' => 20, 'TRENDING_DOWN' => 20, 'RANGING' => -15, 'CHOPPY' => -25,
        ],
    ],

    'min_confidence' => 50,
    'max_confidence' => 95,

    'validate_json'   => true,
    'required_fields' => ['action', 'confidence', 'type', 'reason'],

    'fallback_action'     => 'HOLD',
    'fallback_confidence' => 0,
]
```

> When AI is enabled, AI `confidence_adjusters` (under `models.ai`) apply.
> When AI is disabled, `market_confidence_adjusters` (under each model) apply.

---

## Enabling AI Per Model

To enable AI for a specific model, set in `config/models.php`:

```php
'counter_trend' => [
    'ai_enabled' => true,   // Changed from false
    ...
]
```

Or override at runtime via `GeneralConfig` (database-driven configuration).

---

## Tuning Thresholds

| Parameter | Location | Effect |
|-----------|----------|--------|
| `min_score` | Per model | Filters low-quality signals before AI |
| `notification_threshold` | Per model | Controls when Telegram is triggered |
| `market_confidence_adjusters` | Per model | Adjusts score ±N based on regime |
| `baseline_atr` | `market_regime` | Affects volatility LOW/MEDIUM/HIGH classification |

---

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `OLLAMA_ENDPOINT` | `http://localhost:11434` | AI server URL |
| `OLLAMA_MODEL` | `qwen2.5:7b` | AI model name |
| `TELEGRAM_ENABLED` | `true` | Master toggle for Telegram |
| `TELEGRAM_BOT_TOKEN` | _(required)_ | Bot API token |
| `TELEGRAM_CHAT_ID` | _(required)_ | Chat to send alerts to |
