# Release Process

1. **Branch & version.** Update the root `VERSION` file (SemVer — the UI and
   scripts report it). While pre-1.0, breaking changes may land in minor
   versions (document them in CHANGELOG under "Changed").

2. **Changelog.** Add a `CHANGELOG.md` entry (Added/Changed/Fixed/Security).
   Private/customer history never enters the changelog — the public history
   starts at the first published release.

3. **Release notes.** Fill `docs/open-source/RELEASE_TEMPLATE.md` for the
   announcement.

4. **Quality gates (all must pass):**
   - Owner Console full test suite (in-repo suites; see CI workflow)
   - SDK test suites (JS, PHP)
   - `scripts/security/secret-scan.sh` — no credential material
   - `scripts/release/package-dist.sh` — builds the artifact AND runs the
     private-reference scan; the RC fails if private references are found

5. **Dogfood.** From the packaged artifact: fresh install (compose up →
   /setup → owner → project), restart persistence, multi-project isolation,
   backup + restore drill, upgrade drill. Record results in the release
   notes "Verification" section.

6. **Blockers.** Before ANY public publication, all items in the release
   blockers list must be resolved by the owner:
   - LICENSE file exists (owner decision — see docs/open-source/LICENSE_DECISION.md)
   - Public project name chosen (config + docs no longer use the internal name)
   - Security contact configured (SECURITY.md + SECURITY_CONTACT env)
   - Installation tested on a real external VPS
   - Docker registry / GitHub publication target configured

7. **Tag.** `git tag -a vX.Y.Z -m "Platform vX.Y.Z"` — push only when
   publication is explicitly intended.

Publication (GitHub, Docker registry, announcements) is a separate, explicit
step performed by the repository owner. This repository intentionally ships
with no automated publication.
