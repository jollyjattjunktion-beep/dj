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

## Bulk importer integration with the existing Python API

The Admin → Bulk Importer tab is a secure server-side bridge to the existing importer in the repository root `app.py`. It calls the existing `POST /admin/import-pages` endpoint; it does not duplicate the scraping/parsing implementation in PHP.

On the **PHP admin Render service**, add:
- `IMPORT_API_URL=https://dj-laqb.onrender.com`
- `IMPORT_TOKEN=<the exact same IMPORT_TOKEN configured on the Python API Render service>`

Keep the token private. Do not put it in browser JavaScript or GitHub files. The PHP service sends it server-to-server.

On the **Python API Render service**, confirm the existing `IMPORT_TOKEN` is set. WordPress auto-sync from imported records only happens if `WP_BASE_URL`, `WP_USERNAME`, and `WP_APP_PASSWORD` are configured there. Keep `WP_POST_STATUS=draft` while reviewing imports.

In Admin → Bulk Importer, paste one or more approved Single Track category listing URLs (one per line; maximum 20 per batch) and click **Import all pages**. The existing Python importer performs deduplication and reports new records, duplicates and errors.

Important architecture note: this import job writes to the existing Python API's SQLite catalogue and, when configured, syncs to the WordPress Song catalogue. It does not automatically copy records into the separate PHP admin service's own SQLite database. WordPress remains the current public catalogue of record. A future catalogue bridge is needed if the PHP Songs tab itself must become the single source of truth.
