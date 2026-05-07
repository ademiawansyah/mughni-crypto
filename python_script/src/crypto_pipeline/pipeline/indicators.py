from __future__ import annotations

from math import fabs
from statistics import mean
from typing import Iterable, Sequence


def ema(values: Sequence[float], period: int) -> list[float]:
    if len(values) < period:
        return []
    multiplier = 2 / (period + 1)
    result = [sum(values[:period]) / period]
    for value in values[period:]:
        result.append(((value - result[-1]) * multiplier) + result[-1])
    return result


def rsi(values: Sequence[float], period: int = 14) -> float:
    if len(values) <= period:
        return 50.0
    gains: list[float] = []
    losses: list[float] = []
    for i in range(1, period + 1):
        delta = values[-i] - values[-i - 1]
        if delta >= 0:
            gains.append(delta)
            losses.append(0)
        else:
            gains.append(0)
            losses.append(abs(delta))

    avg_gain = sum(gains) / period
    avg_loss = sum(losses) / period
    if avg_loss == 0:
        return 100.0
    rs = avg_gain / avg_loss
    return 100 - (100 / (1 + rs))


def macd(values: Sequence[float]) -> tuple[float, float, float]:
    if len(values) < 35:
        return 0.0, 0.0, 0.0
    ema12 = ema(values, 12)
    ema26 = ema(values, 26)
    if not ema12 or not ema26:
        return 0.0, 0.0, 0.0
    macd_series = []
    offset = len(ema12) - len(ema26)
    for i in range(len(ema26)):
        macd_series.append(ema12[i + offset] - ema26[i])
    signal_series = ema(macd_series, 9)
    if not signal_series:
        return 0.0, 0.0, 0.0
    macd_value = macd_series[-1]
    signal_value = signal_series[-1]
    return macd_value, signal_value, macd_value - signal_value


def atr(highs: Sequence[float], lows: Sequence[float], closes: Sequence[float], period: int = 14) -> float:
    if len(closes) < period + 1:
        return 0.0
    true_ranges: list[float] = []
    for i in range(1, len(closes)):
        tr = max(
            highs[i] - lows[i],
            fabs(highs[i] - closes[i - 1]),
            fabs(lows[i] - closes[i - 1]),
        )
        true_ranges.append(tr)
    return sum(true_ranges[-period:]) / period


def linear_slope(values: Iterable[float]) -> float:
    points = list(values)
    n = len(points)
    if n < 2:
        return 0.0
    x_mean = (n - 1) / 2
    y_mean = mean(points)
    numerator = sum((i - x_mean) * (points[i] - y_mean) for i in range(n))
    denominator = sum((i - x_mean) ** 2 for i in range(n))
    return numerator / denominator if denominator else 0.0


def normalize_score(raw_score: float) -> float:
    return max(0.0, min(100.0, raw_score))
