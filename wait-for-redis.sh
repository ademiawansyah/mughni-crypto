#!/bin/bash
set -e

HOST="${REDIS_HOST:-redis}"
PORT="${REDIS_PORT:-6379}"

echo "⏳ Waiting for Redis at $HOST:$PORT..."
until nc -z "$HOST" "$PORT"; do
  sleep 1
done

echo "✅ Redis ready — starting Horizon..."
exec "$@"
