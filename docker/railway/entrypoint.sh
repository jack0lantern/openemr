#!/bin/sh
# Railway entrypoint: ensure sites/default/sqlconf.php exists before openemr.sh runs.
# Fixes "Failed to open stream: No such file or directory" when volume is empty on first deploy.

set -e

SITES_DIR="/var/www/localhost/htdocs/openemr/sites"
DEFAULT_SITE="$SITES_DIR/default"
SQLCONF="$DEFAULT_SITE/sqlconf.php"

# Populate sites from swarm-pieces if sqlconf.php is missing (empty volume on first deploy)
if [ ! -f "$SQLCONF" ]; then
    echo "Sites directory appears empty. Populating from /swarm-pieces/sites..."
    if [ -f /swarm-pieces/sites/default/sqlconf.php ]; then
        cp -a /swarm-pieces/sites/default/sqlconf.php "$SQLCONF"
    elif [ -f /railway-sqlconf-template.php ]; then
        cp /railway-sqlconf-template.php "$SQLCONF"
    else
        # Fallback: create minimal sqlconf.php so require_once does not fail
        cat > "$SQLCONF" << 'SQLCONF'
<?php
$host   = getenv('MYSQL_HOST') ?: 'localhost';
$port   = getenv('MYSQL_PORT') ?: '3306';
$login  = getenv('MYSQL_USER') ?: 'openemr';
$pass   = getenv('MYSQL_PASS') ?: 'openemr';
$dbase  = getenv('MYSQL_DATABASE') ?: 'openemr';

$sqlconf = [];
global $sqlconf;
$sqlconf["host"]= $host;
$sqlconf["port"]= $port;
$sqlconf["login"]= $login;
$sqlconf["pass"]= $pass;
$sqlconf["dbase"]= $dbase;

$config = 0;
SQLCONF
    fi
    chown -R apache:root "$SITES_DIR" 2>/dev/null || true
fi

# Ensure meta/ health endpoint is accessible
chown -R apache:root /var/www/localhost/htdocs/openemr/meta 2>/dev/null || true
chmod -R 755 /var/www/localhost/htdocs/openemr/meta 2>/dev/null || true

# Patch Apache for Railway PORT if needed
PORT="${PORT:-80}"
if [ "$PORT" != "80" ]; then
    for conf in /etc/apache2/httpd.conf /etc/apache2/ports.conf /etc/apache2/apache2.conf; do
        if [ -f "$conf" ]; then
            sed -i "s/^Listen 80$/Listen ${PORT}/" "$conf"
            sed -i "s/^Listen 0.0.0.0:80$/Listen 0.0.0.0:${PORT}/" "$conf"
        fi
    done
    find /etc/apache2 -name "*.conf" -exec sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/g" {} \;
fi

# Ensure required directories exist (repairs existing volumes from previous failed deploys)
mkdir -p "$DEFAULT_SITE/documents"
mkdir -p "$DEFAULT_SITE/images"
mkdir -p "$DEFAULT_SITE/LBF"
chown -R apache:root "$SITES_DIR" 2>/dev/null || true

cd /var/www/localhost/htdocs/openemr
exec ./openemr.sh
