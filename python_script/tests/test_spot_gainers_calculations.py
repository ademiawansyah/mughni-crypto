import unittest
from unittest.mock import patch

from services import spot_gainers


def _make_candle(open_p, high, low, close, volume=1000.0, taker_buy=520.0):
    return {
        "open": float(open_p),
        "high": float(high),
        "low": float(low),
        "close": float(close),
        "volume": float(volume),
        "taker_buy_volume": float(taker_buy),
    }


class TestSpotGainersCalculations(unittest.TestCase):
    def _valid_7_candles(self):
        return [
            _make_candle(95.0, 96.0, 94.5, 95.2, volume=1000.0),
            _make_candle(95.2, 96.2, 95.0, 95.8, volume=1050.0),
            _make_candle(95.8, 97.0, 95.5, 96.7, volume=1100.0),
            _make_candle(96.7, 98.0, 96.4, 97.8, volume=1150.0),
            _make_candle(97.8, 99.0, 97.4, 98.8, volume=1200.0),
            _make_candle(98.8, 100.0, 98.2, 99.4, volume=1250.0),
            _make_candle(99.4, 105.4, 99.3, 104.8, volume=2500.0),
        ]

    def test_gate_all_criteria_pass(self):
        gate = spot_gainers.evaluate_bullish_candle_gate(self._valid_7_candles())
        self.assertTrue(gate["passed"])
        self.assertTrue(gate["green_candle"])
        self.assertTrue(gate["large_body"])
        self.assertTrue(gate["minimal_upper_wick"])
        self.assertTrue(gate["close_breakout"])
        self.assertTrue(gate["high_volume"])
        self.assertGreaterEqual(gate["body_ratio"], 0.6)
        self.assertLessEqual(gate["upper_wick_ratio"], 0.2)
        self.assertGreater(gate["volume_ratio"], 1.0)

    def test_gate_fails_on_body_ratio(self):
        candles = self._valid_7_candles()
        candles[-1] = _make_candle(99.4, 106.0, 99.0, 101.0, volume=2500.0)
        gate = spot_gainers.evaluate_bullish_candle_gate(candles)
        self.assertFalse(gate["passed"])
        self.assertFalse(gate["large_body"])

    def test_gate_fails_on_upper_wick(self):
        candles = self._valid_7_candles()
        candles[-1] = _make_candle(99.4, 110.0, 99.3, 104.8, volume=2500.0)
        gate = spot_gainers.evaluate_bullish_candle_gate(candles)
        self.assertFalse(gate["passed"])
        self.assertFalse(gate["minimal_upper_wick"])

    def test_gate_fails_on_breakout(self):
        candles = self._valid_7_candles()
        # Prior high is 100.0, close below that should fail breakout.
        candles[-1] = _make_candle(99.4, 100.5, 99.3, 99.9, volume=2500.0)
        gate = spot_gainers.evaluate_bullish_candle_gate(candles)
        self.assertFalse(gate["passed"])
        self.assertFalse(gate["close_breakout"])

    def test_gate_fails_on_volume(self):
        candles = self._valid_7_candles()
        candles[-1] = _make_candle(99.4, 105.4, 99.3, 104.8, volume=900.0)
        gate = spot_gainers.evaluate_bullish_candle_gate(candles)
        self.assertFalse(gate["passed"])
        self.assertFalse(gate["high_volume"])

    def test_gate_threshold_boundaries(self):
        # Exact body_ratio = 0.6 and upper_wick_ratio = 0.2
        candles = [
            _make_candle(95.0, 96.0, 94.5, 95.2, volume=1000.0),
            _make_candle(95.2, 96.2, 95.0, 95.8, volume=1050.0),
            _make_candle(95.8, 97.0, 95.5, 96.7, volume=1100.0),
            _make_candle(96.7, 98.0, 96.4, 97.8, volume=1150.0),
            _make_candle(97.8, 99.0, 97.4, 98.8, volume=1200.0),
            _make_candle(98.8, 99.0, 98.2, 98.9, volume=1250.0),
            _make_candle(100.0, 106.0, 96.0, 102.0, volume=2500.0),
        ]
        gate = spot_gainers.evaluate_bullish_candle_gate(candles)
        self.assertAlmostEqual(gate["body_ratio"], 0.2, places=6)
        self.assertFalse(gate["passed"])

        candles[-1] = _make_candle(100.0, 106.0, 100.0, 103.6, volume=2500.0)
        gate = spot_gainers.evaluate_bullish_candle_gate(candles)
        self.assertAlmostEqual(gate["body_ratio"], 0.6, places=6)
        self.assertAlmostEqual(gate["upper_wick_ratio"], 2.4 / 3.6, places=6)
        self.assertFalse(gate["passed"])

        candles[-1] = _make_candle(100.0, 104.32, 100.0, 103.6, volume=2500.0)
        gate = spot_gainers.evaluate_bullish_candle_gate(candles)
        self.assertAlmostEqual(gate["body_ratio"], 3.6 / 4.32, places=6)
        self.assertAlmostEqual(gate["upper_wick_ratio"], 0.72 / 3.6, places=6)
        self.assertTrue(gate["passed"])

    def test_gate_insufficient_candles(self):
        candles = self._valid_7_candles()[:5]
        gate = spot_gainers.evaluate_bullish_candle_gate(candles)
        self.assertFalse(gate["passed"])
        self.assertEqual(gate["reason"], "insufficient_candles")

    def test_score_components_and_clamp(self):
        score = spot_gainers.calculate_score(price_change_24h=20.0, volume_ratio=2.0, body_ratio=0.8)
        self.assertAlmostEqual(score["change_score"], 8.0, places=6)
        self.assertAlmostEqual(score["volume_score"], 35.0, places=6)
        self.assertAlmostEqual(score["body_score"], 20.0, places=6)
        self.assertAlmostEqual(score["total_score"], 63.0, places=6)

        score_max = spot_gainers.calculate_score(price_change_24h=999.0, volume_ratio=10.0, body_ratio=10.0)
        self.assertAlmostEqual(score_max["total_score"], 100.0, places=6)

    @patch("services.spot_gainers.fetch_ohlcv")
    def test_run_integration_style(self, mock_fetch_ohlcv):
        valid = self._valid_7_candles()

        invalid = self._valid_7_candles()
        invalid[-1] = _make_candle(99.4, 106.0, 99.0, 101.0, volume=2500.0)  # fails body ratio

        def side_effect(symbol, interval, limit):
            if symbol == "AAAUSDT":
                return valid
            if symbol == "BBBUSDT":
                return valid
            if symbol == "CCCUSDT":
                return invalid
            return []

        mock_fetch_ohlcv.side_effect = side_effect

        coins = [
            {
                "id": "a",
                "symbol": "aaa",
                "name": "AAA",
                "current_price": 10.0,
                "market_cap": 150_000_000,
                "market_cap_rank": 50,
                "total_volume": 5_000_000,
                "price_change_percentage_24h": 30.0,
            },
            {
                "id": "b",
                "symbol": "bbb",
                "name": "BBB",
                "current_price": 12.0,
                "market_cap": 180_000_000,
                "market_cap_rank": 80,
                "total_volume": 6_000_000,
                "price_change_percentage_24h": 22.0,
            },
            {
                "id": "c",
                "symbol": "ccc",
                "name": "CCC",
                "current_price": 8.0,
                "market_cap": 130_000_000,
                "market_cap_rank": 120,
                "total_volume": 4_000_000,
                "price_change_percentage_24h": 25.0,
            },
        ]

        out = spot_gainers.run(coins, execution_id="test-exec", source="unit-test")
        self.assertEqual(out["model"], "spot-momentum-gainers")
        self.assertEqual(out["execution_id"], "test-exec")
        self.assertEqual(out["signal_count"], 2)
        self.assertEqual(len(out["results"]), 2)
        self.assertGreaterEqual(out["results"][0]["total_score"], out["results"][1]["total_score"])
        self.assertEqual(out["results"][0]["rank"], 1)
        self.assertEqual(out["results"][1]["rank"], 2)

        first = out["results"][0]
        self.assertTrue(first["components"]["bullish_candle_gate"])
        self.assertIn("stop_loss", first["metadata"])
        self.assertTrue(first["metadata"]["spot_only"])


if __name__ == "__main__":
    unittest.main()
