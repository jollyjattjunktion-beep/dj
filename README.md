# JattBeat — WordPress catalogue + Render importer/media API

WordPress on `https://jattbeat.com` is the **main song catalogue and public website**. The Render FastAPI service remains the trusted import worker and approved-media token service. Its SQLite database is retained as an import/index and media-origin mapping store; the WordPress Song custom post type is the public catalogue of record.

## Architecture

- **WordPress:** song posts, artist/title/release metadata, public song URLs, homepage, all-songs page and SEO fields.
- **JattBeat Song Catalog plugin:** `wordpress/preet-song-catalog/`. Registers the Song post type, the dark black/red JattBeat storefront shortcodes, authenticated WordPress upsert API, and a server-side media bridge.
- **Render API:** imports feed entries, deduplicates Render records, syncs new and changed records into WordPress, and issues short-lived signed media URLs. It never sends the raw provider audio URL to WordPress.
- **Persistent Render disk:** keeps the import index stable between deployments. Do not remove the disk or change `DB_PATH` without a planned database migration.

## 1. Install the WordPress plugin

Copy the complete folder `wordpress/preet-song-catalog/` into:

```text
wp-content/plugins/preet-song-catalog/
```

The folder must contain `preet-song-catalog.php` directly. Activate **JattBeat Song Catalog** in WordPress → Plugins, then visit Settings → Permalinks and click **Save Changes** once.

The plugin defaults its Render API URL to `https://dj-laqb.onrender.com`. It can be changed in WordPress → Settings → JattBeat Catalog.

### Create the public pages

Create these WordPress pages:

| Page | Slug | Page content |
|---|---|---|
| Home | `home` (or your preferred home slug) | `[jattbeat_homepage]` |
| Songs | `songs` | `[jattbeat_all_songs]` |
| Artists | `artists` | `[jattbeat_artists]` |

Set the Home page as the static front page under Settings → Reading. Keep the Song post URLs under `/song/<song-name>/`. The plugin uses 15 songs per page and a mobile-responsive JattBeat black/charcoal/red layout.

The older `[preet_song_catalog]` shortcode remains as a compatibility alias for the all-songs listing.

## 2. Create WordPress importer credentials

Create a dedicated WordPress user with the **Editor** role (or another role allowed to edit Song posts). Generate an Application Password from that user's profile. Use the Application Password, not the normal login password.

In Render → your API service → Environment, set:

| Variable | Value |
|---|---|
| `WP_BASE_URL` | `https://jattbeat.com` |
| `WP_USERNAME` | Dedicated importer username |
| `WP_APP_PASSWORD` | WordPress Application Password (spaces may be included) |
| `WP_POST_STATUS` | `draft` during migration/review |
| `PUBLIC_API_BASE_URL` | `https://dj-laqb.onrender.com` |
| `MEDIA_TOKEN_SECRET` | Long random secret; keep stable across restarts/deploys |

Do not put Application Passwords or the import token in WordPress page content, JavaScript, GitHub, or screenshots.

The Render blueprint generates `IMPORT_TOKEN` and `MEDIA_TOKEN_SECRET` if it creates the service. For an existing Render service, confirm these values exist in its Environment page. In particular, set a stable `MEDIA_TOKEN_SECRET`; changing it invalidates previously issued media links, which is expected for short-lived links but should not happen on every restart.

Keep `DB_PATH=/var/data/catalog.sqlite3` on the Render persistent disk configured in `render.yaml`.

## 3. Safely backfill the existing Render songs into WordPress

After deploying the updated Render code and setting the WordPress variables, call the protected backfill endpoint. It syncs existing Render rows into WordPress in batches and is safe to repeat.

PowerShell:

```powershell
$headers = @{ "X-Import-Token" = "YOUR_RENDER_IMPORT_TOKEN" }
Invoke-RestMethod -Method Post `
  -Uri "https://dj-laqb.onrender.com/admin/sync-wordpress?limit=200&offset=0" `
  -Headers $headers
```

The response reports `scanned`, `synced`, `errors` and `next_offset`. If `next_offset` is a number, call the endpoint again with that offset.

### Duplicate and edit protection

- WordPress records are matched by a stable key `render:<Render song row ID>`.
- The importer also checks legacy external IDs and legacy title/artist slugs before creating a post.
- Re-imports update the same WordPress post; they do not create a new post for the same Render row.
- Backfill and routine imports fill missing WordPress metadata but preserve existing manually entered title, artist and other non-empty catalogue fields.
- Existing post status is retained. New posts are created as drafts by default.
- A failed WordPress sync does not discard the Render import. Run the backfill endpoint again after fixing the connection.

