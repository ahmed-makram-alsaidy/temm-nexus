# Release Notes Template

Copy this file, fill it in for the new version, and include it in the release
publication. Keep entries factual — no overclaiming (no "enterprise-grade",
no security guarantees, no uptime promises).

---

## Platform vX.Y.Z

**Release date:** YYYY-MM-DD
**Status:** release / release candidate
**Minimum upgrade-from version:** X.Y.Z (see docs/open-source/UPGRADE_ROLLBACK.md)

### What's new

- <added feature — one line each, user-visible first>

### Changed

- <behavior changes, configuration changes>

### Fixed

- <fixes>

### Security

- <security-relevant fixes; reference advisories if any>

### Upgrade notes

- <operator actions required: env changes, migration notes, manual steps>

### Known limitations

- <honest list of what this release does NOT do>

### Verification

This release passed the following gates before publication:
- Fresh-install dogfood (clean state → setup → owner bootstrap → project)
- Restart persistence, multi-project isolation
- Full test suite (numbers: tests / assertions / failures)
- Secret scan + private-reference scan of the distribution artifact
