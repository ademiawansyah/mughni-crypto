from __future__ import annotations

from crypto_pipeline.core.config import AppConfig, STABLECOINS, WRAPPED_KEYWORDS
from crypto_pipeline.models.base import BaseModelService, parse_klines
from crypto_pipeline.pipeline.indicators import normalize_score


class SpotMomentumGainersModel(BaseModelService):
    def __init__(self, config: AppConfig, binance_provider, cmc_provider, coingecko_provider) -> None:
        super().__init__(config.model4)
        self.config = config
        self.binance = binance_provider
        self.cmc = cmc_provider
        self.coingecko = coingecko_provider

    def run(self) -> list:
        top_candidates = self._fetch_top_gainers()
        rows = []

        for candidate in top_candidates:
            symbol = candidate["symbol"].upper()
            pair = f"{symbol}USDT"
            try:
                klines = self.binance.get_klines(pair, "1d", 7)
            except Exception:
                continue

            candles = parse_klines(klines)
            gate, metrics = self._bullish_gate(candles)
            if not gate:
                continue

            change_24h = float(candidate.get("change_24h") or 0)
            volume_ratio = metrics["volume_ratio"]
            body_ratio = metrics["body_ratio"]

            score = (
                self.model_config.weights["change_24h"] * min(max(change_24h / 25.0, 0.0), 1.0)
                + self.model_config.weights["volume_ratio"] * min(volume_ratio / 3.0, 1.0)
                + self.model_config.weights["body_ratio"] * min(body_ratio, 1.0)
            ) * 100.0

            rows.append(
                {
                    "symbol": pair,
                    "price": candles["close"][-1],
                    "total_score": normalize_score(score),
                    "components": {
                        "gate_passed": gate,
                        "change_24h": change_24h,
                        "volume_ratio": round(volume_ratio, 4),
                        "body_ratio": round(body_ratio, 4),
                    },
                    "metadata": {
                        "entry_timeframe": "1d",
                        "stop_loss": candles["low"][-1],
                        "spot_only": True,
                    },
                }
            )

        return self.rank(rows)

    def _fetch_top_gainers(self) -> list[dict]:
        records: list[dict] = []
        try:
            cmc_rows = self.cmc.get_top_200()
            for row in cmc_rows:
                quote = row.get("quote", {}).get("USD", {})
                records.append(
                    {
                        "symbol": str(row.get("symbol", "")).lower(),
                        "market_cap": float(quote.get("market_cap") or 0),
                        "change_24h": float(quote.get("percent_change_24h") or 0),
                    }
                )
        except Exception:
            gecko_rows = self.coingecko.get_markets_percent_change(200)
            for row in gecko_rows:
                records.append(
                    {
                        "symbol": str(row.get("symbol", "")).lower(),
                        "market_cap": float(row.get("market_cap") or 0),
                        "change_24h": float(row.get("price_change_percentage_24h") or 0),
                    }
                )

        cleaned = []
        for row in records:
            symbol = row["symbol"]
            if symbol in STABLECOINS:
                continue
            if any(keyword in symbol for keyword in WRAPPED_KEYWORDS):
                continue
            if row["market_cap"] < 100_000_000:
                continue
            cleaned.append(row)

        cleaned.sort(key=lambda item: item["change_24h"], reverse=True)
        return cleaned[:10]

    def _bullish_gate(self, candles: dict[str, list[float]]) -> tuple[bool, dict[str, float]]:
        o = candles["open"][-1]
        h = candles["high"][-1]
        l = candles["low"][-1]
        c = candles["close"][-1]
        prev_h = candles["high"][-2]
        vol_today = candles["volume"][-1]
        vol_avg5 = sum(candles["volume"][-6:-1]) / 5

        body = c - o
        full_range = max(h - l, 1e-9)
        body_ratio = body / full_range
        upper_wick_ratio = (h - c) / max(body, 1e-9)
        volume_ratio = vol_today / max(vol_avg5, 1e-9)

        gate = (
            c > o
            and body_ratio >= 0.6
            and upper_wick_ratio <= 0.2
            and c > prev_h
            and vol_today > vol_avg5
        )

        return gate, {"body_ratio": body_ratio, "upper_wick_ratio": upper_wick_ratio, "volume_ratio": volume_ratio}
