from __future__ import annotations

import json
import uuid
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict


@dataclass
class ExecutionContext:
    execution_id: str
    timestamp: datetime
    output_dir: Path

    @property
    def execution_date(self) -> str:
        return self.timestamp.astimezone(timezone.utc).date().isoformat()

    @property
    def iso_timestamp(self) -> str:
        return self.timestamp.isoformat()

    def artifact_dir(self) -> Path:
        directory = self.output_dir / self.execution_date / self.execution_id
        directory.mkdir(parents=True, exist_ok=True)
        return directory

    def write_json(self, relative_path: str, payload: Dict[str, Any]) -> Path:
        target = self.artifact_dir() / relative_path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(json.dumps(payload, indent=2, sort_keys=True), encoding="utf-8")
        return target


def new_execution(output_dir: Path) -> ExecutionContext:
    output_dir.mkdir(parents=True, exist_ok=True)
    return ExecutionContext(execution_id=str(uuid.uuid4()), timestamp=datetime.now(timezone.utc), output_dir=output_dir)
