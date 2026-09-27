# Privacy

**Default: no telemetry.** The platform ships without analytics, crash
uploading, phone-home, or update pings. No project metadata leaves the
server by default.

## Outbound calls — exhaustive list

| Feature | Calls | When |
|---|---|---|
| TLS certificates | Let's Encrypt ACME | only when PRIMARY_DOMAIN is set |
| AI Copilot | your configured AI provider | only with a configured key AND when you invoke AI |
| Mail | your configured SMTP | only if MAIL_MAILER=smtp and mail is used |
| Supabase import | your Supabase account (management API + DB) | only when you configure an import |

Everything else — dashboards, metrics, health, backups, migrations — runs
locally.

## AI data minimization

When you use the copilot: scoped, redacted context packs only (analysis
inventories, selected files you approve, failure summaries). Secrets,
credentials and unselected project content are never included.

## Future telemetry policy

If telemetry is ever considered, it MUST be explicit opt-in, documented in
this file, and reviewable in the code. There is currently none, hidden or
otherwise.
