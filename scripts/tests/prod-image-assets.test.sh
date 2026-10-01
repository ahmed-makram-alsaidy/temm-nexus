#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────
# Production image frontend-asset regression test.
#
#   scripts/tests/prod-image-assets.test.sh <image>
#
# Guards against publishing a production application image without the
# generated frontend assets (the Phase 35.5-pre live defect: the edge
# served an unstyled Filament UI because the image never ran
# `php artisan filament:assets` and no Vite build existed).
#
# Asserts:
#   1. Filament compiled CSS/JS/fonts exist in the image.
#   2. If a Vite build (public/build) exists, manifest.json must exist and
#      every file it references must exist (CSS/JS entries included).
#
# Non-fatal assumptions: uses docker; the image entrypoint is overridden.
# ─────────────────────────────────────────────────────────────────
set -euo pipefail

IMAGE="${1:?usage: prod-image-assets.test.sh <image>}"

fail=0

run() { # $1 label, script on stdin
  local label="$1"
  if docker run --rm -i --entrypoint sh "$IMAGE" -s; then
    echo "ok: $label"
  else
    echo "FAIL: $label"
    fail=$((fail + 1))
  fi
}

run "Filament compiled CSS present (public/css/filament/filament/app.css)" <<'EOF'
test -f /var/www/html/public/css/filament/filament/app.css
test -s /var/www/html/public/css/filament/filament/app.css
EOF

run "Filament compiled JS present (public/js/filament/filament/app.js)" <<'EOF'
test -f /var/www/html/public/js/filament/filament/app.js
test -s /var/www/html/public/js/filament/filament/app.js
EOF

run "Filament font assets present (public/fonts/filament)" <<'EOF'
test -f /var/www/html/public/fonts/filament/filament/inter/index.css
EOF

run "Vite build consistency (manifest references real files, when a build exists)" <<'EOF'
if [ -d /var/www/html/public/build ]; then
  test -f /var/www/html/public/build/manifest.json
  php -r '
    $m = json_decode(file_get_contents("/var/www/html/public/build/manifest.json"), true);
    if (!is_array($m) || $m === []) { fwrite(STDERR, "manifest.json unreadable or empty\n"); exit(1); }
    $refs = [];
    foreach ($m as $key => $entry) {
      if (!empty($entry["file"])) { $refs[] = $entry["file"]; }
      foreach ((array) ($entry["css"] ?? []) as $css) { $refs[] = $css; }
      foreach ((array) ($entry["assets"] ?? []) as $asset) { $refs[] = $asset; }
    }
    if ($refs === []) { fwrite(STDERR, "manifest.json references no files\n"); exit(1); }
    foreach ($refs as $ref) {
      if (!is_file("/var/www/html/public/build/" . $ref)) {
        fwrite(STDERR, "manifest references missing file: {$ref}\n");
        exit(1);
      }
    }
    echo "manifest OK: " . count($refs) . " referenced file(s) present\n";
  '
else
  echo "no public/build (app templates do not consume Vite yet) — skipped"
fi
EOF

echo "prod-image-assets: $fail failed"
[ "$fail" = "0" ]
