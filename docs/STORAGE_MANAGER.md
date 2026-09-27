# Storage manager (control plane)

Logical buckets (top-level folders, private/public marker) under the confined root
`<repo>/projects/<slug>/storage/control-plane/`. Browse, upload (24 MB cap),
download (owner-only signed route, audit logged), move/rename, delete with
confirmation, MIME/size/modified display, usage totals.

## Safety

- Every path resolved via realpath + prefix containment; `..`, backslashes,
  null bytes rejected (tested).
- UI can only ever address the project root — host filesystem unreachable.
- Production path stays S3-compatible: bucket abstraction maps to disks later
  without UI changes (see `docs/STORAGE.md`).
