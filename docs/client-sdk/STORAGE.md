# Storage

Backend: `Storage` default disk (`local` dev → S3-compatible prod), per-bucket
policy (visibility, max size, MIME allowlist) enforced server-side, signed
expiring downloads. Contract: `docs/CLIENT_API_CONTRACT.md` §7.

```ts
// JS
const obj = await backend.storage.upload(file, { bucket: 'avatars', fileName: 'p.jpg', visibility: 'private' });
const url = await backend.storage.signedUrl('invoices', '2026/09/inv-1.pdf'); // { expires, signature } bearer URL
await backend.storage.remove('avatars', 'u/1/p.jpg');
```

```dart
// Dart
final obj = await backend.storage.upload(bucket: 'avatars', bytes: bytes, fileName: 'p.jpg');
final url = await backend.storage.signedUrl('invoices', '2026/09/inv-1.pdf');
```

```php
// PHP (server key, storage:write)
$backend->storage()->upload('avatars', '/tmp/p.jpg', ['visibility' => 'private']);
$url = $backend->storage()->signedUrl('invoices', '2026/09/inv-1.pdf');
```

Rules: user token or key with `storage:read` (download/signed URL) /
`storage:write` (upload/remove); policy violations → 422 `VALIDATION_ERROR`;
`..` / absolute keys → 400; large files use presigned direct-to-S3 POST
(`FILESYSTEM_DISK=s3` era) to bypass PHP workers. Signed URLs are bearers by
design — they expire, and must not be logged. Never address disks directly.
