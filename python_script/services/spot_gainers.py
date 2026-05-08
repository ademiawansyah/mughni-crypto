"""
Model 4 — Spot Momentum Gainers
Strategy: Top 24H gainers + strict bullish daily candle validation.

Flow:
  1. Layer 3 filter: rank <= 200, market cap >= $100M, exclude stable/wrapped.
  2. Keep top 10 by 24H percentage gain.
  3. For each coin, fetch 1D candles from Binance and validate ALL 5 gate criteria:
     - close > open
     - (close-open)/(high-low) >= 0.6
     - (high-close)/(close-open) <= 0.2
     - close[today] > high[yesterday]
     - volume[today] > avg(volume previous 5 candles)
  4. If gate passes, compute score:
     - 24H percentage change magnitude: 40
     - Volume spike ratio: 35
     - Body ratio: 25
  5. Rank and return top 10 in standard output contract.

SPOT ONLY: Long-bias signal generation only. No short/leverage logic.
"""

from __future__ import annotations

import logging
from concurrent.futures import ThreadPoolExecutor, as_completed

from shared.binance import fetch_ohlcv
from shared.fetch import MODEL4_MIN_MARKET_CAP, STABLECOINS, WRAPPED_KW

logger = logging.getLogger(__name__)

SCORE_CHANGE = 40.0
SCORE_VOLUME = 35.0
SCORE_BODY = 25.0

LAYER3_RANK_MAX = 200
TOP_N = 10


def _clamp(value: float, low: float, high: float) -> float:
    return max(low, min(value, high))


def _coin_symbol_to_binance(coin: dict) -> str:
    raw = (coin.get("symbol") or "").upper()
    cleaned = "".join(ch for ch in raw if ch.isalnum())
    return cleaned + "USDT" if cleaned else ""


def _is_stable_or_wrapped(coin: dict) -> bool:
    symbol = (coin.get("symbol") or "").lower()
    name = (coin.get("name") or "").lower()
    if symbol in STABLECOINS:
        return True
    return any(kw in name for kw in WRAPPED_KW)


def filter_layer3(coins: list[dict]) -> list[dict]:
    """
    Layer 3 filter for Model 4.
    Keeps rank <= 200, market cap >= $100M, excludes stable/wrapped,
    then picks top 10 by 24H gain.
    """
    filtered = []
    for coin in coins:
        rank = coin.get("market_cap_rank") or 9999
        market_cap = coin.get("market_cap") or 0
        price = coin.get("current_price")
        volume = coin.get("total_volume") or 0

        if rank > LAYER3_RANK_MAX:
            continue
        if market_cap < MODEL4_MIN_MARKET_CAP:
            continue
        if _is_stable_or_wrapped(coin):
            continue
        if not price or volume <= 0:
            continue

        filtered.append(coin)

    filtered.sort(key=lambda x: x.get("price_change_percentage_24h") or 0.0, reverse=True)
    top = filtered[:TOP_N]
    logger.info("Layer 3 (Model 4): %d/%d coins after filter", len(top), len(filtered))
    return top


def evaluate_bullish_candle_gate(candles_1d: list[dict]) -> dict:
    """
    Validate strict bullish candle gate using the latest daily candle.

    Returns a dict with booleans and computed ratios for auditability.
    """
    if len(candles_1d) < 7:
        return {
            "passed": False,
            "green_candle": False,
            "large_body": False,
            "minimal_upper_wick": False,
            "close_breakout": False,
            "high_volume": False,
            "body_ratio": None,
            "upper_wick_ratio": None,
            "volume_ratio": None,
            "prior_high": None,
            "stop_loss": None,
            "reason": "insufficient_candles",
        }

    today = candles_1d[-1]
    prev = candles_1d[-2]
    prev5 = candles_1d[-6:-1]

    open_p = float(today["open"])
    high = float(today["high"])
    low = float(today["low"])
    close = float(today["close"])
    volume = float(today["volume"])

    body = close - open_p
    total_range = high - low
    avg_prev5_volume = sum(float(c["volume"]) for c in prev5) / 5.0

    body_ratio = (body / total_range) if total_range > 0 else 0.0
    upper_wick_ratio = ((high - close) / body) if body > 0 else 999.0
    volume_ratio = (volume / avg_prev5_volume) if avg_prev5_volume > 0 else 0.0

    green_candle = close > open_p
    large_body = body_ratio >= 0.6
    minimal_upper_wick = upper_wick_ratio <= 0.2
    close_breakout = close > float(prev["high"])
    high_volume = volume > avg_prev5_volume

    passed = all([green_candle, large_body, minimal_upper_wick, close_breakout, high_volume])

    return {
        "passed": passed,
        "green_candle": green_candle,
        "large_body": large_body,
        "minimal_upper_wick": minimal_upper_wick,
        "close_breakout": close_breakout,
        "high_volume": high_volume,
        "body_ratio": body_ratio,
        "upper_wick_ratio": upper_wick_ratio,
        "volume_ratio": volume_ratio,
        "prior_high": float(prev["high"]),
        "stop_loss": low,
        "reason": "ok" if passed else "criteria_not_met",
    }


