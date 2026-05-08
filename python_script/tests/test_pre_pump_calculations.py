import unittest

from services import pre_pump


def _make_candle(open_p, high, low, close, volume=1000.0, taker_buy=520.0):
    return {
        "open": float(open_p),
        "high": float(high),
        "low": float(low),
        "close": float(close),
        "volume": float(volume),
        "taker_buy_volume": float(taker_buy),
    }


class TestPrePumpCalculations(unittest.TestCase):
    def test_persistent_negative_funding_true(self):
        # 6 x 4H points -> 3 x 8H aggregated points, all below -0.05%
        funding = [
            {"funding_rate": -0.0008},
            {"funding_rate": -0.0009},
            {"funding_rate": -0.0007},
            {"funding_rate": -0.0006},
            {"funding_rate": -0.00055},
            {"funding_rate": -0.00075},
        ]
        ok, recent = pre_pump._persistent_negative_funding(funding, threshold=-0.0005)
        self.assertTrue(ok)
        self.assertEqual(len(recent), 3)

    def test_oi_rising_and_price_sideways(self):
        oi = [
            {"open_interest": 100.0},
            {"open_interest": 103.0},
            {"open_interest": 107.0},
            {"open_interest": 112.0},
        ]
        oi_ok, growth = pre_pump._oi_rising_24h(oi, threshold=0.10)
        self.assertTrue(oi_ok)
        self.assertAlmostEqual(growth, 0.12, places=6)

        candles = [
            _make_candle(100, 101, 99.8, 100.1),
            _make_candle(100.1, 101.2, 99.9, 100.2),
            _make_candle(100.2, 101.1, 100.0, 100.3),
            _make_candle(100.3, 101.0, 100.1, 100.2),
            _make_candle(100.2, 101.1, 100.0, 100.1),
            _make_candle(100.1, 101.0, 99.9, 100.0),
        ]
        sideways, rng = pre_pump._price_sideways_24h(candles, threshold=0.03)
        self.assertTrue(sideways)
        self.assertLess(rng, 0.03)

    def test_atr_and_rsi_compression(self):
        candles = []
        price = 100.0
        for i in range(220):
            close = price + (0.05 if i % 2 == 0 else -0.05)
            candles.append(_make_candle(price, price + 0.2, price - 0.2, close))
            price = close

        atr_14 = pre_pump._calc_atr(candles, period=14)
        atr_30 = pre_pump._calc_atr(candles[-180:], period=30)
        self.assertIsNotNone(atr_14)
        self.assertIsNotNone(atr_30)
        self.assertGreater(atr_14, 0)
        self.assertGreater(atr_30, 0)

        rsi_ok, recent_rsi = pre_pump._rsi_compression(candles, low=40.0, high=60.0, min_candles=5)
        self.assertTrue(rsi_ok)
        self.assertEqual(len(recent_rsi), 5)

    def test_score_weight_consistency(self):
        components = {
            "persistent_negative_funding": True,
            "oi_rising_price_sideways": True,
            "low_atr_compression": False,
            "cvd_quietly_rising": True,
            "rsi_compression": False,
        }
        expected = 35 + 25 + 12
        actual = 0
        actual += pre_pump.SCORE_FUNDING if components["persistent_negative_funding"] else 0
        actual += pre_pump.SCORE_OI_PRICE if components["oi_rising_price_sideways"] else 0
        actual += pre_pump.SCORE_ATR if components["low_atr_compression"] else 0
        actual += pre_pump.SCORE_CVD if components["cvd_quietly_rising"] else 0
        actual += pre_pump.SCORE_RSI if components["rsi_compression"] else 0
        self.assertEqual(actual, expected)


if __name__ == "__main__":
    unittest.main()
