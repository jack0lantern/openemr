#!/usr/bin/env bash
# Deploy OpenEMR to Railway following docker/production/docker-compose.yml
# Prerequisites: railway login, project linked to openemr/ directory
#
# Manual steps first (Railway dashboard):
# 1. + New → Database → MySQL
# 2. + New → GitHub Repo → select openemr-system, root: openemr/
# 3. Set OpenEMR env vars (see README.railway.md)
# 4. Add volumes: /var/www/localhost/htdocs/openemr/sites, /var/log
# 5. Enable Public Domain on OpenEMR service

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

railway up --detach
echo "Deployment triggered. First boot takes 3-5 min for DB setup."
echo "Check status: railway status"
