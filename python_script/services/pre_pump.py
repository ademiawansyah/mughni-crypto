"""
Model 2 — Pre-Pump Detector
Strategy: Pressure Cooker + Momentum Runner (Short Squeeze Expansion Setup).

Flow:
  1. Layer 3 filter: rank 20-150, 24H volume >= $10M.
  2. Funding squeeze gate: persistent negative funding over 3 consecutive 8H windows.
  3. OI + price compression: OI up >10% in 24H while price range <3%.
  4. Low ATR: ATR(14) on 4H below 30-day ATR baseline.
  5. Drying volume: current 24H volume < 50% of 7-day average volume.
  6. CVD accumulation: CVD trend rising while price remains sideways.
  7. RSI compression: RSI(14) in 45-55 for at least 5 recent 4H candles.

Scoring weights:
  - Persistent negative funding: 35
  - OI rising + price sideways: 25
  - Low ATR: 20
  - CVD quietly rising: 12
  - RSI compression: 8
"""

from __future__ import annotations

import logging
import time
from concurrent.futures import ThreadPoolExecutor, as_completed

import requests

from shared.analysis import check_oi_decline
from shared.binance import fetch_ohlcv
from shared.config import (
    COINGECKO_API_KEY,
    COINGECKO_BASE_URL,
    COINGECKO_TIMEOUT,
    COINGECKO_VS_CURRENCY,
)
from shared.derivatives import fetch_funding_rate, fetch_open_interest

logger = logging.getLogger(__name__)

# --- Scoring weights ---
SCORE_FUNDING = 35
SCORE_OI_PRICE = 25
SCORE_ATR = 20
SCORE_CVD = 12
SCORE_RSI = 8

# --- Layer 3 filter thresholds ---
LAYER3_RANK_MIN = 20
LAYER3_RANK_MAX = 150
LAYER3_MIN_VOLUME = 10_000_000

TOP_N = 10

_market_chart_cache: dict[tuple[str, int], tuple[list[float], float]] = {}
_market_chart_ttl = 300

_CG_HEADERS = {"x-cg-demo-api-key": COINGECKO_API_KEY} if COINGECKO_API_KEY else {}


def filter_layer3(coins: list[dict]) -> list[dict]:
    """
    Layer 3 filter for Model 2.
    Keeps coins with market_cap_rank 20-150 and 24H volume >= $10M.
    """
    result = [
        c
        for c in coins
        if LAYER3_RANK_MIN <= (c.get("market_cap_rank") or 9999) <= LAYER3_RANK_MAX
        and (c.get("total_volume") or 0) >= LAYER3_MIN_VOLUME
    ]
    logger.info("Layer 3 (Model 2): %d coins after rank/volume filter", len(result))
    return result


def _coin_symbol_to_binance(coin: dict) -> str:
    raw = (coin.get("symbol") or "").upper()
    cleaned = "".join(ch for ch in raw if ch.isalnum())
    return cleaned + "USDT" if cleaned else ""


def _true_ranges(candles: list[dict]) -> list[float]:
    trs: list[float] = []
    for idx, candle in enumerate(candles):
        high = candle["high"]
        low = candle["low"]
        if idx == 0:
            trs.append(high - low)
            continue
        prev_close = candles[idx - 1]["close"]
        tr = max(high - low, abs(high - prev_close), abs(low - prev_close))
        trs.append(tr)
    return trs


def _sma(values: list[float], length: int) -> list[float]:
    if length <= 0:
        return []
    if len(values) < length:
        return []

    out = []
    rolling_sum = sum(values[:length])
    out.append(rolling_sum / length)
    for i in range(length, len(values)):
        rolling_sum += values[i] - values[i - length]
        out.append(rolling_sum / length)
    return out


def _calc_atr(candles: list[dict], period: int = 14) -> float | None:
    trs = _true_ranges(candles)
    atr_series = _sma(trs, period)
    if not atr_series:
        return None
    return atr_series[-1]


def _calc_rsi_series(closes: list[float], period: int = 14) -> list[float]:
    if len(closes) <= period:
        return []

    gains = []
    losses = []
    for i in range(1, len(closes)):
        delta = closes[i] - closes[i - 1]
        gains.append(max(delta, 0.0))
        losses.append(abs(min(delta, 0.0)))

    avg_gain = sum(gains[:period]) / period
    avg_loss = sum(losses[:period]) / period
    rsi_values: list[float] = []

    for i in range(period, len(gains)):
        avg_gain = ((avg_gain * (period - 1)) + gains[i]) / period
        avg_loss = ((avg_loss * (period - 1)) + losses[i]) / period
        if avg_loss == 0:
            rsi_values.append(100.0)
            continue
        rs = avg_gain / avg_loss
        rsi_values.append(100.0 - (100.0 / (1.0 + rs)))

    return rsi_values


def _price_sideways_24h(candles_4h: list[dict], threshold: float = 0.03) -> tuple[bool, float | None]:
    if len(candles_4h) < 6:
        return False, None

    window = candles_4h[-6:]
    low = min(c["low"] for c in window)
    high = max(c["high"] for c in window)
    if low <= 0:
        return False, None
    price_range = (high - low) / low
    return price_range < threshold, price_range


