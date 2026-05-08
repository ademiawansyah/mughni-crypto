"""
Open Interest and Funding Rate fetcher using Coinalyze API.
Used as confirmation signals in Model 1 and 2.

Coinalyze uses aggregated perpetual futures data across exchanges.
Symbol format: "<SYMBOL>_PERP.6" for aggregated (e.g. "BTCUSDT_PERP.6").

API key is passed as `api_key` query parameter.
Cached in-memory: OI for 120s, Funding for 120s.
"""

import time
import logging
import requests

from shared.config import COINALYZE_BASE_URL, COINALYZE_API_KEY

logger = logging.getLogger(__name__)

OI_TTL = 120       # seconds
FUNDING_TTL = 120  # seconds
CIRCUIT_BREAKER_WINDOW = 300  # 5 minutes
CIRCUIT_BREAKER_THRESHOLD = 5

_oi_cache: dict = {}       # key: symbol → (data, timestamp)
_funding_cache: dict = {}  # key: symbol → (data, timestamp)

_coinalyze_fail_count = 0
_coinalyze_block_until = 0.0


def _coinalyze_circuit_open() -> bool:
    return time.time() < _coinalyze_block_until


def _coinalyze_register_failure() -> None:
    global _coinalyze_fail_count, _coinalyze_block_until
    _coinalyze_fail_count += 1
    if _coinalyze_fail_count >= CIRCUIT_BREAKER_THRESHOLD:
        _coinalyze_block_until = time.time() + CIRCUIT_BREAKER_WINDOW
        logger.warning(
            "Coinalyze circuit opened for %ds after %d consecutive failures",
            CIRCUIT_BREAKER_WINDOW,
            _coinalyze_fail_count,
        )


def _coinalyze_reset_failure() -> None:
    global _coinalyze_fail_count
    _coinalyze_fail_count = 0


def _to_coinalyze_symbol(binance_symbol: str) -> str:
    """
    Convert Binance USDT-margined symbol to Coinalyze aggregated perp format.
    e.g. "BTCUSDT" → "BTCUSD_PERP.A"
    Strips the trailing 'T' from USDT pairs; .A = aggregated across exchanges.
    """
    base = binance_symbol.removesuffix("USDT")
    return f"{base}USD_PERP.A"


def fetch_open_interest(symbol: str, interval: str = "1hour", limit: int = 24) -> list[dict]:
    """
    Fetch Open Interest history from Coinalyze for an aggregated perpetual.
    Cached per symbol for 120 seconds.

    Args:
        symbol:   Binance-style trading pair, e.g. "BTCUSDT".
        interval: Candle interval — "1min","5min","15min","30min","1hour","4hour","daily".
        limit:    Number of past data points (converted to a `from` timestamp).

    Returns:
        List of {timestamp, open_interest} sorted oldest-first.
        Empty list if symbol has no data or on failure.
    """
    now = time.time()
    cached = _oi_cache.get(symbol)
    if cached and (now - cached[1]) < OI_TTL:
        return cached[0]

    if not COINALYZE_API_KEY:
        logger.warning("COINALYZE_API_KEY not set — skipping OI fetch for %s", symbol)
        return []

    if _coinalyze_circuit_open():
        logger.warning("Coinalyze circuit open — skipping OI fetch for %s", symbol)
        return []

    # Convert interval string to seconds to derive `from` timestamp
    interval_seconds = {"1min": 60, "5min": 300, "15min": 900, "30min": 1800,
                        "1hour": 3600, "4hour": 14400, "daily": 86400}
    secs = interval_seconds.get(interval, 3600)
    ts_from = int(now) - secs * limit
    ts_to = int(now)

    coinalyze_symbol = _to_coinalyze_symbol(symbol)
    url = f"{COINALYZE_BASE_URL}/open-interest-history"
    params = {
        "symbols": coinalyze_symbol,
        "interval": interval,
        "from": ts_from,
        "to": ts_to,
        "api_key": COINALYZE_API_KEY,
    }

    for attempt in range(1, 4):
        try:
            resp = requests.get(url, params=params, timeout=10)
            if resp.status_code in (400, 404):
                logger.debug("OI %s: Coinalyze %d — symbol not available", symbol, resp.status_code)
                _oi_cache[symbol] = ([], now)
                return []
            if resp.status_code == 429:
                _coinalyze_register_failure()
                wait = 2 ** (attempt - 1)
                logger.warning("OI %s attempt %d: Coinalyze 429 — retry in %ds", symbol, attempt, wait)
                time.sleep(wait)
                continue
            resp.raise_for_status()
            data = resp.json()

            # Each entry: {"symbol": ..., "history": [{t, o, h, l, c}, ...]}
            # c = close = current OI value for that candle
            items = []
            for entry in data:
                for candle in entry.get("history", []):
                    items.append({
                        "timestamp": candle["t"],
                        "open_interest": float(candle["c"]),
                    })

            items.sort(key=lambda x: x["timestamp"])
            _oi_cache[symbol] = (items, now)
            _coinalyze_reset_failure()
            logger.debug("OI %s: fetched %d data points", symbol, len(items))
            return items

        except Exception as exc:
            wait = 2 ** (attempt - 1)
            _coinalyze_register_failure()
            logger.warning("OI %s attempt %d: %s — retry in %ds", symbol, attempt, exc, wait)
            time.sleep(wait)

    logger.error("OI %s: all retries failed", symbol)
    _oi_cache[symbol] = ([], now)
    return []


