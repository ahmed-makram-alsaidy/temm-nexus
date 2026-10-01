# Release Notes — Platform v0.3.0-rc.3

**Release date:** 2026-10-01
**Status:** PRE-RELEASE — release candidate (not stable)
**Minimum upgrade-from version:** 0.1.0

## What's new

Release-closure fixes found during the v0.3.0-rc.2 stability soak
(Phase 36.5). No product features; rc.1 and rc.2 remain frozen.

### Fixed

- **Connector Catalog page rendered HTTP 500 for every admin.** Two
  defects, found live through the real browser: `Table::columns()` was
  given a Closure (this Filament version requires an array), and the
  page's `render()` override bypassed the Filament panel page lifecycle,
  so Livewire fell back to the missing default `layouts.app`. The page
  now declares its view like every other page; a feature regression
  asserts the page renders (fails against rc.2).
- **Upgrader: the Caddy edge now also refreshes when the app container
  is recreated.** Live on the soak: after any app-container recreation
  (every real code upgrade rebuilds the image), the running old Caddy
  served empty 404s on HTTPS while HTTP:80 kept redirecting, until
  caddy itself was recreated. The upgrader compares the app container ID
  before/after activation and force-recreates caddy in that case, in
  addition to the existing Caddyfile-fingerprint rule. Unchanged config
  and unchanged app still leave the edge untouched (verified: the rc.3
  upgrade itself did not need to touch caddy's configuration).

### Security

- No security-relevant changes. `composer audit`: 0 advisories; secret
  and private-reference scans pass.

### Verification

- Hermetic blocking suite: 427 tests / 2209 assertions / 0 failures /
  0 errors (includes the new page regression).
- CI green on the release PR (fresh-install smoke + both caddy-refresh
  regressions).
- Live upgrade rc.2 → rc.3 on the Azure test VM; Connector Catalog
  renders (all six first_party connectors); Doctor 18 PASS / 0 FAIL;
  HTTPS healthy; CDC soak streams reconciled with 0 delta.
