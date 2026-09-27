#!/usr/bin/env bash
# 18H live WebSocket proof: client subscribes, demo app emits DemoOrderCreated,
# client must receive it. Writes projects/<slug>/.control-plane/reverb-proof.json.
# Usage: reverb-handshake.sh [slug]
set -euo pipefail
SLUG="${1:-control-plane-demo}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
export MSYS_NO_PATHCONV=1
HOST="${REVERB_TEST_HOST:-cpdemo-reverb}"
PORT="${REVERB_TEST_PORT:-8080}"
KEY="${REVERB_TEST_KEY:-local-key}"
CHANNEL="${REVERB_TEST_CHANNEL:-control-plane-demo}"
EVENT="DemoOrderCreated"
RESULT="$ROOT/projects/$SLUG/.control-plane/reverb-proof.json"
rm -f "$RESULT" "$RESULT.ready"

echo "==> starting listener (background)"
docker run --rm --network backend-infra-database \
  -v "$ROOT/scripts/reverb-handshake.php:/ws.php:ro" \
  -v "$ROOT/projects/$SLUG/.control-plane:/out" \
  backend-infra/owner-console-php:8.4 \
  php /ws.php "$HOST" "$PORT" "$KEY" "$CHANNEL" "$EVENT" 40 /out/reverb-proof.json &
LISTENER=$!

echo "==> waiting for subscription"
for i in $(seq 1 20); do
  [ -f "$ROOT/projects/$SLUG/.control-plane/reverb-proof.json.ready" ] && break
  sleep 1
done
[ -f "$ROOT/projects/$SLUG/.control-plane/reverb-proof.json.ready" ] || { echo "FAIL: listener never subscribed"; wait $LISTENER || true; exit 1; }
echo "==> subscribed; emitting test event from demo app"
docker run --rm -v "$ROOT/projects/$SLUG:/app" -w /app --network backend-infra-database \
  -e REVERB_HOST="$HOST" \
  backend-infra/owner-console-php:8.4 php artisan demo:emit-test-event
echo "==> waiting for delivery"
wait $LISTENER
cat "$RESULT"
