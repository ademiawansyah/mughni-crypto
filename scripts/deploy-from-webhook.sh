#!/usr/bin/env bash
set -Eeuo pipefail

LOCK_FILE="/tmp/mughni-crypto-deploy.lock"
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOG_FILE="${PROJECT_DIR}/storage/logs/deploy-script.log"
BRANCH="${DEPLOY_BRANCH:-main}"

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

ensure_command git
ensure_command php
ensure_command composer

exec 9>"${LOCK_FILE}"
if ! flock -n 9; then
  log "Deployment already running. Exiting."
  exit 0
fi

cd "${PROJECT_DIR}"

CURRENT_COMMIT="$(git rev-parse --short HEAD 2>/dev/null || echo 'unknown')"
TARGET_REF="origin/${BRANCH}"

log "Starting deployment (current=${CURRENT_COMMIT}, target=${TARGET_REF})"

if ! git fetch origin "${BRANCH}" --prune; then
  log "Failed to fetch branch ${BRANCH}"
  exit 1
fi

TARGET_COMMIT="$(git rev-parse --short "${TARGET_REF}" 2>/dev/null || echo '')"
if [[ -z "${TARGET_COMMIT}" ]]; then
  log "Unable to resolve target commit for ${TARGET_REF}"
  exit 1
fi

if [[ "${CURRENT_COMMIT}" == "${TARGET_COMMIT}" ]]; then
  log "Already up to date (${CURRENT_COMMIT}). Running migrations/cache refresh only."
else
  log "Updating code to ${TARGET_COMMIT}"
  git reset --hard "${TARGET_REF}"
fi

log "Installing PHP dependencies"
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

if command -v npm >/dev/null 2>&1; then
  log "Node.js detected. Building frontend assets"
  npm ci
  npm run build
else
  log "Node.js is not installed. Skipping frontend build"
fi

log "Running database migrations"
php artisan migrate --force

log "Refreshing Laravel caches"
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

log "Restarting Horizon workers gracefully"
php artisan horizon:terminate || true

log "Deployment completed (commit=${TARGET_COMMIT})"
