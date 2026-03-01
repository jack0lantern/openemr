#!/usr/bin/env bash
# Deploy OpenEMR and MariaDB to Railway via CLI
# Prerequisites: railway login, project linked to openemr-system/ directory

set -e
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OPENEMR_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

echo "Deploying OpenEMR from $OPENEMR_ROOT"
cd "$OPENEMR_ROOT"

if ! command -v railway &>/dev/null; then
  echo "Railway CLI not found. Install: npm install -g @railway/cli"
  exit 1
fi

if ! railway whoami &>/dev/null; then
  echo "Not logged in. Run: railway login"
  exit 1
fi

echo "Ensuring project is linked..."
if ! railway status &>/dev/null; then
  echo "No linked project found. Please run 'railway link' first."
  exit 1
fi

echo "--- Deploying MariaDB ---"
# Add mariadb service if it doesn't exist
if ! railway service list | grep -q "mariadb"; then
  echo "Provisioning mariadb:11.8..."
  railway add --service mariadb --image mariadb:11.8 \
    -v MYSQL_ROOT_PASSWORD=root \
    -v MYSQL_DATABASE=openemr \
    -v MYSQL_USER=openemr \
    -v MYSQL_PASSWORD=openemr
else
  echo "MariaDB service already exists."
fi

echo "--- Deploying OpenEMR ---"
# Configure variables to connect to mariadb
echo "Configuring OpenEMR variables..."
railway variable set --skip-deploys \
  MYSQL_HOST=\${{mariadb.RAILWAY_PRIVATE_DOMAIN}} \
  MYSQL_ROOT_PASS=root \
  MYSQL_USER=openemr \
  MYSQL_PASS=openemr \
  MYSQL_PORT=3306 \
  OE_USER=admin \
  OE_PASS=pass \
  || true

echo "Triggering OpenEMR deployment..."
railway up -d

echo "Deployment triggered. First boot takes 3-5 min for DB setup."
echo "Check status: railway status"
