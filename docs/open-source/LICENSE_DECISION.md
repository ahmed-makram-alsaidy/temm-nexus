# License Decision

**Decision (2026-09-27): AGPL-3.0-only.** The full license text ships as
[LICENSE](../../LICENSE) and package metadata identifies `AGPL-3.0-only`.
The comparison below is retained for the record; it is not legal advice.

## Apache-2.0 (permissive)

- Self-hosting: unrestricted; anyone runs it, including as a paid SaaS,
  with no source-disclosure obligation.
- Commercial use: fully open, including closed derivatives.
- Modifications: allowed under any license; patent grant included.
- SaaS implications: competitors may offer hosted versions using your code
  with zero obligation to share changes.
- Community contributions: automatically license-compatible.
- Enterprise/cloud model: maximum adoption; monetization relies on hosting,
  support, trademark or proprietary add-ons.

## AGPL-3.0-only (copyleft, network clause)

- Self-hosting: unrestricted for the operator's own use.
- Commercial use: allowed, but anyone offering the platform (or modified
  versions) as a network service MUST offer their modified source to their
  users — including competitors hosting it.
- Modifications: derivative work must remain AGPL-3.0.
- SaaS implications: the network-use clause closes the "free hosted
  competitor" gap that permissive licenses leave open.
- Community contributions: all contributions stay under AGPL-3.0.
- Enterprise/cloud model: dual-licensing common (AGPL + commercial license
  for customers needing closed integration).

## Practical comparison

| Concern | Apache-2.0 | AGPL-3.0-only |
|---|---|---|
| Max adoption | best | good |
| Prevents closed hosted competitors | no | yes |
| Patent protection | explicit grant | implied |
| Dual-licensing simplicity | n/a | common pattern |
| Corporate-friendliness | procurement-safe | some orgs prohibit AGPL |

## Decision checklist (owner)

- [ ] Choose license (or dual-license strategy)
- [ ] Add the LICENSE file
- [ ] Update README License section + CONTRIBUTING (DCO/CLA choice)
- [ ] Re-check dependency license compatibility (composer/npm/pub)
