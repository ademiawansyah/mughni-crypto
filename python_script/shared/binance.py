"""
OHLCV and trade data fetcher from Binance public API.
OHLCV is cached for 60 seconds.
Recent trades are cached for 300 seconds for CVD calculations.
"""

import time
import logging
import requests

from shared.config import BINANCE_BASE_URL, BINANCE_API_KEY

logger = logging.getLogger(__name__)

OHLCV_TTL = 60  # seconds

# API key is required for private endpoints but also improves rate limits on public ones.
_BINANCE_HEADERS = {"X-MBX-APIKEY": BINANCE_API_KEY} if BINANCE_API_KEY else {}

_cache: dict = {}  # key: (symbol, interval, limit) → (candles, timestamp)
_trades_cache: dict = {}  # key: (symbol, limit) -> (trades, timestamp)
TRADES_TTL = 300


def fetch_ohlcv(symbol: str, interval: str, limit: int = 100) -> list[dict]:
    """
    Fetch OHLCV candles from Binance public API.
    Result is cached per (symbol, interval, limit) for 60 seconds.

    Args:
        symbol:   Binance trading pair, e.g. "BTCUSDT".
        interval: Candle interval, e.g. "1h", "4h", "15m", "1d".
        limit:    Number of candles to fetch (max 1000).

    Returns:
        List of candle dicts: {open_time, open, high, low, close, volume}.
        Empty list on failure.
    """
    cache_key = (symbol, interval, limit)
    now = time.time()
    cached = _cache.get(cache_key)
    if cached and (now - cached[1]) < OHLCV_TTL:
        return cached[0]

    klines_url = f"{BINANCE_BASE_URL}/klines"
    params = {"symbol": symbol, "interval": interval, "limit": limit}

    for attempt in range(1, 4):
        try:
            resp = requests.get(klines_url, params=params, headers=_BINANCE_HEADERS, timeout=10)
            # 400 means the symbol doesn't exist on Binance — no point retrying
            if resp.status_code == 400:
                logger.debug("OHLCV %s %s: symbol not on Binance (400) — skipping", symbol, interval)
                _cache[cache_key] = ([], now)
                return []
            resp.raise_for_status()
            raw = resp.json()
            candles = [
                {
                    "open_time": r[0],
                    "open": float(r[1]),
                    "high": float(r[2]),
                    "low": float(r[3]),
                    "close": float(r[4]),
                    "volume": float(r[5]),
                    "taker_buy_volume": float(r[9]),
                }
                for r in raw
            ]
            _cache[cache_key] = (candles, now)
            return candles
        except Exception as exc:
            wait = 2 ** (attempt - 1)
            logger.warning(
                "OHLCV %s %s attempt %d: %s — retry in %ds", symbol, interval, attempt, exc, wait
            )
            time.sleep(wait)

    logger.error("OHLCV %s %s: all retries failed", symbol, interval)
    return []


def fetch_recent_trades(symbol: str, limit: int = 1000) -> list[dict]:
    """
    Fetch recent trades from Binance futures endpoint for CVD estimation.

    Args:
        symbol: Binance trading pair, e.g. "BTCUSDT".
        limit: Number of trades to fetch (max 1000).

    Returns:
        List of trade dicts with keys: time, qty, is_buyer_maker.
        Empty list on failure.
    """
    cache_key = (symbol, limit)
    now = time.time()
    cached = _trades_cache.get(cache_key)
    if cached and (now - cached[1]) < TRADES_TTL:
        return cached[0]

    trades_url = f"{BINANCE_BASE_URL}/trades"
    params = {"symbol": symbol, "limit": min(limit, 1000)}

    for attempt in range(1, 4):
        try:
            resp = requests.get(trades_url, params=params, headers=_BINANCE_HEADERS, timeout=10)
            if resp.status_code == 400:
                logger.debug("Trades %s: symbol not on Binance (400) — skipping", symbol)
                _trades_cache[cache_key] = ([], now)
                return []
            resp.raise_for_status()
            raw = resp.json()
            trades = [
                {
                    "time": int(r["time"]),
                    "qty": float(r["qty"]),
                    "is_buyer_maker": bool(r["isBuyerMaker"]),
                }
                for r in raw
            ]
            _trades_cache[cache_key] = (trades, now)
            return trades
        except Exception as exc:
            wait = 2 ** (attempt - 1)
            logger.warning("Trades %s attempt %d: %s — retry in %ds", symbol, attempt, exc, wait)
            time.sleep(wait)

    logger.error("Trades %s: all retries failed", symbol)
    return []
