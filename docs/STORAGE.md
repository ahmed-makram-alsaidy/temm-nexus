# Storage strategy (Phase 16)

## Principle

Application code NEVER addresses disks directly — only Laravel `Storage` facades
with a configured default. Moving from VPS disk to object storage = env change.

## Configuration (template default)

```env
FILESYSTEM_DISK=local            # dev / day-1 VPS
# Production later:
# FILESYSTEM_DISK=s3
# AWS_ACCESS_KEY_ID=...
# AWS_SECRET_ACCESS_KEY=...
# AWS_DEFAULT_REGION=auto
# AWS_BUCKET=project-a-uploads
# AWS_ENDPOINT=https://<account>.r2.cloudflarestorage.com
# AWS_USE_PATH_STYLE_ENDPOINT=false
```

Suitable S3-compatible targets: Cloudflare R2 (no egress fees — default pick),
Backblaze B2, AWS S3. DB + runtime stay on VPS SSD; only user uploads move.

## Rules for product code (enforced in review)

- `Storage::disk()` without name (uses default) — never hard-code `local`/`s3`.
- Public URLs via `Storage::url()` / temporary URLs (`temporaryUrl()` for private).
- Direct-to-R2 uploads for large files (presigned POST) to bypass PHP workers.
- `backup-files.sh` covers the `local` era; after S3 cutover, bucket versioning +
  lifecycle rules replace it (documented per project at cutover).

## GATE 16

- Local disk storage: proven (all template/owner apps run `local`, uploads +
  `storage/app` tar via `backup-files.sh`) ✅
- S3-compatible placeholder: `.env.example` + per-app `config/filesystems.php`
  `s3` disk (skeleton default, endpoint-overridable) — no paid account needed,
  switch is config-only ✅
