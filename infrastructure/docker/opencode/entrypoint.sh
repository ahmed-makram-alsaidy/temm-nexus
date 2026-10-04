#!/bin/sh
# Managed OpenCode service entrypoint (Phase 43).
#
# The agent runtime must read/write exactly what the platform's PHP
# processes write, so it drops to the OWNER OF THE WORKSPACES ROOT instead
# of a hardcoded uid: whatever uid the app containers use (www-data on the
# php image, etc.) is what the agent runs as. Running the agent as root is
# refused — that is a hard line.
set -e

WS_ROOT=/var/www/html/storage/app/private/agent-workspaces

if [ "$(id -u)" = "0" ]; then
    mkdir -p "$WS_ROOT" 2>/dev/null || true

    OWNER_UID="$(stat -c %u "$WS_ROOT" 2>/dev/null || echo '')"
    OWNER_GID="$(stat -c %g "$WS_ROOT" 2>/dev/null || echo '')"

    if [ -z "$OWNER_UID" ] || [ "$OWNER_UID" = "0" ]; then
        echo "entrypoint: workspaces root is missing or root-owned; refusing to run the agent as root" >&2
        exit 1
    fi

    if ! getent passwd "$OWNER_UID" >/dev/null 2>&1; then
        useradd -o --uid "$OWNER_UID" --gid "$OWNER_GID" -M -d /home/agent -s /bin/sh agent
    fi

    exec gosu "$OWNER_UID:$OWNER_GID" "$@"
fi

exec "$@"
