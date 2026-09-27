#!/usr/bin/env bash
# Phase 22.1: run the PHP SDK live proof. Usage: gate22-1.sh php-live
# Requires GATE_KEY env (disposable key). Never echoes the key.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
export MSYS_NO_PATHCONV=1
set -a; . "$ROOT/.env"; set +a

test -n "${GATE_KEY:-}" || { echo "GATE_KEY env required" >&2; exit 1; }
MSYS2_ARG_CONV_EXCL='*' docker run --rm --network backend-infra-application \
  -v "$ROOT/packages/backend-sdk-php:/sdk" -w /sdk \
  -e GATE_KEY="$GATE_KEY" \
  -e LIVE_API_URL=http://sdklive-a-web:8000 \
  -e LIVE_FUNCTIONS_URL=http://caddy \
  -e LIVE_HOST=console.test \
  backend-infra/owner-console-php:8.4 php /sdk/tests/live-php-proof.php
