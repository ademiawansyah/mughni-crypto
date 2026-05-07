from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path
from typing import Dict, List


STABLECOINS = {"usdt", "usdc", "dai", "busd", "tusd", "frax", "usdd", "usdp", "gusd", "lusd"}
WRAPPED_KEYWORDS = ["wrapped", "wbtc", "weth", "steth", "reth", "cbeth"]


@dataclass(frozen=True)
class CacheTtl:
    layer1_market: int = 300
    layer2_prefilter: int = 300
    ohlcv: int = 60
    oi_funding: int = 120
    cvd: int = 300
    model_subset: int = 60


@dataclass(frozen=True)
class HttpConfig:
    timeout_seconds: int
    retry_count: int
    backoff_seconds: List[int]
    circuit_breaker_failures: int
    circuit_breaker_ttl_seconds: int


@dataclass(frozen=True)
class EndpointConfig:
    coingecko_base_url: str
    coingecko_api_key: str
    coinmarketcap_base_url: str
    coinmarketcap_api_key: str
    binance_base_url: str
    binance_futures_base_url: str


@dataclass(frozen=True)
class RuntimeConfig:
    output_dir: Path
    cache_backend: str
    redis_url: str
    force_refresh: bool


@dataclass(frozen=True)
class ModelConfig:
    name: str
    version: str
    l3_rank_min: int
    l3_rank_max: int
    l3_min_volume: float
    weights: Dict[str, float]
    top_n: int = 10


@dataclass(frozen=True)
class AppConfig:
    cache_ttl: CacheTtl
    http: HttpConfig
    endpoints: EndpointConfig
    runtime: RuntimeConfig
    model1: ModelConfig
    model2: ModelConfig
    model3: ModelConfig
    model4: ModelConfig


def _env_bool(name: str, default: bool) -> bool:
    value = os.getenv(name)
    if value is None:
        return default
    return value.strip().lower() in {"1", "true", "yes", "on"}


def _env_int(name: str, default: int) -> int:
    value = os.getenv(name)
    return int(value) if value else default


def _env_list_int(name: str, default: str) -> List[int]:
    value = os.getenv(name, default)
    return [int(part.strip()) for part in value.split(",") if part.strip()]


def load_config(force_refresh: bool = False) -> AppConfig:
    runtime_force_refresh = force_refresh or _env_bool("FORCE_REFRESH", False)
    output_dir = Path(os.getenv("OUTPUT_DIR", "./artifacts")).resolve()

    return AppConfig(
        cache_ttl=CacheTtl(),
        http=HttpConfig(
            timeout_seconds=_env_int("HTTP_TIMEOUT_SECONDS", 10),
            retry_count=_env_int("HTTP_RETRY_COUNT", 3),
            backoff_seconds=_env_list_int("HTTP_BACKOFF_SECONDS", "1,2,4"),
            circuit_breaker_failures=5,
            circuit_breaker_ttl_seconds=300,
        ),
        endpoints=EndpointConfig(
            coingecko_base_url=os.getenv("COINGECKO_BASE_URL", "https://api.coingecko.com/api/v3"),
            coingecko_api_key=os.getenv("COINGECKO_API_KEY", ""),
            coinmarketcap_base_url=os.getenv("COINMARKETCAP_BASE_URL", "https://pro-api.coinmarketcap.com"),
            coinmarketcap_api_key=os.getenv("COINMARKETCAP_API_KEY", ""),
            binance_base_url=os.getenv("BINANCE_BASE_URL", "https://api.binance.com"),
            binance_futures_base_url=os.getenv("BINANCE_FUTURES_BASE_URL", "https://fapi.binance.com"),
        ),
        runtime=RuntimeConfig(
            output_dir=output_dir,
            cache_backend=os.getenv("CACHE_BACKEND", "file").lower(),
            redis_url=os.getenv("REDIS_URL", "redis://127.0.0.1:6379/0"),
            force_refresh=runtime_force_refresh,
        ),
        model1=ModelConfig(
            name="counter_trend",
            version="2.0",
            l3_rank_min=50,
            l3_rank_max=300,
            l3_min_volume=5_000_000,
            weights={"liquidity_sweep": 0.40, "mss": 0.30, "fvg_ob": 0.15, "oi_decline": 0.08, "funding_extreme": 0.07},
        ),
        model2=ModelConfig(
            name="pre_pump",
            version="2.0",
            l3_rank_min=20,
            l3_rank_max=150,
            l3_min_volume=10_000_000,
            weights={"funding_persistent": 0.35, "oi_sideways": 0.25, "atr_compression": 0.20, "cvd_divergence": 0.12, "rsi_compression": 0.08},
        ),
        model3=ModelConfig(
            name="trend_momentum",
            version="2.0",
            l3_rank_min=1,
            l3_rank_max=50,
            l3_min_volume=50_000_000,
            weights={"ema_gate": 0.30, "macd_zone": 0.25, "rsi_zone": 0.20, "bos": 0.15, "oi_cvd": 0.10},
        ),
        model4=ModelConfig(
            name="spot_momentum_gainers",
            version="2.0",
            l3_rank_min=1,
            l3_rank_max=200,
            l3_min_volume=0,
            weights={"change_24h": 0.40, "volume_ratio": 0.35, "body_ratio": 0.25},
        ),
    )
