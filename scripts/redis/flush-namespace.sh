#!/usr/bin/env sh
# Delete ONLY keys under one project prefix (SCAN + batched DEL, no FLUSHDB).
# Usage: FLUSH_CONFIRM=<prefix> flush-namespace.sh <prefix>
set -eu
PREFIX="${1:?usage: FLUSH_CONFIRM=<prefix> $0 <prefix>}"
[ "${FLUSH_CONFIRM:-}" = "$PREFIX" ] || { echo "ERROR: set FLUSH_CONFIRM=$PREFIX" >&2; exit 1; }
printf '%s' "$PREFIX" | grep -Eq '^[a-z][a-z0-9_]{0,62}$' || { echo "ERROR: invalid prefix" >&2; exit 1; }
: "${REDIS_PASSWORD:?REDIS_PASSWORD must be set}"
CLI="redis-cli -a $REDIS_PASSWORD --no-auth-warning"
N=0
for K in $($CLI --scan --pattern "$PREFIX:*"); do $CLI DEL "$K" >/dev/null; N=$((N+1)); done
# Companion Horizon namespace uses a dash by template convention (HORIZON_PREFIX).
M=0
for K in $($CLI --scan --pattern "$PREFIX-horizon*"); do $CLI DEL "$K" >/dev/null; M=$((M+1)); done
echo "OK deleted $N key(s) under '$PREFIX:*' + $M under '$PREFIX-horizon*' (other namespaces untouched)"
