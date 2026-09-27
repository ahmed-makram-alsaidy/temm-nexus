#!/usr/bin/env sh
# Post-deploy verification for one project (or infra when SLUG=infra).
# Exit 0 = healthy. Used by deploy-app.sh, rollback-app.sh, and cron.
set -eu
SLUG="${1:-infra}"
HOST_HDR="${2:-console.test}"
if [ "$SLUG" = "infra" ]; then
  docker compose ps --format '{{.Service}} {{.Health}}' | tee /dev/stderr | grep -qv unhealthy \
    || { echo "FAIL: unhealthy service"; exit 1; }
fi
curl -fsS -m 15 -H "Host: $HOST_HDR" http://localhost/api/health | grep -q '"ok":true' \
  || { echo "FAIL: /api/health not ok ($HOST_HDR)"; exit 1; }
echo "OK $SLUG verified"
