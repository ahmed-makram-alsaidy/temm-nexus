#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────
# DISTRIBUTION PACKAGER (Phase 26K.5)
#
# Builds the local release artifact (tar.gz) from the repository,
# enforcing docs/open-source/DISTRIBUTION_ALLOWLIST.md. Nothing is
# uploaded anywhere — the artifact stays in release-artifacts/.
#
#   ./scripts/release/package-dist.sh [output-dir]
# ─────────────────────────────────────────────────────────────────
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
OUT_DIR="${1:-release-artifacts}"
VERSION="$(cat VERSION 2>/dev/null || echo 0.0.0-dev)"
STAMP="$(date +%Y%m%d-%H%M%S)"
NAME="platform-${VERSION}-${STAMP}"
mkdir -p "$OUT_DIR"

# Exclusions per DISTRIBUTION_ALLOWLIST.md.
#
# CRITICAL (26.1A finding): unanchored tar patterns match at EVERY path
# level — `--exclude='projects'` silently dropped
# apps/owner-console/resources/views/filament/projects/ and broke the
# project workspace in the artifact. Top-level-only paths are excluded with
# --anchored patterns; generic names (vendor, node_modules, .env, …) stay
# unanchored but are never top-level-only concerns.
EXCLUDES=(
  --anchored
  --exclude='./projects'
  --exclude='./backups'
  --exclude='./release-artifacts'
  --exclude='./.ai' --exclude='./.zcode' --exclude='./.claude'
  --exclude='./.rc1-stash'
  --no-anchored
  --exclude='.git'
  --exclude='.env'
  --exclude='*.key' --exclude='*.pem'
  --exclude='secrets'
  --exclude='backups/data' --exclude='backups/*.sql' --exclude='backups/*.sql.gz' --exclude='backups/*.dump'
  --exclude='PHASE*.md' --exclude='FINAL_INFRASTRUCTURE_REPORT.md'
  --exclude='docs/phase18-evidence' --exclude='docs/phase20*' --exclude='docs/phase21' --exclude='docs/phase22'
  --exclude='docs/control-plane-screenshots'
  --exclude='docs/platform/ai-migration/PHASE25_TEST_MATRIX.md'
  --exclude='docs/platform/productization/PHASE24_TEST_MATRIX.md'
  --exclude='docs/platform/productization/PHASE24_ARCHITECTURE.md'
  --exclude='apps/owner-console/tests/Feature/Phase23'
  --exclude='apps/owner-console/tests/Feature/Phase25/Dogfood25Test.php'
  --exclude='apps/owner-console/tests/Feature/Phase20/PlatformE2EAcceptanceTest.php'
  --exclude='apps/owner-console/nul' --exclude='apps/owner-console/bootstrap/cache' --exclude='scripts/e2e-18s.sh' --exclude='docs/PREFLIGHT.md' --exclude='docs/open-source/PRIVATE_ARTIFACT_AUDIT.md'
  --exclude='vendor' --exclude='node_modules' --exclude='.next' --exclude='dist' --exclude='build'
  --exclude='.dart_tool' --exclude='.phpunit.result.cache'
  --exclude='storage/app/private' --exclude='storage/logs' --exclude='storage/framework/cache' --exclude='storage/framework/sessions' --exclude='storage/framework/views'
  --exclude='docker-compose.override.yaml'
  --exclude='.ai' --exclude='.zcode' --exclude='.claude'
  --exclude='*.sqlite' --exclude='*.sql' --exclude='*.dump' --exclude='*.log'
  --exclude='coverage'
)

OUT="$OUT_DIR/${NAME}.tar.gz"
echo "==> packaging $NAME"
tar -czf "$OUT" "${EXCLUDES[@]}" \
  apps packages infrastructure deploy scripts docs examples sdk-integration-demo \
  docker-compose.yml docker-compose.prod.yml .env.example .gitignore .dockerignore VERSION \
  README.md INSTALL.md UPGRADE.md BACKUP.md SECURITY.md CONTRIBUTING.md CODE_OF_CONDUCT.md \
  ARCHITECTURE.md TROUBLESHOOTING.md CHANGELOG.md LICENSE COPYRIGHT.md TRADEMARKS.md .github 2>/dev/null || true

echo "==> verifying artifact size + top-level contents"
du -h "$OUT"
tar -tzf "$OUT" | awk -F/ '{print $1}' | sort | uniq -c | sort -rn | head

echo "==> running the private-reference scan against the artifact"
bash "$ROOT/scripts/release/private-ref-scan.sh" "$OUT"

echo "== ARTIFACT: $OUT =="
