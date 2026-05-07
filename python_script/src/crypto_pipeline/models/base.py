from __future__ import annotations

from typing import Any

from crypto_pipeline.core.config import ModelConfig
from crypto_pipeline.core.schema import ModelResult


def coin_to_usdt_symbol(coin: dict[str, Any]) -> str:
    return f"{str(coin.get('symbol', '')).upper()}USDT"


def get_rank(coin: dict[str, Any]) -> int:
    rank = coin.get("market_cap_rank")
    return int(rank) if rank is not None else 999999


def get_volume(coin: dict[str, Any]) -> float:
    return float(coin.get("total_volume") or 0)


class BaseModelService:
    def __init__(self, model_config: ModelConfig) -> None:
        self.model_config = model_config

    def l3_filter(self, coins: list[dict[str, Any]]) -> list[dict[str, Any]]:
        result = []
        for coin in coins:
            rank = get_rank(coin)
            volume = get_volume(coin)
            if rank < self.model_config.l3_rank_min or rank > self.model_config.l3_rank_max:
                continue
            if volume < self.model_config.l3_min_volume:
                continue
            result.append(coin)
        return result

    def rank(self, rows: list[dict[str, Any]]) -> list[ModelResult]:
        sorted_rows = sorted(rows, key=lambda item: item["total_score"], reverse=True)[: self.model_config.top_n]
        results: list[ModelResult] = []
        for index, row in enumerate(sorted_rows, start=1):
            results.append(
                ModelResult(
                    rank=index,
                    symbol=row["symbol"],
                    price=float(row["price"]),
                    total_score=float(row["total_score"]),
                    components=row["components"],
                    metadata=row["metadata"],
                )
            )
        return results


def parse_klines(klines: list[list[Any]]) -> dict[str, list[float]]:
    opens, highs, lows, closes, volumes = [], [], [], [], []
    for row in klines:
        opens.append(float(row[1]))
        highs.append(float(row[2]))
        lows.append(float(row[3]))
        closes.append(float(row[4]))
        volumes.append(float(row[5]))
    return {"open": opens, "high": highs, "low": lows, "close": closes, "volume": volumes}
