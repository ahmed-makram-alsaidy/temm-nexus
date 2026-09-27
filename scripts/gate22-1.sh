#!/usr/bin/env bash
# Phase 22.1 live-gate runner. Usage: gate22-1.sh <step>
# Steps: console-row | caddy-reload | serve-a | serve-b | seed-a | seed-b | status
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
export MSYS_NO_PATHCONV=1
set -a; . "$ROOT/.env"; set +a
DC="docker compose -f $ROOT/docker-compose.yml"

step="${1:?usage: gate22-1.sh <step>}"

dc() { docker compose -f "$ROOT/docker-compose.yml" "$@"; }

console_psql() {
  dc exec -T postgres psql -h localhost -U "${POSTGRES_USER:-postgres}" \
    -d owner_console_db -v ON_ERROR_STOP=1 "$@"
}

case "$step" in
  console-row)
    console_psql -tAc "SELECT slug, api_domain, db_name, status FROM projects WHERE slug LIKE 'sdk-live-%' ORDER BY slug;"
    ;;
  caddy-reload)
    dc exec -T caddy caddy reload --config /etc/caddy/Caddyfile 2>&1 | tail -2
    ;;
  serve-a)
    docker rm -f sdklive-a-web 2>/dev/null || true
    docker run -d --name sdklive-a-web \
      --network backend-infra-application \
      -v "$ROOT/projects/sdk-live-a:/var/www/html" \
      -w /var/www/html \
      backend-infra/owner-console-php:8.4 \
      php artisan serve --host=0.0.0.0 --port=8000
    docker network connect backend-infra-database sdklive-a-web
    sleep 6
    docker exec sdklive-a-web php artisan --version
    ;;
  serve-b)
    docker rm -f sdklive-b-web 2>/dev/null || true
    docker run -d --name sdklive-b-web \
      --network backend-infra-application \
      -v "$ROOT/projects/sdk-live-b:/var/www/html" \
      -w /var/www/html \
      backend-infra/owner-console-php:8.4 \
      php artisan serve --host=0.0.0.0 --port=8000
    docker network connect backend-infra-database sdklive-b-web
    sleep 6
    docker exec sdklive-b-web php artisan --version
    ;;
  status)
    docker ps --format 'table {{.Names}}\t{{.Status}}' | grep -E 'NAMES|sdk|caddy|postgres|redis|owner-console' || true
    ;;
  live-fixtures)
    # Generates a disposable API key + static function for sdk-live-a.
    # Prints shell exports (KEY + PREFIX) for the caller — handle as secret.
    PREFIX="cp_gate221a"
    SECRET="$(openssl rand -hex 20)"
    HASH="$(printf '%s' "${PREFIX}.${SECRET}" | openssl dgst -sha256 -r | awk '{print $1}')"
    sed -e "s/__PREFIX__/${PREFIX}/" -e "s/__HASH__/${HASH}/" \
      "$ROOT/scripts/gate22-1-fixtures.sql" > "$ROOT/scripts/.gate22-1-fixtures.gen.sql"
    # psql runs inside postgres container; feed the generated SQL via stdin:
    docker exec -i backend-infra-postgres-1 psql -h localhost -U "${POSTGRES_USER:-postgres}" \
      -d owner_console_db -v ON_ERROR_STOP=1 -q < "$ROOT/scripts/.gate22-1-fixtures.gen.sql"
    rm -f "$ROOT/scripts/.gate22-1-fixtures.gen.sql"
    echo "GATE_KEY=${PREFIX}.${SECRET}"
    echo "GATE_PREFIX=${PREFIX}"
    ;;
  live-fixtures-clean)
    console_psql -q -c "DELETE FROM project_api_keys WHERE project_id = (SELECT id FROM projects WHERE slug='sdk-live-a') AND name='gate-22-1';"
    console_psql -q -c "DELETE FROM project_functions WHERE project_id = (SELECT id FROM projects WHERE slug='sdk-live-a') AND slug='gate-hello';"
    console_psql -q -c "DELETE FROM project_functions WHERE project_id = (SELECT id FROM projects WHERE slug='sdk-live-b') AND slug='gate-hello';"
    echo "fixtures removed"
    ;;
  key-reset)
    # $2 = sha256 hex of "cp_gate221a.<secret>"
    test -n "${2:-}" || { echo "usage: gate22-1.sh key-reset <sha256hex>" >&2; exit 1; }
    sed -e "s/__HASH__/$2/" "$ROOT/scripts/gate22-1-keyreset.sql" > "$ROOT/scripts/.gate22-1-keyreset.gen.sql"
    docker exec -i backend-infra-postgres-1 psql -h localhost -U "${POSTGRES_USER:-postgres}" \
      -d owner_console_db -v ON_ERROR_STOP=1 -q < "$ROOT/scripts/.gate22-1-keyreset.gen.sql"
    rm -f "$ROOT/scripts/.gate22-1-keyreset.gen.sql"
    ;;
  logs-a)
    docker exec sdklive-a-web sh -c 'grep -h "local.ERROR" storage/logs/laravel-*.log | tail -2 | cut -c1-600'
    ;;
  fixtures-b)
    docker exec -i backend-infra-postgres-1 psql -h localhost -U "${POSTGRES_USER:-postgres}" \
      -d owner_console_db -v ON_ERROR_STOP=1 -q < "$ROOT/scripts/gate22-1-fixtures-b.sql"
    ;;
  gate-admin)
    docker exec -i backend-infra-postgres-1 psql -h localhost -U "${POSTGRES_USER:-postgres}" \
      -d owner_console_db -v ON_ERROR_STOP=1 < "$ROOT/scripts/gate22-1-admin.sql"
    ;;
  gate-admin-clean)
    console_psql -q -c "DELETE FROM users WHERE email='gate22-1@local.test';"
    echo "gate admin removed"
    ;;
  audit)
    console_psql -tAc "SELECT 'users:'||count(*) FROM users WHERE email='gate22-1@local.test';"
    console_psql -tAc "SELECT 'projects:'||count(*) FROM projects WHERE slug LIKE 'sdk-live-%';"
    console_psql -tAc "SELECT 'keys:'||count(*) FROM project_api_keys WHERE name='gate-22-1';"
    console_psql -tAc "SELECT 'functions:'||count(*) FROM project_functions WHERE slug='gate-hello' AND project_id IN (SELECT id FROM projects WHERE slug LIKE 'sdk-live-%');"
    test ! -d "$ROOT/projects/sdk-live-a" && test ! -d "$ROOT/projects/sdk-live-b" && echo "dirs: gone"
    docker ps --format '{{.Names}}' | grep -c '^sdklive-' || echo "containers: none"
    grep -c 'sdk-live' "$ROOT/infrastructure/caddy/Caddyfile" || echo "caddy: clean"
    ;;
esac
