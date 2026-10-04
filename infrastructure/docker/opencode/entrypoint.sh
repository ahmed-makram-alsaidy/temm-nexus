#!/bin/sh
# Managed OpenCode service entrypoint (Phase 43).
#
# Fixes workspace-root ownership so the non-root agent user can read/write
# TEMM's isolated task workspaces, then drops privileges permanently.
set -e

WS_ROOT=/var/www/html/storage/app/private/agent-workspaces

if [ "$(id -u)" = "0" ]; then
    mkdir -p "$WS_ROOT" 2>/dev/null || true
    # Chown only the workspace root (never wider), then drop to agent.
    chown -R agent:agent "$WS_ROOT" 2>/dev/null || true
    exec gosu agent "$@"
fi

exec "$@"
