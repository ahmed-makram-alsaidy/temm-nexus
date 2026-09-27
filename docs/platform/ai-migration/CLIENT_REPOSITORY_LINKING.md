# Client Repository Linking

> 25D · `ClientRepositoryService` · Page: Project → Client Repository

## Local path (25D.1)

The operator explicitly approves a root path. Guards:

- the path must exist and be a directory;
- obvious system roots are refused (`/etc`, `/proc`, `/sys`, `/dev`, `/bin`,
  `/boot`, `C:\Windows`, filesystem roots);
- the resolved root is persisted on `client_repositories.root_path`;
- EVERY filesystem read/write (scanner, copilot file reads, patch apply)
  goes through `safePath`/`safePathForRoot`: traversal (`..`) rejected,
  absolute paths rejected, realpath containment under the approved root
  enforced. The AI can never browse arbitrary machine folders.

## Git repository (25D.2)

Metadata model only in this phase: URL (https/ssh validated), branch, and a
vault credential REFERENCE name. No clone, no credential handling, no hosted
provider OAuth — nothing insecure is invented.

## Repository inventory (25D.3)

Detected at link time: framework (`flutter` via pubspec.yaml, `php` via
composer.json, `react`/`javascript` via package.json deps, else `unknown`),
package files, environment file NAMES only (never values), test directories,
and a `supabase/` directory marker.

Secret values are never read into AI context — env files contribute their
names to the inventory and their secret-bearing lines to the hashed secret
scan only.
