<?php

namespace App\Services\Market\DTO;

/**
 * Metadata contract and vocabulary for coin analysis results.
 *
 * This class documents the standard metadata keys used across all model analysis outputs
 * and specifies which keys are canonical (used by all models) vs. model-specific (diagnostics).
 *
 * CANONICAL METADATA KEYS (All models MUST provide these when applicable):
 * =========================================================================
 *
 * - structure_timeframe (string): Primary timeframe for structural analysis (e.g., "1H", "4H", "1D")
 * - entry_timeframe (string): Confirmed entry timeframe (e.g., "15M", "1H")
 * - macro_timeframe (string, nullable): Optional macro/trend context timeframe (e.g., "1D")
 * - strategy (string): Model name or strategy identifier (e.g., "counter_trend", "pre_pump")
 * - stop_loss (float, nullable): Calculated stop loss price
 *
 * METADATA TYPE SPECIFICATION:
 * ============================
 * Metadata fields are mixed-type to accommodate diverse model outputs:
 * - string: timeframes, strategy names, reasons
 * - float: technical indicator values (ema50, atr_14, cvd_slope_24h)
 * - int: count values, point scores (oi_points, funding_points)
 * - array: historical sequences (ema50_slope_last3 => [0.1, 0.05, 0.02], funding_recent_8h => [...])
 * - bool: feature flags (coinalyze_available, spot_only)
 * - null: optional/unavailable values (macro_timeframe, stop_loss when not applicable)
 *
 * MODEL-SPECIFIC METADATA EXTRAS (In addition to canonical keys):
 * ===============================================================
 *
 * COUNTER_TREND:
 *   - sweep_detected (bool): Liquidity sweep confirmed
 *   - mss_formed (bool): Market structure shift detected
 *   - fvg_ob_entry (bool): FVG/Order Block zone identified
 *   - oi_decline_pct (float): Open Interest change percentage
 *   - funding_rate (float): Current funding rate
 *   - cvd_divergence (bool): CVD bearish divergence with price
 *   - ema_50 (float): EMA50 value
 *   - ema_200 (float): EMA200 value
 *
 * PRE_PUMP:
 *   - funding_rate_8h (float): 8-hour funding rate
 *   - oi_growth_pct (float): Open Interest growth percentage in 24H
 *   - atr_14 (float): 14-period ATR on 4H
 *   - atr_30d_baseline (float): 30-day average ATR
 *   - atr_ratio (float): Current ATR / baseline ATR
 *   - volume_24h (float): 24-hour trading volume
 *   - volume_7d_avg (float): 7-day average volume
 *   - volume_ratio (float): Current 24H volume / 7-day average
 *   - cvd_slope_24h (float): CVD 24-hour slope/trend
 *   - rsi_current (float): Current RSI 14 value
 *   - price_range_24h (float): Price range in 24H
 *
 * TREND_MOMENTUM:
 *   - ema_50 (float): EMA50 value
 *   - ema_200 (float): EMA200 value
 *   - ema_spread (float): Spread between EMA50 and EMA200
 *   - ema_50_slope (float): Slope of EMA50 over recent periods
 *   - macd_value (float): MACD line value
 *   - macd_signal (float): MACD signal line
 *   - macd_histogram (float): MACD histogram
 *   - rsi_current (float): Current RSI 14 value
 *   - bos_detected (bool): Break of Structure confirmed
 *   - oi_growth_pct (float): OI 24H growth percentage
 *   - price_growth_pct (float): Price 24H growth percentage
 *   - cvd_positive (bool): CVD trending positive
 *
 * SPOT_MOMENTUM_GAINER:
 *   - spot_only (bool): Spot trading only (true for this model)
 *   - data_source (string): "coinmarketcap" or "coingecko_fallback"
 *   - price_change_percentage_24h (float): 24-hour percentage change
 *   - body_ratio (float): Bullish candle body ratio (close-open)/(high-low)
 *   - upper_wick_ratio (float): Upper wick ratio (high-close)/(close-open)
 *   - volume_ratio (float): Current volume / 5-bar average
 *   - allow_short (bool): Whether short positions allowed (false for spot)
 *   - allow_leverage (bool): Whether leverage allowed (false for spot)
 *   - prior_high (float): Previous candle high (for breakout validation)
 *   - entry_point (float): Calculated entry price
 *
 * LAYER 4 DERIVATIVE FIELDS (As metadata extras, model-specific availability):
 * =============================================================================
 *   - candles_1d (array, nullable): Daily OHLCV candles [{open, high, low, close, volume}, ...]
 *   - candles_4h (array, nullable): 4H OHLCV candles [{open, high, low, close, volume}, ...]
 *   - candles_15m (array, nullable): 15M OHLCV candles [{open, high, low, close, volume}, ...]
 *   - open_interest (float, nullable): Open Interest in USD
 *   - funding_rate (float, nullable): Funding rate as percentage (e.g., -0.001 = -0.1%)
 *   - cvd_24h (float, nullable): Cumulative Volume Delta over 24 hours
 */
class MetadataContract
{
    // Canonical keys required or conditional in all models
    public const string KEY_STRUCTURE_TIMEFRAME = 'structure_timeframe';

    public const string KEY_ENTRY_TIMEFRAME = 'entry_timeframe';

    public const string KEY_MACRO_TIMEFRAME = 'macro_timeframe';

    public const string KEY_STRATEGY = 'strategy';

    public const string KEY_STOP_LOSS = 'stop_loss';

    // Layer 4 derivative fields (model-specific availability)
    public const string KEY_CANDLES_1D = 'candles_1d';

    public const string KEY_CANDLES_4H = 'candles_4h';

    public const string KEY_CANDLES_15M = 'candles_15m';

    public const string KEY_OPEN_INTEREST = 'open_interest';

    public const string KEY_FUNDING_RATE = 'funding_rate';

    public const string KEY_CVD_24H = 'cvd_24h';
}
