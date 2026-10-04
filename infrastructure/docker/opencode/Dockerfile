# TEMM Nexus — managed OpenCode service image (Phase 43).
#
# OpenCode does not yet publish an official headless `serve` image (upstream
# feature request), so TEMM builds a minimal, version-pinned image from the
# OFFICIAL npm package (`opencode-ai`). The version is pinned at build time
# and must match config('agent.opencode.pin') / OPENCODE_VERSION.
#
# Security posture (see docs/agent-runtime/DEVELOPER_AGENT.md):
#   - runs as a dedicated non-root user;
#   - `serve` binds 0.0.0.0 INSIDE the container only — the service is never
#     host-published and never routed through Caddy;
#   - the only filesystem it receives is TEMM's isolated task-workspace
#     root (mounted by compose at the same absolute path the app uses);
#   - no Docker socket, no host root, no SSH, no cloud credentials.
FROM node:22-bookworm-slim

ARG OPENCODE_VERSION=1.18.34
ENV OPENCODE_VERSION=${OPENCODE_VERSION}

RUN apt-get update \
    && apt-get install -y --no-install-recommends git ca-certificates gosu \
    && npm install -g "opencode-ai@${OPENCODE_VERSION}" \
    && opencode --version

# ll-sh — the Landlock-sandboxed session shell (final hardening). Compiled
# here, then the compiler is removed again in the same layer: the runtime
# image never ships a toolchain. See ll-sh.c for the containment contract.
COPY ll-sh.c /tmp/ll-sh.c
RUN apt-get install -y --no-install-recommends gcc libc6-dev \
    && gcc -O2 -s -o /usr/local/bin/ll-sh /tmp/ll-sh.c \
    && rm /tmp/ll-sh.c \
    && apt-get purge -y --auto-remove gcc libc6-dev \
    && rm -rf /var/lib/apt/lists/* \
    && test -x /usr/local/bin/ll-sh

# The runtime user is created at STARTUP by the entrypoint (uid derived
# from the app's storage owner); here we only prepare its home directory
# skeleton (opencode keeps state under $HOME) and bake the runtime config
# that routes every session shell through the Landlock wrapper.
RUN mkdir -p /home/agent/.config/opencode \
    && printf '%s\n' '{"$schema":"https://opencode.ai/config.json","shell":"/usr/local/bin/ll-sh"}' \
       > /home/agent/.config/opencode/opencode.json

# On a FRESH shared app_storage volume, whichever container starts first
# seeds it via Docker's copy-up. The opencode container must therefore
# carry the SAME storage tree as the application image, owned by the same
# uid (www-data = 82 on the pinned php base) — otherwise a fresh install
# where opencode starts first would shadow the app's storage with an
# incomplete root-owned tree (found live during the rc.1 fresh-install
# gate). The entrypoint still adapts dynamically if the app's uid differs.
RUN mkdir -p /var/www/html/storage/framework/views \
             /var/www/html/storage/framework/cache \
             /var/www/html/storage/framework/sessions \
             /var/www/html/storage/app/private/agent-workspaces \
             /var/www/html/storage/app/public \
             /var/www/html/storage/logs \
    && chown -R 82:82 /var/www/html/storage

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
COPY healthcheck.mjs /usr/local/bin/healthcheck.mjs
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html/storage/app/private/agent-workspaces

EXPOSE 4096

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["opencode", "serve", "--hostname", "0.0.0.0", "--port", "4096"]
