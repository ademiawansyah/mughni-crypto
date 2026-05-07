from __future__ import annotations

from crypto_pipeline.core.config import AppConfig
from crypto_pipeline.models.base import BaseModelService, coin_to_usdt_symbol, parse_klines
from crypto_pipeline.pipeline.indicators import ema, linear_slope, macd, normalize_score, rsi


class TrendMomentumModel(BaseModelService):
    def __init__(self, config: AppConfig, binance_provider) -> None:
        super().__init__(config.model3)
        self.config = config
        self.binance = binance_provider

    def run(self, coins: list[dict]) -> list:
        candidates = self.l3_filter(coins)
        symbols = {coin.get("symbol", "").lower() for coin in candidates}
        for forced in ["btc", "eth"]:
            if forced not in symbols:
                candidates.append({"symbol": forced, "current_price": 0.0, "market_cap_rank": 1, "total_volume": 999_999_999})

        rows = []
        for coin in candidates:
            symbol = coin_to_usdt_symbol(coin)
            try:
                klines_1d = self.binance.get_klines(symbol, "1d", 260)
                klines_4h = self.binance.get_klines(symbol, "4h", 180)
                oi_hist = self.binance.get_open_interest_hist(symbol, period="4h", limit=30)
                trades = self.binance.get_trades(symbol, limit=1000)
            except Exception:
                continue

            daily = parse_klines(klines_1d)
            intraday = parse_klines(klines_4h)

            ema_gate = self._ema_gate(daily["close"])
            if not ema_gate:
                continue

            macd_zone = self._macd_zone(intraday["close"])
            rsi_zone = self._rsi_zone(intraday["close"])
            bos = self._bos(intraday["close"])
            oi_cvd = self._oi_cvd(oi_hist, intraday["close"], trades)

            score = (
                self.model_config.weights["ema_gate"] * float(ema_gate)
                + self.model_config.weights["macd_zone"] * float(macd_zone)
                + self.model_config.weights["rsi_zone"] * float(rsi_zone)
                + self.model_config.weights["bos"] * float(bos)
                + self.model_config.weights["oi_cvd"] * float(oi_cvd)
            ) * 100.0

            rows.append(
                {
                    "symbol": symbol,
                    "price": float(coin.get("current_price") or intraday["close"][-1]),
                    "total_score": normalize_score(score),
                    "components": {
                        "ema_gate": ema_gate,
                        "macd_zone": macd_zone,
                        "rsi_zone": rsi_zone,
                        "bos": bos,
                        "oi_cvd": oi_cvd,
                    },
                    "metadata": {"entry_timeframe": "4h", "trend_timeframe": "1d"},
                }
            )
        return self.rank(rows)

    def _ema_gate(self, closes: list[float]) -> bool:
        if len(closes) < 210:
            return False
        ema50 = ema(closes, 50)
        ema200 = ema(closes, 200)
        if not ema50 or not ema200:
            return False
        current = closes[-1]
        e50 = ema50[-1]
        e200 = ema200[-1]
        slope_ok = linear_slope(ema50[-3:]) > 0
        return current > e50 > e200 and slope_ok

    def _macd_zone(self, closes: list[float]) -> bool:
        macd_value, signal, hist = macd(closes)
        return macd_value > signal > 0 and hist > 0

    def _rsi_zone(self, closes: list[float]) -> bool:
        value = rsi(closes, 14)
        return 50 <= value <= 65

    def _bos(self, closes: list[float]) -> bool:
        if len(closes) < 30:
            return False
        return closes[-1] > max(closes[-20:-1])

    def _oi_cvd(self, oi_hist: list[dict], closes: list[float], trades: list[dict]) -> bool:
        if len(oi_hist) < 2 or len(closes) < 24 or len(trades) < 20:
            return False
        oi_first = float(oi_hist[0].get("sumOpenInterest") or 0)
        oi_last = float(oi_hist[-1].get("sumOpenInterest") or 0)
        if oi_first <= 0:
            return False
        oi_up = (oi_last - oi_first) / oi_first > 0.05
        price_up = (closes[-1] - closes[-24]) / max(closes[-24], 1e-9) > 0.05

        cvd = 0.0
        for trade in trades[-200:]:
            qty = float(trade.get("qty") or 0)
            cvd += -qty if bool(trade.get("isBuyerMaker")) else qty
        return oi_up and price_up and cvd > 0
