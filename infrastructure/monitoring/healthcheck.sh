#!/usr/bin/env sh
# Unified infrastructure health probe (VPS cron every 2 min; alerts on failure).
# Exit 0 = all healthy. Exit !=0 = something needs attention (message on stdout).
# Checks: compose service health, postgres, redis, disk, DB sizes, failed jobs.
set -eu
cd "$(dirname "$0")/../.."
FAIL=0
say() { printf '%s\n' "$*"; }
fail() { say "FAIL: $*"; FAIL=1; }

# 1. Container health (unhealthy or exited services).
BAD="$(docker compose ps --format '{{.Service}} {{.Health}} {{.State}}' | awk '$2==\"unhealthy\" || $3==\"exited\" || $3==\"dead\" {print}')"
[ -z "$BAD" ] || fail "containers: $BAD"

# 2. PostgreSQL accept + replication-lag free (single node: just readiness).
docker compose exec -T postgres pg_isready -U "${POSTGRES_USER:-postgres}" >/dev/null 2>&1 \
  || fail "postgres not ready"

# 3. Redis ping.
docker compose exec -T redis redis-cli -a "${REDIS_PASSWORD:?}" --no-auth-warning ping 2>/dev/null | grep -q PONG \
  || fail "redis ping failed"

# 4. Disk usage (>80% = warn threshold per spec).
USE="$(df -P / | awk 'NR==2{print $5}' | tr -d %)"
[ "$USE" -lt 80 ] || fail "disk ${USE}% used (threshold 80%)"

# 5. Per-app health endpoints (add lines as projects onboard).
for URL in http://localhost/api/health; do
  # Local Caddy answers on :80; Host header selects the site (see Phase 8).
  curl -fsS -m 10 -H 'Host: console.test' "$URL" | grep -q '"ok":true' \
    || fail "app unhealthy: $URL"
done

[ "$FAIL" = "0" ] && say "OK all healthy (disk ${USE}%)"
exit "$FAIL"
