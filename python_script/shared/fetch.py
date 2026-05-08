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
    COINMARKETCAP_API_KEY,
    COINMARKETCAP_BASE_URL,
    COINMARKETCAP_TIMEOUT,
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
_CMC_HEADERS = {"X-CMC_PRO_API_KEY": COINMARKETCAP_API_KEY} if COINMARKETCAP_API_KEY else {}

_cache: dict = {"data": None, "ts": 0.0}
_spot_cache: dict = {"data": None, "ts": 0.0, "source": "none"}

# --- Layer 2 constants ---
STABLECOINS = {"usdt", "usdc", "dai", "busd", "tusd", "frax", "usdd", "usdp", "gusd", "lusd"}
WRAPPED_KW = ["wrapped", "wbtc", "weth", "steth", "reth", "cbeth"]
MIN_VOLUME = 1_000_000       # $1M
MIN_MARKET_CAP = 50_000_000  # $50M
MODEL4_MIN_MARKET_CAP = 100_000_000  # $100M
MODEL4_TOP_N = 10


def _is_stable_or_wrapped(coin: dict) -> bool:
    symbol = (coin.get("symbol") or "").lower()
    name = (coin.get("name") or "").lower()
    if symbol in STABLECOINS:
        return True
    return any(kw in name for kw in WRAPPED_KW)


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


def _normalize_cmc_coin(raw: dict) -> dict:
    quote = ((raw.get("quote") or {}).get("USD") or {})
    return {
        "id": str(raw.get("id") or ""),
        "symbol": (raw.get("symbol") or "").lower(),
        "name": raw.get("name") or "",
        "current_price": quote.get("price"),
        "market_cap": quote.get("market_cap"),
        "market_cap_rank": raw.get("cmc_rank"),
        "total_volume": quote.get("volume_24h"),
        "price_change_percentage_24h": quote.get("percent_change_24h"),
    }


def _fetch_spot_gainers_from_cmc() -> tuple[list[dict], str]:
    if not COINMARKETCAP_API_KEY:
        logger.warning("Model 4 source: COINMARKETCAP_API_KEY is missing, using CoinGecko fallback")
        return [], "coingecko-fallback"

    url = f"{COINMARKETCAP_BASE_URL}/v1/cryptocurrency/listings/latest"
    params = {
        "start": 1,
        "limit": 200,
        "sort": "market_cap",
        "convert": "USD",
    }

    for attempt in range(1, 4):
        try:
            resp = requests.get(url, params=params, headers=_CMC_HEADERS, timeout=COINMARKETCAP_TIMEOUT)
            if resp.status_code in (401, 403):
                logger.error("Model 4 source: CoinMarketCap auth failed (%d), using fallback", resp.status_code)
                return [], "coingecko-fallback"
            if resp.status_code == 429:
                logger.warning("Model 4 source: CoinMarketCap rate-limited, using fallback")
                return [], "coingecko-fallback"
            resp.raise_for_status()

            payload = resp.json() or {}
            items = (payload.get("data") or [])
            normalized = [_normalize_cmc_coin(item) for item in items]
            logger.info("Model 4 source: CoinMarketCap returned %d coins", len(normalized))
            return normalized, "coinmarketcap"
        except Exception as exc:
            wait = 2 ** (attempt - 1)
            logger.warning(
                "Model 4 source CMC attempt %d failed: %s — retrying in %ds",
                attempt,
                exc,
                wait,
            )
            time.sleep(wait)

    return [], "coingecko-fallback"


def _fetch_spot_gainers_from_coingecko() -> list[dict]:
    raw = fetch_market_data()
    if not raw:
        return []

    top_200 = [c for c in raw if (c.get("market_cap_rank") or 9999) <= 200]
    logger.info("Model 4 source fallback: CoinGecko top 200 universe size=%d", len(top_200))
    return top_200


def get_spot_gainers_universe() -> tuple[list[dict], str]:
    """
    Get Model 4 universe with CoinMarketCap primary and CoinGecko fallback.

    Returns:
        (coins, source)
            coins: normalized coin dicts compatible with model services.
            source: "coinmarketcap" | "coingecko-fallback" | "cache-stale"
    """
    now = time.time()
    if _spot_cache["data"] and (now - _spot_cache["ts"]) < CACHE_TTL:
        logger.debug("Model 4 source cache hit (%s)", _spot_cache.get("source"))
        return _spot_cache["data"], _spot_cache.get("source") or "cache-stale"

    coins, source = _fetch_spot_gainers_from_cmc()
    if not coins:
        coins = _fetch_spot_gainers_from_coingecko()
        source = "coingecko-fallback"

    if coins:
        _spot_cache["data"] = coins
        _spot_cache["ts"] = now
        _spot_cache["source"] = source
        return coins, source

    if _spot_cache["data"]:
        logger.warning("Model 4 source failed — serving stale universe cache")
        return _spot_cache["data"], "cache-stale"

    return [], source


def get_spot_gainers_candidates() -> tuple[list[dict], str]:
    """
    Build Model 4 candidate set from top-200 universe.

    Criteria:
      - Exclude stablecoins and wrapped tokens.
      - market_cap >= 100M
      - current_price and total_volume present
      - Sort by 24H % change descending and keep top 10.

    Returns:
      (candidates, source)
    """
    coins, source = get_spot_gainers_universe()
    filtered = []
    for coin in coins:
        market_cap = coin.get("market_cap") or 0
        price = coin.get("current_price")
        volume = coin.get("total_volume") or 0

        if _is_stable_or_wrapped(coin):
            continue
        if market_cap < MODEL4_MIN_MARKET_CAP:
            continue
        if not price or volume <= 0:
            continue
        filtered.append(coin)

    filtered.sort(key=lambda x: x.get("price_change_percentage_24h") or 0.0, reverse=True)
    top = filtered[:MODEL4_TOP_N]
    logger.info("Model 4 candidates: %d/%d after filter, source=%s", len(top), len(filtered), source)
    return top, source


def get_filtered_coins() -> list[dict]:
    """
    Convenience: Run Layer 1 + Layer 2 and return pre-filtered coins.
    Entry point for all model services.

    Returns:
        Pre-filtered coin list ready for model-specific Layer 3 filters.
    """
    raw = fetch_market_data()
    return pre_filter(raw)
