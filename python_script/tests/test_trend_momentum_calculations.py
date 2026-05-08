import unittest

from services import trend_momentum


def _make_candle(open_p, high, low, close, volume=1000.0, taker_buy=520.0):
    return {
        "open": float(open_p),
        "high": float(high),
        "low": float(low),
        "close": float(close),
        "volume": float(volume),
        "taker_buy_volume": float(taker_buy),
    }


class TestTrendMomentumCalculations(unittest.TestCase):
    def test_ema_gate_true_on_uptrend(self):
        candles = []
        price = 100.0
        for i in range(260):
            prev = price
            # Accelerate in the most recent section so EMA50-EMA200 spread widens.
            step = 0.2 if i < 220 else 1.0
            price += step
            candles.append(_make_candle(prev, price + 0.2, prev - 0.2, price))

        ok, meta = trend_momentum._ema_gate(candles)
        self.assertTrue(ok)
        self.assertTrue(meta["ema_gate_ok"])
        self.assertGreater(meta["ema50"], meta["ema200"])

    def test_macd_and_rsi_helpers(self):
        candles = []
        price = 100.0
        for i in range(220):
            prev = price
            # Build bullish acceleration near the end so histogram expands.
            if i < 150:
                step = 0.05
            elif i < 190:
                step = 0.2
            else:
                step = 0.6
            price += step
            candles.append(_make_candle(prev, price + 0.25, prev - 0.2, price))

        macd_ok, macd_meta = trend_momentum._macd_ok(candles)
        rsi_ok, rsi_meta = trend_momentum._rsi_zone_ok(candles, low=50.0, high=95.0)

        self.assertIsNotNone(macd_meta["macd"])
        self.assertIsNotNone(macd_meta["signal"])
        self.assertIsNotNone(macd_meta["histogram"])
        self.assertIsNotNone(macd_meta["histogram_prev"])
        expected_macd = (
            macd_meta["macd"] > macd_meta["signal"] > 0
            and macd_meta["histogram"] > 0
            and macd_meta["histogram"] > macd_meta["histogram_prev"]
        )
        self.assertEqual(macd_ok, expected_macd)
        self.assertEqual(macd_meta["macd_ok"], expected_macd)
        self.assertIsNotNone(rsi_meta["rsi"])
        expected_rsi = 50.0 <= rsi_meta["rsi"] <= 95.0
        self.assertEqual(rsi_ok, expected_rsi)
        self.assertEqual(rsi_meta["rsi_ok"], expected_rsi)

    def test_oi_price_and_cvd_positive(self):
        oi = [
            {"open_interest": 100.0},
            {"open_interest": 103.0},
            {"open_interest": 106.0},
            {"open_interest": 110.0},
        ]
        candles = []
        p = 100.0
        for _ in range(10):
            prev = p
            p += 1.1
            candles.append(_make_candle(prev, p + 0.2, prev - 0.2, p, volume=1000, taker_buy=650))

        oi_price_ok, oi_meta = trend_momentum._oi_price_rising(oi, candles, threshold=0.05)
        cvd_ok, cvd_meta = trend_momentum._cvd_positive(candles, lookback=6)

        self.assertTrue(oi_price_ok)
        self.assertTrue(cvd_ok)
        self.assertGreater(oi_meta["oi_growth"], 0.05)
        self.assertGreater(cvd_meta["cvd_slope"], 0)

    def test_score_weight_consistency(self):
        components = {
            "ema_gate": True,
            "macd_positive_zone": True,
            "rsi_momentum_zone": False,
            "bos_confirmed": True,
            "oi_cvd_positive": False,
        }

        expected = 30 + 25 + 15
        actual = 0
        actual += trend_momentum.SCORE_EMA if components["ema_gate"] else 0
        actual += trend_momentum.SCORE_MACD if components["macd_positive_zone"] else 0
        actual += trend_momentum.SCORE_RSI if components["rsi_momentum_zone"] else 0
        actual += trend_momentum.SCORE_BOS if components["bos_confirmed"] else 0
        actual += trend_momentum.SCORE_DERIVATIVES if components["oi_cvd_positive"] else 0

        self.assertEqual(actual, expected)


if __name__ == "__main__":
    unittest.main()