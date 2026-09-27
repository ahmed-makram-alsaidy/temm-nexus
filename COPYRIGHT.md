# Copyright & Ownership

TEMM Nexus — the open-source, self-hosted backend migration & control
plane — is Copyright © 2026 Ahmed Makram El-Saidy.

## License

The TEMM Nexus source code in this repository is released under the
**GNU Affero General Public License v3.0 only** (`AGPL-3.0-only`).
See [LICENSE](LICENSE) for the full license text.

This means, in short:

- You are free to use, study, modify and redistribute TEMM Nexus.
- If you run a modified version as a network service, you must offer
  the corresponding source of that modified version to the users of
  that service (AGPL §13).
- There is no warranty, to the extent permitted by law.

This summary is informational only — the [LICENSE](LICENSE) file is the
only authoritative statement of the license terms.

## Scope of the copyright claim

The copyright statement above covers the original TEMM Nexus project
source: the owner console application, the connectors, the Connector
SDK, the client SDKs, the deployment/installation tooling, and the
project documentation written for this project.

**It does not cover third-party dependencies.** TEMM Nexus builds on
many open-source projects (Laravel, Filament, PostgreSQL client tooling,
Redis, Caddy, and others). Each dependency keeps its own copyright and
license. Dependency inventories live in the package manifests
(`apps/owner-console/composer.json`, `packages/*/package.json`,
`composer.json`, `pubspec.yaml`) and in the SBOM documentation
([docs/open-source/SBOM.md](docs/open-source/SBOM.md)).

## Contributions

Unless you state otherwise, any contribution intentionally submitted for
inclusion in TEMM Nexus is provided under the project license
(`AGPL-3.0-only`), without additional terms or conditions.
