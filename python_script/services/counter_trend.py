"""
Model 1 — Counter Trend (Mia Style)
Strategy: Liquidity Sweep + Market Structure Shift + Exhaustion Reversal Detection.

Flow:
  1. Layer 3 filter: rank 50–300, 24H volume ≥ $5M (from pre-filtered Layer 2 output).
  2. For each coin: fetch 1H OHLCV from Binance.
  3. Gate 1 (REQUIRED): Liquidity sweep detected           → 40 pts
  4. Gate 2 (REQUIRED): MSS (Market Structure Shift)       → 30 pts
  5. Optional: FVG / Order Block entry zone                → 15 pts
  6. Confirmation: OI declining ≥ 5%                       →  8 pts
  7. Confirmation: Extreme funding rate (|rate| ≥ 0.1%)    →  7 pts
  Total max: 100 pts.

  Coins failing Gate 1 or Gate 2 are excluded from output.
  Output: top 10 coins by total_score in standard JSON contract.
"""

import logging
from concurrent.futures import ThreadPoolExecutor, as_completed

from shared.binance import fetch_ohlcv
from shared.derivatives import fetch_open_interest, fetch_funding_rate
from shared.analysis import (
    detect_liquidity_sweep,
    detect_mss,
    detect_fvg_ob,
    check_oi_decline,
    check_extreme_funding,
)

logger = logging.getLogger(__name__)

# --- Scoring weights ---
SCORE_SWEEP = 40    # Required gate
SCORE_MSS = 30      # Required gate
SCORE_FVG_OB = 15   # Optional
SCORE_OI = 8        # Confirmation
SCORE_FUNDING = 7   # Confirmation

# --- Layer 3 filter thresholds ---
LAYER3_RANK_MIN = 50
LAYER3_RANK_MAX = 300
LAYER3_MIN_VOLUME = 5_000_000  # $5M

TOP_N = 10


def filter_layer3(coins: list[dict]) -> list[dict]:
    """
    Layer 3 filter for Model 1.
    Keeps coins with market_cap_rank 50–300 and 24H volume ≥ $5M.

    Args:
        coins: Pre-filtered list from Layer 2.

    Returns:
        Subset of coins eligible for Model 1 analysis.
    """
    result = [
        c for c in coins
        if LAYER3_RANK_MIN <= (c.get("market_cap_rank") or 9999) <= LAYER3_RANK_MAX
        and (c.get("total_volume") or 0) >= LAYER3_MIN_VOLUME
    ]
    logger.info("Layer 3 (Model 1): %d coins after rank/volume filter", len(result))
    return result


def _coin_symbol_to_binance(coin: dict) -> str:
    """Convert CoinGecko symbol (e.g. 'btc') to Binance USDT pair (e.g. 'BTCUSDT')."""
    return coin["symbol"].upper() + "USDT"


