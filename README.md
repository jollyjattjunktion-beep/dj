# Preet Music Portal — Render MVP

This is a feed-first catalogue importer for a WordPress music-discovery site.

## Scope and safeguards

- Reads an **authorized RSS/Atom or JSON feed**. It does not crawl DJJOhAL HTML pages.
- Imports metadata and creates WordPress **drafts** by default.
- Uses a stable feed ID (or normalized title + artist) for duplicate prevention.
- Accepts only HTTPS URLs and supports source/audio host allow-lists.
- Does not download, proxy, cache, or store MP3 files.
- Does not remove copyright/provenance metadata.
- Uses provider-hosted streaming links only when the feed/provider authorizes that use.
- Does not copy cover artwork; it stores the URL as reference metadata.
- SEO summary is generated from factual fields, not copied/reworded feed descriptions.

## Deploy to Render

1. Push this folder to a new GitHub repository.
2. In Render, choose **New → Blueprint** and select the repository.
3. Render reads `render.yaml`; create the service.
4. In the service Environment page, set:
   - `FEED_URL`: the exact authorized feed URL.
   - `SOURCE_ALLOWED_HOSTS`: source domain(s) permitted by the feed agreement.
   - `AUDIO_ALLOWED_HOSTS`: provider domain(s) that authorize embedded/linked playback.
   - `WP_BASE_URL`, `WP_USERNAME`, `WP_APP_PASSWORD`: only if connecting WordPress.
5. Keep `WP_POST_STATUS=draft` during testing.
6. Confirm `/health` returns `{"ok":true,...}`.

The Render blueprint creates a persistent disk for SQLite. Do not remove it while relying on the database for deduplication.

## Test endpoints

- `GET /health` — health check.
- `GET /api/songs?limit=20` — imported catalogue records.
- `POST /admin/import` — import up to 100 feed entries.

PowerShell test:

```powershell
$headers = @{ "X-Import-Token" = "YOUR_RENDER_IMPORT_TOKEN" }
Invoke-RestMethod -Method Post -Uri "https://YOUR-SERVICE.onrender.com/admin/import" -Headers $headers
Invoke-RestMethod -Uri "https://YOUR-SERVICE.onrender.com/api/songs?limit=20"
```

## Feed formats

### JSON example

```json
{
  "items": [
    {
      "id": "provider-track-001",
      "title": "Example Track",
      "artist": "Example Artist",
      "album": "Example Album",
      "label": "Example Label",
      "release_date": "2026-11-01",
      "duration": "3:25",
      "source_url": "https://catalog-provider.example/song/001",
      "audio_url": "https://authorized-audio-provider.example/embed/001",
      "artwork_url": "https://catalog-provider.example/art/001.jpg"
    }
  ]
}
```

RSS/Atom feeds are also supported. An audio enclosure is recognized only if the feed supplies an audio MIME type.

The exact feed schema must be checked before production use. If the feed uses different field names or nested data, add an explicit adapter rather than guessing.

## WordPress setup

1. Upload and activate `wordpress/preet-song-catalog/`.
2. Save **Settings → Permalinks** once.
3. Create a WordPress Application Password for a dedicated importer account.
4. Set the WordPress environment variables on Render.
5. Test import with `WP_POST_STATUS=draft`.
6. Review each draft before publishing.

## Streaming behavior

This MVP stores the provider's authorized playback/embed URL and presents it as an external provider link. It deliberately does not disguise or proxy the provider URL. A tokenized first-party route cannot make a third-party media URL secret once a browser plays it. Provider-specific iframe embeds can be added after the provider and its embed policy are identified.

## Important

A feed endpoint is not automatically a licence to republish every field or stream every audio item. Confirm the feed terms and each audio provider's embedding/distribution permission. Keep attribution and rights-holder credits intact.
