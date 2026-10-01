#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────
# SELF-HOSTED PLATFORM UPGRADER (Phase 26H)
#
#   ./scripts/upgrade.sh            check + backup + upgrade + verify
#   ./scripts/upgrade.sh --check    pre-upgrade report only
#
# Guarantees:
#   - pre-flight checks: stack health, disk, versions (never blind)
#   - a database backup is taken before migrations (or a loud warning)
#   - schema changes use normal Laravel migrations — NEVER migrate:fresh
#   - project data is preserved; rollback paths are printed, not faked
# ─────────────────────────────────────────────────────────────────
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
COMPOSE="docker compose -f docker-compose.prod.yml"
CHECK_ONLY=0
[ "${1:-}" = "--check" ] && CHECK_ONLY=1

echo "== Platform upgrader ${CHECK_ONLY:+"(report only)"} =="

CURRENT="$(cat VERSION 2>/dev/null || echo unknown)"
TARGET="$CURRENT"   # updated after fetch below when possible

# ── fetch new code (skipped with --check; on VPS usually a git pull) ──
if [ -d .git ] && [ "$CHECK_ONLY" = "0" ]; then
  OLD_TARGET="$TARGET"
  git fetch --tags origin 2>/dev/null || true
  if git rev-parse --abbrev-ref HEAD >/dev/null 2>&1; then
    # Operator has typically checked out the release tag already; show state.
    TARGET="$(cat VERSION 2>/dev/null || echo unknown)"
    [ "$TARGET" = "$CURRENT" ] || true
  fi
  TARGET="$OLD_TARGET"
fi

echo "  current platform version : $CURRENT"
echo "  target platform version  : $(cat VERSION 2>/dev/null || echo unknown)"
echo "  (upgrade by checking out the new release tag/commit, then re-run this script)"

# ── pre-flight: health, disk ─────────────────────────────────────
HEALTHY="$($COMPOSE ps --format '{{.Name}} {{.Health}}' 2>/dev/null | grep -c 'healthy' || true)"
if [ "$HEALTHY" -lt 3 ]; then
  echo "ABORT: the running stack is not healthy ($HEALTHY healthy services)."
  echo "       Fix the current installation before upgrading."
  exit 1
fi
echo "  stack health             : OK ($HEALTHY services healthy)"

FREE_KB="$(df -Pk "$ROOT" | awk 'NR==2 {print $4}')"
if [ "${FREE_KB:-0}" -lt $((2 * 1024 * 1024)) ]; then
  echo "ABORT: less than 2 GB free disk — not safe to upgrade."
  exit 1
fi
echo "  disk                     : $((FREE_KB / 1024 / 1024)) GB free"

if [ "$CHECK_ONLY" = "1" ]; then
  echo "== Pre-upgrade report complete =="
  exit 0
fi

# ── backup before upgrade (26H.2) ────────────────────────────────
echo "==> taking a pre-upgrade database backup"
mkdir -p backups/data
DB_USER="$(grep -E '^POSTGRES_USER=' .env | cut -d= -f2)"
DB_PASS="$(grep -E '^POSTGRES_PASSWORD=' .env | cut -d= -f2)"
STAMP="$(date +%Y%m%d-%H%M%S)"
BAK="backups/data/pre-upgrade-${STAMP}.sql.gz"
set +e
$COMPOSE exec -T postgres sh -lc \
  "PGPASSWORD='$DB_PASS' pg_dump -U '${DB_USER:-postgres}' -d '${PLATFORM_DB:-owner_console}' -Fc" > "$BAK"
RC=$?
set -e
if [ $RC -eq 0 ] && [ -s "$BAK" ]; then
  echo "  backup written: $BAK ($(du -h "$BAK" | cut -f1))"
else
  echo "  ┌─────────────────────────────────────────────────────────┐"
  echo "  │ WARNING: NO pre-upgrade backup could be created.        │"
  echo "  │ An upgrade without a backup may be UNRECOVERABLE.       │"
  echo "  └─────────────────────────────────────────────────────────┘"
  if [ "${UPGRADE_FORCE:-0}" != "1" ]; then
    echo "ABORT: set UPGRADE_FORCE=1 to upgrade without a backup (not recommended)."
    exit 1
  fi
fi

# ── build new image, run migrations (normal migrations only) ─────
echo "==> building the upgraded application image"
APP_BEFORE="$($COMPOSE ps -q app 2>/dev/null)"
$COMPOSE build app

echo "==> applying database migrations (Laravel migrate --force; never fresh)"
$COMPOSE run --rm migrate

