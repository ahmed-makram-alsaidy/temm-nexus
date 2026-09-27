# VPS Requirements

Recommendations, not hard guarantees — the stack is config-driven and the
per-service memory limits in docker-compose.prod.yml can be tuned.

| Tier | vCPU | RAM | Disk | Suitable for |
|---|---|---|---|---|
| Evaluation | 2 | 4 GB | 20 GB | single project, light usage |
| Small production | 4 | 8 GB | 60 GB | a handful of projects with real traffic |
| Multi-project | 8 | 16 GB | 120 GB+ | many projects, frequent migrations/backups |

Notes:

- Disk is dominated by PostgreSQL data + backups; keep ≥ 2× your largest
  database free for dumps (the upgrader refuses under 2 GB free).
- RAM: postgres is capped at ~1.5 GB, app 768 MB, redis 512 MB by default;
  these defaults suit the "small production" tier.
- OS: Ubuntu 24.04 LTS recommended; any Docker-capable Linux works.
- Outbound internet is needed during install (image pulls, composer).
  Runtime needs outbound only for what you configure (mail, AI BYOK,
  certificate issuance with a domain).
- Open firewall ports: 22 (SSH), 80, 443. Nothing else.
