# Deploying OpenEMR on Railway

## Quick deploy (one-time setup)

**Option A – Docker Compose (mirrors production):** Drag `docker/production/docker-compose.railway.yml` onto your Railway project canvas. Add volumes and domain as noted in "Docker Compose import" below.

**Option B – Manual setup:**

1. **Login:** `railway login`
2. **Create project:** In [Railway](https://railway.app) → New Project → Deploy from GitHub → select `openemr-system`, set **Root Directory** to `openemr`
3. **Add MySQL:** In project → + New → Database → MySQL
4. **Configure OpenEMR service** → Variables tab:
   - `OE_USER`: `admin`
   - `OE_PASS`: *(strong password)*
   *(Database variables like `MYSQLHOST` are mapped automatically).*

5. **Networking:** OpenEMR service → Settings → Generate Domain
6. **Volumes (optional):** Add mounts for `/var/www/localhost/htdocs/openemr/sites` and `/var/log`

**Redeploy:** `cd openemr && railway up` or run `docker/production/deploy-railway.sh`

---

## Overview

This deployment mirrors the setup in `docker/production/docker-compose.yml`:

| Docker Compose | Railway |
|----------------|---------|
| `mysql` (mariadb:11.8) | **Option A:** MariaDB service from `docker-compose.railway.yml` (same image, utf8mb4, healthcheck) |
| | **Option B:** + New → Database → MySQL |
| `openemr` (openemr/openemr:7.0.4) | Service built from `Dockerfile.railway` |

# Option A (recommended): Drag docker/production/docker-compose.railway.yml onto your Railway project canvas to import both MariaDB and OpenEMR services—matching production exactly (mariadb:11.8, utf8mb4 charset, healthcheck, same env vars). The entrypoint waits for MySQL to be ready before starting OpenEMR.

Alternatively, you can initialize a new Railway project from the terminal using the Railway CLI. To deploy both the database and OpenEMR together, initialize your project using the template provided:

```bash
# Deploys both MySQL and OpenEMR via docker-compose configuration
railway init
railway up -d
```

**Option B:** Use Railway's managed MySQL. OpenEMR is deployed as a single service backed by a Railway MySQL database.

On first boot, the container auto-provisions the database schema (~3–5 min).

## Setup

### 1. Add a MySQL database service

In your Railway project, click **+ New → Database → MySQL**. This replaces the `mysql` service from docker-compose.

### 2. Deploy the OpenEMR service

Link this repository (or the `openemr/` subdirectory) as a new Railway service. Railway will automatically detect `railway.toml` and build from `Dockerfile.railway`, which uses the same `openemr/openemr:7.0.4` image as docker-compose.

### 3. Set environment variables

In the OpenEMR service **Variables** tab, add the following admin credentials:

| Variable          | Default | Notes                              |
|-------------------|---------|------------------------------------|
| `OE_USER`         | `admin` | OpenEMR admin login username       |
| `OE_PASS`         | `pass`  | Make up your own secure password   |

> **Note:** The deployment entrypoint automatically maps Railway's injected MySQL variables (`MYSQLHOST`, `MYSQLPASSWORD`, etc.) to the standard variables OpenEMR expects (`MYSQL_HOST`, `MYSQL_ROOT_PASS`, etc.). You do not need to map the database credentials manually!

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

## Docker Compose import (Option A)

To mirror production exactly (MariaDB 11.8 + OpenEMR with same config), drag and drop `docker/production/docker-compose.railway.yml` onto your [Railway project canvas](https://docs.railway.app/overview/the-basics#project--project-canvas). This imports both services with:

- **mysql:** mariadb:11.8, utf8mb4 charset, same healthcheck as production
- **openemr:** Built from `Dockerfile.railway`, waits for MySQL before starting

After import:

1. Set OpenEMR service Root Directory to `openemr` and `RAILWAY_DOCKERFILE_PATH` to `Dockerfile.railway` (if not auto-detected)
2. Add volumes: mysql → `/var/lib/mysql`, openemr → `/var/www/localhost/htdocs/openemr/sites` and `/var/log`
3. Generate a domain on the OpenEMR service

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
