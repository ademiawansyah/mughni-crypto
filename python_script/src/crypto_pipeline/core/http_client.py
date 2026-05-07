from __future__ import annotations

import time
from collections import defaultdict
from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Any, Callable, Dict, Optional

import requests


@dataclass
class CallLedgerEntry:
    provider: str
    endpoint: str
    status_code: int
    latency_ms: int
    cache_status: str
    timestamp: str


class CircuitOpenError(RuntimeError):
    pass


class HttpClient:
    def __init__(
        self,
        timeout_seconds: int,
        retry_count: int,
        backoff_seconds: list[int],
        breaker_failures: int,
        breaker_ttl_seconds: int,
        ledger_callback: Optional[Callable[[CallLedgerEntry], None]] = None,
    ) -> None:
        self.timeout_seconds = timeout_seconds
        self.retry_count = retry_count
        self.backoff_seconds = backoff_seconds
        self.breaker_failures = breaker_failures
        self.breaker_ttl_seconds = breaker_ttl_seconds
        self.ledger_callback = ledger_callback
        self._failures: Dict[str, int] = defaultdict(int)
        self._open_until: Dict[str, float] = {}

    def _ensure_circuit_closed(self, provider: str) -> None:
        open_until = self._open_until.get(provider)
        if open_until and open_until > time.time():
            raise CircuitOpenError(f"Circuit open for provider: {provider}")
        if open_until and open_until <= time.time():
            self._open_until.pop(provider, None)
            self._failures[provider] = 0

    def request_json(
        self,
        provider: str,
        method: str,
        url: str,
        headers: Optional[dict[str, str]] = None,
        params: Optional[dict[str, Any]] = None,
    ) -> Any:
        self._ensure_circuit_closed(provider)

        last_error: Optional[Exception] = None
        for attempt in range(self.retry_count):
            started = time.time()
            try:
                response = requests.request(
                    method=method,
                    url=url,
                    headers=headers,
                    params=params,
                    timeout=self.timeout_seconds,
                )
                latency_ms = int((time.time() - started) * 1000)
                if self.ledger_callback:
                    self.ledger_callback(
                        CallLedgerEntry(
                            provider=provider,
                            endpoint=url,
                            status_code=response.status_code,
                            latency_ms=latency_ms,
                            cache_status="refresh",
                            timestamp=datetime.now(timezone.utc).isoformat(),
                        )
                    )
                # 4xx errors mean a bad request (e.g. invalid trading pair) — not a
                # provider outage. Raise immediately without retrying or counting
                # toward the circuit breaker.
                if 400 <= response.status_code < 500:
                    response.raise_for_status()
                response.raise_for_status()
                self._failures[provider] = 0
                return response.json()
            except requests.exceptions.HTTPError as exc:
                last_error = exc
                # Only 5xx or connection-level errors should count toward the breaker.
                status = exc.response.status_code if exc.response is not None else 500
                if status >= 500:
                    self._failures[provider] += 1
                    if self._failures[provider] >= self.breaker_failures:
                        self._open_until[provider] = time.time() + self.breaker_ttl_seconds
                if status >= 400 or attempt == self.retry_count - 1:
                    break  # no point retrying 4xx; raise after loop
                sleep_seconds = self.backoff_seconds[min(attempt, len(self.backoff_seconds) - 1)]
                time.sleep(sleep_seconds)
            except Exception as exc:
                last_error = exc
                self._failures[provider] += 1
                if self._failures[provider] >= self.breaker_failures:
                    self._open_until[provider] = time.time() + self.breaker_ttl_seconds
                if attempt < self.retry_count - 1:
                    sleep_seconds = self.backoff_seconds[min(attempt, len(self.backoff_seconds) - 1)]
                    time.sleep(sleep_seconds)

        raise RuntimeError(f"HTTP request failed after retries: provider={provider}, url={url}, error={last_error}")
