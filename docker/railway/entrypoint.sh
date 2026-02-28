#!/bin/sh
# Railway provides a dynamic PORT env var for HTTP routing.
# Apache in the OpenEMR base image listens on port 80 by default.
# This script patches the Apache config to use PORT, then hands off
# to the original OpenEMR startup script.

set -e

PORT="${PORT:-80}"

# If the sites directory was mounted as an empty volume (e.g. on Railway),
# populate it from the pre-packaged swarm-pieces directory before openemr.sh runs.
if [ ! -f /var/www/localhost/htdocs/openemr/sites/default/sqlconf.php ]; then
    echo "Sites directory appears empty. Populating from /swarm-pieces/sites..."
    rsync --owner --group --perms --recursive --links /swarm-pieces/sites/ /var/www/localhost/htdocs/openemr/sites/
fi

# Ensure meta/ health endpoint is always accessible by Apache, regardless of
# what the base image's openemr.sh does to directory permissions during setup.
chown -R apache:root /var/www/localhost/htdocs/openemr/meta 2>/dev/null || true
chmod -R 755 /var/www/localhost/htdocs/openemr/meta 2>/dev/null || true

# Patch Apache's listening port if it differs from 80
if [ "$PORT" != "80" ]; then
    for conf in /etc/apache2/httpd.conf /etc/apache2/ports.conf /etc/apache2/apache2.conf; do
        if [ -f "$conf" ]; then
            sed -i "s/^Listen 80$/Listen ${PORT}/" "$conf"
            sed -i "s/^Listen 0.0.0.0:80$/Listen 0.0.0.0:${PORT}/" "$conf"
        fi
    done
    # Patch any VirtualHost directives that reference port 80
    find /etc/apache2 -name "*.conf" -exec sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/g" {} \;
fi

# Hand off to the original OpenEMR startup script (WORKDIR is /var/www/localhost/htdocs/openemr)
exec ./openemr.sh
