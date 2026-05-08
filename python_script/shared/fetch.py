"""
Layer 1: Shared market data fetcher from CoinGecko.
Layer 2: Shared pre-filter — removes stablecoins, wrapped tokens, low volume/cap.

All models call get_filtered_coins() to get the pre-filtered coin list.
Result is cached in-memory for 5 minutes to avoid redundant API calls.
"""

import time
import logging
import requests

from shared.config import (
    COINGECKO_BASE_URL,
    COINGECKO_API_KEY,
    COINGECKO_TIMEOUT,
    COINGECKO_VS_CURRENCY,
)

logger = logging.getLogger(__name__)

# --- Layer 1 ---
CACHE_TTL = 300  # 5 minutes

# CoinGecko Demo key uses x-cg-demo-api-key header.
# Pro key uses x-cg-pro-api-key — change header name if you upgrade.
_CG_HEADERS = {"x-cg-demo-api-key": COINGECKO_API_KEY} if COINGECKO_API_KEY else {}

_cache: dict = {"data": None, "ts": 0.0}

# --- Layer 2 constants ---
STABLECOINS = {"usdt", "usdc", "dai", "busd", "tusd", "frax", "usdd", "usdp", "gusd", "lusd"}
WRAPPED_KW = ["wrapped", "wbtc", "weth", "steth", "reth", "cbeth"]
MIN_VOLUME = 1_000_000       # $1M
MIN_MARKET_CAP = 50_000_000  # $50M


def fetch_market_data() -> list[dict]:
    """
    Layer 1: Fetch top 300 coins by market cap from CoinGecko.
    CoinGecko free tier caps per_page at 100, so we fetch 3 pages.
    Result is cached for 5 minutes. Falls back to stale cache on failure.

    Returns:
        List of raw coin dicts from CoinGecko /coins/markets.
    """
    now = time.time()
    if _cache["data"] and (now - _cache["ts"]) < CACHE_TTL:
        logger.debug("Layer 1 cache hit — skipping API call")
        return _cache["data"]

    all_coins: list[dict] = []

    for page in range(1, 4):  # pages 1, 2, 3 → 300 coins
        params = {
            "vs_currency": COINGECKO_VS_CURRENCY,
            "order": "market_cap_desc",
            "per_page": 100,
            "page": page,
            "sparkline": False,
            "price_change_percentage": "24h",
        }
        url = f"{COINGECKO_BASE_URL}/coins/markets"
        for attempt in range(1, 4):
            try:
                resp = requests.get(url, params=params, headers=_CG_HEADERS, timeout=COINGECKO_TIMEOUT)
                resp.raise_for_status()
                batch = resp.json()
                all_coins.extend(batch)
                logger.debug("Layer 1: page %d fetched %d coins", page, len(batch))
                break
            except Exception as exc:
                wait = 2 ** (attempt - 1)
                logger.warning(
                    "Layer 1 page %d attempt %d failed: %s — retrying in %ds",
                    page, attempt, exc, wait,
                )
                time.sleep(wait)
        else:
            logger.error("Layer 1: page %d failed after all retries", page)

    if all_coins:
        _cache["data"] = all_coins
        _cache["ts"] = now
        logger.info("Layer 1: fetched %d coins from CoinGecko (3 pages)", len(all_coins))
    else:
        logger.error("Layer 1: all pages failed — returning stale cache if available")

    return _cache["data"] or []


def pre_filter(coins: list[dict]) -> list[dict]:
    """
    Layer 2: Apply universal pre-filters to raw coin list.
    Removes stablecoins, wrapped tokens, and coins below min volume/market cap.

    Args:
        coins: Raw list from Layer 1 (fetch_market_data).

    Returns:
        Filtered list (~150–200 coins).
    """
    result = []
    for coin in coins:
        symbol = (coin.get("symbol") or "").lower()
        name = (coin.get("name") or "").lower()
        volume = coin.get("total_volume") or 0
        market_cap = coin.get("market_cap") or 0
        price = coin.get("current_price")

        if symbol in STABLECOINS:
            continue
        if any(kw in name for kw in WRAPPED_KW):
            continue
        if volume < MIN_VOLUME:
            continue
        if market_cap < MIN_MARKET_CAP:
            continue
        if not price:
            continue

        result.append(coin)

    logger.info("Layer 2: %d/%d coins passed pre-filter", len(result), len(coins))
    return result


def get_filtered_coins() -> list[dict]:
    """
    Convenience: Run Layer 1 + Layer 2 and return pre-filtered coins.
    Entry point for all model services.

    Returns:
        Pre-filtered coin list ready for model-specific Layer 3 filters.
    """
    raw = fetch_market_data()
    return pre_filter(raw)
