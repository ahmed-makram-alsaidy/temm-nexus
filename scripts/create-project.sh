#!/usr/bin/env bash
# Onboard a new Laravel project end-to-end (VPS canonical; also runs in git-bash locally).
# Usage: create-project.sh <slug>
# Does: skeleton -> stack -> installers -> overlay -> DB/role -> .env ->
#       migrate -> gate test -> owner-console registration -> Caddy stanza.
# Idempotent: re-running skips completed steps (safe to retry after a failure).
set -euo pipefail
trap 'echo "ABORTED at line $LINENO (exit $?)" >&2' ERR
SLUG="${1:?usage: $0 <slug>}"
printf '%s' "$SLUG" | grep -Eq '^[a-z][a-z0-9-]{1,40}$' || { echo "ERROR: slug must be kebab-case" >&2; exit 1; }
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST="$ROOT/projects/$SLUG"
DB="$(printf '%s' "$SLUG" | tr '-' '_')_db"
ROLE="$(printf '%s' "$SLUG" | tr '-' '_')_user"
PREFIX="$(printf '%s' "$SLUG" | tr '-' '_')"

# Docker volume path: msys/git-bash needs a Windows path, Linux uses $DEST as-is.
if command -v cygpath >/dev/null 2>&1; then VOL="$(cygpath -w "$DEST")"; else VOL="$DEST"; fi
export MSYS_NO_PATHCONV=1

# shellcheck disable=SC1091
set -a; . "$ROOT/.env"; set +a
dc() { docker compose -f "$ROOT/docker-compose.yml" "$@"; }
have_db() { dc exec -T postgres psql -h localhost -U "${POSTGRES_USER:-postgres}" -d postgres -tAc \
  "SELECT 1 FROM pg_database WHERE datname='$DB';" | grep -q 1; }

echo "==> [1/8] skeleton"
mkdir -p "$DEST"
if [ -f "$DEST/vendor/autoload.php" ]; then echo "    skip (vendor present)";
else docker run --rm -v "$VOL:/app" composer:2.8 create-project laravel/laravel:^13.0 /app --no-interaction --prefer-dist; fi

echo "==> [2/8] first-party stack"
if grep -q 'laravel/horizon' "$DEST/composer.json" 2>/dev/null; then echo "    skip (stack present)";
else docker run --rm -v "$VOL:/app" -w /app composer:2.8 require laravel/sanctum laravel/horizon laravel/pulse laravel/reverb \
  -W --no-interaction --prefer-dist --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-intl; fi

# NOTE: docker needs -i so installers that prompt (reverb:install asks for
# port/host with "required" validation) receive an empty answer (= defaults)
# instead of a closed stdin, which aborts the install.
php_run() { docker run --rm -i -v "$VOL:/app" -w /app --network backend-infra-database backend-infra/owner-console-php:8.4 "$@"; }
echo "==> [3/8] installers"
[ -f "$DEST/config/sanctum.php" ] || php_run php artisan install:api --no-interaction
[ -f "$DEST/config/horizon.php" ] || php_run php artisan horizon:install --no-interaction
# NOTE: reverb:install MUST run interactively (piped newline = accept defaults);
# --no-interaction makes its "required" prompt validation throw.
[ -f "$DEST/config/reverb.php" ] || printf '\n' | php_run php artisan reverb:install
[ -f "$DEST/config/pulse.php" ] || php_run php artisan vendor:publish --tag=pulse-config --no-interaction
ls "$DEST/database/migrations/"*pulse* >/dev/null 2>&1 || php_run php artisan vendor:publish --tag=pulse-migrations --no-interaction
echo "    installers done"

echo "==> [4/8] overlay"
cp -r "$ROOT/projects/_template/overlay/." "$DEST/"

echo "==> [5/8] database + role"
if have_db; then echo "    reuse existing $DB (password unchanged — use the one in $DEST/.env)";
else
  DB_PASS="$(openssl rand -hex 16)"
  dc exec -T postgres sh /scripts/postgres/create-project-db.sh "$DB" "$ROLE" "$DB_PASS"
  DB_PASS_NEW="$DB_PASS"
fi

echo "==> [6/8] .env"
if [ -f "$DEST/.env" ] && grep -q "^DB_DATABASE=$DB$" "$DEST/.env" 2>/dev/null; then echo "    keep existing .env";
else
  APP_KEY="$(php_run php artisan key:generate --show)"
  PASS_TO_USE="${DB_PASS_NEW:?DB password unknown — refusing to guess. Restore it or drop + recreate the DB.}"
  # NOTE: '|' delimiter — APP_KEY (base64) and passwords may contain '/'.
  sed -e "s|^APP_NAME=.*|APP_NAME=\"$SLUG\"|" \
      -e "s|^APP_KEY=.*|APP_KEY=$APP_KEY|" \
      -e "s|^DB_DATABASE=.*|DB_DATABASE=$DB|" \
      -e "s|^DB_USERNAME=.*|DB_USERNAME=$ROLE|" \
      -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$PASS_TO_USE|" \
      -e "s|^REDIS_PASSWORD=.*|REDIS_PASSWORD=$REDIS_PASSWORD|" \
      -e "s|^REDIS_PREFIX=.*|REDIS_PREFIX=$PREFIX|" \
      -e "s|^REDIS_QUEUE=.*|REDIS_QUEUE=${PREFIX}-default|" \
      -e "s|^HORIZON_PREFIX=.*|HORIZON_PREFIX=${PREFIX}-horizon|" \
      -e "s|^APP_URL=.*|APP_URL=https://api.$SLUG.test|" \
      "$ROOT/projects/_template/.env.example" > "$DEST/.env"
  chmod 600 "$DEST/.env" 2>/dev/null || true
fi

echo "==> [7/8] migrate + gate test"
php_run php artisan migrate --force | tail -2
php_run php artisan test --filter=TemplateGateTest | tail -3

echo "==> [8/8] owner-console registration + Caddy stanza"
dc exec -T postgres psql -h localhost -U "${POSTGRES_USER:-postgres}" \
  -d owner_console_db -v ON_ERROR_STOP=1 -q -c \
  "INSERT INTO projects (name, slug, api_domain, status, db_name, redis_prefix, storage_disk, deploy_status, created_at, updated_at) VALUES ('$SLUG', '$SLUG', 'api.$SLUG.test', 'active', '$DB', '$PREFIX', 'local', 'deployed', now(), now()) ON CONFLICT (slug) DO NOTHING;"
STANZA_START="# BEGIN $SLUG (managed by create-project.sh)"
if ! grep -q "$STANZA_START" "$ROOT/infrastructure/caddy/Caddyfile"; then
  cat >> "$ROOT/infrastructure/caddy/Caddyfile" <<EOF

$STANZA_START
api.$SLUG.test:80 {
	encode gzip
	root * /srv/$SLUG/public
	php_fastcgi $SLUG:9000 {
		root /var/www/html/public
	}
	file_server
	header {
		X-Content-Type-Options "nosniff"
		X-Frame-Options "SAMEORIGIN"
		Referrer-Policy "strict-origin-when-cross-origin"
		-Server
	}
	@websockets {
		header Connection *Upgrade*
		header Upgrade websocket
	}
	reverse_proxy @websockets $SLUG:6001
	request_body {
		max_size 28MB
	}
}
# END $SLUG
EOF
fi

trap - ERR
echo "OK project $SLUG onboarded (DB password ONLY in projects/$SLUG/.env)"
