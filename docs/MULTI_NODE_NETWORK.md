# Multi-Node Network Architecture (Phase 21B)

Target production shape. Nothing here is provisioned in Phase 21 — this is
the model the code (endpoint chains, node records, topology) already supports.

```
Internet
   │
Cloudflare (DNS + WAF + TLS, Phase 8 strategy in docs/REVERSE_PROXY.md)
   │
Proxy / App Nodes (public: 80/443 only via Caddy)
   │   Caddy → Laravel API → Control Plane UI
   │
Private Network (WireGuard / Tailscale / provider private LAN — REQUIRED)
   ├── PostgreSQL ......... 5432, private only
   ├── Redis .............. 6379, private only (+ password, protected mode)
   ├── Horizon / workers .. no inbound ports (outbound to Redis/Postgres)
   ├── Reverb ............. WS via proxy; origin-to-node on private net
   └── Object storage ..... R2/S3 over HTTPS (public endpoint, signed URLs)
```

## Rules (non-negotiable)

1. **PostgreSQL and Redis are never exposed to the public internet.**
   Today: neither publishes host ports (`docker-compose.yml` has no `ports:`
   for them; pgAdmin/Mailpit bind `127.0.0.1` only). In split/distributed
   profiles they bind the PRIVATE interface / private-LAN address only.
2. Inter-node traffic rides an encrypted private network. Provider LAN alone
   is acceptable only inside one VPC with security groups; cross-provider or
   cross-region links MUST use WireGuard/Tailscale.
3. Public ingress terminates at the proxy nodes (Caddy, automatic HTTPS in
   production per `docs/REVERSE_PROXY.md`). App nodes accept HTTP only from
   the proxy + health checks from monitoring.
4. Redis keeps `requirepass` + `protected-mode yes` (see
   `infrastructure/redis/redis.conf`); PostgreSQL keeps least-privilege
   per-project roles (no shared superuser for apps).
5. Node agents initiate OUTBOUND HTTPS to the Control Plane (`POST
   /cp-nodes/heartbeat`). The Control Plane never opens inbound SSH to
   nodes and has no remote shell capability by design.

## Profiles

- **Single node** (current): all services on `node-local-01`. No private net
  needed; loopback + compose networks only.
- **Split database**: Node A (proxy/app/redis/workers/realtime) + Node B
  (PostgreSQL). Private link carries 5432. Backups run from a job with a
  route to Node B and write to object storage (pending — see gap audit).
- **Distributed**: dedicated app (A/B), PostgreSQL (C), Redis (D), workers
  (E), realtime (F) nodes + R2/S3. Scheduler uses `onOneServer()` over the
  shared Redis lock. Each project's mapping rows + endpoint overrides select
  its nodes (Control Plane → project → Infrastructure).

## Port map (private net)

| Service | Port | Reachable from |
|---------|------|----------------|
| Caddy HTTP/HTTPS | 80/443 | Internet (via Cloudflare) |
| Laravel/FPM | 9000 | Proxy node only |
| PostgreSQL | 5432 | App/worker/backup/monitor nodes only |
| Redis | 6379 | App/worker/realtime/monitor nodes only |
| Reverb WS | 8080 (managed) | Proxy + app nodes |
| Node agent | 443 egress | Control Plane URL only |
