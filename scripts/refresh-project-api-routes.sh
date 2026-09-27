#!/usr/bin/env bash
# Snapshot a project's API routes into projects/<slug>/.control-plane/api-routes.json
# (consumed by the control plane API page, labeled with freshness timestamp).
# Runs `route:list --json` in a throwaway container on the app network.
# Usage: refresh-project-api-routes.sh <slug>
set -euo pipefail
trap 'echo "ABORTED at line $LINENO (exit $?)" >&2' ERR
SLUG="${1:?usage: $0 <slug>}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST="$ROOT/projects/$SLUG"
[ -d "$DEST" ] || { echo "ERROR: unknown project $SLUG" >&2; exit 1; }
if command -v cygpath >/dev/null 2>&1; then VOL="$(cygpath -w "$DEST")"; else VOL="$DEST"; fi
export MSYS_NO_PATHCONV=1
mkdir -p "$DEST/.control-plane"
docker run --rm -i -v "$VOL:/app" -w /app --network backend-infra-database \
  backend-infra/owner-console-php:8.4 php artisan route:list --json > "$DEST/.control-plane/api-routes.json"
trap - ERR
echo "OK routes snapshot for $SLUG ($(wc -c < "$DEST/.control-plane/api-routes.json") bytes)"
