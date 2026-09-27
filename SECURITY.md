# Security Policy

## Supported versions

The platform is pre-1.0. Only the **latest tagged release** receives security
fixes. Upgrade before reporting issues against older versions.

## Reporting a vulnerability

**Do not open a public issue for security reports.**

Use GitHub's **private vulnerability reporting** for this repository
(Security tab → "Report a vulnerability"). This is the only supported
reporting route until a dedicated security contact address is announced
in this file. If private vulnerability reporting is unavailable, open a
minimal public issue asking for a private channel — do not include
vulnerability details.

Include: affected version (from the console footer or `VERSION`), the
surface (setup wizard, admin API, project function runtime, connector, AI
gateway, …), steps to reproduce, and impact. A proof of concept that does
not exfiltrate data is appreciated.

You will receive an acknowledgement; fixes are released and credited
(opt-out) in the changelog.

## Scope

- The platform application and its Docker distribution
- The first-run setup wizard and admin surfaces
- Client SDKs (JavaScript, PHP, Dart)
- Included installation/upgrade scripts

Out of scope: the operator's own server configuration, the underlying
Laravel/Filament/PostgreSQL/Redis/Caddy projects (report upstream, but let
us know so we can ship updated images), and social engineering.

## What NOT to publish publicly

- Production Supabase refs, PATs, database passwords or AI keys
- Concrete takeover steps against a live instance before a fix exists
- Operator data from your own testing

## Security posture summary

- No default credentials; the first owner is created through the locked
  first-run wizard
- Secrets (PATs, AI keys, project secrets, settings) encrypted at rest
- PostgreSQL/Redis never host-published in production
- Rate limiting on login, setup and sensitive admin endpoints
- Security headers at the edge; secure cookies by default
- No telemetry; outbound calls only where the operator configures them

Hardening details: [docs/open-source/DISTRIBUTION_SECURITY.md](docs/open-source/DISTRIBUTION_SECURITY.md)
