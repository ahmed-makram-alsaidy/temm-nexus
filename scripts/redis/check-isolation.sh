#!/usr/bin/env sh
# Prove namespace isolation between two app prefixes on the shared Redis.
# Usage: check-isolation.sh [prefix_a] [prefix_b]
# Writes canary keys, verifies SCAN-per-prefix sees only its own keys, cleans up.
set -eu
A="${1:-gateapp_a}"
B="${2:-gateapp_b}"
: "${REDIS_PASSWORD:?REDIS_PASSWORD must be set}"
CLI="redis-cli -a $REDIS_PASSWORD --no-auth-warning"

$CLI SET "$A:canary" "a" >/dev/null
$CLI SET "$B:canary" "b" >/dev/null
$CLI SADD "$A:queues:default" "job-1" >/dev/null
$CLI SADD "$B:queues:default" "job-2" >/dev/null

A_KEYS="$($CLI --scan --pattern "$A:*" | sort)"
B_KEYS="$($CLI --scan --pattern "$B:*" | sort)"
echo "--- $A:* ---"; echo "$A_KEYS"
echo "--- $B:* ---"; echo "$B_KEYS"

case "$B_KEYS" in *"$A:canary"*) echo "FAIL: A key visible in B namespace" >&2; exit 1;; esac
case "$A_KEYS" in *"$B:canary"*) echo "FAIL: B key visible in A namespace" >&2; exit 1;; esac
[ -n "$A_KEYS" ] || { echo "FAIL: A namespace empty" >&2; exit 1; }
[ -n "$B_KEYS" ] || { echo "FAIL: B namespace empty" >&2; exit 1; }

# Cleanup canaries only.
$CLI DEL "$A:canary" "$B:canary" >/dev/null
$CLI DEL "$A:queues:default" "$B:queues:default" >/dev/null
echo "OK isolation holds between '$A' and '$B'"
