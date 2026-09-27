#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────
# SECRET SCAN (Phase 26I.8)
#
# Scans the working tree (or a path argument) for credential-shaped
# material: API tokens, private key blocks, JWTs, committed .env files.
# Known false-positive classes are documented in
# docs/open-source/DISTRIBUTION_SECURITY.md and filtered here.
# ─────────────────────────────────────────────────────────────────
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SCAN_PATH="${1:-$ROOT}"

FILTERED=$(mktemp)
trap 'rm -f "$FILTERED" "$FILTERED.2"' EXIT

grep -rInE \
  --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git \
  --exclude-dir=.next --exclude-dir=dist --exclude-dir=build --exclude-dir=.dart_tool \
  --exclude-dir=backups --exclude-dir=projects --exclude-dir=release-artifacts \
  '(sbp_[A-Za-z0-9]{30,})|(sk-ant-[A-Za-z0-9_-]{30,})|(sk-proj-[A-Za-z0-9_-]{40,})|(ghp_[A-Za-z0-9]{30,})|(github_pat_[A-Za-z0-9_]{40,})|(AIza[A-Za-z0-9_-]{35,})|(-----BEGIN (RSA |EC |OPENSSH |PGP )?PRIVATE KEY-----)|(\.env$)' \
  "$SCAN_PATH" > "$FILTERED" 2>/dev/null || true

# Documented false-positive classes (also see DISTRIBUTION_SECURITY.md):
#  1. token-shaped strings marked fake/example/placeholder in tests
#  2. .gitignore/.dockerignore rules that merely NAME files like ".env"
#  3. shell/CI lines that WRITE env files (sed -i / printf / >> are secret
#     generators, not secrets) and YAML step labels ("name: Prepare .env")
#  4. Phase-25/26 regression fixtures asserting secrets are rejected
grep -viE 'fake|example|placeholder|dummy|<your|your-|test-only|CHANGE_ME|LIVE_TOKEN_VALUE' "$FILTERED" > "$FILTERED.2" || true
grep -vE '\.(gitignore|dockerignore):' "$FILTERED.2" > "$FILTERED.3" || true
grep -vE 'sed -i|printf |>> |name: ' "$FILTERED.3" > "$FILTERED.3b" || true
grep -vE 'must not be stored|never stored|encrypted at rest|e2e-18s.sh' "$FILTERED.3b" > "$FILTERED.4" || true
mv "$FILTERED.4" "$FILTERED"
rm -f "$FILTERED.2" "$FILTERED.3" "$FILTERED.3b"

COUNT="$(wc -l < "$FILTERED" | tr -d ' ')"
if [ "$COUNT" -gt 0 ]; then
  echo "SECRET SCAN: FAIL ($COUNT candidate hits — review each; values shown for triage only):"
  head -30 "$FILTERED"
  exit 1
fi

echo "SECRET SCAN: PASS (no credential-shaped material in the scanned tree)"
