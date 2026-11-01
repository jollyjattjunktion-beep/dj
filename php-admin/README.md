# JattBeat PHP Music Admin (separate Render service)

This is a separate PHP 8.2 + Apache + SQLite service inside the existing `dj` repository. It does **not** replace the existing FastAPI importer/media API, root Dockerfile, root Render blueprint, or WordPress plugin.

## Included in this initial integrated build

- First-run SQLite installer and admin login with password hashing, CSRF checks and secure session cookies.
- Song creation, draft/publish status, catalogue listing/search and delete.
- Artist and category creation.
- Authorized streaming/download URL fields with a required rights/permission note.
- Per-song SEO title and description, canonical URL, Open Graph metadata and MusicRecording JSON-LD.
- Dynamic XML sitemap at `/sitemap.xml`.
- Ad snippet manager for homepage/song placements (trusted admins only).
- Responsive dark/red storefront and admin styling.
- Dockerfile, health endpoint and persistent SQLite path.

Not included yet: edit-existing-song form, bulk actions, poster file upload, OneSignal push, roles/multi-admin, artist public profile pages, or automated sync into the WordPress catalogue. Treat this as an initial working admin, not a fully audited production CMS.

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

Deploy, then open `https://YOUR-ADMIN-DOMAIN/install.php` to create the first admin. Use a unique password of at least 12 characters. After successful installation, set `INSTALL_LOCK=1` in Render and redeploy.

## Existing JattBeat services and data

The existing root-level Python API keeps its current SQLite database at `/var/data/catalog.sqlite3`; this PHP app uses a **different** SQLite database at `/var/data/jattbeat.sqlite` on its own Render service/disk. Render disks are service-specific. No catalogue data is copied or synchronized automatically.

Your existing WordPress site remains the public catalogue of record until a separate, tested sync bridge is built. Do not change the root `Dockerfile`, root `render.yaml`, `app.py`, or `wordpress/` files to deploy this PHP app.

## Rights and safety

Only add streaming/download URLs and artwork that you own, are licensed to use, or are expressly authorized to link/embed. A public listing page does not itself grant redistribution rights. Keep required artist, label and rights-holder credits. The app does not scrape providers or bypass access controls. Ad code is executable HTML/JavaScript; only trusted admins should be allowed to manage it.

## Backups

Back up both `/var/data/jattbeat.sqlite` and any future uploaded media before upgrades. Do not remove or replace the persistent disk during redeployments.
