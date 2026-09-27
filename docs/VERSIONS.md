# Pinned versions (verified 2026-09-16)

| Component | Pin | Verification source |
|---|---|---|
| Ubuntu (VPS design) | 24.04 LTS Noble (support → Jun 2029) | ubuntu.com/releases, endoflife.date/ubuntu. 26.04 LTS (Resolute, Apr 2026, 26.04.1 Aug 2026) exists — noted as upgrade path, not day-1 |
| Laravel | `^13.0` (observed 13.31.0) | laravel.com/docs/releases: L13 PHP 8.3–8.5, bugs → Q3 2027, sec → Mar 2028 |
| PHP | `8.4.25` (`php:8.4.25-fpm-alpine`) | php.watch 8.4.25 (Aug 2026); satisfies L13 `^8.3` |
| PostgreSQL | `17.11` (`postgres:17.11-alpine`) | postgresql.org: 17.11 + 18.6 released Aug 2026; PG17 supported → Nov 2029; matches Supabase 17.6.x |
| Redis | `8.2` (`redis:8.2-alpine`) | hub.docker.com/_/redis tags: 8.2/8.4/8.6/8.8/8.10 lines; fallback 7.4-alpine |
| Caddy | `2.10.2` (`caddy:2.10.2-alpine`) | PHP-FPM+Caddy tag survey shows caddy 2.10.2 current |
| pgAdmin | `9.8` (`dpage/pgadmin4:9.8`) | pinned minor; never `latest` |
| Mailpit (local only) | `v1.21.3` (`axllent/mailpit:v1.21.3`) | local mail testing |
| Composer (build) | `2.8` (`composer:2.8`) | build stage only |

## Why these pins

- **Ubuntu 24.04 over 26.04**: 24.04.4 has 2 years of production burn-in; Docker CE +
  UFW + Fail2ban playbooks are stable. 26.04 upgrade documented in `docs/SECURITY_HARDENING.md`.
- **PHP 8.4 over 8.3/8.5**: 8.3 is minimum for L13 but EOL sooner; 8.5 (Nov 2025
  major) is too new for ext parity (imagick/swoole). 8.4 is the sweet spot.
- **PG17 over PG18**: PG18.0 Sep 2025 → 18.6 Aug 2026 is still young; PG17.11 is
  mature, Supabase-compatible (migration source is PG17), supported to Nov 2029.
- **Redis 8.2**: 8.x is stable; 8.2-alpine has broad Laravel Horizon/Pulse
  compatibility. Memory policy `noeviction` + per-project prefixes (see Phase 4).
- **Caddy over Nginx**: automatic HTTPS + Caddyfile simplicity outweigh Nginx
  familiarity at this scale; Nginx alternative config documented in
  `infrastructure/caddy/README.md`.
