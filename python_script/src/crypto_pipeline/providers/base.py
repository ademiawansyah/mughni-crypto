from __future__ import annotations

from datetime import datetime, timezone
from typing import Any

from crypto_pipeline.core.cache import CacheAdapter
from crypto_pipeline.core.http_client import CallLedgerEntry


class CachedProvider:
    def __init__(self, cache: CacheAdapter, force_refresh: bool, ledger_add) -> None:
        self.cache = cache
        self.force_refresh = force_refresh
        self.ledger_add = ledger_add

    def _record_cache_hit(self, provider: str, endpoint: str) -> None:
        self.ledger_add(
            CallLedgerEntry(
                provider=provider,
                endpoint=endpoint,
                status_code=200,
                latency_ms=0,
                cache_status="hit",
                timestamp=datetime.now(timezone.utc).isoformat(),
            )
        )

    def _get_or_fetch(self, *, cache_key: str, ttl_seconds: int, provider: str, endpoint: str, fetcher) -> Any:
        if not self.force_refresh:
            cached = self.cache.get(cache_key)
            if cached is not None:
                self._record_cache_hit(provider, endpoint)
                return cached

        try:
            response = fetcher()
            self.cache.set(cache_key, response, ttl_seconds)
            return response
        except Exception:
            stale = self.cache.get(cache_key, allow_stale=True)
            if stale is not None:
                return stale
            raise
