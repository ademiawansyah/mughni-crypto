"""
Technical analysis functions for Model 1 (Counter Trend — Mia Style).

Provides:
  - Swing high/low detection
  - Liquidity sweep detection (wick pokes level, body closes back inside)
  - Market Structure Shift (MSS) detection (body close breaks structure)
  - Fair Value Gap (FVG) / Order Block (OB) detection
  - OI decline check
  - Extreme funding rate check
"""


# ---------------------------------------------------------------------------
# Swing Points
# ---------------------------------------------------------------------------

def find_swing_points(candles: list[dict], lookback: int = 5) -> tuple[list, list]:
    """
    Find swing highs and lows from OHLCV candle list.

    A swing high is a candle whose high is the highest in the surrounding
    window of `lookback` candles on each side. Same logic for swing low.

    Args:
        candles:  Ordered list of OHLCV dicts (oldest → newest).
        lookback: Number of candles on each side required to confirm a swing.

    Returns:
        Tuple of (swing_highs, swing_lows).
        Each item: {'index': int, 'price': float}.
    """
    swing_highs = []
    swing_lows = []
    n = len(candles)

    for i in range(lookback, n - lookback):
        high = candles[i]["high"]
        low = candles[i]["low"]

        # Swing high: strictly highest in the full window
        if all(candles[j]["high"] < high for j in range(i - lookback, i + lookback + 1) if j != i):
            swing_highs.append({"index": i, "price": high})

        # Swing low: strictly lowest in the full window
        if all(candles[j]["low"] > low for j in range(i - lookback, i + lookback + 1) if j != i):
            swing_lows.append({"index": i, "price": low})

    return swing_highs, swing_lows


# ---------------------------------------------------------------------------
# Liquidity Sweep Detection
# ---------------------------------------------------------------------------

def detect_liquidity_sweep(candles: list[dict], scan_recent: int = 10) -> dict:
    """
    Scan the most recent candles for a liquidity sweep.

    A sweep is confirmed when a candle's wick breaks a prior swing level but
    the body (close) closes back inside the range:
      - Bullish sweep: wick below swing low, close back above it.
      - Bearish sweep: wick above swing high, close back below it.

    Scans the last `scan_recent` candles (newest first) so we catch sweeps
    that happened recently, not only on the very last candle.

    Args:
        candles:     OHLCV list, oldest → newest. Needs ≥ 20 candles.
        scan_recent: How many trailing candles to scan for a sweep event.

    Returns:
        {
          'detected':     bool,
          'direction':    'bullish' | 'bearish' | None,
          'level':        float | None,   # swept swing price level
          'candle_index': int | None,     # index of the sweep candle in `candles`
        }
    """
    if len(candles) < 20:
        return {"detected": False, "direction": None, "level": None, "candle_index": None}

    n = len(candles)
    start = max(n - scan_recent, 10)  # leave at least 10 candles as reference

    # Scan newest-first so we return the most recent sweep
    for i in range(n - 1, start - 1, -1):
        # Build swing levels only from candles before this one
        reference = candles[:i]
        swing_highs, swing_lows = find_swing_points(reference, lookback=5)
        c = candles[i]

        # Bearish sweep: wick above swing high, body closes back below
        if swing_highs:
            level = swing_highs[-1]["price"]
            if c["high"] > level and c["close"] < level:
                return {"detected": True, "direction": "bearish", "level": level, "candle_index": i}

        # Bullish sweep: wick below swing low, body closes back above
        if swing_lows:
            level = swing_lows[-1]["price"]
            if c["low"] < level and c["close"] > level:
                return {"detected": True, "direction": "bullish", "level": level, "candle_index": i}

    return {"detected": False, "direction": None, "level": None, "candle_index": None}


# ---------------------------------------------------------------------------
# Market Structure Shift (MSS)
# ---------------------------------------------------------------------------

