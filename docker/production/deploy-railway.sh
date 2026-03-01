#!/usr/bin/env bash
# Deploy OpenEMR to Railway using docker-compose
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

echo "Make sure you have run 'railway link' to connect to your project."
echo "Deploying using Railway Up..."

railway up -d

echo "Deployment triggered. First boot takes 3-5 min for DB setup."
echo "Check status: railway status"
