from __future__ import annotations

from typing import Any

from crypto_pipeline.core.config import AppConfig
from crypto_pipeline.core.http_client import HttpClient
from crypto_pipeline.providers.base import CachedProvider


class BinanceProvider(CachedProvider):
    def __init__(self, config: AppConfig, http_client: HttpClient, cache, ledger_add) -> None:
        super().__init__(cache=cache, force_refresh=config.runtime.force_refresh, ledger_add=ledger_add)
        self.config = config
        self.http = http_client

    def get_klines(self, symbol: str, interval: str, limit: int) -> list[list[Any]]:
        endpoint = f"{self.config.endpoints.binance_base_url}/api/v3/klines"
        params = {"symbol": symbol.upper(), "interval": interval, "limit": limit}
        cache_key = f"ohlcv:binance:{symbol.lower()}:{interval}:{limit}"

        return self._get_or_fetch(
            cache_key=cache_key,
            ttl_seconds=self.config.cache_ttl.ohlcv,
            provider="binance",
            endpoint=endpoint,
            fetcher=lambda: self.http.request_json("binance", "GET", endpoint, params=params),
        )

    def get_trades(self, symbol: str, limit: int = 1000) -> list[dict[str, Any]]:
        endpoint = f"{self.config.endpoints.binance_base_url}/api/v3/trades"
        params = {"symbol": symbol.upper(), "limit": limit}
        cache_key = f"trades:binance:{symbol.lower()}:{limit}"

        return self._get_or_fetch(
            cache_key=cache_key,
            ttl_seconds=self.config.cache_ttl.cvd,
            provider="binance",
            endpoint=endpoint,
            fetcher=lambda: self.http.request_json("binance", "GET", endpoint, params=params),
        )

    def get_funding_rate(self, symbol: str, limit: int = 10) -> list[dict[str, Any]]:
        endpoint = f"{self.config.endpoints.binance_futures_base_url}/fapi/v1/fundingRate"
        params = {"symbol": symbol.upper(), "limit": limit}
        cache_key = f"funding:binance:{symbol.lower()}:{limit}"

        return self._get_or_fetch(
            cache_key=cache_key,
            ttl_seconds=self.config.cache_ttl.oi_funding,
            provider="binance_futures",
            endpoint=endpoint,
            fetcher=lambda: self.http.request_json("binance_futures", "GET", endpoint, params=params),
        )

    def get_open_interest_hist(self, symbol: str, period: str = "4h", limit: int = 30) -> list[dict[str, Any]]:
        endpoint = f"{self.config.endpoints.binance_futures_base_url}/futures/data/openInterestHist"
        params = {"symbol": symbol.upper(), "period": period, "limit": limit}
        cache_key = f"oi:binance:{symbol.lower()}:{period}:{limit}"

        return self._get_or_fetch(
            cache_key=cache_key,
            ttl_seconds=self.config.cache_ttl.oi_funding,
            provider="binance_futures",
            endpoint=endpoint,
            fetcher=lambda: self.http.request_json("binance_futures", "GET", endpoint, params=params),
        )
