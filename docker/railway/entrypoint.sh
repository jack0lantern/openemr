#!/bin/sh
# Railway entrypoint: ensure sites/default/sqlconf.php exists before openemr.sh runs.
# Fixes "Failed to open stream: No such file or directory" when volume is empty on first deploy.
# Waits for MySQL when MYSQL_HOST is remote (mirrors docker-compose depends_on: service_healthy).

set -e

SITES_DIR="/var/www/localhost/htdocs/openemr/sites"
DEFAULT_SITE="$SITES_DIR/default"
SQLCONF="$DEFAULT_SITE/sqlconf.php"
CONFIG_PHP="$DEFAULT_SITE/config.php"

# Populate sites from swarm-pieces if required site config files are missing
if [ ! -f "$SQLCONF" ] || [ ! -f "$CONFIG_PHP" ]; then
    echo "Required site config files are missing. Populating from /swarm-pieces/sites..."
    if [ -d /swarm-pieces/sites ]; then
        cp -a /swarm-pieces/sites/. "$SITES_DIR/"
    else
        # Fallback: create minimal sqlconf.php so require_once does not fail
        mkdir -p "$DEFAULT_SITE"
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

# Wait for MySQL when using remote host (mirrors docker-compose depends_on: service_healthy)
export MYSQL_HOST="${MYSQL_HOST:-localhost}"
export MYSQL_PORT="${MYSQL_PORT:-3306}"
if [ "$MYSQL_HOST" != "localhost" ] && [ "$MYSQL_HOST" != "127.0.0.1" ]; then
    echo "Waiting for MySQL at $MYSQL_HOST:$MYSQL_PORT..."
    max_attempts=90
    attempt=0
    while [ $attempt -lt $max_attempts ]; do
        if php -r "
            \$h = getenv('MYSQL_HOST') ?: 'localhost';
            \$p = (int)(getenv('MYSQL_PORT') ?: 3306);
            \$s = @fsockopen(\$h, \$p, \$err, \$errstr, 2);
            if (\$s) { fclose(\$s); exit(0); }
            exit(1);
        " 2>/dev/null; then
            echo "MySQL is ready."
            break
        fi
        attempt=$((attempt + 1))
        if [ $attempt -ge $max_attempts ]; then
            echo "Timeout waiting for MySQL after ${max_attempts} attempts."
            exit 1
        fi
        sleep 2
    done
fi

cd /var/www/localhost/htdocs/openemr
exec ./openemr.sh
