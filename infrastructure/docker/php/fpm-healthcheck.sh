#!/bin/sh
# FPM readiness probe: executes the Laravel /up health route through FPM
# itself, proving the whole request path (FPM workers + app boot) works.
# Uses the cgi-fcgi binary shipped in the app image.
SCRIPT_NAME=/up \
REQUEST_URI=/up \
SCRIPT_FILENAME=/var/www/html/public/index.php \
REQUEST_METHOD=GET \
QUERY_STRING= \
CONTENT_LENGTH=0 \
timeout 5 cgi-fcgi -bind -connect 127.0.0.1:9000 2>/dev/null | grep -q "200 OK"