def _oi_rising_24h(oi_data: list[dict], threshold: float = 0.10) -> tuple[bool, float | None]:
    if len(oi_data) < 2:
        return False, None
    start = oi_data[0]["open_interest"]
    end = oi_data[-1]["open_interest"]
    if start <= 0:
        return False, None
    growth = (end - start) / start
    return growth > threshold, growth


def _aggregate_to_8h_funding(funding_data_4h: list[dict]) -> list[float]:
    """
    Coinalyze commonly returns 4H data.
    To evaluate 3x8H persistence, aggregate pairs of 4H points by average.
    """
    rates = [x["funding_rate"] for x in funding_data_4h]
    if len(rates) < 2:
        return []

    grouped = []
    start = 0 if len(rates) % 2 == 0 else 1
    for i in range(start, len(rates) - 1, 2):
        grouped.append((rates[i] + rates[i + 1]) / 2.0)
    return grouped


def _persistent_negative_funding(funding_data_4h: list[dict], threshold: float = -0.0005) -> tuple[bool, list[float]]:
    rates_8h = _aggregate_to_8h_funding(funding_data_4h)
    if len(rates_8h) < 3:
        return False, rates_8h

    recent = rates_8h[-3:]
    ok = all(rate < threshold for rate in recent)
    return ok, recent


def _cvd_slope_positive(candles_4h: list[dict], lookback: int = 6) -> tuple[bool, float | None]:
    """
    Approximate CVD using taker-buy volume from Binance futures klines.
    Delta per candle = taker_buy_volume - (total_volume - taker_buy_volume).
    """
    if len(candles_4h) < lookback:
        return False, None

    window = candles_4h[-lookback:]
    deltas = []
    for candle in window:
        taker_buy = candle.get("taker_buy_volume")
        total = candle.get("volume")
        if taker_buy is None or total is None:
            return False, None
        deltas.append((2.0 * taker_buy) - total)

    cvd = []
    cumulative = 0.0
    for d in deltas:
        cumulative += d
        cvd.append(cumulative)

    if len(cvd) < 2:
        return False, None

    slope = (cvd[-1] - cvd[0]) / (len(cvd) - 1)
    return slope > 0, slope


def _rsi_compression(candles_4h: list[dict], low: float = 45.0, high: float = 55.0, min_candles: int = 5) -> tuple[bool, list[float]]:
    closes = [c["close"] for c in candles_4h]
    rsi_values = _calc_rsi_series(closes, period=14)
    if len(rsi_values) < min_candles:
        return False, rsi_values

    recent = rsi_values[-min_candles:]
    ok = all(low <= x <= high for x in recent)
    return ok, recent


def _fetch_coingecko_daily_volumes(coin_id: str, days: int = 8) -> list[float]:
    cache_key = (coin_id, days)
    now = time.time()
    cached = _market_chart_cache.get(cache_key)
    if cached and (now - cached[1]) < _market_chart_ttl:
        return cached[0]

    if not coin_id:
        return []

    url = f"{COINGECKO_BASE_URL}/coins/{coin_id}/market_chart"
    params = {
        "vs_currency": COINGECKO_VS_CURRENCY,
        "days": days,
        "interval": "daily",
    }

    for attempt in range(1, 4):
        try:
            resp = requests.get(url, params=params, headers=_CG_HEADERS, timeout=COINGECKO_TIMEOUT)
            if resp.status_code in (404, 429):
                logger.debug("Market chart %s unavailable (%d)", coin_id, resp.status_code)
                _market_chart_cache[cache_key] = ([], now)
                return []
            resp.raise_for_status()
            data = resp.json()
            volumes = [float(v[1]) for v in data.get("total_volumes", []) if len(v) >= 2]
            _market_chart_cache[cache_key] = (volumes, now)
            return volumes
        except Exception as exc:
            wait = 2 ** (attempt - 1)
            logger.warning("Market chart %s attempt %d failed: %s — retry in %ds", coin_id, attempt, exc, wait)
            time.sleep(wait)

    _market_chart_cache[cache_key] = ([], now)
    return []


def _volume_drying(coin: dict) -> tuple[bool, float | None, float | None]:
    current_24h = float(coin.get("total_volume") or 0.0)
    coin_id = coin.get("id") or ""
    history = _fetch_coingecko_daily_volumes(coin_id, days=8)
    if current_24h <= 0 or len(history) < 7:
        return False, None, None

    baseline = sum(history[-7:]) / 7.0
    if baseline <= 0:
        return False, baseline, None

    ratio = current_24h / baseline
    # "drops >50%" means current < 50% of baseline.
    return ratio < 0.5, baseline, ratio


