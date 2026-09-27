# Local / private distribution (Phase 22I)

Public publishing to npm / pub.dev / Packagist is explicitly OUT of scope.

## JavaScript — npm-compatible, file-linked

```json
"dependencies": { "@platform/backend-sdk": "file:../../packages/backend-sdk-js" }
```

`package.json` has correct `main` / `types` / `exports` / `files` so the day-1
publish is `npm publish --access restricted` with no layout change. Until then:
private scope, `"private": true`, `UNLICENSED`.

## Flutter — path / git

```yaml
dependencies:
  backend_sdk:
    path: ../../packages/backend_sdk_dart
    # later: git: { url: <private repo>, ref: v0.1.0, path: packages/backend_sdk_dart }
```

`pubspec.yaml` is publish-ready (name/description/version/repository).
`dart pub get` + `dart test` run offline once hosted deps are cached.

## PHP — Composer path repo

```json
"repositories": [{ "type": "path", "url": "../../packages/backend-sdk-php" }],
"require": { "platform/backend-sdk": "@dev" }
```

PSR-4 (`Platform\BackendSdk\`), no runtime deps, so private Packagist/Satis
later needs no restructuring.

## What NOT to do this phase

No public publish, no `latest` tags, no committing built `dist/` output
(except the local `dist/` already produced by `npm run build` for tests —
untracked), no secrets in any package tarball (`files` allowlists only).
