# Python Spec Reference Pipeline

This folder contains a Python reference implementation for the requirement spec in `.github/system-requirement.instructions.md`.

## Goals
- Implement shared Layer1-Layer4 flow with cache.
- Run 4 independent model services.
- Persist per-model standardized JSON outputs.
- Emit API endpoint call ledger per execution.

## Quick start
1. `cd python_script`
2. `python3 -m venv .venv && source .venv/bin/activate`
3. `pip install -r requirements.txt`
4. `cp .env.example .env` and set API keys.
5. Run:
   - Shared fetch: `python main.py fetch`
   - Model 1: `python main.py model1`
   - Model 2: `python main.py model2`
   - Model 3: `python main.py model3`
   - Model 4: `python main.py model4`
   - All: `python main.py all`

## Validation mode
- Use `--force-refresh` to bypass cache reads and trigger live API refresh.
- Verify required endpoint coverage: `python main.py validate-calls --force-refresh`
