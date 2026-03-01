# Deploying OpenEMR on Railway

## Quick deploy (one-time setup)

1. **Login:** `railway login`
2. **Create project:** In [Railway](https://railway.app) → New Project → Deploy from GitHub → select `openemr-system`, set **Root Directory** to `openemr`
3. **Add MySQL:** In project → + New → Database → MySQL
4. **Configure OpenEMR service** → Variables tab:

   | Variable        | Value                          |
   |-----------------|--------------------------------|
   | `MYSQL_HOST`    | `${{MySQL.MYSQLHOST}}`         |
   | `MYSQL_ROOT_PASS` | `${{MySQL.MYSQLPASSWORD}}`   |
   | `MYSQL_USER`    | `openemr`                      |
   | `MYSQL_PASS`    | `${{MySQL.MYSQLPASSWORD}}`     |
   | `OE_USER`       | `admin`                        |
   | `OE_PASS`       | *(strong password)*            |

5. **Networking:** OpenEMR service → Settings → Generate Domain
6. **Volumes (optional):** Add mounts for `/var/www/localhost/htdocs/openemr/sites` and `/var/log`

**Redeploy:** `cd openemr && railway up` or run `docker/production/deploy-railway.sh`

---

## Overview

This deployment mirrors the setup in `docker/production/docker-compose.yml`:

| Docker Compose | Railway |
|----------------|---------|
| `mysql` (mariadb:11.8) | **+ New → Database → MySQL** |
| `openemr` (openemr/openemr:7.0.4) | Service built from `Dockerfile.railway` |

OpenEMR is deployed as a single service backed by a Railway MySQL database. On first boot, the container auto-provisions the database schema (~3–5 min).

## Setup

### 1. Add a MySQL database service

In your Railway project, click **+ New → Database → MySQL**. This replaces the `mysql` service from docker-compose.

### 2. Deploy the OpenEMR service

Link this repository (or the `openemr/` subdirectory) as a new Railway service. Railway will automatically detect `railway.toml` and build from `Dockerfile.railway`, which uses the same `openemr/openemr:7.0.4` image as docker-compose.

### 3. Set environment variables

In the OpenEMR service **Variables** tab, add the following. These map to the docker-compose `openemr` service env vars. Use Railway's reference syntax (`${{MySQL.VAR}}`) so the values stay in sync if the database credentials rotate.

| Variable          | docker-compose value | Railway value                     | Notes                              |
|-------------------|----------------------|-----------------------------------|------------------------------------|
| `MYSQL_HOST`      | `mysql`              | `${{MySQL.MYSQLHOST}}`            | Internal Railway hostname          |
| `MYSQL_ROOT_PASS` | `root`               | `${{MySQL.MYSQLPASSWORD}}`        | Root password from MySQL service   |
| `MYSQL_USER`      | `openemr`            | `openemr`                         | App-specific DB user (auto-created)|
| `MYSQL_PASS`      | `openemr`            | `${{MySQL.MYSQLPASSWORD}}`        | Same password, or set a custom one |
| `OE_USER`         | `admin`              | `admin`                           | OpenEMR admin login username       |
| `OE_PASS`         | `pass`               | *(choose a strong password)*      | Make up your own secure password   |

> **Security:** Set `OE_PASS` to a strong, unique password before deploying to production. Do not use the default `pass`.

### 4. Expose the service

In the Railway service settings, enable a **Public Domain** under **Networking**. Railway's edge terminates TLS; OpenEMR receives plain HTTP on the internal port.

> **Note on Ports:** Railway automatically sets a dynamic `PORT` environment variable and handles routing. **Do not** manually configure a port for the public domain or override the `PORT` variable.

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

Railway volumes preserve data across deploys. Attach volumes to the OpenEMR service for the following paths (same as docker-compose `volumes`):

| Mount path                                    | docker-compose volume | Purpose                    |
|-----------------------------------------------|------------------------|----------------------------|
| `/var/www/localhost/htdocs/openemr/sites`      | `sitevolume`           | Patient files, config       |
| `/var/log`                                    | `logvolume01`          | Application logs            |

## Alternative: Docker Compose import

Railway supports importing services from a Docker Compose file. Drag and drop `docker/production/docker-compose.yml` onto your [Railway project canvas](https://docs.railway.app/overview/the-basics#project--project-canvas) to auto-import services. You will still need to:

1. Replace the `mysql` service with Railway's **+ New → Database → MySQL** (Railway manages MySQL separately)
2. Point the OpenEMR service's `MYSQL_HOST` to the Railway MySQL hostname
3. Configure environment variables as in step 3 above

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
