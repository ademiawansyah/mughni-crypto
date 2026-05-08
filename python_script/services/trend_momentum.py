"""
Model 3 — Trend Momentum
Strategy: MACD-RSI-EMA confirmation for breakout continuation.

Flow:
  1. Layer 3 filter: rank 1-50, volume >= $50M (BTC and ETH always included).
  2. Gate (required): price > EMA50 > EMA200 on 1D and EMA50 slope positive 3 days.
  3. MACD confirmation on 4H: MACD > Signal > 0, histogram positive and expanding.
  4. RSI momentum zone on 4H: RSI(14) in 50-65.
  5. BOS confirmation on 4H: close breaks prior swing high.
  6. Derivatives health: OI and price both up >5% in 24H + CVD slope positive.

Scoring weights:
  - EMA gate: 30 (required)
  - MACD positive zone: 25
  - RSI 50-65: 20
  - BOS confirmed: 15
  - OI + CVD positive: 10
"""

from __future__ import annotations

import logging
from concurrent.futures import ThreadPoolExecutor, as_completed

from shared.analysis import find_swing_points
from shared.binance import fetch_ohlcv
from shared.derivatives import fetch_open_interest

logger = logging.getLogger(__name__)

# --- Scoring weights ---
SCORE_EMA = 30
SCORE_MACD = 25
SCORE_RSI = 20
SCORE_BOS = 15
SCORE_DERIVATIVES = 10

# --- Layer 3 filter thresholds ---
LAYER3_RANK_MAX = 50
LAYER3_MIN_VOLUME = 50_000_000
ALWAYS_INCLUDE_SYMBOLS = {"btc", "eth"}

TOP_N = 10


def filter_layer3(coins: list[dict]) -> list[dict]:
    """
    Layer 3 filter for Model 3.
    Keeps rank <= 50, volume >= $50M; always includes BTC/ETH if present.
    """
    result = []
    for coin in coins:
        symbol = (coin.get("symbol") or "").lower()
        rank_ok = (coin.get("market_cap_rank") or 9999) <= LAYER3_RANK_MAX
        vol_ok = (coin.get("total_volume") or 0) >= LAYER3_MIN_VOLUME
        if (rank_ok and vol_ok) or symbol in ALWAYS_INCLUDE_SYMBOLS:
            result.append(coin)

    logger.info("Layer 3 (Model 3): %d coins after rank/volume filter", len(result))
    return result


def _coin_symbol_to_binance(coin: dict) -> str:
    raw = (coin.get("symbol") or "").upper()
    cleaned = "".join(ch for ch in raw if ch.isalnum())
    return cleaned + "USDT" if cleaned else ""


def _ema(values: list[float], period: int) -> list[float]:
    if period <= 0 or len(values) < period:
        return []

    k = 2.0 / (period + 1)
    seed = sum(values[:period]) / period
    out = [seed]
    for v in values[period:]:
        out.append((v * k) + (out[-1] * (1.0 - k)))
    return out


def _rsi_series(closes: list[float], period: int = 14) -> list[float]:
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
    out = []

    for i in range(period, len(gains)):
        avg_gain = ((avg_gain * (period - 1)) + gains[i]) / period
        avg_loss = ((avg_loss * (period - 1)) + losses[i]) / period
        if avg_loss == 0:
            out.append(100.0)
            continue
        rs = avg_gain / avg_loss
        out.append(100.0 - (100.0 / (1.0 + rs)))
    return out


def _ema_gate(candles_1d: list[dict]) -> tuple[bool, dict]:
    """
    Gate: close > EMA50 > EMA200 and EMA50 slope positive for 3 days.
    """
    closes = [c["close"] for c in candles_1d]
    ema50 = _ema(closes, 50)
    ema200 = _ema(closes, 200)

    if not ema50 or not ema200:
        return False, {
            "close": closes[-1] if closes else None,
            "ema50": None,
            "ema200": None,
            "ema_spread": None,
            "ema50_slope_last3": [],
            "ema_gate_ok": False,
        }

    # Align EMA50 to the same timeline as EMA200 (latest portion only).
    aligned_ema50 = ema50[-len(ema200):]
    close_now = closes[-1]
    ema50_now = aligned_ema50[-1]
    ema200_now = ema200[-1]

    spread = ema50_now - ema200_now
    spread_prev = aligned_ema50[-2] - ema200[-2] if len(ema200) >= 2 else None
    spread_widening = spread_prev is not None and spread > spread_prev

    slope3 = []
    if len(aligned_ema50) >= 4:
        slope3 = [
            aligned_ema50[-3] - aligned_ema50[-4],
            aligned_ema50[-2] - aligned_ema50[-3],
            aligned_ema50[-1] - aligned_ema50[-2],
        ]
    slope_ok = len(slope3) == 3 and all(s > 0 for s in slope3)

    gate_ok = close_now > ema50_now > ema200_now and spread_widening and slope_ok
    return gate_ok, {
        "close": close_now,
        "ema50": ema50_now,
        "ema200": ema200_now,
        "ema_spread": spread,
        "ema50_slope_last3": slope3,
        "ema_gate_ok": gate_ok,
    }


