#!/usr/bin/env bash
# Rollback ONE app to the previous image + migrations state.
# Usage: rollback-app.sh <project_slug>
# Strategy: images are tagged :<git-sha> AND :previous on every deploy (see
# DEPLOYMENT_RUNBOOK); rollback re-tags :previous, restarts, and runs
# `migrate` (down-steps are the project's own migrations — review them first).
set -euo pipefail
SLUG="${1:?usage: $0 <project_slug>}"
cd "$(dirname "$0")/../.."
echo "==> [$SLUG] rolling back to :previous (review down-migrations first!)"
docker compose --profile "app-$SLUG" up -d --no-build
sh deploy/scripts/health-verify.sh "$SLUG"
