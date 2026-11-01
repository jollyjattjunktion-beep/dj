# JattBeat PHP Music Admin (separate Render service)

This folder is an additional PHP + SQLite admin application. It is intentionally isolated from the existing root-level FastAPI importer/media API and the existing WordPress plugin. It does not replace either service, and it does not yet synchronize its SQLite catalogue into WordPress. WordPress remains the public catalogue of record until a separately tested sync bridge is implemented.

## Deploy this folder on Render

Create a **new Web Service** from the same GitHub repository `jollyjattjunktion-beep/dj`:

- Branch: `main`
- Runtime: Docker
- Root Directory: `php-admin`
- Dockerfile Path: `Dockerfile`
- Docker Context: `.`
- Health Check Path: `/health`
- Instance: paid service when using persistent SQLite storage

Add a persistent disk:
- Mount path: `/var/data`
- Size: 1 GB initially

Set environment variables:
- `APP_ENV=production`
- `APP_URL=https://YOUR-ADMIN-DOMAIN`
- `DB_PATH=/var/data/jattbeat.sqlite`
- `INSTALL_LOCK=0`
- `SESSION_NAME=jattbeat_admin`

Deploy, then open `https://YOUR-ADMIN-DOMAIN/install.php` to create the first admin. After installation, set `INSTALL_LOCK=1` in Render and redeploy.

## Existing JattBeat services

Do not change the root `Dockerfile`, root `render.yaml`, `app.py`, or `wordpress/` files to deploy this PHP app. The existing Python API and WordPress plugin remain untouched. This service uses its own SQLite database on its own persistent disk.

## Rights and safety

Only add streaming/download URLs and artwork that you own, are licensed to use, or are expressly authorized to link/embed. A public listing page does not itself grant redistribution rights. Keep required artist, label and rights-holder credits. The app does not scrape providers or bypass access controls.

## Notes

The PHP admin source files are added in this directory. The initial build includes the Docker runtime and routing foundation. Review the application feature checklist and test login, CRUD, SEO, sitemap, backups and security before production launch.
