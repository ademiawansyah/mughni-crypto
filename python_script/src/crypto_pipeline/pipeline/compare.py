from __future__ import annotations

import json
from pathlib import Path


def load_json(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def compare_model_outputs(python_output: Path, laravel_output: Path) -> dict:
    py = load_json(python_output)
    lv = load_json(laravel_output)

    py_symbols = {item["symbol"]: item for item in py.get("results", [])}
    lv_symbols = {item["symbol"]: item for item in lv.get("results", [])}

    missing_in_python = sorted([symbol for symbol in lv_symbols if symbol not in py_symbols])
    missing_in_laravel = sorted([symbol for symbol in py_symbols if symbol not in lv_symbols])

    score_deltas = []
    for symbol in sorted(set(py_symbols.keys()) & set(lv_symbols.keys())):
        score_deltas.append(
            {
                "symbol": symbol,
                "python_score": py_symbols[symbol].get("total_score"),
                "laravel_score": lv_symbols[symbol].get("total_score"),
                "delta": (py_symbols[symbol].get("total_score", 0) - lv_symbols[symbol].get("total_score", 0)),
            }
        )

    return {
        "model": py.get("model"),
        "missing_in_python": missing_in_python,
        "missing_in_laravel": missing_in_laravel,
        "score_deltas": score_deltas,
    }
