from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Dict, List


@dataclass
class ModelResult:
    rank: int
    symbol: str
    price: float
    total_score: float
    components: Dict[str, Any]
    metadata: Dict[str, Any]


def build_output(model: str, version: str, timestamp: str, execution_date: str, results: List[ModelResult]) -> Dict[str, Any]:
    return {
        "model": model,
        "version": version,
        "timestamp": timestamp,
        "execution_date": execution_date,
        "results": [
            {
                "rank": result.rank,
                "symbol": result.symbol,
                "price": round(result.price, 8),
                "total_score": round(result.total_score, 4),
                "components": result.components,
                "metadata": result.metadata,
            }
            for result in results
        ],
    }
