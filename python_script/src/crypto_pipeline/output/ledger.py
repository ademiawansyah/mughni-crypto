from __future__ import annotations

from dataclasses import asdict
from typing import List

from crypto_pipeline.core.http_client import CallLedgerEntry


class EndpointCallLedger:
    def __init__(self) -> None:
        self.entries: List[CallLedgerEntry] = []

    def add(self, entry: CallLedgerEntry) -> None:
        self.entries.append(entry)

    def to_json(self) -> dict:
        return {"entries": [asdict(entry) for entry in self.entries]}
