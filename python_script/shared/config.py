"""
Loads configuration from .env file in the project root.
All other shared modules import from here — no direct os.getenv calls elsewhere.
"""

import os
from pathlib import Path


def _load_env(path: Path) -> None:
    """Minimal .env loader — sets os.environ for KEY=VALUE lines."""
    if not path.exists():
        return
    with open(path) as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, _, value = line.partition("=")
            os.environ.setdefault(key.strip(), value.strip())


# Load .env from project root (two levels up from this file: shared/ → root)
_load_env(Path(__file__).parent.parent / ".env")


def _require(key: str) -> str:
    val = os.getenv(key)
    if not val:
        raise EnvironmentError(f"Required env variable '{key}' is not set. Check your .env file.")
    return val


def _get(key: str, default: str = "") -> str:
    return os.getenv(key, default)


# CoinGecko
COINGECKO_BASE_URL: str = _get("COINGECKO_BASE_URL", "https://api.coingecko.com/api/v3")
COINGECKO_API_KEY: str = _get("COINGECKO_API_KEY")
COINGECKO_TIMEOUT: int = int(_get("COINGECKO_TIMEOUT", "10"))
COINGECKO_VS_CURRENCY: str = _get("COINGECKO_VS_CURRENCY", "usd")

# Binance (futures)
BINANCE_BASE_URL: str = _get("BINANCE_BASE_URL", "https://fapi.binance.com/fapi/v1")
BINANCE_API_KEY: str = _get("BINANCE_API_KEY")

# Coinalyze
COINALYZE_BASE_URL: str = _get("COINALYZE_BASE_URL", "https://api.coinalyze.net/v1")
COINALYZE_API_KEY: str = _get("COINALYZE_API_KEY")
