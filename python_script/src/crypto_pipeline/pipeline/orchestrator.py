from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
from typing import Optional

from crypto_pipeline.core.cache import CacheAdapter
from crypto_pipeline.core.config import AppConfig, load_config
from crypto_pipeline.core.execution import ExecutionContext, new_execution
from crypto_pipeline.core.http_client import HttpClient
from crypto_pipeline.core.schema import build_output
from crypto_pipeline.models.model_counter_trend import CounterTrendModel
from crypto_pipeline.models.model_pre_pump import PrePumpModel
from crypto_pipeline.models.model_spot_gainers import SpotMomentumGainersModel
from crypto_pipeline.models.model_trend_momentum import TrendMomentumModel
from crypto_pipeline.output.ledger import EndpointCallLedger
from crypto_pipeline.output.writer import write_call_ledger, write_model_output
from crypto_pipeline.pipeline.shared_fetch import run_layer1_layer2
from crypto_pipeline.providers.binance import BinanceProvider
from crypto_pipeline.providers.coingecko import CoinGeckoProvider
from crypto_pipeline.providers.coinmarketcap import CoinMarketCapProvider


@dataclass
class RuntimeServices:
    config: AppConfig
    execution: ExecutionContext
    ledger: EndpointCallLedger
    coingecko: CoinGeckoProvider
    binance: BinanceProvider
    cmc: CoinMarketCapProvider


def build_runtime(force_refresh: bool = False) -> RuntimeServices:
    config = load_config(force_refresh=force_refresh)
    execution = new_execution(config.runtime.output_dir)
    ledger = EndpointCallLedger()

    cache = CacheAdapter(
        backend=config.runtime.cache_backend,
        redis_url=config.runtime.redis_url,
        base_dir=config.runtime.output_dir,
    )

    http_client = HttpClient(
        timeout_seconds=config.http.timeout_seconds,
        retry_count=config.http.retry_count,
        backoff_seconds=config.http.backoff_seconds,
        breaker_failures=config.http.circuit_breaker_failures,
        breaker_ttl_seconds=config.http.circuit_breaker_ttl_seconds,
        ledger_callback=ledger.add,
    )

    return RuntimeServices(
        config=config,
        execution=execution,
        ledger=ledger,
        coingecko=CoinGeckoProvider(config=config, http_client=http_client, cache=cache, ledger_add=ledger.add),
        binance=BinanceProvider(config=config, http_client=http_client, cache=cache, ledger_add=ledger.add),
        cmc=CoinMarketCapProvider(config=config, http_client=http_client, cache=cache, ledger_add=ledger.add),
    )


def run_fetch_service(runtime: RuntimeServices) -> list[dict]:
    coins = run_layer1_layer2(runtime.execution, runtime.coingecko)
    write_call_ledger(runtime.execution, runtime.ledger.to_json())
    return coins


def run_model1(runtime: RuntimeServices, prefiltered_coins: Optional[list[dict]] = None) -> dict:
    coins = prefiltered_coins if prefiltered_coins is not None else run_fetch_service(runtime)
    model = CounterTrendModel(runtime.config, runtime.binance)
    results = model.run(coins)
    payload = build_output(runtime.config.model1.name, runtime.config.model1.version, runtime.execution.iso_timestamp, runtime.execution.execution_date, results)
    write_model_output(runtime.execution, runtime.config.model1.name, payload)
    write_call_ledger(runtime.execution, runtime.ledger.to_json())
    return payload


def run_model2(runtime: RuntimeServices, prefiltered_coins: Optional[list[dict]] = None) -> dict:
    coins = prefiltered_coins if prefiltered_coins is not None else run_fetch_service(runtime)
    model = PrePumpModel(runtime.config, runtime.binance)
    results = model.run(coins)
    payload = build_output(runtime.config.model2.name, runtime.config.model2.version, runtime.execution.iso_timestamp, runtime.execution.execution_date, results)
    write_model_output(runtime.execution, runtime.config.model2.name, payload)
    write_call_ledger(runtime.execution, runtime.ledger.to_json())
    return payload


def run_model3(runtime: RuntimeServices, prefiltered_coins: Optional[list[dict]] = None) -> dict:
    coins = prefiltered_coins if prefiltered_coins is not None else run_fetch_service(runtime)
    model = TrendMomentumModel(runtime.config, runtime.binance)
    results = model.run(coins)
    payload = build_output(runtime.config.model3.name, runtime.config.model3.version, runtime.execution.iso_timestamp, runtime.execution.execution_date, results)
    write_model_output(runtime.execution, runtime.config.model3.name, payload)
    write_call_ledger(runtime.execution, runtime.ledger.to_json())
    return payload


def run_model4(runtime: RuntimeServices) -> dict:
    model = SpotMomentumGainersModel(runtime.config, runtime.binance, runtime.cmc, runtime.coingecko)
    results = model.run()
    payload = build_output(runtime.config.model4.name, runtime.config.model4.version, runtime.execution.iso_timestamp, runtime.execution.execution_date, results)
    write_model_output(runtime.execution, runtime.config.model4.name, payload)
    write_call_ledger(runtime.execution, runtime.ledger.to_json())
    return payload


def run_all(force_refresh: bool = False) -> Path:
    runtime = build_runtime(force_refresh=force_refresh)
    coins = run_fetch_service(runtime)
    run_model1(runtime, coins)
    run_model2(runtime, coins)
    run_model3(runtime, coins)
    run_model4(runtime)
    write_call_ledger(runtime.execution, runtime.ledger.to_json())
    return runtime.execution.artifact_dir()
