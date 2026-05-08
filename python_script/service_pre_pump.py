"""
service_pre_pump.py — Entry point for Model 2 (Pre-Pump Detector).

Usage:
  python service_pre_pump.py

Schedule (recommended via cron or APScheduler):
  Every 4 hours.

Output:
  Prints JSON result to stdout.
  Optionally saves to output/pre_pump_YYYYMMDD_HHMMSS.json.
"""

import json
import logging
import sys
import uuid
from datetime import datetime, timezone
from pathlib import Path

from services.pre_pump import run
from shared.fetch import get_filtered_coins

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
logger = logging.getLogger("service_pre_pump")


def save_output(data: dict) -> None:
    output_dir = Path("output")
    output_dir.mkdir(exist_ok=True)

    timestamp = datetime.now(timezone.utc).strftime("%Y%m%d_%H%M%S")
    filename = output_dir / f"pre_pump_{timestamp}.json"

    with open(filename, "w") as f:
        json.dump(data, f, indent=2)

    logger.info("Output saved -> %s", filename)


def main() -> None:
    execution_id = str(uuid.uuid4())
    logger.info("=== Pre-Pump service started | execution_id=%s ===", execution_id)

    coins_layer2 = get_filtered_coins()
    if not coins_layer2:
        logger.error("[%s] No coins from Layer 1/2 — aborting", execution_id)
        sys.exit(1)

    result = run(coins_layer2, execution_id)

    print(json.dumps(result, indent=2))
    save_output(result)

    logger.info("=== Pre-Pump service finished | execution_id=%s ===", execution_id)


if __name__ == "__main__":
    main()
