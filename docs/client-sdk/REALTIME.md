# Realtime

Transport: Reverb = Pusher protocol over WebSocket. Endpoint per project
(`REVERB_HOST/PORT/SCHEME` + app key; Connect page shows the URL template with
states only, never the secret). Contract: `docs/CLIENT_API_CONTRACT.md` §9.

```ts
const sub = await backend.realtime.subscribe({
  channel: 'orders',
  event: 'order.created',          // optional filter
  onEvent: (e) => console.log(e.event, e.data),
});
await sub.unsubscribe();
await backend.realtime.unsubscribeAll();
```

```dart
final sub = await backend.realtime.subscribe(
  channel: 'orders',
  onEvent: (e) => debugPrint('${e.event}: ${e.data}'),
);
await sub.unsubscribe();
```

Rules: channel/event names `^[A-Za-z0-9_.:-]{1,160}$`; payloads are JSON
objects (capped 4000 chars); `private-` / `presence-` require the project API
key (anonymous subscribe refused with 401); no duplicate subscriptions
(re-subscribing returns the existing handle); exponential-backoff reconnect
with jitter; auth failures never retried silently. PHP does not subscribe —
see `Realtime::wsUrl()` / `validateChannel()` helpers for operator tooling.