def _analyze_coin(coin: dict, execution_id: str) -> dict | None:
    """
    Run full Model 1 multi-timeframe analysis pipeline on a single coin.

    Timeframe roles (per spec §4.3):
      - 1H : Structure Screening — detect liquidity sweep + MSS (required gates).
      - 15M : Entry Confirmation — check FVG / Order Block on finer timeframe.
      - 1D : Macro Context Filter — optional bias check (skips coin if 1D trend
              opposes the sweep direction).

    Steps:
      1. Fetch 1H OHLCV → Gate 1: sweep. Gate 2: MSS. Skip if either absent.
      2. Fetch 1D OHLCV → macro bias check (optional filter, does not score).
      3. Fetch 15M OHLCV → FVG/OB entry zone confirmation (optional score).
      4. Fetch OI + Funding → confirmation scores.
      5. Compute total score.

    Args:
        coin:         Coin dict from Layer 2/3 (CoinGecko format).
        execution_id: Current execution ID for traceability.

    Returns:
        Scored result dict, or None if coin fails required gates or macro filter.
    """
    binance_symbol = _coin_symbol_to_binance(coin)

    # -------------------------------------------------------------------------
    # TIMEFRAME 1: 1H — Structure Screening (sweep + MSS gates)
    # -------------------------------------------------------------------------
    candles_1h = fetch_ohlcv(binance_symbol, "1h", 100)
    if len(candles_1h) < 20:
        logger.debug("[%s] %s: insufficient 1H candles (%d), skipping", execution_id, binance_symbol, len(candles_1h))
        return None

    # Gate 1: Liquidity Sweep (REQUIRED, 40 pts)
    sweep = detect_liquidity_sweep(candles_1h)
    if not sweep["detected"]:
        logger.debug("[%s] %s: no liquidity sweep on 1H", execution_id, binance_symbol)
        return None

    # Gate 2: MSS (REQUIRED, 30 pts)
    mss = detect_mss(candles_1h, sweep["candle_index"], sweep["direction"])
    if not mss["detected"]:
        logger.debug("[%s] %s: 1H sweep=%s but no MSS", execution_id, binance_symbol, sweep["direction"])
        return None

    logger.info("[%s] %s: 1H sweep=%s MSS=%s — confirmed", execution_id, binance_symbol, sweep["direction"], mss["direction"])

    # -------------------------------------------------------------------------
    # TIMEFRAME 2: 1D — Macro Context Filter (optional, no score)
    # Reject coin if daily trend strongly opposes the sweep direction.
    # Bullish sweep requires: last 1D candle close >= open (not strongly bearish).
    # Bearish sweep requires: last 1D candle close <= open (not strongly bullish).
    # -------------------------------------------------------------------------
    candles_1d = fetch_ohlcv(binance_symbol, "1d", 10)
    macro_aligned = True  # default: pass if we can't fetch daily
    if len(candles_1d) >= 2:
        last_daily = candles_1d[-1]
        daily_body = last_daily["close"] - last_daily["open"]
        daily_range = last_daily["high"] - last_daily["low"] or 1
        # "Strong" opposing candle: body > 60% of range in the wrong direction
        if sweep["direction"] == "bullish" and daily_body < 0 and abs(daily_body) / daily_range >= 0.6:
            macro_aligned = False
        elif sweep["direction"] == "bearish" and daily_body > 0 and abs(daily_body) / daily_range >= 0.6:
            macro_aligned = False

    if not macro_aligned:
        logger.debug("[%s] %s: 1D macro opposes %s sweep — skipping", execution_id, binance_symbol, sweep["direction"])
        return None

    # -------------------------------------------------------------------------
    # TIMEFRAME 3: 15M — Entry Confirmation (FVG / Order Block, optional 15 pts)
    # Check if price is currently inside an FVG/OB zone on the 15M chart,
    # meaning a valid entry zone exists right now at the finer timeframe.
    # -------------------------------------------------------------------------
    candles_15m = fetch_ohlcv(binance_symbol, "15m", 100)
    fvg = detect_fvg_ob(candles_15m) if len(candles_15m) >= 5 else {"detected": False, "zone_high": None, "zone_low": None}

    if fvg["detected"]:
        logger.debug("[%s] %s: 15M FVG/OB entry zone confirmed", execution_id, binance_symbol)

    # -------------------------------------------------------------------------
    # Derivatives: OI + Funding confirmation (no timeframe — latest values)
    # -------------------------------------------------------------------------
    oi_data = fetch_open_interest(binance_symbol, interval="1hour", limit=24)
    funding_data = fetch_funding_rate(binance_symbol, limit=10)

    oi_declining = check_oi_decline(oi_data)
    extreme_funding = check_extreme_funding(funding_data)

    # -------------------------------------------------------------------------
    # Score
    # -------------------------------------------------------------------------
    score = SCORE_SWEEP + SCORE_MSS  # both gates passed
    score += SCORE_FVG_OB if fvg["detected"] else 0
    score += SCORE_OI if oi_declining else 0
    score += SCORE_FUNDING if extreme_funding else 0

    # Determine stop loss level from 1H sweep candle (beyond the swept level)
    sweep_candle_1h = candles_1h[sweep["candle_index"]]
    if sweep["direction"] == "bullish":
        stop_loss = sweep_candle_1h["low"]   # below sweep candle low
    else:
        stop_loss = sweep_candle_1h["high"]  # above sweep candle high

    return {
        "symbol": binance_symbol,
        "price": coin.get("current_price"),
        "total_score": score,
        "components": {
            "liquidity_sweep": sweep["direction"],
            "liquidity_sweep_level": sweep["level"],
            "mss": mss["direction"],
            "fvg_ob_15m": fvg["detected"],
            "oi_declining": oi_declining,
            "extreme_funding": extreme_funding,
        },
        "metadata": {
            "structure_timeframe": "1H",
            "entry_timeframe": "15M",
            "macro_timeframe": "1D",
            "macro_aligned": macro_aligned,
            "stop_loss": stop_loss,
            "fvg_zone_15m": f"{fvg['zone_low']:.6f}–{fvg['zone_high']:.6f}" if fvg["detected"] else None,
        },
    }


def run(coins_layer2: list[dict], execution_id: str) -> dict:
    """
    Execute Model 1 full pipeline and return the standard output contract.

    Args:
        coins_layer2: Pre-filtered coin list from Layer 1+2.
        execution_id: Unique ID for this execution run.

    Returns:
        Standard model output dict (JSON-serializable).
    """
    from datetime import datetime, timezone

    logger.info("[%s] Model 1 started — %d Layer 2 coins", execution_id, len(coins_layer2))

    # Layer 3 filter
    candidates = filter_layer3(coins_layer2)

    # Analyze candidates concurrently (max 10 threads to respect rate limits)
    results = []
    with ThreadPoolExecutor(max_workers=10) as executor:
        futures = {executor.submit(_analyze_coin, coin, execution_id): coin for coin in candidates}
        for future in as_completed(futures):
            try:
                result = future.result()
                if result is not None:
                    results.append(result)
            except Exception as exc:
                coin = futures[future]
                logger.error("[%s] Unhandled error for %s: %s", execution_id, coin.get("symbol"), exc)

    # Sort by score descending, take top 10
    results.sort(key=lambda x: x["total_score"], reverse=True)
    top = results[:TOP_N]

    # Assign ranks
    for i, item in enumerate(top, start=1):
        item["rank"] = i

    now = datetime.now(timezone.utc)
    output = {
        "model": "counter-trend",
        "version": "2.0",
        "execution_id": execution_id,
        "timestamp": now.isoformat(),
        "execution_date": now.strftime("%Y-%m-%d"),
        "signal_count": len(top),
        "results": top,
    }

    logger.info(
        "[%s] Model 1 complete — %d/%d coins passed gates, top %d selected",
        execution_id, len(results), len(candidates), len(top),
    )
    return output
