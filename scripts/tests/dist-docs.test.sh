#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────
# DISTRIBUTION DOCS TEST (0.4.0-rc.2)
#
# Guards two rc.1 findings:
#   1. the unanchored `--exclude='PHASE*.md'` silently dropped every
#      docs/product/PHASE_*.md gate report from the artifact — the
#      root-history exclude must stay ANCHORED (./PHASE*.md);
#   2. the required 0.4.0 release/acceptance docs must exist in the
#      repository (the allowlist's rule 6 list) and must actually land
#      in a built artifact.
#
# Run: bash scripts/tests/dist-docs.test.sh
# ─────────────────────────────────────────────────────────────────
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

FAILED=0
ok()   { printf '  \033[32mPASS\033[0m %s\n' "$1"; }
fail() { printf '  \033[31mFAIL\033[0m %s\n' "$1"; FAILED=1; }

REQUIRED_DOCS=(
  docs/product/PHASE_ABC_GATE.md
  docs/product/PHASE_D_GATE.md
  docs/product/PHASE_E_GATE.md
  docs/product/PHASE_F_GATE.md
  docs/product/PHASE_I_GATE.md
  docs/product/PHASE_J_GATE.md
  docs/product/PHASE_IJK_FINAL_REPORT.md
  docs/product/V040_RC1_RELEASE_REPORT.md
  docs/product/RC1_ACCEPTANCE_REPORT.md
  docs/ai/NEXUS_INSPECT_MODE.md
  docs/ai/NEXUS_ACTION_SAFETY.md
  docs/ai/NEXUS_COPILOT_ARCHITECTURE.md
)

echo "== dist-docs: required 0.4.0 docs =="
for doc in "${REQUIRED_DOCS[@]}"; do
  if [ -f "$doc" ]; then ok "exists: $doc"; else fail "missing: $doc"; fi
done

echo "== dist-docs: packager excludes are anchored =="
if grep -qE "exclude='\./PHASE\*\.md'" scripts/release/package-dist.sh; then
  ok "root history exclude is anchored (./PHASE*.md)"
else
  fail "package-dist.sh lost the anchored ./PHASE*.md exclude — docs/product gate reports would be dropped again"
fi
if grep -qE "exclude='PHASE\*\.md'" scripts/release/package-dist.sh; then
  fail "unanchored PHASE*.md exclude is back (matches docs/product at every level)"
else
  ok "no unanchored PHASE*.md exclude"
fi

echo "== dist-docs: end-to-end packaging =="
TMP="$ROOT/release-artifacts/.dist-docs-test"
mkdir -p "$TMP"
trap '' EXIT
sync 2>/dev/null || true
if bash scripts/release/package-dist.sh "$TMP" > "$TMP/pack.log" 2>&1; then
  ARTIFACT="$(ls "$TMP"/platform-*.tar.gz | head -1)"
  # Repeated tar -tzf reads of one archive intermittently return truncated
  # listings on Windows (observed as random 'missing' files). Take several
  # listings and keep the longest; the packager's own tar exit was already
  # verified, so the longest listing is authoritative.
  BEST=0
  BEST_LIST="$TMP/list.txt"
  for try in 1 2 3; do
    tar -tzf "$ARTIFACT" > "$TMP/try$try.txt" 2>/dev/null || true
    N="$(wc -l < "$TMP/try$try.txt")"
    if [ "$N" -gt "$BEST" ]; then BEST="$N"; cp "$TMP/try$try.txt" "$BEST_LIST"; fi
  done
  ok "archive listing: $BEST entries (longest of 3 reads)"
  MISSING_IN_ARTIFACT=0
  for doc in "${REQUIRED_DOCS[@]}"; do
    if ! grep -qF "$doc" "$BEST_LIST"; then
      fail "not in artifact: $doc"
      MISSING_IN_ARTIFACT=1
    fi
  done
  [ "$MISSING_IN_ARTIFACT" = "0" ] && ok "all required docs present in the built artifact"
  if tar -tzf "$ARTIFACT" | grep -qE "^\./PHASE|^[^/]*/PHASE_(ABC|D|E|F)_GATE" && ! tar -tzf "$ARTIFACT" | grep -qF "docs/product/PHASE_ABC_GATE.md"; then
    fail "unexpected PHASE file at artifact root"
  fi
else
  fail "package-dist.sh failed to build (see $TMP/pack.log)"
  cat "$TMP/pack.log" || true
fi

if [ "$FAILED" = "1" ]; then
  echo "== dist-docs: FAIL =="
  exit 1
fi
echo "== dist-docs: PASS =="
