# SDK versioning (Phase 22H)

SemVer for all three SDKs, independent of each other and of the Laravel app version.

## Compatibility matrix

| Platform API | JS SDK (`@platform/backend-sdk`) | Flutter (`backend_sdk_dart`) | PHP (`platform/backend-sdk`) |
|---|---|---|---|
| v1 (this phase) | 0.1.0 | 0.1.0 | 0.1.0 |

- `0.x`: API v1, local/private distribution, breaking changes allowed with a
  changelog entry and a Connect-page note. No `1.0` before a production VPS
  proves the contract end-to-end.
- SDK versions are NOT bound to Laravel app versions. A project on any Laravel
  13.x template works with any SDK whose row covers its API version.
- Contract changes: additive only on v1 (new fields/endpoints). Anything
  breaking ships as `/api/v2` + migration note, never as a silent v1 change.

## Changelog structure (all SDKs)

`CHANGELOG.md` per package, Keep-a-Changelog format: `## [x.y.z] — date`
with bullet lists of added/changed/fixed. Unpublished in Phase 22 (local use).
