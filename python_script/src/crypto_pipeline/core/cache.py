from __future__ import annotations

import json
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Optional

try:
    import redis  # type: ignore
except ImportError:  # pragma: no cover - optional dependency
    redis = None


@dataclass
class CacheValue:
    value: Any
    expires_at: float


class CacheAdapter:
    def __init__(self, backend: str, redis_url: str, base_dir: Path) -> None:
        self.backend = backend
        self.base_dir = base_dir / "cache"
        self.base_dir.mkdir(parents=True, exist_ok=True)
        self.redis_client: Optional[Any] = None
        if backend == "redis" and redis is not None:
            self.redis_client = redis.from_url(redis_url, decode_responses=True)

    def _path_for_key(self, key: str) -> Path:
        safe_key = key.replace(":", "__").replace("/", "_")
        return self.base_dir / f"{safe_key}.json"

    def get(self, key: str, allow_stale: bool = False) -> Any:
        if self.redis_client is not None:
            raw = self.redis_client.get(key)
            if raw is None:
                return None
            parsed = json.loads(raw)
            if not allow_stale and parsed["expires_at"] < time.time():
                return None
            return parsed["value"]

        path = self._path_for_key(key)
        if not path.exists():
            return None
        parsed = json.loads(path.read_text(encoding="utf-8"))
        if not allow_stale and parsed["expires_at"] < time.time():
            return None
        return parsed["value"]

    def set(self, key: str, value: Any, ttl_seconds: int) -> None:
        payload = {"value": value, "expires_at": time.time() + ttl_seconds}
        if self.redis_client is not None:
            self.redis_client.set(key, json.dumps(payload), ex=ttl_seconds + 60)
            return
        path = self._path_for_key(key)
        path.write_text(json.dumps(payload), encoding="utf-8")
