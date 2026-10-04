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
    && rm -rf /var/lib/apt/lists/* \
    && npm install -g "opencode-ai@${OPENCODE_VERSION}" \
    && opencode --version

# The runtime user is created at STARTUP by the entrypoint (uid derived
# from the app's storage owner); here we only prepare its home directory
# skeleton (opencode keeps state under $HOME).
RUN mkdir -p /home/agent

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
COPY healthcheck.mjs /usr/local/bin/healthcheck.mjs
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html/storage/app/private/agent-workspaces

EXPOSE 4096

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["opencode", "serve", "--hostname", "0.0.0.0", "--port", "4096"]
