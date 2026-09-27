#!/usr/bin/env sh
# Repo hygiene gate: fails if secrets or runtime data are git-tracked.
# Run locally and in CI before every push.
set -eu
cd "$(dirname "$0")/.."
FAIL=0

# 1. No real .env files tracked (only *.example allowed).
if git ls-files 2>/dev/null | grep -E '(^|/)\.env($|\.)' | grep -v '\.example$' | grep -q .; then
  echo "FAIL: real .env file tracked:"; git ls-files | grep -E '(^|/)\.env($|\.)' | grep -v '\.example$'; FAIL=1
else echo "ok: no tracked .env files"; fi

# 2. No private keys / tokens in tracked content.
if git grep -nE 'BEGIN (RSA |OPENSSH |EC )?PRIVATE KEY|AKIA[0-9A-Z]{16}|xox[bpas]-|ghp_[A-Za-z0-9]{20,}' -- ':!.env.example' ':(exclude)*.example' 2>/dev/null | grep -q .; then
  echo "FAIL: possible secret in tracked files:"; git grep -nE 'BEGIN (RSA |OPENSSH |EC )?PRIVATE KEY|AKIA[0-9A-Z]{16}' -- .; FAIL=1
else echo "ok: no obvious secrets tracked"; fi

# 3. .gitignore covers the essentials.
for PAT in '^\.env$' 'backups/\*\.dump' 'vendor/'; do
  grep -Eq "$PAT" .gitignore || { echo "FAIL: .gitignore missing $PAT"; FAIL=1; }
done
echo "ok: .gitignore covers .env, dumps, vendor"

# 4. No `latest` image tags in compose.
if grep -nE 'image:\s*[^: ]+:latest' docker-compose.yml deploy/production/*.yml 2>/dev/null | grep -q .; then
  echo "FAIL: :latest tag pinned somewhere"; FAIL=1
else echo "ok: no :latest tags"; fi

[ "$FAIL" = "0" ] && echo "SECURITY-CHECK OK" || { echo "SECURITY-CHECK FAILED"; exit 1; }
