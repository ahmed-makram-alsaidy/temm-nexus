#!/usr/bin/env bash
# Deploy or update ONE Laravel app container set (app + horizon + reverb + scheduler).
# Usage: deploy-app.sh <project_slug> [git_ref]
# Safe defaults: migrations run BEFORE traffic switch; opcache reloaded after.
set -euo pipefail
SLUG="${1:?usage: $0 <project_slug> [git_ref]}"
REF="${2:-main}"
cd "$(dirname "$0")/../.."

echo "==> [$SLUG] pulling $REF"
docker compose --profile "app-$SLUG" pull 2>/dev/null || true

echo "==> [$SLUG] migrate --force"
docker compose exec -T "$SLUG" php artisan migrate --force

echo "==> [$SLUG] caches"
docker compose exec -T "$SLUG" php artisan config:cache
docker compose exec -T "$SLUG" php artisan route:cache
docker compose exec -T "$SLUG" php artisan view:cache

echo "==> [$SLUG] restart workers + reverb"
docker compose restart "${SLUG}-horizon" "${SLUG}-reverb" 2>/dev/null || true

echo "==> [$SLUG] reload php-fpm (opcache, validate_timestamps=0)"
docker compose exec -T "$SLUG" kill -USR2 1

echo "==> [$SLUG] verify"
sh deploy/scripts/health-verify.sh "$SLUG"
