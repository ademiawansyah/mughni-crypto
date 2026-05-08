"""
service_counter_trend.py — Entry point for Model 1 (Counter Trend).

Usage:
  python service_counter_trend.py

Schedule (recommended via cron or APScheduler):
  Every 15 minutes.

Output:
  Prints JSON result to stdout.
  Optionally saves to output/counter_trend_YYYYMMDD_HHMMSS.json.
"""

import json
import logging
import os
import sys
import uuid
from datetime import datetime, timezone
from pathlib import Path

from shared.fetch import get_filtered_coins
from services.counter_trend import run

# --- Logging setup ---
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
logger = logging.getLogger("service_counter_trend")


def save_output(data: dict) -> None:
    """
    Save model output to output/ directory as JSON.
    File name: counter_trend_YYYYMMDD_HHMMSS.json

    Args:
        data: Model output dict.
    """
    output_dir = Path("output")
    output_dir.mkdir(exist_ok=True)

    timestamp = datetime.now(timezone.utc).strftime("%Y%m%d_%H%M%S")
    filename = output_dir / f"counter_trend_{timestamp}.json"

    with open(filename, "w") as f:
        json.dump(data, f, indent=2)

    logger.info("Output saved → %s", filename)


def main() -> None:
    execution_id = str(uuid.uuid4())
    logger.info("=== Counter Trend service started | execution_id=%s ===", execution_id)

    # Layer 1 + 2: fetch and pre-filter market data
    coins_layer2 = get_filtered_coins()

    if not coins_layer2:
        logger.error("[%s] No coins from Layer 1/2 — aborting", execution_id)
        sys.exit(1)

    # Run Model 1
    result = run(coins_layer2, execution_id)

    # Output to stdout
    print(json.dumps(result, indent=2))

    # Save to file
    save_output(result)

    logger.info("=== Counter Trend service finished | execution_id=%s ===", execution_id)


if __name__ == "__main__":
    main()
