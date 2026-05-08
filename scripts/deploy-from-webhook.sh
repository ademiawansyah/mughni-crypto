#!/usr/bin/env bash
set -Eeuo pipefail

LOCK_FILE="/tmp/mughni-crypto-deploy.lock"
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOG_FILE="${PROJECT_DIR}/storage/logs/deploy-script.log"
BRANCH="${DEPLOY_BRANCH:-main}"
TRIGGER_PATH="${DEPLOY_TRIGGER_PATH:-${PROJECT_DIR}/storage/logs/deploy.trigger}"
TRIGGER_DIR="$(dirname "${TRIGGER_PATH}")"

mkdir -p "$(dirname "${LOG_FILE}")"

log() {
  printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$1" | tee -a "${LOG_FILE}"
}

ensure_command() {
  if ! command -v "$1" >/dev/null 2>&1; then
    log "Missing required command: $1"
    exit 1
  fi
}

ensure_command mkdir
ensure_command mv

exec 9>"${LOCK_FILE}"
if ! flock -n 9; then
  log "Deployment already running. Exiting."
  exit 0
fi

cd "${PROJECT_DIR}"

mkdir -p "${TRIGGER_DIR}"

TRIGGER_TMP_FILE="${TRIGGER_PATH}.tmp.$$"
REQUESTED_AT="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
COMMIT="${DEPLOY_COMMIT:-}"
REPOSITORY="${DEPLOY_REPOSITORY:-}"

cat > "${TRIGGER_TMP_FILE}" <<EOF
requested_at=${REQUESTED_AT}
repository=${REPOSITORY}
branch=${BRANCH}
commit=${COMMIT}
EOF

mv "${TRIGGER_TMP_FILE}" "${TRIGGER_PATH}"

log "Host deploy trigger written to ${TRIGGER_PATH} (branch=${BRANCH}, commit=${COMMIT:-unknown}, repository=${REPOSITORY:-unknown})"
log "The host watcher will run: git pull --ff-only origin ${BRANCH} && make refresh"
