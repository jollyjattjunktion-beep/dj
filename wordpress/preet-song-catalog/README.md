# JattBeat Song Catalog WordPress plugin

## Install
Copy this whole folder into `wp-content/plugins/preet-song-catalog/`, activate **JattBeat Song Catalog**, then open Settings → Permalinks and click Save Changes.

## Required WordPress pages
Create these WordPress pages:

- Home: `[jattbeat_homepage]`
- Songs (slug `songs`): `[jattbeat_all_songs]`
- Artists (slug `artists`): `[jattbeat_artists]`

Set Home as the static front page under Settings → Reading. Individual Song posts use `/song/<title-and-artist>/`.

## Render connection
Open WordPress → Settings → JattBeat Catalog and keep the API URL as `https://dj-laqb.onrender.com` unless your Render service URL is different.

Create a dedicated WordPress Editor user and an Application Password. Configure `WP_BASE_URL`, `WP_USERNAME` and `WP_APP_PASSWORD` on the Render service. Keep `WP_POST_STATUS=draft` while reviewing the first import.

## Sync existing songs
After the updated Render service is deployed and the environment variables are set, run the protected endpoint:

```powershell
$headers = @{ "X-Import-Token" = "YOUR_RENDER_IMPORT_TOKEN" }
Invoke-RestMethod -Method Post -Uri "https://dj-laqb.onrender.com/admin/sync-wordpress?limit=200&offset=0" -Headers $headers
```

Repeat with `next_offset` from the response when it is a number. The operation is safe to repeat; it upserts by the stable Render row ID and does not create duplicate Song posts.

## Media
The plugin does not put raw provider MP3 URLs into WordPress content or metadata. Play/Download asks the Render API for a fresh short-lived signed URL. Only published songs can request media. Source attribution and rights-holder credits are retained.
