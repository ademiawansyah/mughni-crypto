from __future__ import annotations

from crypto_pipeline.core.execution import ExecutionContext


def write_model_output(execution: ExecutionContext, model_name: str, payload: dict) -> None:
    execution.write_json(f"outputs/{model_name}.json", payload)


def write_call_ledger(execution: ExecutionContext, payload: dict) -> None:
    execution.write_json("logs/endpoint_call_ledger.json", payload)