def calculate_score(price_change_24h: float, volume_ratio: float, body_ratio: float) -> dict:
    """
    Weighted score with clamped final value [0, 100].

    - change_score: 40 * clamp(price_change_24h / 100, 0, 1)
    - volume_score: 35 * clamp(volume_ratio, 0, 1)
    - body_score:   25 * clamp(body_ratio, 0, 1)
    """
    change_norm = _clamp((price_change_24h or 0.0) / 100.0, 0.0, 1.0)
    volume_norm = _clamp(volume_ratio or 0.0, 0.0, 1.0)
    body_norm = _clamp(body_ratio or 0.0, 0.0, 1.0)

    change_score = SCORE_CHANGE * change_norm
    volume_score = SCORE_VOLUME * volume_norm
    body_score = SCORE_BODY * body_norm
    total = _clamp(change_score + volume_score + body_score, 0.0, 100.0)

    return {
        "change_score": change_score,
        "volume_score": volume_score,
        "body_score": body_score,
        "total_score": total,
    }


def _analyze_coin(coin: dict, execution_id: str, source: str) -> dict | None:
    symbol = _coin_symbol_to_binance(coin)
    if not symbol or symbol == "USDT":
        return None

    candles_1d = fetch_ohlcv(symbol, "1d", 7)
    gate = evaluate_bullish_candle_gate(candles_1d)
    if not gate["passed"]:
        logger.debug("[%s] %s: gate failed (%s)", execution_id, symbol, gate["reason"])
        return None

    price_change_24h = float(coin.get("price_change_percentage_24h") or 0.0)
    score = calculate_score(
        price_change_24h=price_change_24h,
        volume_ratio=float(gate["volume_ratio"] or 0.0),
        body_ratio=float(gate["body_ratio"] or 0.0),
    )

    return {
        "symbol": symbol,
        "price": coin.get("current_price"),
        "total_score": round(score["total_score"], 4),
        "components": {
            "bullish_candle_gate": True,
            "green_candle": gate["green_candle"],
            "large_body": gate["large_body"],
            "minimal_upper_wick": gate["minimal_upper_wick"],
            "close_breakout": gate["close_breakout"],
            "high_volume": gate["high_volume"],
            "change_score": round(score["change_score"], 4),
            "volume_score": round(score["volume_score"], 4),
            "body_score": round(score["body_score"], 4),
        },
        "metadata": {
            "screening_timeframe": "1D",
            "entry_timeframe": "1D",
            "spot_only": True,
            "data_source": source,
            "price_change_percentage_24h": price_change_24h,
            "body_ratio": gate["body_ratio"],
            "upper_wick_ratio": gate["upper_wick_ratio"],
            "volume_ratio": gate["volume_ratio"],
            "prior_high": gate["prior_high"],
            "stop_loss": gate["stop_loss"],
        },
    }


def run(coins_universe: list[dict], execution_id: str, source: str = "unknown") -> dict:
    """
    Execute Model 4 pipeline and return standard output contract.
    """
    from datetime import datetime, timezone

    logger.info("[%s] Model 4 started — %d source coins (%s)", execution_id, len(coins_universe), source)
    candidates = filter_layer3(coins_universe)

    results = []
    with ThreadPoolExecutor(max_workers=6) as executor:
        futures = {executor.submit(_analyze_coin, coin, execution_id, source): coin for coin in candidates}
        for future in as_completed(futures):
            try:
                item = future.result()
                if item is not None:
                    results.append(item)
            except Exception as exc:
                coin = futures[future]
                logger.error("[%s] Unhandled error for %s: %s", execution_id, coin.get("symbol"), exc)

    results.sort(key=lambda x: x["total_score"], reverse=True)
    top = results[:TOP_N]

    for i, item in enumerate(top, start=1):
        item["rank"] = i

    now = datetime.now(timezone.utc)
    output = {
        "model": "spot-momentum-gainers",
        "version": "2.0",
        "execution_id": execution_id,
        "timestamp": now.isoformat(),
        "execution_date": now.strftime("%Y-%m-%d"),
        "signal_count": len(top),
        "results": top,
    }

    logger.info(
        "[%s] Model 4 complete — %d/%d passed gate, top %d selected",
        execution_id,
        len(results),
        len(candidates),
        len(top),
    )
    return output
