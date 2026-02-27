# Deploying OpenEMR on Railway

## Overview

OpenEMR is deployed as a single service backed by a Railway MySQL database. On first boot, the container auto-provisions the database schema (~3–5 min).

## Setup

### 1. Add a MySQL database service

In your Railway project, click **+ New → Database → MySQL**.

### 2. Deploy the OpenEMR service

Link this repository (or the `openemr/` subdirectory) as a new Railway service. Railway will automatically detect `railway.toml` and build from `Dockerfile.railway`.

### 3. Set environment variables

In the OpenEMR service **Variables** tab, add the following. Use Railway's reference syntax (`${{MySQL.VAR}}`) so the values stay in sync if the database credentials rotate.

| Variable          | Value                              | Notes                              |
|-------------------|------------------------------------|------------------------------------|
| `MYSQL_HOST`      | `${{MySQL.MYSQLHOST}}`             | Internal Railway hostname          |
| `MYSQL_ROOT_PASS` | `${{MySQL.MYSQLPASSWORD}}`         | Root password from MySQL service   |
| `MYSQL_USER`      | `openemr`                          | App-specific DB user (auto-created)|
| `MYSQL_PASS`      | `${{MySQL.MYSQLPASSWORD}}`         | Same password, or set a custom one |
| `OE_USER`         | `admin`                            | OpenEMR admin login username       |
| `OE_PASS`         | *(choose a strong password)*       | OpenEMR admin login password       |

> **Security:** Set `OE_PASS` to a strong, unique password before deploying to production. Do not use the default `pass`.

### 4. Expose the service

In the Railway service settings, enable a **Public Domain** under **Networking**. Railway's edge terminates TLS; OpenEMR receives plain HTTP on the internal port.

### 5. Wait for first-run setup

On the first deployment, OpenEMR bootstraps the database. The health check at `/meta/health/readyz` will report unhealthy until this completes (typically 3–5 minutes). Subsequent deploys are faster.

## Seeding sample data

The Railway deployment starts with a clean OpenEMR database (base schema only). The sample FHIR data in `openemr-agent/scripts/seed_fhir_data.sql` is not loaded automatically — it must be run after OpenEMR finishes its first-boot setup (~3–5 min).

### Prerequisites

Install the [Railway CLI](https://docs.railway.com/guides/cli):

```bash
npm install -g @railway/cli
railway login
```

### Run the seed script

Connect to the Railway MySQL service and pipe in the seed file:

```bash
railway run --service MySQL mysql \
  -h "$MYSQLHOST" \
  -u "$MYSQLUSER" \
  -p"$MYSQLPASSWORD" \
  openemr < openemr-agent/scripts/seed_fhir_data.sql
```

Alternatively, use the external TCP proxy connection string shown in the Railway MySQL service dashboard with any MySQL client (TablePlus, DBeaver, etc.).

> **Timing:** Only run the seed after the OpenEMR healthcheck passes. Running it against an incomplete schema will fail.

## Persistent volumes

Railway volumes preserve data across deploys. Attach volumes to the OpenEMR service for the following paths:

| Mount path                                    | Purpose                    |
|-----------------------------------------------|----------------------------|
| `/var/www/localhost/htdocs/openemr/sites`      | Patient files, config       |
| `/var/log`                                    | Application logs            |

## Environment variable reference

Additional optional variables supported by the OpenEMR Docker image:

| Variable                  | Default   | Description                                        |
|---------------------------|-----------|----------------------------------------------------|
| `MYSQL_PORT`              | `3306`    | MySQL port                                         |
| `MYSQL_DATABASE`          | `openemr` | Database name                                      |
| `OE_USER`                 | `admin`   | Initial admin username                             |
| `OE_PASS`                 | `pass`    | Initial admin password                             |
| `OPENSSL_SELFSIGNED_CN`   | —         | Common name for the self-signed TLS cert           |
| `SWARM_MODE`              | —         | Set to `yes` for Docker Swarm / multi-replica use  |
