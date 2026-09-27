#!/usr/bin/env bash
# 18C API auth proofs against the demo app (cpdemo-web must be running).
# Prints PASS/FAIL per step. Exits non-zero on first failure.
set -euo pipefail
t() { echo "--- $1"; }
fail() { echo "FAIL: $1"; echo "$2" | head -c 500; exit 1; }

t "login (customer)"
LOGIN=$(docker exec backend-infra-owner-console-1 curl -s -H 'Accept: application/json' -X POST http://cpdemo-web:8000/api/auth/login -H 'Content-Type: application/json' -d '{"email":"customer@demo.test","password":"demo-pass-123","device_name":"e2e"}')
echo "$LOGIN" | grep -q '"token"' || fail "login" "$LOGIN"
TOKEN=$(echo "$LOGIN" | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')
[ -n "$TOKEN" ] || fail "empty token" "$LOGIN"
echo "PASS login (token ${#TOKEN} chars)"

t "token-authenticated /auth/me"
ME=$(docker exec backend-infra-owner-console-1 curl -s -H 'Accept: application/json' http://cpdemo-web:8000/api/auth/me -H "Authorization: Bearer $TOKEN")
echo "$ME" | grep -q 'customer@demo.test' || fail "me" "$ME"
echo "PASS me"

t "logout revokes token"
docker exec backend-infra-owner-console-1 curl -s -H 'Accept: application/json' -X POST http://cpdemo-web:8000/api/auth/logout -H "Authorization: Bearer $TOKEN" | grep -q 'Logged out' || fail "logout" ""
ME2=$(docker exec backend-infra-owner-console-1 curl -s -H 'Accept: application/json' -o /dev/null -w '%{http_code}' http://cpdemo-web:8000/api/auth/me -H "Authorization: Bearer $TOKEN")
[ "$ME2" = "401" ] || fail "revoked token still works (http $ME2)" ""
echo "PASS revoke (old token -> 401)"

t "disabled user cannot authenticate"
DIS=$(docker exec backend-infra-owner-console-1 curl -s -H 'Accept: application/json' -o /dev/null -w '%{http_code}' -X POST http://cpdemo-web:8000/api/auth/login -H 'Content-Type: application/json' -d '{"email":"disabled@demo.test","password":"demo-pass-123"}')
[ "$DIS" = "403" ] || fail "disabled login http=$DIS (want 403)" ""
echo "PASS disabled -> 403"

t "wrong password rejected"
BAD=$(docker exec backend-infra-owner-console-1 curl -s -H 'Accept: application/json' -o /dev/null -w '%{http_code}' -X POST http://cpdemo-web:8000/api/auth/login -H 'Content-Type: application/json' -d '{"email":"customer@demo.test","password":"wrong-pass-xyz"}')
[ "$BAD" = "422" ] || fail "bad password http=$BAD (want 422)" ""
echo "PASS wrong password -> 422"

echo "ALL AUTH PROOFS PASS"
