from __future__ import annotations

from statistics import mean

from crypto_pipeline.core.config import AppConfig
from crypto_pipeline.models.base import BaseModelService, coin_to_usdt_symbol, parse_klines
from crypto_pipeline.pipeline.indicators import atr, linear_slope, normalize_score, rsi


class PrePumpModel(BaseModelService):
    def __init__(self, config: AppConfig, binance_provider) -> None:
        super().__init__(config.model2)
        self.config = config
        self.binance = binance_provider

    def run(self, coins: list[dict]) -> list:
        candidates = self.l3_filter(coins)
        rows = []
        for coin in candidates:
            symbol = coin_to_usdt_symbol(coin)
            try:
                klines_4h = self.binance.get_klines(symbol, "4h", 220)
                funding = self.binance.get_funding_rate(symbol, limit=12)
                oi_hist = self.binance.get_open_interest_hist(symbol, period="4h", limit=30)
                trades = self.binance.get_trades(symbol, limit=1000)
            except Exception:
                continue

            candles = parse_klines(klines_4h)
            closes = candles["close"]
            highs = candles["high"]
            lows = candles["low"]

            funding_persistent = self._funding_persistent(funding)
            oi_sideways = self._oi_rising_price_sideways(oi_hist, closes)
            atr_compression = self._atr_compression(highs, lows, closes)
            cvd_divergence = self._cvd_accumulation(trades, closes)
            rsi_compression = self._rsi_compression(closes)

            score = (
                self.model_config.weights["funding_persistent"] * float(funding_persistent)
                + self.model_config.weights["oi_sideways"] * float(oi_sideways)
                + self.model_config.weights["atr_compression"] * float(atr_compression)
                + self.model_config.weights["cvd_divergence"] * float(cvd_divergence)
                + self.model_config.weights["rsi_compression"] * float(rsi_compression)
            ) * 100.0

            rows.append(
                {
                    "symbol": symbol,
                    "price": float(coin.get("current_price") or 0),
                    "total_score": normalize_score(score),
                    "components": {
                        "funding_persistent": funding_persistent,
                        "oi_sideways": oi_sideways,
                        "atr_compression": atr_compression,
                        "cvd_divergence": cvd_divergence,
                        "rsi_compression": rsi_compression,
                    },
                    "metadata": {"entry_timeframe": "1h", "structure_timeframe": "4h"},
                }
            )
        return self.rank(rows)

    def _funding_persistent(self, funding: list[dict]) -> bool:
        if len(funding) < 3:
            return False
        last_three = funding[-3:]
        return all(float(row.get("fundingRate") or 0) < -0.0005 for row in last_three)

    def _oi_rising_price_sideways(self, oi_hist: list[dict], closes: list[float]) -> bool:
        if len(oi_hist) < 2 or len(closes) < 24:
            return False
        first = float(oi_hist[0].get("sumOpenInterest") or 0)
        last = float(oi_hist[-1].get("sumOpenInterest") or 0)
        if first <= 0:
            return False
        oi_change = (last - first) / first
        price_range = (max(closes[-24:]) - min(closes[-24:])) / max(min(closes[-24:]), 1e-9)
        return oi_change > 0.10 and price_range < 0.03

    def _atr_compression(self, highs: list[float], lows: list[float], closes: list[float]) -> bool:
        if len(closes) < 120:
            return False
        current_atr = atr(highs[-60:], lows[-60:], closes[-60:], period=14)
        atr_values = []
        for i in range(60, len(closes)):
            atr_values.append(atr(highs[: i + 1], lows[: i + 1], closes[: i + 1], period=14))
        baseline = mean(atr_values[-30:]) if atr_values else 0.0
        return baseline > 0 and current_atr < baseline

    def _cvd_accumulation(self, trades: list[dict], closes: list[float]) -> bool:
        if len(trades) < 30 or len(closes) < 30:
            return False
        deltas = []
        for trade in trades:
            qty = float(trade.get("qty") or 0)
            deltas.append(-qty if bool(trade.get("isBuyerMaker")) else qty)
        cvd, total = [], 0.0
        for delta in deltas:
            total += delta
            cvd.append(total)
        return abs(linear_slope(closes[-30:])) < 0.01 and linear_slope(cvd[-30:]) > 0

    def _rsi_compression(self, closes: list[float]) -> bool:
        if len(closes) < 25:
            return False
        sub = []
        for i in range(5):
            sub.append(rsi(closes[: len(closes) - i], 14))
        return all(45 <= x <= 55 for x in sub)