def _macd_ok(candles_4h: list[dict]) -> tuple[bool, dict]:
    closes = [c["close"] for c in candles_4h]
    ema12 = _ema(closes, 12)
    ema26 = _ema(closes, 26)
    if not ema12 or not ema26:
        return False, {
            "macd": None,
            "signal": None,
            "histogram": None,
            "histogram_prev": None,
            "macd_ok": False,
        }

    aligned_ema12 = ema12[-len(ema26):]
    macd_line = [a - b for a, b in zip(aligned_ema12, ema26)]
    signal = _ema(macd_line, 9)
    if not signal:
        return False, {
            "macd": None,
            "signal": None,
            "histogram": None,
            "histogram_prev": None,
            "macd_ok": False,
        }

    macd_tail = macd_line[-len(signal):]
    histogram = [m - s for m, s in zip(macd_tail, signal)]
    if len(histogram) < 2:
        return False, {
            "macd": macd_tail[-1] if macd_tail else None,
            "signal": signal[-1],
            "histogram": histogram[-1] if histogram else None,
            "histogram_prev": None,
            "macd_ok": False,
        }

    macd_now = macd_tail[-1]
    signal_now = signal[-1]
    hist_now = histogram[-1]
    hist_prev = histogram[-2]

    ok = macd_now > signal_now > 0 and hist_now > 0 and hist_now > hist_prev
    return ok, {
        "macd": macd_now,
        "signal": signal_now,
        "histogram": hist_now,
        "histogram_prev": hist_prev,
        "macd_ok": ok,
    }


def _rsi_zone_ok(candles_4h: list[dict], low: float = 50.0, high: float = 65.0) -> tuple[bool, dict]:
    closes = [c["close"] for c in candles_4h]
    rsi = _rsi_series(closes, period=14)
    if not rsi:
        return False, {"rsi": None, "rsi_ok": False}
    rsi_now = rsi[-1]
    ok = low <= rsi_now <= high
    return ok, {"rsi": rsi_now, "rsi_ok": ok}


def _bos_ok(candles_4h: list[dict]) -> tuple[bool, dict]:
    """
    Bullish BOS: latest close breaks above prior confirmed swing high.
    """
    if len(candles_4h) < 30:
        return False, {"last_close": None, "last_swing_high": None, "bos_ok": False}

    # Exclude latest candle from swing reference to avoid self-referential level.
    reference = candles_4h[:-1]
    swing_highs, _ = find_swing_points(reference, lookback=5)
    if not swing_highs:
        return False, {
            "last_close": candles_4h[-1]["close"],
            "last_swing_high": None,
            "bos_ok": False,
        }

    last_swing_high = swing_highs[-1]["price"]
    last_close = candles_4h[-1]["close"]
    ok = last_close > last_swing_high
    return ok, {
        "last_close": last_close,
        "last_swing_high": last_swing_high,
        "bos_ok": ok,
    }


def _oi_price_rising(oi_data: list[dict], candles_4h: list[dict], threshold: float = 0.05) -> tuple[bool, dict]:
    if len(oi_data) < 2 or len(candles_4h) < 6:
        return False, {"oi_growth": None, "price_growth": None, "oi_price_ok": False}

    oi_start = oi_data[0]["open_interest"]
    oi_end = oi_data[-1]["open_interest"]
    if oi_start <= 0:
        return False, {"oi_growth": None, "price_growth": None, "oi_price_ok": False}

    price_start = candles_4h[-6]["close"]
    price_end = candles_4h[-1]["close"]
    if price_start <= 0:
        return False, {"oi_growth": None, "price_growth": None, "oi_price_ok": False}

    oi_growth = (oi_end - oi_start) / oi_start
    price_growth = (price_end - price_start) / price_start
    ok = oi_growth > threshold and price_growth > threshold
    return ok, {
        "oi_growth": oi_growth,
        "price_growth": price_growth,
        "oi_price_ok": ok,
    }


