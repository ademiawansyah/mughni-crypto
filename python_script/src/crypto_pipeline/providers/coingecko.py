from __future__ import annotations

from typing import Any

from crypto_pipeline.core.config import AppConfig
from crypto_pipeline.core.http_client import HttpClient
from crypto_pipeline.providers.base import CachedProvider


class CoinGeckoProvider(CachedProvider):
    def __init__(self, config: AppConfig, http_client: HttpClient, cache, ledger_add) -> None:
        super().__init__(cache=cache, force_refresh=config.runtime.force_refresh, ledger_add=ledger_add)
        self.config = config
        self.http = http_client

    def get_markets_top_300(self) -> list[dict[str, Any]]:
        endpoint = f"{self.config.endpoints.coingecko_base_url}/coins/markets"
        params = {
            "vs_currency": "usd",
            "order": "market_cap_desc",
            "per_page": 300,
            "page": 1,
            "sparkline": False,
            "price_change_percentage": "24h",
        }
        headers = {}
        if self.config.endpoints.coingecko_api_key:
            headers["x-cg-demo-api-key"] = self.config.endpoints.coingecko_api_key

        return self._get_or_fetch(
            cache_key="layer1:coingecko:markets:300",
            ttl_seconds=self.config.cache_ttl.layer1_market,
            provider="coingecko",
            endpoint=endpoint,
            fetcher=lambda: self.http.request_json("coingecko", "GET", endpoint, headers=headers, params=params),
        )

    def get_ohlc(self, coin_id: str, vs_currency: str = "usd", days: int = 7) -> list[list[float]]:
        endpoint = f"{self.config.endpoints.coingecko_base_url}/coins/{coin_id}/ohlc"
        params = {"vs_currency": vs_currency, "days": days}
        headers = {}
        if self.config.endpoints.coingecko_api_key:
            headers["x-cg-demo-api-key"] = self.config.endpoints.coingecko_api_key

        return self._get_or_fetch(
            cache_key=f"ohlcv:coingecko:{coin_id}:{days}",
            ttl_seconds=self.config.cache_ttl.ohlcv,
            provider="coingecko",
            endpoint=endpoint,
            fetcher=lambda: self.http.request_json("coingecko", "GET", endpoint, headers=headers, params=params),
        )

    def get_markets_percent_change(self, per_page: int = 200) -> list[dict[str, Any]]:
        endpoint = f"{self.config.endpoints.coingecko_base_url}/coins/markets"
        params = {
            "vs_currency": "usd",
            "order": "percent_change_24h_desc",
            "per_page": per_page,
            "page": 1,
            "sparkline": False,
            "price_change_percentage": "24h",
        }
        headers = {}
        if self.config.endpoints.coingecko_api_key:
            headers["x-cg-demo-api-key"] = self.config.endpoints.coingecko_api_key

        return self._get_or_fetch(
            cache_key=f"markets:coingecko:percent_change:{per_page}",
            ttl_seconds=self.config.cache_ttl.layer1_market,
            provider="coingecko",
            endpoint=endpoint,
            fetcher=lambda: self.http.request_json("coingecko", "GET", endpoint, headers=headers, params=params),
        )
