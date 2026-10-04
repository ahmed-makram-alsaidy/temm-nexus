#!/bin/sh
# Managed OpenCode service entrypoint (Phase 43).
#
# The agent runtime must share file ownership with the platform's PHP
# processes, so it derives the uid from the app's own storage/private
# directory (created by the application image) — whatever uid the app
# uses (www-data on the php image, etc.) is what the agent runs as, and
# the workspaces root is normalized to that owner. Running the agent as
# root is refused — that is a hard line.
set -e

WS_ROOT=/var/www/html/storage/app/private/agent-workspaces
APP_PRIVATE=/var/www/html/storage/app/private

if [ "$(id -u)" = "0" ]; then
    OWNER_UID="$(stat -c %u "$APP_PRIVATE" 2>/dev/null || echo '')"
    OWNER_GID="$(stat -c %g "$APP_PRIVATE" 2>/dev/null || echo '')"

    if [ -z "$OWNER_UID" ] || [ "$OWNER_UID" = "0" ]; then
        echo "entrypoint: app storage owner could not be resolved (or is root); refusing to run the agent as root" >&2
        exit 1
    fi

    mkdir -p "$WS_ROOT" 2>/dev/null || true
    chown "$OWNER_UID:$OWNER_GID" "$WS_ROOT" 2>/dev/null || true

    if ! getent group "$OWNER_GID" >/dev/null 2>&1; then
        groupadd -o -g "$OWNER_GID" agentgrp
    fi

    if ! getent passwd "$OWNER_UID" >/dev/null 2>&1; then
        useradd -o --uid "$OWNER_UID" --gid "$OWNER_GID" -M -d /home/agent -s /bin/sh agent
    fi

    exec gosu "$OWNER_UID:$OWNER_GID" "$@"
fi

exec "$@"
