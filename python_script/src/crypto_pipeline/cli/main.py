from __future__ import annotations

import argparse
import json
from pathlib import Path

from crypto_pipeline.pipeline.compare import compare_model_outputs
from crypto_pipeline.pipeline.orchestrator import (
    build_runtime,
    run_all,
    run_fetch_service,
    run_model1,
    run_model2,
    run_model3,
    run_model4,
)


def _print_json(payload: dict) -> None:
    print(json.dumps(payload, indent=2))


def _assert_required_endpoints_called(ledger_entries: list[dict], include_cmc: bool = True) -> None:
    endpoints = [entry.get("endpoint", "") for entry in ledger_entries]
    required = [
        "/coins/markets",
        "/ohlc",
        "/api/v3/klines",
        "/api/v3/trades",
        "/fapi/v1/fundingRate",
        "/futures/data/openInterestHist",
    ]
    if include_cmc:
        required.append("/v1/cryptocurrency/listings/latest")

    for needle in required:
        if not any(needle in endpoint for endpoint in endpoints):
            raise RuntimeError(f"Missing required live API call for endpoint pattern: {needle}")


def main() -> None:
    parser = argparse.ArgumentParser(description="Spec reference crypto pipeline")
    parser.add_argument("command", choices=["fetch", "model1", "model2", "model3", "model4", "all", "compare", "validate-calls"])
    parser.add_argument("--force-refresh", action="store_true", help="Bypass cache reads and force live API calls")
    parser.add_argument("--python-output", type=str)
    parser.add_argument("--laravel-output", type=str)

    args = parser.parse_args()

    if args.command == "all":
        artifact_dir = run_all(force_refresh=args.force_refresh)
        print(f"Completed all services. Artifacts saved to: {artifact_dir}")
        for name in ["counter_trend", "pre_pump", "trend_momentum", "spot_momentum_gainers"]:
            print(f"  {artifact_dir / 'outputs' / f'{name}.json'}")
        return

    if args.command == "compare":
        if not args.python_output or not args.laravel_output:
            raise ValueError("--python-output and --laravel-output are required for compare")
        payload = compare_model_outputs(Path(args.python_output), Path(args.laravel_output))
        _print_json(payload)
        return

    runtime = build_runtime(force_refresh=args.force_refresh)

    if args.command == "fetch":
        coins = run_fetch_service(runtime)
        print(f"Layer1/Layer2 completed. Passed coins: {len(coins)}")
        return

    if args.command == "model1":
        payload = run_model1(runtime)
        saved = runtime.execution.artifact_dir() / "outputs" / f"{runtime.config.model1.name}.json"
        print(f"Saved: {saved}")
        _print_json(payload)
        return

    if args.command == "model2":
        payload = run_model2(runtime)
        saved = runtime.execution.artifact_dir() / "outputs" / f"{runtime.config.model2.name}.json"
        print(f"Saved: {saved}")
        _print_json(payload)
        return

    if args.command == "model3":
        payload = run_model3(runtime)
        saved = runtime.execution.artifact_dir() / "outputs" / f"{runtime.config.model3.name}.json"
        print(f"Saved: {saved}")
        _print_json(payload)
        return

    if args.command == "model4":
        payload = run_model4(runtime)
        saved = runtime.execution.artifact_dir() / "outputs" / f"{runtime.config.model4.name}.json"
        print(f"Saved: {saved}")
        _print_json(payload)
        return

    if args.command == "validate-calls":
        run_fetch_service(runtime)
        run_model1(runtime)
        run_model2(runtime)
        run_model3(runtime)
        run_model4(runtime)
        runtime.coingecko.get_ohlc("bitcoin", vs_currency="usd", days=7)
        entries = runtime.ledger.to_json()["entries"]
        include_cmc = bool(runtime.config.endpoints.coinmarketcap_api_key)
        _assert_required_endpoints_called(entries, include_cmc=include_cmc)
        print("Required API endpoint coverage verified.")
        return


if __name__ == "__main__":
    main()
