# Realtime (control plane)

Per project: Reverb installed/not, app-key state (never the value), endpoint,
plus the last live handshake proof (`reverb-proof.json`).

## Live proof (18H, PASS)

`scripts/reverb-handshake.sh <slug>`: dependency-free PHP WebSocket client
subscribes to the project channel, the demo app broadcasts `DemoOrderCreated`
(ShouldBroadcastNow), the client receives it with payload. Verified 2026-09-16:
`{"ok":true,"detail":"Received DemoOrderCreated …","payload":{"order_id":2,…}}`.
