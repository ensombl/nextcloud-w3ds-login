#!/bin/sh
chown www-data:www-data /var/www/html/custom_apps 2>/dev/null || true
exec /entrypoint.sh "$@"
