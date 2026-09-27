# External VPS Verification Checklist

> Status: **AWAITING_REAL_VPS** — nothing in this document has been executed
> against a real external server yet. Everything below is the exact future
> checklist for a clean Ubuntu 24.04 VPS. Local verification (Phase 26.1)
> covered as much as Docker-on-one-machine can; the items here CANNOT be
> proven locally and must not be marked PASS without a real VPS.

## Target

- Clean Ubuntu 24.04 LTS VPS, fresh IPv4, nothing else installed.
- A domain with DNS control (for the HTTPS phase).
- The local release artifact (`platform-0.2.0-rc.1-*.tar.gz`) or the git
  repository — transfer, do not publish.
- Verified locally (Phase 28.1) before shipping: fresh installs ×2, real-UI
  Supabase + MongoDB imports, clean rehearsal, backup/restore drill, and the
  0.1.0-rc.2 → 0.2.0-rc.1 upgrade path.

## Checklist

| # | Step | Command / action | Status |
|---|---|---|---|
| 1 | Install Docker + Compose plugin | `curl -fsSL https://get.docker.com \| sh` | AWAITING_REAL_VPS |
| 2 | Transfer/clone the release | scp the artifact, extract | AWAITING_REAL_VPS |
| 3 | Pre-flight check | `./scripts/install.sh --check` | AWAITING_REAL_VPS |
| 4 | Install | `./scripts/install.sh` | AWAITING_REAL_VPS |
| 5 | First-run setup | browser `http://<ip>/setup`, create owner | AWAITING_REAL_VPS |
| 6 | DNS + domain | point A/AAAA record, set `PRIMARY_DOMAIN` | AWAITING_REAL_VPS |
| 7 | Real Let's Encrypt HTTPS | `compose up -d`, verify cert issuer | AWAITING_REAL_VPS |
| 8 | Create project via UI | Projects → New project | AWAITING_REAL_VPS |
| 9 | Connect Supabase (read-only) | import wizard with a real PAT | AWAITING_REAL_VPS |
| 10 | Connect MongoDB (read-only) | import wizard with a real `mongodb://` URI, least-privilege read role | AWAITING_REAL_VPS |
| 11 | Analyze + Migration Center | capability probe + analysis (both connectors) | AWAITING_REAL_VPS |
| 12 | Configure AI key (BYOK) | AI Providers → add + test | AWAITING_REAL_VPS |
| 13 | Copilot smoke | run advisor action | AWAITING_REAL_VPS |
| 14 | Backup | Backup Center destination + manual run | AWAITING_REAL_VPS |
| 15 | Restore drill | disposable restore, verify login + data | AWAITING_REAL_VPS |
| 16 | Restart | `compose restart`, verify persistence | AWAITING_REAL_VPS |
| 17 | Upgrade | next RC via `./scripts/upgrade.sh` | AWAITING_REAL_VPS |
| 18 | Doctor | `platform:doctor` exit 0/1 | AWAITING_REAL_VPS |
| 19 | External firewall | ucloud/provider firewall: only 22/80/443 inbound | AWAITING_REAL_VPS |
| 20 | External port scan | only 22/80/443 reachable from outside | AWAITING_REAL_VPS |
| 21 | Webhooks | inbound webhook from an external service reaches the platform API over HTTPS | AWAITING_REAL_VPS |
| 22 | Public Reverb (WSS) | websocket connects over public TLS | AWAITING_REAL_VPS |
| 23 | Reboot VPS | verify stack auto-recovers (`restart: unless-stopped`) | AWAITING_REAL_VPS |
| 24 | Persistence after reboot | login, projects, storage, secrets intact | AWAITING_REAL_VPS |

## Items that MUST remain marked UNVERIFIED until a real VPS

- Real Ubuntu host installation (systemd, kernel, apt differences)
- Actual public IPv4 networking and routing
- Real DNS propagation
- Real public TLS certificate issuance (ACME HTTP-01 from Let's Encrypt)
- External firewall behavior (ufw rules in `deploy/production/bootstrap.sh`)
- Public-internet Reverb websockets (WSS through Caddy + TLS)
- External inbound webhook reachability (project webhooks over public URL)
- Real VPS reboot persistence
- Real provider network latency/performance

Do not mark any of these PASS based on local Docker testing.
