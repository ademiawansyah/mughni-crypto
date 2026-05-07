from __future__ import annotations

from crypto_pipeline.core.config import AppConfig
from crypto_pipeline.models.base import BaseModelService, coin_to_usdt_symbol, parse_klines
from crypto_pipeline.pipeline.indicators import linear_slope, normalize_score


class CounterTrendModel(BaseModelService):
    def __init__(self, config: AppConfig, binance_provider) -> None:
        super().__init__(config.model1)
        self.config = config
        self.binance = binance_provider

    def run(self, coins: list[dict]) -> list:
        candidates = self.l3_filter(coins)
        rows = []
        for coin in candidates:
            symbol = coin_to_usdt_symbol(coin)
            try:
                klines_1h = self.binance.get_klines(symbol, "1h", 120)
                klines_15m = self.binance.get_klines(symbol, "15m", 120)
                oi_hist = self.binance.get_open_interest_hist(symbol, period="1h", limit=20)
                funding = self.binance.get_funding_rate(symbol, limit=3)
                trades = self.binance.get_trades(symbol, limit=1000)
            except Exception:
                continue

            candle_1h = parse_klines(klines_1h)
            candle_15m = parse_klines(klines_15m)
            closes = candle_1h["close"]
            highs = candle_1h["high"]
            lows = candle_1h["low"]

            liquidity_sweep = self._liquidity_sweep(highs, lows, closes)
            mss = self._mss(closes)
            if not (liquidity_sweep and mss):
                continue

            fvg_ob = self._fvg_ob_bonus(candle_15m)
            oi_decline = self._oi_decline(oi_hist)
            funding_extreme = self._funding_extreme(funding)
            cvd_div = self._cvd_divergence(trades, closes)

            score = (
                self.model_config.weights["liquidity_sweep"] * float(liquidity_sweep)
                + self.model_config.weights["mss"] * float(mss)
                + self.model_config.weights["fvg_ob"] * fvg_ob
                + self.model_config.weights["oi_decline"] * float(oi_decline)
                + self.model_config.weights["funding_extreme"] * float(funding_extreme)
            ) * 100.0

            rows.append(
                {
                    "symbol": symbol,
                    "price": float(coin.get("current_price") or 0),
                    "total_score": normalize_score(score),
                    "components": {
                        "liquidity_sweep": liquidity_sweep,
                        "mss": mss,
                        "fvg_ob": round(fvg_ob, 4),
                        "oi_decline": oi_decline,
                        "funding_extreme": funding_extreme,
                        "cvd_divergence": cvd_div,
                    },
                    "metadata": {"entry_timeframe": "15m", "structure_timeframe": "1h"},
                }
            )
        return self.rank(rows)

    def _liquidity_sweep(self, highs: list[float], lows: list[float], closes: list[float]) -> bool:
        if len(highs) < 30:
            return False
        prev_high = max(highs[-30:-1])
        prev_low = min(lows[-30:-1])
        last_high = highs[-1]
        last_low = lows[-1]
        last_close = closes[-1]
        bearish_sweep = last_high > prev_high and last_close < prev_high
        bullish_sweep = last_low < prev_low and last_close > prev_low
        return bearish_sweep or bullish_sweep

    def _mss(self, closes: list[float]) -> bool:
        if len(closes) < 25:
            return False
        recent = closes[-10:]
        prior = closes[-25:-10]
        return (recent[-1] > max(prior)) or (recent[-1] < min(prior))

    def _fvg_ob_bonus(self, candle: dict[str, list[float]]) -> float:
        highs = candle["high"]
        lows = candle["low"]
        if len(highs) < 3:
            return 0.0
        gap_exists = lows[-1] > highs[-3] or highs[-1] < lows[-3]
        return 1.0 if gap_exists else 0.3

    def _oi_decline(self, oi_hist: list[dict]) -> bool:
        if len(oi_hist) < 2:
            return False
        first = float(oi_hist[0].get("sumOpenInterest") or 0)
        last = float(oi_hist[-1].get("sumOpenInterest") or 0)
        if first <= 0:
            return False
        return ((last - first) / first) <= -0.05

    def _funding_extreme(self, funding: list[dict]) -> bool:
        if not funding:
            return False
        latest = float(funding[-1].get("fundingRate") or 0)
        return latest <= -0.001 or latest >= 0.001

    def _cvd_divergence(self, trades: list[dict], closes: list[float]) -> bool:
        if len(trades) < 20 or len(closes) < 20:
            return False
        deltas = []
        for trade in trades:
            qty = float(trade.get("qty") or 0)
            is_buyer_maker = bool(trade.get("isBuyerMaker"))
            deltas.append(-qty if is_buyer_maker else qty)
        cvd = []
        total = 0.0
        for delta in deltas:
            total += delta
            cvd.append(total)
        return linear_slope(closes[-20:]) > 0 and linear_slope(cvd[-20:]) < 0
