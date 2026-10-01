#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────
# fpm-healthcheck.sh decision-logic regression test (no Docker/FPM).
#
# Stubs `cgi-fcgi` on PATH with canned FastCGI responses and asserts
# the probe's verdict:
#   healthy   → exit 0 : complete 200 response (no Status header, or
#                        an explicit "Status: 200")
#   unhealthy → exit 1 : explicit non-200 status, headerless or empty
#                        payload (FPM down, timeout, aborted exchange)
#
# Regression context: the probe used to grep for the literal "200 OK",
# which PHP never emits for a bare 200 (the CGI Status header only
# appears for non-200 responses) — every healthy app looked unhealthy.
# ─────────────────────────────────────────────────────────────────
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROBE="$SCRIPT_DIR/../../infrastructure/docker/php/fpm-healthcheck.sh"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/bin"

cat > "$TMP/bin/cgi-fcgi" <<'STUB'
#!/usr/bin/env bash
# Emits the canned fixture byte-for-byte; arguments are ignored.
cat "${STUB_FIXTURE:?}"
STUB
chmod +x "$TMP/bin/cgi-fcgi"

pass=0
fail=0

check() { # $1 label, $2 fixture file, $3 expected exit (0|1)
  local label="$1" fixture="$2" expected="$3" rc
  STUB_FIXTURE="$fixture" PATH="$TMP/bin:$PATH" sh "$PROBE" >/dev/null 2>&1 && rc=0 || rc=$?
  if [ "$rc" = "$expected" ]; then
    echo "ok: $label"
    pass=$((pass + 1))
  else
    echo "FAIL: $label (expected exit $expected, got $rc)"
    fail=$((fail + 1))
  fi
}

# ── fixtures (exact CGI response bytes) ──────────────────────────
printf 'Cache-Control: no-cache, private\r\nDate: Wed, 30 Sep 2026 16:31:49 GMT\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<!DOCTYPE html>\n' > "$TMP/ok-no-status"
printf 'Content-Type: text/html; charset=utf-8\r\nStatus: 200 OK\r\n\r\nOK\n' > "$TMP/ok-explicit-200"
printf 'Content-Type: text/html; charset=utf-8\r\nStatus: 500 Internal Server Error\r\n\r\nboom\n' > "$TMP/fail-500"
printf 'Content-Type: text/html; charset=utf-8\r\nStatus: 404 Not Found\r\n\r\nmissing\n' > "$TMP/fail-404"
printf 'Content-Type: text/plain\r\nStatus: 302 Found\r\nLocation: /setup\r\n\r\n' > "$TMP/fail-302"
: > "$TMP/fail-empty"
printf 'truncated body, headers never arrived\n' > "$TMP/fail-headerless"

check "healthy 200 without Status header passes"    "$TMP/ok-no-status"    0
check "explicit Status: 200 passes"                 "$TMP/ok-explicit-200" 0
check "Status: 500 fails"                           "$TMP/fail-500"        1
check "Status: 404 fails"                           "$TMP/fail-404"        1
check "Status: 302 fails"                           "$TMP/fail-302"        1
check "empty payload (timeout/FPM down) fails"      "$TMP/fail-empty"      1
check "headerless payload (aborted exchange) fails" "$TMP/fail-headerless" 1

echo "fpm-healthcheck: $pass passed, $fail failed"
[ "$fail" = "0" ]
