# Naming / Branding

**Decision (2026-09-27): the public product name is TEMM Nexus.**
Tagline: *Move your backend. Own your infrastructure.* Usage policy:
[TRADEMARKS.md](../../TRADEMARKS.md).

Implementation notes:

- The product name is configurable: `PLATFORM_NAME` / `BRAND_NAME` in
  `.env` (default `TEMM Nexus`; `config/platform.php`).
- The UI, setup wizard, welcome page and documentation use the configured
  name. Operators can rebrand their instance without code changes.
- No destructive namespace renames were performed for the branding.
