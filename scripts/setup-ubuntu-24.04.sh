#!/usr/bin/env bash
set -Eeuo pipefail

PROJECT_DIR="${MUGHNI_PROJECT_DIR:-/home/edualima/code/mughni-crypto}"
DEPLOY_USER="${DEPLOY_USER:-${SUDO_USER:-$USER}}"
DEPLOY_GROUP="${DEPLOY_GROUP:-${DEPLOY_USER}}"
TRIGGER_FILE="${GITHUB_DEPLOY_TRIGGER_PATH:-${PROJECT_DIR}/storage/logs/deploy.trigger}"
HOST_SCRIPT_PATH="/usr/local/bin/mughni-crypto-host-deploy"
SYSTEMD_SERVICE_PATH="/etc/systemd/system/mughni-crypto-deploy.service"
SYSTEMD_PATH_UNIT_PATH="/etc/systemd/system/mughni-crypto-deploy.path"

require_command() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Missing required command: $1" >&2
    exit 1
  fi
}

require_command sudo
require_command systemctl
require_command git
require_command make

if [[ ! -d "${PROJECT_DIR}/.git" ]]; then
  echo "Project directory does not look like a git checkout: ${PROJECT_DIR}" >&2
  exit 1
fi

sudo install -d -m 775 -o "${DEPLOY_USER}" -g "${DEPLOY_GROUP}" "$(dirname "${TRIGGER_FILE}")"

sudo tee "${HOST_SCRIPT_PATH}" >/dev/null <<EOF
#!/usr/bin/env bash
set -Eeuo pipefail

PROJECT_DIR="${PROJECT_DIR}"
TRIGGER_FILE="${TRIGGER_FILE}"
LOCK_FILE="/tmp/mughni-crypto-host-deploy.lock"
LOG_FILE="\${PROJECT_DIR}/storage/logs/host-deploy.log"

mkdir -p "\$(dirname "\${LOG_FILE}")"

log() {
  printf '[%s] %s\n' "\$(date '+%Y-%m-%d %H:%M:%S')" "\$1" | tee -a "\${LOG_FILE}"
}

read_trigger_value() {
  local key="\$1"
  awk -F= -v expected="\${key}" '\$1 == expected { print substr(\$0, index(\$0, "=") + 1); exit }' "\${TRIGGER_FILE}"
}

exec 9>"\${LOCK_FILE}"
if ! flock -n 9; then
  log "Host deployment already running. Exiting."
  exit 0
fi

if [[ ! -f "\${TRIGGER_FILE}" ]]; then
  log "Trigger file not found. Nothing to do."
  exit 0
fi

BRANCH="\$(read_trigger_value branch)"
COMMIT="\$(read_trigger_value commit)"
REPOSITORY="\$(read_trigger_value repository)"
REQUESTED_AT="\$(read_trigger_value requested_at)"

if [[ -z "\${BRANCH}" ]]; then
  BRANCH="main"
fi

cleanup() {
  rm -f "\${TRIGGER_FILE}"
}

trap cleanup EXIT

cd "\${PROJECT_DIR}"

log "Starting host deployment (repository=\${REPOSITORY:-unknown}, branch=\${BRANCH}, commit=\${COMMIT:-unknown}, requested_at=\${REQUESTED_AT:-unknown})"
git pull --ff-only origin "\${BRANCH}"
make refresh
log "Host deployment completed at commit \$(git rev-parse --short HEAD)"
EOF

sudo chmod 755 "${HOST_SCRIPT_PATH}"

sudo tee "${SYSTEMD_SERVICE_PATH}" >/dev/null <<EOF
[Unit]
Description=Mughni Crypto host deploy runner
After=network-online.target docker.service
Wants=network-online.target

[Service]
Type=oneshot
User=${DEPLOY_USER}
Group=${DEPLOY_GROUP}
ExecStart=${HOST_SCRIPT_PATH}
EOF

sudo tee "${SYSTEMD_PATH_UNIT_PATH}" >/dev/null <<EOF
[Unit]
Description=Watch for Mughni Crypto deploy trigger

[Path]
PathExists=${TRIGGER_FILE}
PathChanged=${TRIGGER_FILE}
Unit=mughni-crypto-deploy.service

[Install]
WantedBy=multi-user.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable --now mughni-crypto-deploy.path

echo "Installed host deploy watcher."
echo "Project directory: ${PROJECT_DIR}"
echo "Trigger file: ${TRIGGER_FILE}"
