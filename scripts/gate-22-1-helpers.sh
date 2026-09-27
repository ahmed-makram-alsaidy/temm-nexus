#!/usr/bin/env bash
# Phase 22.1 live-gate helpers (disposable use only, local).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
export MSYS_NO_PATHCONV=1
set -a; . "$ROOT/.env"; set +a

console_psql() {
  docker compose -f "$ROOT/docker-compose.yml" exec -T postgres psql -h localhost \
    -U "${POSTGRES_USER:-postgres}" -d owner_console_db -v ON_ERROR_STOP=1 "$@"
}
