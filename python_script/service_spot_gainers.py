"""
service_spot_gainers.py — Entry point for Model 4 (Spot Momentum Gainers).

Usage:
  python service_spot_gainers.py

Schedule (recommended via cron or APScheduler):
  Daily at 07:00 WIB (UTC+7).

Output:
  Prints JSON result to stdout.
  Saves output/spot_gainers_YYYYMMDD_HHMMSS.json.
"""

import json
import logging
import sys
import uuid
from datetime import datetime, timezone
from pathlib import Path

from services.spot_gainers import run
from shared.fetch import get_spot_gainers_universe

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
logger = logging.getLogger("service_spot_gainers")


def save_output(data: dict) -> None:
    output_dir = Path("output")
    output_dir.mkdir(exist_ok=True)

    timestamp = datetime.now(timezone.utc).strftime("%Y%m%d_%H%M%S")
    filename = output_dir / f"spot_gainers_{timestamp}.json"

    with open(filename, "w") as f:
        json.dump(data, f, indent=2)

    logger.info("Output saved -> %s", filename)


def main() -> None:
    execution_id = str(uuid.uuid4())
    logger.info("=== Spot Gainers service started | execution_id=%s ===", execution_id)

    coins_universe, source = get_spot_gainers_universe()
    if not coins_universe:
        logger.error("[%s] No coins from source universe — aborting", execution_id)
        sys.exit(1)

    result = run(coins_universe, execution_id, source=source)

    print(json.dumps(result, indent=2))
    save_output(result)

    logger.info("=== Spot Gainers service finished | execution_id=%s ===", execution_id)


if __name__ == "__main__":
    main()