Review the drafts in WordPress → Songs, correct any metadata as needed, then publish approved records. Keep `WP_POST_STATUS=draft` while testing.

## 4. Import new catalogue entries

The standard importer remains:

- `POST /admin/import` — imports up to 100 entries from the configured authorized RSS/Atom or JSON feed.
- `POST /admin/import-pages` — legacy page-import compatibility route. Only use it where the source's terms and your rights/authorization permit automated retrieval. The feed importer is the preferred production path.

New entries are written to the Render import index, then upserted into WordPress. Existing entries are refreshed and re-synced to the same WordPress post.

Feed import PowerShell:

```powershell
$headers = @{ "X-Import-Token" = "YOUR_RENDER_IMPORT_TOKEN" }
Invoke-RestMethod -Method Post -Uri "https://dj-laqb.onrender.com/admin/import" -Headers $headers
```

## 5. Authorized playback and downloads

WordPress stores the Render song row ID, artwork reference and source/rights attribution. It does **not** store the provider's raw MP3 URL.

When a visitor presses Play or Download on a published song page:

1. The WordPress plugin checks that the Song post is published.
2. The plugin server-side requests `GET /api/songs/{render_song_id}` from Render.
3. Render creates a short-lived HMAC-signed `/media/<token>` link.
4. The browser receives only that approved token URL. The original provider URL remains on the Render side.

The Render API routes are:

- `GET /api/songs?limit=20` — public catalogue/API compatibility.
- `GET /api/songs/{id}` — song metadata plus a newly minted media/download URL.
- `GET /media/{token}` — streams the approved provider URL and supports byte-range playback.
- `POST /admin/sync-wordpress` — protected, repeatable WordPress backfill.
- `GET /health` — service health.

The media token is not DRM and cannot stop recording or re-sharing by a person who can play the file. Keep attribution and required rights-holder credits intact. Only populate the audio allow-list and authorization flags after confirming the intended use is permitted by the relevant provider/rightsholder.

## 6. Deployment and persistence

- GitHub is for versioned code and the plugin.
- Render deploys `app.py` and retains its SQLite file on the persistent disk.
- WordPress holds the canonical song posts and their editorial metadata in its own MariaDB database.
- WordPress theme/plugin updates must not replace `wp-content/uploads` or the WordPress database.
- Render deployments must not remove the persistent disk.

Useful checks:

- `https://dj-laqb.onrender.com/health`
- `https://dj-laqb.onrender.com/api/songs?limit=5`
- `https://jattbeat.com/wp-json/wp/v2/songs` (public published Song records)
- `https://jattbeat.com/wp-json/jattbeat/v1/media/<wordpress-post-id>` (only published songs with a linked Render row)

## Rights and metadata

A feed or catalogue page is not automatically permission to republish its artwork or stream its audio. Keep the actual artist, label, source and required rights-holder credits. The importer accepts provider media only when it is explicitly marked as authorized and hosted on a configured allowed host.


## Optional PHP + SQLite admin panel

A separate PHP music-admin service has been added under `php-admin/`. It does not replace the existing FastAPI service or WordPress catalogue, and it currently maintains its own SQLite catalogue without automatic WordPress synchronization. To deploy it, create a second Render Web Service from this same repository with Root Directory `php-admin`, Runtime Docker, Dockerfile Path `Dockerfile`, Docker Context `.`, health check `/health`, and a paid persistent disk mounted at `/var/data`. Full setup instructions and feature limits are in [php-admin/README.md](php-admin/README.md).

### Bulk import from the PHP admin

The PHP admin now includes **Admin → Bulk Importer**, a server-side bridge to the existing Python importer in `app.py`. It accepts up to 20 approved Single Track category URLs per batch and reuses the existing duplicate detection and optional WordPress sync.

Configure these environment variables on the separate PHP admin Render service:
- `IMPORT_API_URL=https://dj-laqb.onrender.com`
- `IMPORT_TOKEN` = the same secret value as `IMPORT_TOKEN` on the Python API Render service.

The secret stays server-side and is never sent to the browser. Imported rows are saved by the Python API and can sync to WordPress when its credentials are configured on the API service. The PHP admin's own SQLite song table is currently separate; WordPress remains the canonical public catalogue. See [php-admin/README.md](php-admin/README.md) for the deployment steps and limitation.