def detect_mss(candles: list[dict], sweep_candle_index: int, sweep_direction: str | None) -> dict:
    """
    Detect a Market Structure Shift (MSS) in candles AFTER the sweep candle.

    MSS rules (body-close only — wick breaks do NOT qualify):
      - After bullish sweep → bullish MSS: a candle CLOSE > prior swing HIGH.
      - After bearish sweep → bearish MSS: a candle CLOSE < prior swing LOW.

    Swing reference is built from candles before the sweep, so the MSS
    breaks a pre-sweep structural level.

    Args:
        candles:            OHLCV list, oldest → newest.
        sweep_candle_index: Index in `candles` where the sweep was detected.
        sweep_direction:    'bullish' or 'bearish'.

    Returns:
        {'detected': bool, 'direction': 'bullish' | 'bearish' | None}
    """
    if sweep_candle_index is None or sweep_direction is None:
        return {"detected": False, "direction": None}

    # Swing levels established BEFORE the sweep
    reference = candles[:sweep_candle_index]
    if len(reference) < 10:
        return {"detected": False, "direction": None}

    swing_highs, swing_lows = find_swing_points(reference, lookback=5)

    # Scan candles from after the sweep to the end
    for c in candles[sweep_candle_index + 1:]:
        if sweep_direction == "bullish" and swing_highs:
            # Bullish MSS: close breaks above prior swing high
            if c["close"] > swing_highs[-1]["price"]:
                return {"detected": True, "direction": "bullish"}

        if sweep_direction == "bearish" and swing_lows:
            # Bearish MSS: close breaks below prior swing low
            if c["close"] < swing_lows[-1]["price"]:
                return {"detected": True, "direction": "bearish"}

    return {"detected": False, "direction": None}


# ---------------------------------------------------------------------------
# FVG / Order Block Detection
# ---------------------------------------------------------------------------

def detect_fvg_ob(candles: list[dict]) -> dict:
    """
    Detect if current price is inside a Fair Value Gap (FVG).

    FVG definition: a 3-candle sequence where candle[i].high < candle[i+2].low
    (bullish FVG) or candle[i].low > candle[i+2].high (bearish FVG), creating
    an imbalance zone that price later retraces into.

    Checks the last 20 candles for any FVG that the current close is inside.

    Args:
        candles: OHLCV list, oldest → newest. Needs ≥ 5 candles.

    Returns:
        {
          'detected':  bool,
          'zone_high': float | None,
          'zone_low':  float | None,
        }
    """
    if len(candles) < 5:
        return {"detected": False, "zone_high": None, "zone_low": None}

    last_price = candles[-1]["close"]
    search_start = max(0, len(candles) - 22)

    for i in range(search_start, len(candles) - 2):
        c1, c2, c3 = candles[i], candles[i + 1], candles[i + 2]

        # Bullish FVG: gap between c1 top and c3 bottom
        if c1["high"] < c3["low"]:
            zone_low, zone_high = c1["high"], c3["low"]
            if zone_low <= last_price <= zone_high:
                return {"detected": True, "zone_high": zone_high, "zone_low": zone_low}

        # Bearish FVG: gap between c1 bottom and c3 top
        if c1["low"] > c3["high"]:
            zone_low, zone_high = c3["high"], c1["low"]
            if zone_low <= last_price <= zone_high:
                return {"detected": True, "zone_high": zone_high, "zone_low": zone_low}

    return {"detected": False, "zone_high": None, "zone_low": None}


# ---------------------------------------------------------------------------
# Derivatives Checks
# ---------------------------------------------------------------------------

def check_oi_decline(oi_data: list[dict], threshold: float = 0.05) -> bool:
    """
    Check if Open Interest declined by more than `threshold` in the latest period.
    A decline concurrent with a price spike signals exhaustion.

    Args:
        oi_data:   List of {timestamp, open_interest}, sorted oldest-first.
        threshold: Minimum fractional decline to qualify (default 5%).

    Returns:
        True if OI declined ≥ threshold between the last two data points.
    """
    if len(oi_data) < 2:
        return False

    prior = oi_data[-2]["open_interest"]
    recent = oi_data[-1]["open_interest"]

    if prior == 0:
        return False

    return (prior - recent) / prior >= threshold


def check_extreme_funding(funding_data: list[dict], threshold: float = 0.001) -> bool:
    """
    Check if the latest funding rate is extreme (|rate| ≥ threshold).
    Extreme funding (< -0.1% or > +0.1%) signals a potential reversal.

    Args:
        funding_data: List of {timestamp, funding_rate}, sorted oldest-first.
        threshold:    Absolute threshold — default 0.001 (= 0.1%).

    Returns:
        True if the most recent funding rate exceeds the threshold.
    """
    if not funding_data:
        return False

    rate = funding_data[-1]["funding_rate"]
    return abs(rate) >= threshold
