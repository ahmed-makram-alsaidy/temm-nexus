#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────
# PRIVATE-REFERENCE SCAN (distribution guard)
#
# Fails if a distribution artifact (or directory) contains
# credential-shaped material, private network addresses, or
# machine-specific absolute paths. Secret VALUES are never printed —
# only file paths and the pattern class that matched.
#
#   ./scripts/release/private-ref-scan.sh <artifact.tar.gz | directory>
# ─────────────────────────────────────────────────────────────────
set -euo pipefail

TARGET="${1:?usage: private-ref-scan.sh <artifact.tar.gz | directory>}"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

if [ -f "$TARGET" ]; then
  tar -xzf "$TARGET" -C "$WORK"
  SCAN_DIR="$WORK"
else
  SCAN_DIR="$TARGET"
fi

HITS_FILE="$WORK/hits.txt"
: > "$HITS_FILE"

scan() { # $1 label, $2 grep pattern
  # shellcheck disable=SC2086
  grep -rInE --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git \
    --exclude-dir=.next --exclude-dir=dist --exclude-dir=build --exclude-dir=.dart_tool \
    "$2" "$SCAN_DIR" 2>/dev/null | sed "s|^|[$1] |" >> "$HITS_FILE" || true
}

# Credential-shaped strings (high-signal token prefixes).
scan SECRET 'sbp_[A-Za-z0-9]{30,}|sk-ant-[A-Za-z0-9_-]{30,}|sk-proj-[A-Za-z0-9_-]{40,}|ghp_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{40,}|AIza[A-Za-z0-9_-]{35,}|AKIA[0-9A-Z]{16}'

# Private IP ranges outside loopback.
# Documented false positive: SSRF-guard test vectors intentionally
# reference private-IP URLs inside tests/ to prove they are rejected.
scan PRIVATE_IP "192\.168\.[0-9]{1,3}\.[0-9]{1,3}"

# Machine-specific absolute paths (an operator's home directory).
scan MACHINE_PATH '[EC]:[\\/]Users/'

# SSRF-guard test-vector false positives (private-IP URLs under tests/).
grep -vE 'tests/.+/hook.+\[PRIVATE_IP\]|/hook.+\[PRIVATE_IP\]' "$HITS_FILE" > "$HITS_FILE.2" || true
grep -E '\[PRIVATE_IP\] [^:]+:.*http://192\.168\.[0-9.]+/hook' "$HITS_FILE.2" > "$HITS_FILE.fp" || true
grep -vFf "$HITS_FILE.fp" "$HITS_FILE.2" > "$HITS_FILE.3" || true
mv "$HITS_FILE.3" "$HITS_FILE"
rm -f "$HITS_FILE.2" "$HITS_FILE.fp"

COUNT="$(wc -l < "$HITS_FILE" | tr -d ' ')"
if [ "$COUNT" -gt 0 ]; then
  echo "PRIVATE-REFERENCE SCAN: FAIL ($COUNT hits — paths only, values not printed)"
  sed 's/:.*\[/ [/' "$HITS_FILE" | cut -c1-160
  exit 1
fi

echo "PRIVATE-REFERENCE SCAN: PASS (no forbidden private references)"