echo "==> restarting application services"
$COMPOSE up -d

# ── Phase 37: Caddy edge refresh ─────────────────────────────────
# Two independent reasons an old Caddy must be recreated after an upgrade
# (both found live):
#
# 1. rc.1 — a changed Caddyfile.selfhost is invisible to compose: the
#    content of a bind-mounted file is not part of the service definition,
#    and Caddy reads the file once at container start. Fingerprint the
#    Caddy-relevant inputs and recreate caddy when it changes.
#
# 2. rc.3 — an APP CONTAINER RECREATION breaks the running old Caddy's
#    HTTPS routing for the site (HTTP:80 keeps redirecting, HTTPS:443
#    serves caddy 404s with empty bodies) until caddy itself is recreated.
#    Every code upgrade recreates the app image, so this fires on every
#    real code upgrade.
#
# Unchanged configuration + unchanged app container → caddy is left
# running (no destructive recreation without reason); caddy_data /
# caddy_config volumes (TLS state) are never touched.
caddy_inputs() {
  cat infrastructure/caddy/Caddyfile.selfhost 2>/dev/null
  grep -E '^(PRIMARY_DOMAIN|ACME_EMAIL|HTTP_PORT|HTTPS_PORT)=' .env 2>/dev/null
}
CADDY_FP="$(caddy_inputs | sha256sum | cut -d' ' -f1)"
CADDY_STATE="backups/data/.caddy-config-sha256"
APPLIED_FP="$(cat "$CADDY_STATE" 2>/dev/null || echo '')"
APP_AFTER="$($COMPOSE ps -q app 2>/dev/null)"
CADDY_RECREATE_REASON=''
if [ ! -f "$CADDY_STATE" ]; then
  CADDY_RECREATE_REASON='no previous caddy configuration fingerprint — recreating caddy once to guarantee the shipped Caddyfile is active'
elif [ "$CADDY_FP" != "$APPLIED_FP" ]; then
  CADDY_RECREATE_REASON='caddy configuration CHANGED since the last upgrade — recreating caddy'
elif [ -n "$APP_BEFORE" ] && [ "$APP_BEFORE" != "$APP_AFTER" ]; then
  CADDY_RECREATE_REASON='the app container was recreated by this upgrade — recreating caddy to refresh the edge routing'
fi
if [ -n "$CADDY_RECREATE_REASON" ]; then
  echo "  $CADDY_RECREATE_REASON"
  $COMPOSE up -d --force-recreate caddy
  printf '%s\n' "$CADDY_FP" > "$CADDY_STATE"
else
  echo "  caddy configuration unchanged and app container unchanged — leaving the running edge as-is"
fi

# ── post-upgrade health verify (26H.1 spirit: never upgrade blindly) ──
echo "==> verifying health (up to 2 minutes for containers to go healthy)"
HEALTHY_COUNT=0
for i in $(seq 1 24); do
  sleep 5
  TOTAL_SVCS="$($COMPOSE ps --format '{{.Name}} {{.Health}}' | grep -vc 'migrate' || true)"
  HEALTHY_COUNT="$($COMPOSE ps --format '{{.Name}} {{.Health}}' | grep -c 'healthy' || true)"
  UNHEALTHY="$($COMPOSE ps --format '{{.Name}} {{.Health}}' | grep -vc 'healthy\|exited (0)\|starting' || true)"
  [ "$HEALTHY_COUNT" -ge 6 ] && [ "$UNHEALTHY" = "0" ] && break
done
FAILED_SVCS="$($COMPOSE ps --format '{{.Name}} {{.Health}}' | grep -vc 'healthy\|exited (0)\|starting' || true)"
if [ "$HEALTHY_COUNT" -lt 6 ] || [ "$FAILED_SVCS" != "0" ]; then
  echo "  ┌─────────────────────────────────────────────────────────┐"
  echo "  │ ROLLBACK PATH (application):                            │"
  echo "  │   git checkout <previous-tag>                           │"
  echo "  │   docker compose -f docker-compose.prod.yml up -d --build│"
  echo "  │ ROLLBACK PATH (database, only if schema was damaged):   │"
  echo "  │   restore the backup above (see docs/BACKUP_RESTORE.md) │"
  echo "  │ Schema rollbacks are NOT automatic — destructive DB     │"
  echo "  │ changes require the backup restore.                     │"
  echo "  └─────────────────────────────────────────────────────────┘"
  $COMPOSE ps
  exit 1
fi

$COMPOSE ps
echo "== UPGRADE COMPLETE — platform version $(cat VERSION). Backup kept: $BAK =="