def _cvd_positive(candles_4h: list[dict], lookback: int = 6) -> tuple[bool, dict]:
    if len(candles_4h) < lookback:
        return False, {"cvd_slope": None, "cvd_ok": False}

    window = candles_4h[-lookback:]
    deltas = []
    for candle in window:
        taker_buy = candle.get("taker_buy_volume")
        total = candle.get("volume")
        if taker_buy is None or total is None:
            return False, {"cvd_slope": None, "cvd_ok": False}
        deltas.append((2.0 * taker_buy) - total)

    cvd = []
    cumulative = 0.0
    for d in deltas:
        cumulative += d
        cvd.append(cumulative)

    if len(cvd) < 2:
        return False, {"cvd_slope": None, "cvd_ok": False}

    slope = (cvd[-1] - cvd[0]) / (len(cvd) - 1)
    ok = slope > 0
    return ok, {"cvd_slope": slope, "cvd_ok": ok}


def _analyze_coin(coin: dict, execution_id: str) -> dict | None:
    symbol = _coin_symbol_to_binance(coin)
    if not symbol or symbol == "USDT":
        return None

    candles_1d = fetch_ohlcv(symbol, "1d", 260)
    if len(candles_1d) < 220:
        logger.debug("[%s] %s: insufficient 1D candles (%d)", execution_id, symbol, len(candles_1d))
        return None

    ema_gate_ok, ema_meta = _ema_gate(candles_1d)
    if not ema_gate_ok:
        return None

    candles_4h = fetch_ohlcv(symbol, "4h", 220)
    if len(candles_4h) < 120:
        logger.debug("[%s] %s: insufficient 4H candles (%d)", execution_id, symbol, len(candles_4h))
        return None

    macd_ok, macd_meta = _macd_ok(candles_4h)
    rsi_ok, rsi_meta = _rsi_zone_ok(candles_4h, low=50.0, high=65.0)
    bos_ok, bos_meta = _bos_ok(candles_4h)

    oi_data = fetch_open_interest(symbol, interval="4hour", limit=7)
    oi_price_ok, oi_price_meta = _oi_price_rising(oi_data, candles_4h, threshold=0.05)
    cvd_ok, cvd_meta = _cvd_positive(candles_4h, lookback=6)
    derivatives_ok = oi_price_ok and cvd_ok

    score = SCORE_EMA
    score += SCORE_MACD if macd_ok else 0
    score += SCORE_RSI if rsi_ok else 0
    score += SCORE_BOS if bos_ok else 0
    score += SCORE_DERIVATIVES if derivatives_ok else 0

    stop_loss = bos_meta["last_swing_high"] if bos_meta["last_swing_high"] is not None else candles_4h[-2]["low"]

    return {
        "symbol": symbol,
        "price": coin.get("current_price"),
        "total_score": score,
        "components": {
            "ema_gate": ema_gate_ok,
            "macd_positive_zone": macd_ok,
            "rsi_momentum_zone": rsi_ok,
            "bos_confirmed": bos_ok,
            "oi_cvd_positive": derivatives_ok,
            "derivatives_skipped": not bool(oi_data),
        },
        "metadata": {
            "trend_timeframe": "1D",
            "entry_timeframe": "4H",
            "coinalyze_available": bool(oi_data),
            "stop_loss": stop_loss,
            **ema_meta,
            **macd_meta,
            **rsi_meta,
            **bos_meta,
            **oi_price_meta,
            **cvd_meta,
        },
    }


def run(coins_layer2: list[dict], execution_id: str) -> dict:
    """
    Execute Model 3 full pipeline and return the standard output contract.
    """
    from datetime import datetime, timezone

    logger.info("[%s] Model 3 started — %d Layer 2 coins", execution_id, len(coins_layer2))

    candidates = filter_layer3(coins_layer2)
    results = []

    with ThreadPoolExecutor(max_workers=6) as executor:
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
        "model": "trend-momentum",
        "version": "2.0",
        "execution_id": execution_id,
        "timestamp": now.isoformat(),
        "execution_date": now.strftime("%Y-%m-%d"),
        "signal_count": len(top),
        "results": top,
    }

    logger.info(
        "[%s] Model 3 complete — %d/%d coins passed gate, top %d selected",
        execution_id,
        len(results),
        len(candidates),
        len(top),
    )
    return output