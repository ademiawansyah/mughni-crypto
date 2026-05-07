from __future__ import annotations

from typing import Any

from crypto_pipeline.core.config import AppConfig
from crypto_pipeline.core.http_client import HttpClient
from crypto_pipeline.providers.base import CachedProvider


class CoinMarketCapProvider(CachedProvider):
    def __init__(self, config: AppConfig, http_client: HttpClient, cache, ledger_add) -> None:
        super().__init__(cache=cache, force_refresh=config.runtime.force_refresh, ledger_add=ledger_add)
        self.config = config
        self.http = http_client

    def get_top_200(self) -> list[dict[str, Any]]:
        endpoint = f"{self.config.endpoints.coinmarketcap_base_url}/v1/cryptocurrency/listings/latest"
        headers = {}
        if self.config.endpoints.coinmarketcap_api_key:
            headers["X-CMC_PRO_API_KEY"] = self.config.endpoints.coinmarketcap_api_key

        params = {"limit": 200, "sort": "market_cap", "convert": "USD"}

        payload = self._get_or_fetch(
            cache_key="markets:cmc:top200",
            ttl_seconds=self.config.cache_ttl.layer1_market,
            provider="coinmarketcap",
            endpoint=endpoint,
            fetcher=lambda: self.http.request_json("coinmarketcap", "GET", endpoint, headers=headers, params=params),
        )
        return payload.get("data", [])
