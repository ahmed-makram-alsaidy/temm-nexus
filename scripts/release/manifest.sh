#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────
# RELEASE MANIFEST (Phase 26.1L.3)
#
# Emits a machine-readable manifest for the built release artifact:
# version, build date, schema version, supported runtimes, checksum.
# No environment secrets are ever included.
#
#   ./scripts/release/manifest.sh [artifact.tar.gz]
# ─────────────────────────────────────────────────────────────────
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
VERSION="$(cat VERSION 2>/dev/null || echo unknown)"
ARTIFACT="${1:-}"
BUILD_DATE="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
SCHEMA="$(ls apps/owner-console/database/migrations/*.php 2>/dev/null | wc -l | tr -d ' ')"
PHP_REQ="$(php -r '\$c=json_decode(file_get_contents("apps/owner-console/composer.json"),true); echo \$c["require"]["php"] ?? "?";' 2>/dev/null || echo ^8.3)"
CHECKSUM="-"
if [ -n "$ARTIFACT" ] && [ -f "$ARTIFACT" ]; then
  CHECKSUM="$(sha256sum "$ARTIFACT" | awk '{print $1}')"
  ARTIFACT="$(basename "$ARTIFACT")"
fi

cat <<JSON
{
  "version": "$VERSION",
  "build_date_utc": "$BUILD_DATE",
  "schema_migrations": $SCHEMA,
  "requires": { "php": "$PHP_REQ", "postgres": "17", "redis": "8" },
  "container_base_images": {
    "app": "php:8.4.25-fpm-alpine",
    "postgres": "postgres:17-alpine",
    "redis": "redis:8-alpine",
    "caddy": "caddy:2-alpine"
  },
  "artifact": "$ARTIFACT",
  "sha256": "$CHECKSUM",
  "secrets_included": false
}
JSON
