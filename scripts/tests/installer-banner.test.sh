#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────
# install.sh dry-run banner regression test (no Docker required).
#
# Regression: the banner used `${CHECK_ONLY:+"(dry run)"}`, which expands
# for CHECK_ONLY=0 too (non-empty), so real installs displayed "(dry run)".
# Both banners are asserted; the run itself must stop at the (stubbed)
# Docker prerequisites so nothing is ever installed or written.
# ─────────────────────────────────────────────────────────────────
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INSTALLER="$SCRIPT_DIR/../install.sh"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/bin"

# Deterministic Docker stub: `command -v docker` finds it and `docker
# --version` succeeds, but `docker compose version` fails so the installer
# stops immediately after the banner — before .env creation or compose.
cat > "$TMP/bin/docker" <<'STUB'
#!/usr/bin/env bash
if [ "${1:-}" = "compose" ]; then
  echo "stub: compose unavailable for banner test" >&2
  exit 1
fi
echo "Docker version 0.0.0-stub"
STUB
chmod +x "$TMP/bin/docker"

run() { # $1 mode flag ("--check" or ""), $2 expected output substring, $3 label
  local mode="$1" expected="$2" label="$3" out rc
  out="$(cd "$TMP" && PATH="$TMP/bin:$PATH" bash "$INSTALLER" $mode 2>&1)" && rc=0 || rc=$?
  if [ "$rc" = "1" ] && grep -qF "$expected" <<<"$out"; then
    echo "ok: $label"
  else
    echo "FAIL: $label (rc=$rc)"
    echo "$out"
    exit 1
  fi
}

run "--check" "== Platform installer (dry run) ==" "dry-run mode shows explicit dry-run banner"
run ""       "== Platform installer =="            "real mode shows no dry-run banner"
run ""       "compose plugin missing"              "real mode stops at stubbed prerequisites (nothing installed)"

# Guard against re-introduction of the buggy expansion form.
if grep -qE 'CHECK_ONLY:\+' "$INSTALLER"; then
  echo "FAIL: install.sh still uses the buggy \${CHECK_ONLY:+} expansion"
  exit 1
fi

echo "install.sh banner: OK"