def fetch_funding_rate(symbol: str, limit: int = 10, interval: str = "daily") -> list[dict]:
    """
    Fetch recent funding rate history from Coinalyze for an aggregated perpetual.
    Uses the requested Coinalyze interval; `limit` periods back from now.
    Cached per symbol for 120 seconds.

    Args:
        symbol: Binance-style trading pair, e.g. "BTCUSDT".
        limit:    Number of past funding periods to fetch.
        interval: Coinalyze interval, e.g. "1hour", "4hour", "daily".

    Returns:
        List of {timestamp, funding_rate} sorted oldest-first.
        Empty list if symbol has no data or on failure.
    """
    now = time.time()
    cached = _funding_cache.get(symbol)
    if cached and (now - cached[1]) < FUNDING_TTL:
        return cached[0]

    if not COINALYZE_API_KEY:
        logger.warning("COINALYZE_API_KEY not set — skipping funding fetch for %s", symbol)
        return []

    if _coinalyze_circuit_open():
        logger.warning("Coinalyze circuit open — skipping funding fetch for %s", symbol)
        return []

    interval_seconds = {
        "1min": 60,
        "5min": 300,
        "15min": 900,
        "30min": 1800,
        "1hour": 3600,
        "4hour": 14400,
        "daily": 86400,
    }
    secs = interval_seconds.get(interval, 86400)
    ts_from = int(now) - secs * limit
    ts_to = int(now)

    coinalyze_symbol = _to_coinalyze_symbol(symbol)
    url = f"{COINALYZE_BASE_URL}/funding-rate-history"
    params = {
        "symbols": coinalyze_symbol,
        "interval": interval,
        "from": ts_from,
        "to": ts_to,
        "api_key": COINALYZE_API_KEY,
    }

    for attempt in range(1, 4):
        try:
            resp = requests.get(url, params=params, timeout=10)
            if resp.status_code in (400, 404):
                logger.debug("Funding %s: Coinalyze %d — symbol not available", symbol, resp.status_code)
                _funding_cache[symbol] = ([], now)
                return []
            if resp.status_code == 429:
                _coinalyze_register_failure()
                wait = 2 ** (attempt - 1)
                logger.warning("Funding %s attempt %d: Coinalyze 429 — retry in %ds", symbol, attempt, wait)
                time.sleep(wait)
                continue
            resp.raise_for_status()
            data = resp.json()

            # Each history entry: {t, o, h, l, c} — c = latest funding rate for that period
            items = []
            for entry in data:
                for point in entry.get("history", []):
                    items.append({
                        "timestamp": point["t"],
                        "funding_rate": float(point["c"]),
                    })

            items.sort(key=lambda x: x["timestamp"])
            _funding_cache[symbol] = (items, now)
            _coinalyze_reset_failure()
            logger.debug("Funding %s: fetched %d data points", symbol, len(items))
            return items

        except Exception as exc:
            wait = 2 ** (attempt - 1)
            _coinalyze_register_failure()
            logger.warning("Funding %s attempt %d: %s — retry in %ds", symbol, attempt, exc, wait)
            time.sleep(wait)

    logger.error("Funding %s: all retries failed", symbol)
    _funding_cache[symbol] = ([], now)
    return []
