#!/usr/bin/env bash
set -Eeuo pipefail

SOURCE_DIR="${1:-/var/www/html/public}"
TARGET_DIR="${2:-/srv/public}"

mkdir -p "${TARGET_DIR}"
find "${TARGET_DIR}" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
cp -a "${SOURCE_DIR}/." "${TARGET_DIR}/"