def _analyze_coin(coin: dict, execution_id: str) -> dict | None:
    symbol = _coin_symbol_to_binance(coin)
    if not symbol or symbol == "USDT":
        return None

    candles_4h = fetch_ohlcv(symbol, "4h", 220)
    if len(candles_4h) < 180:
        logger.debug("[%s] %s: insufficient 4H candles (%d)", execution_id, symbol, len(candles_4h))
        return None

    funding_data_4h = fetch_funding_rate(symbol, limit=12, interval="4hour")
    funding_ok = False
    funding_recent_8h: list[float] = []
    funding_gate_bypassed = False
    if funding_data_4h:
        funding_ok, funding_recent_8h = _persistent_negative_funding(funding_data_4h)
    else:
        # If Coinalyze data is unavailable, do not block the coin on derivative gates.
        funding_gate_bypassed = True

    oi_data = fetch_open_interest(symbol, interval="4hour", limit=7)
    oi_rising = False
    oi_growth = None
    if oi_data:
        oi_rising, oi_growth = _oi_rising_24h(oi_data)

    if not funding_ok and not funding_gate_bypassed:
        # Primary trigger gate applies only when funding data is available.
        return None

    sideways, price_range_24h = _price_sideways_24h(candles_4h)
    oi_plus_price_ok = oi_rising and sideways

    atr_14 = _calc_atr(candles_4h, period=14)
    atr_baseline = _calc_atr(candles_4h[-180:], period=30)
    atr_ok = False
    atr_ratio = None
    if atr_14 is not None and atr_baseline and atr_baseline > 0:
        atr_ratio = atr_14 / atr_baseline
        atr_ok = atr_14 < atr_baseline

    cvd_up, cvd_slope = _cvd_slope_positive(candles_4h, lookback=6)
    cvd_ok = cvd_up and sideways

    rsi_ok, rsi_recent = _rsi_compression(candles_4h, low=45.0, high=55.0, min_candles=5)

    volume_drying_ok, volume_7d_avg, volume_ratio = _volume_drying(coin)

    score = 0
    score += SCORE_FUNDING if funding_ok else 0
    score += SCORE_OI_PRICE if oi_plus_price_ok else 0
    score += SCORE_ATR if atr_ok else 0
    score += SCORE_CVD if cvd_ok else 0
    score += SCORE_RSI if rsi_ok else 0

    # Drop coins with no active signals at all.
    if score <= 0:
        return None

    coinalyze_available = bool(funding_data_4h or oi_data)

    # Keep volume drying visible for validation, but it is not weighted in section 5.5.
    return {
        "symbol": symbol,
        "price": coin.get("current_price"),
        "total_score": score,
        "components": {
            "persistent_negative_funding": funding_ok,
            "funding_gate_bypassed": funding_gate_bypassed,
            "oi_rising_price_sideways": oi_plus_price_ok,
            "low_atr_compression": atr_ok,
            "cvd_quietly_rising": cvd_ok,
            "rsi_compression": rsi_ok,
            "drying_volume": volume_drying_ok,
        },
        "metadata": {
            "screening_timeframe": "4H",
            "entry_timeframe": "1H",
            "coinalyze_available": coinalyze_available,
            "funding_recent_8h": funding_recent_8h,
            "oi_24h_growth": oi_growth,
            "price_range_24h": price_range_24h,
            "atr_14": atr_14,
            "atr_30d_baseline": atr_baseline,
            "atr_ratio": atr_ratio,
            "cvd_slope_24h": cvd_slope,
            "rsi_recent_4h": rsi_recent[-5:] if rsi_recent else [],
            "volume_24h": coin.get("total_volume"),
            "volume_7d_avg": volume_7d_avg,
            "volume_ratio": volume_ratio,
            "oi_declining_check_reference": check_oi_decline(oi_data),
        },
    }


def run(coins_layer2: list[dict], execution_id: str) -> dict:
    """
    Execute Model 2 full pipeline and return the standard output contract.
    """
    from datetime import datetime, timezone

    logger.info("[%s] Model 2 started — %d Layer 2 coins", execution_id, len(coins_layer2))

    candidates = filter_layer3(coins_layer2)
    results = []

    # Keep concurrency conservative to reduce Coinalyze 429 bursts.
    with ThreadPoolExecutor(max_workers=4) as executor:
        futures = {executor.submit(_analyze_coin, coin, execution_id): coin for coin in candidates}
        for future in as_completed(futures):
            try:
                result = future.result()
                if result is not None:
                    results.append(result)
            except Exception as exc:
                coin = futures[future]
                logger.error("[%s] Unhandled error for %s: %s", execution_id, coin.get("symbol"), exc)

    results.sort(key=lambda x: x["total_score"], reverse=True)
    top = results[:TOP_N]

    for i, item in enumerate(top, start=1):
        item["rank"] = i

    now = datetime.now(timezone.utc)
    output = {
        "model": "pre-pump-detector",
        "version": "2.0",
        "execution_id": execution_id,
        "timestamp": now.isoformat(),
        "execution_date": now.strftime("%Y-%m-%d"),
        "signal_count": len(top),
        "results": top,
    }

    logger.info(
        "[%s] Model 2 complete — %d/%d coins passed gates, top %d selected",
        execution_id,
        len(results),
        len(candidates),
        len(top),
    )
    return output
