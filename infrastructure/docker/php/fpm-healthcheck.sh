#!/bin/sh
# FPM readiness probe: executes the Laravel /up health route through FPM
# itself, proving the whole request path (FPM workers + app boot) works.
# Uses the cgi-fcgi binary shipped in the app image.
#
# CGI status semantics: PHP emits an explicit "Status:" header only for
# non-200 responses — a successful 200 carries no Status header at all
# (200 is the FastCGI default). Matching the literal string "200 OK"
# therefore fails on every healthy response. Instead: require a complete
# CGI response (headers, including Content-Type) and reject any explicit
# Status header that is not 200.
set -u

RESP="$(SCRIPT_NAME=/up \
REQUEST_URI=/up \
SCRIPT_FILENAME=/var/www/html/public/index.php \
REQUEST_METHOD=GET \
QUERY_STRING= \
CONTENT_LENGTH=0 \
timeout 5 cgi-fcgi -bind -connect 127.0.0.1:9000 2>/dev/null)" || exit 1

# An aborted or failed exchange (FPM down, timeout, connection reset)
# produces an empty or headerless payload — never healthy.
printf '%s\n' "$RESP" | grep -qi '^Content-Type:' || exit 1

# Any explicit status that is not 200 (500, 404, 302, ...) is unhealthy.
if printf '%s\n' "$RESP" | grep -qi '^Status:[[:space:]]'; then
  printf '%s\n' "$RESP" | grep -Eiq '^Status:[[:space:]]*200([[:space:]]|$)' || exit 1
fi
