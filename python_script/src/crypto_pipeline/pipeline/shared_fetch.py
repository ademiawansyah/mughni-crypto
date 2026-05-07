from __future__ import annotations

from typing import Any

from crypto_pipeline.core.config import STABLECOINS, WRAPPED_KEYWORDS
from crypto_pipeline.core.execution import ExecutionContext
from crypto_pipeline.providers.coingecko import CoinGeckoProvider


def run_layer1_layer2(execution: ExecutionContext, coingecko: CoinGeckoProvider) -> list[dict[str, Any]]:
    raw_market = coingecko.get_markets_top_300()
    execution.write_json("layer1/raw_coingecko_markets.json", {"execution_id": execution.execution_id, "raw": raw_market})

    filtered = []
    prefilter_audit = []
    for coin in raw_market:
        passed, reason = apply_prefilter(coin)
        prefilter_audit.append({
            "symbol": coin.get("symbol", "").upper(),
            "coin_id": coin.get("id"),
            "passed": passed,
            "reason": reason,
        })
        if passed:
            filtered.append(coin)

    execution.write_json("layer2/prefilter_audit.json", {"execution_id": execution.execution_id, "audit": prefilter_audit})
    execution.write_json("layer2/prefilter_passed.json", {"execution_id": execution.execution_id, "coins": filtered})
    return filtered


def apply_prefilter(coin: dict[str, Any]) -> tuple[bool, str]:
    symbol = str(coin.get("symbol", "")).lower()
    name = str(coin.get("name", "")).lower()
    market_cap = float(coin.get("market_cap") or 0)
    volume = float(coin.get("total_volume") or 0)
    price = float(coin.get("current_price") or 0)

    if symbol in STABLECOINS:
        return False, "stablecoin"
    if any(token in name for token in WRAPPED_KEYWORDS):
        return False, "wrapped_token"
    if volume < 1_000_000:
        return False, "low_volume"
    if market_cap < 50_000_000:
        return False, "low_market_cap"
    if volume <= 0 or price <= 0:
        return False, "missing_price_or_volume"

    return True, "passed"
