#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────
# SELF-HOSTED PLATFORM INSTALLER (Docker Compose)
#
# Desired operator flow:
#   git clone <repo> && cd <repo>
#   ./scripts/install.sh
#   → open http(s)://<host>/setup
#
# Modes:
#   ./scripts/install.sh            full install (idempotent, safe re-run)
#   ./scripts/install.sh --check    dry run: report readiness, change nothing
#
# Safety rules (Phase 26G):
#   - never silently installs OS packages, touches firewall/SSH/DNS
#   - never overwrites an existing .env, database volume, or storage
#   - if Docker is missing: print instructions and STOP
# ─────────────────────────────────────────────────────────────────
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

COMPOSE="docker compose -f docker-compose.prod.yml"
CHECK_ONLY=0
[ "${1:-}" = "--check" ] && CHECK_ONLY=1

ok()   { printf '  \033[32mPASS\033[0m %s\n' "$1"; }
warn() { printf '  \033[33mWARN\033[0m %s\n' "$1"; }
fail() { printf '  \033[31mFAIL\033[0m %s\n' "$1"; FAILED=1; }
FAILED=0

echo "== Platform installer ${CHECK_ONLY:+"(dry run)"} =="

# ── prerequisites ────────────────────────────────────────────────
if ! command -v docker >/dev/null 2>&1; then
  fail "Docker not found. Install Docker Engine 24+ first:"
  echo "      https://docs.docker.com/engine/install/  (also install the"
  echo "      compose plugin: https://docs.docker.com/compose/install/)"
  echo "Then re-run this script. NOTHING was modified."
  exit 1
fi
ok "docker found: $(docker --version | cut -d, -f1)"

if docker compose version >/dev/null 2>&1; then
  ok "docker compose plugin found: $(docker compose version --short)"
else
  fail "docker compose plugin missing — install it and re-run. NOTHING was modified."
  exit 1
fi

if docker info >/dev/null 2>&1; then
  ok "docker daemon reachable"
else
  fail "docker daemon not reachable (is the service running? are you in the docker group?)"
  exit 1
fi

# Disk check: refuse to start a likely-doomed install under 5 GB free.
FREE_KB="$(df -Pk "$ROOT" | awk 'NR==2 {print $4}')"
if [ "${FREE_KB:-0}" -lt $((5 * 1024 * 1024)) ]; then
  fail "less than 5 GB free disk space at $ROOT"
else
  ok "disk space: $((FREE_KB / 1024 / 1024)) GB free"
fi

# ── environment file ─────────────────────────────────────────────
if [ -f .env ]; then
  ok ".env already exists — kept as-is (re-run safety: never overwritten)"
else
  if [ "$CHECK_ONLY" = "1" ]; then
    warn "no .env yet — the real run would create one from .env.example with generated secrets"
  else
    cp .env.example .env
    chmod 600 .env 2>/dev/null || true
    gen_secret() { openssl rand -hex 24 2>/dev/null || head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n'; }
    sed -i.bak \
      -e "s|^APP_KEY=$|APP_KEY=base64:$(openssl rand -base64 32 | tr -d '\n')|" \
      -e "s|^POSTGRES_PASSWORD=.*|POSTGRES_PASSWORD=$(gen_secret)|" \
      -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$(gen_secret)|" \
      -e "s|^MONITOR_DB_PASSWORD=.*|MONITOR_DB_PASSWORD=$(gen_secret)|" \
      -e "s|^REDIS_PASSWORD=.*|REDIS_PASSWORD=$(gen_secret)|" \
      -e "s|^REVERB_APP_KEY=$|REVERB_APP_KEY=$(gen_secret | cut -c1-20)|" \
      -e "s|^REVERB_APP_SECRET=$|REVERB_APP_SECRET=$(gen_secret)|" \
      .env && rm -f .env.bak
    ok ".env created from template with generated secrets"
    warn "review .env: set APP_URL, PRIMARY_DOMAIN, MAIL_*, ACME_EMAIL to your values"
  fi
fi

# Required secrets present?
for var in POSTGRES_PASSWORD DB_PASSWORD REDIS_PASSWORD; do
  if grep -qE "^${var}=CHANGE_ME" .env 2>/dev/null || ! grep -qE "^${var}=" .env 2>/dev/null; then
    fail "$var is missing or still a placeholder in .env"
  fi
done
if grep -qE "^APP_KEY=$" .env 2>/dev/null; then
  warn "APP_KEY empty — generate with: docker compose -f docker-compose.prod.yml run --rm app php artisan key:generate"
fi
[ "$FAILED" = "1" ] && { echo "Fix .env and re-run. NOTHING was started."; exit 1; }
ok "required secrets configured"

# ── directories (safe: only created, never emptied) ──────────────
mkdir -p backups/data
ok "runtime directories present (backups/data)"

# ── existing installation detection ──────────────────────────────
EXISTING="$($COMPOSE ps -q 2>/dev/null | grep -c . || true)"
PG_VOLUME="$(docker volume ls -q 2>/dev/null | grep -c 'backend-plane-pgdata' || true)"
if [ "$EXISTING" -gt 0 ] || [ "$PG_VOLUME" -gt 0 ]; then
  ok "existing installation detected — upgrading in place (volumes and .env preserved)"
fi

if [ "$CHECK_ONLY" = "1" ]; then
  echo "== Dry run complete. System is ready for: ./scripts/install.sh =="
  [ "$FAILED" = "1" ] && exit 1
  exit 0
fi

# ── database provisioning ────────────────────────────────────────
echo "==> starting postgres + redis"
$COMPOSE up -d postgres redis
for i in $(seq 1 24); do
  PG_OK="$($COMPOSE ps postgres --format '{{.Health}}' 2>/dev/null | grep -c healthy || true)"
  RD_OK="$($COMPOSE ps redis --format '{{.Health}}' 2>/dev/null | grep -c healthy || true)"
  [ "$PG_OK" = "1" ] && [ "$RD_OK" = "1" ] && break
  sleep 5
done
echo "==> ensuring the platform database exists (idempotent)"
$COMPOSE exec -T postgres sh /scripts/postgres/ensure-platform-db.sh

# ── build & start ────────────────────────────────────────────────
echo "==> building and starting the stack (first build takes a few minutes)"
$COMPOSE up -d --build

echo "==> waiting for services to report healthy"
for i in $(seq 1 60); do
  UNHEALTHY="$($COMPOSE ps --format '{{.Name}} {{.Health}}' 2>/dev/null | grep -vc 'healthy\|exited (0)' || true)"
  [ "$UNHEALTHY" = "0" ] && break
  sleep 5
done
$COMPOSE ps

cat <<'NEXT'

════════════════════════════════════════════════════════════════
  INSTALL COMPLETE — finish configuration in the browser:

      http://<your-host>/setup

  The setup wizard verifies the stack, creates the first platform
  owner (no default password exists), and locks itself afterwards.

  PostgreSQL and Redis are internal-only. Only ports 80/443 are
  published. For HTTPS, point DNS at this server, set PRIMARY_DOMAIN
  in .env, and run: docker compose -f docker-compose.prod.yml up -d
════════════════════════════════════════════════════════════════
NEXT
