from __future__ import annotations

import base64
import hashlib
import hmac
import json
import os
import secrets
import re
import sqlite3
from contextlib import closing
from datetime import datetime, timezone
import time
import asyncio
from urllib.parse import urlparse

import feedparser
import httpx
from fastapi import FastAPI, Header, HTTPException
from fastapi.responses import HTMLResponse, StreamingResponse

APP_NAME = "Preet Music Portal API"
DB_PATH = os.getenv("DB_PATH", "/tmp/preet_music_portal.sqlite3")
FEED_URL = os.getenv("FEED_URL", "").strip()
FEED_FORMAT = os.getenv("FEED_FORMAT", "auto").lower()
IMPORT_TOKEN = os.getenv("IMPORT_TOKEN", "")
MEDIA_TOKEN_SECRET = (os.getenv("MEDIA_TOKEN_SECRET") or IMPORT_TOKEN or secrets.token_urlsafe(48)).encode("utf-8")
MEDIA_TOKEN_TTL = max(300, int(os.getenv("MEDIA_TOKEN_TTL", "3600")))
WP_BASE_URL = os.getenv("WP_BASE_URL", "").rstrip("/")
WP_USERNAME = os.getenv("WP_USERNAME", "")
WP_APP_PASSWORD = os.getenv("WP_APP_PASSWORD", "")
WP_POST_STATUS = os.getenv("WP_POST_STATUS", "draft").lower()
AUDIO_ALLOWED_HOSTS = {
    h.strip().lower()
    for h in os.getenv("AUDIO_ALLOWED_HOSTS", "").split(",")
    if h.strip()
}

MEDIA_HOSTS = {"hd1.djjohal.com"} | AUDIO_ALLOWED_HOSTS
SOURCE_ALLOWED_HOSTS = {
    h.strip().lower()
    for h in os.getenv("SOURCE_ALLOWED_HOSTS", "").split(",")
    if h.strip()
}
ARTWORK_ALLOWED_HOSTS = {
    h.strip().lower()
    for h in os.getenv("ARTWORK_ALLOWED_HOSTS", "").split(",")
    if h.strip()
}
USER_AGENT = os.getenv(
    "FEED_USER_AGENT", "PreetMusicPortal/0.1 (+contact site administrator)"
)

app = FastAPI(title=APP_NAME, version="0.1.0")


def db():
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    return conn


def init_db():
    with closing(db()) as conn:
        conn.execute(
            """CREATE TABLE IF NOT EXISTS songs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                fingerprint TEXT UNIQUE NOT NULL,
                external_id TEXT,
                title TEXT NOT NULL,
                artist TEXT DEFAULT '',
                album TEXT DEFAULT '',
                label TEXT DEFAULT '',
                release_date TEXT DEFAULT '',
                duration TEXT DEFAULT '',
                description TEXT DEFAULT '',
                source_url TEXT NOT NULL,
                audio_url TEXT DEFAULT '',
                artwork_url TEXT DEFAULT '',
                wordpress_id INTEGER,
                wordpress_url TEXT,
                created_at TEXT NOT NULL
            )"""
        )
        conn.commit()


def backfill_known_credits():
    with closing(db()) as conn:
        rows = conn.execute("SELECT id, title, artist FROM songs").fetchall()
        for row in rows:
            clean_title, parsed_artist = parse_title_and_artist(row["title"], row["artist"])
            media = KNOWN_MEDIA.get(clean_title.casefold(), {})
            if parsed_artist:
                conn.execute(
                    """UPDATE songs SET title=?, artist=?,
                       artwork_url=CASE WHEN ? != '' THEN ? ELSE artwork_url END,
                       audio_url=CASE WHEN ? != '' THEN ? ELSE audio_url END,
                       duration=CASE WHEN ? != '' THEN ? ELSE duration END,
                       label=CASE WHEN ? != '' THEN ? ELSE label END
                       WHERE id=?""",
                    (
                        clean_title, parsed_artist,
                        media.get("artwork_url", ""), media.get("artwork_url", ""),
                        media.get("audio_url", ""), media.get("audio_url", ""),
                        media.get("duration", ""), media.get("duration", ""),
                        media.get("label", ""), media.get("label", ""),
                        row["id"],
                    ),
                )
        conn.commit()


def seed_known_catalog_if_empty():
    source_slugs = {
        "karwa chauth": "karwa-chauth-jesann",
        "on me": "on-me-manni-virdi",
        "for my bae": "for-my-bae-ajay-amiwalia",
        "hoshiarpur anthem": "hoshiarpur-anthem-ranjha-chagran-wala",
        "six sunday": "six-sunday-yuvraj-goraya-and-shetu-katal",
        "kabool ae": "kabool-ae-deep-sukh",
        "das jandi": "das-jandi-sembhy-k",
        "low cars": "low-cars-karman",
        "who jatt": "who-jatt-gur-sohal",
    }
    release_dates = {
        "karwa chauth": "2026-10-24",
        "on me": "2026-10-24",
        "for my bae": "2026-10-24",
        "hoshiarpur anthem": "2026-10-24",
        "six sunday": "2026-10-25",
        "kabool ae": "2026-10-25",
        "das jandi": "2026-10-25",
        "low cars": "2026-10-25",
        "who jatt": "2026-10-25",
    }
    with closing(db()) as conn:
        count = conn.execute("SELECT COUNT(*) FROM songs").fetchone()[0]
        if count:
            return
        for key, (title, artist) in KNOWN_CREDITS.items():
            media = KNOWN_MEDIA.get(key, {})
            source_url = "https://www.djjohal.com/single/" + source_slugs[key] + ".html"
            stable = source_url
            fingerprint = hashlib.sha256(stable.encode("utf-8")).hexdigest()
            conn.execute(
                """INSERT OR IGNORE INTO songs
                   (fingerprint,external_id,title,artist,album,label,release_date,
                    duration,description,source_url,audio_url,artwork_url,created_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)""",
                (
                    fingerprint, source_url, title, artist, "", media.get("label", ""),
                    release_dates[key], media.get("duration", ""), "",
                    source_url, media.get("audio_url", ""), media.get("artwork_url", ""),
                    datetime.now(timezone.utc).isoformat(),
                ),
            )
        conn.commit()


@app.on_event("startup")
def startup():
    init_db()
    backfill_known_credits()
    seed_known_catalog_if_empty()


def clean(value):
    if value is None:
        return ""
    if isinstance(value, (list, tuple)):
        value = ", ".join(str(item) for item in value)
    return re.sub(r"\s+", " ", str(value)).strip()


def safe_url(value, allowed_hosts, required=False):
    value = clean(value)
    if not value:
        if required:
            raise ValueError("Required URL is missing")
        return ""
    parsed = urlparse(value)
    if parsed.scheme != "https" or not parsed.hostname:
        raise ValueError("Only HTTPS URLs are accepted")
    if allowed_hosts and parsed.hostname.lower() not in allowed_hosts:
        raise ValueError(f"Host is not allow-listed: {parsed.hostname}")
    return value


def field(entry, *names):
    for name in names:
        value = entry.get(name)
        if value not in (None, ""):
            return value
    return ""


# Explicit credits for the nine catalogue entries already imported.
# Keep this list factual and review credits before adding new overrides.
KNOWN_CREDITS = {
    "karwa chauth": ("Karwa Chauth", "Jesann"),
    "on me": ("On Me", "Manni Virdi"),
    "for my bae": ("For My Bae", "Ajay Amiwalia"),
    "hoshiarpur anthem": ("Hoshiarpur Anthem", "Ranjha Chagran Wala"),
    "six sunday": ("Six Sunday", "Yuvraj Goraya, Shetu Katal"),
    "kabool ae": ("Kabool Ae", "Deep Sukh"),
    "das jandi": ("Das Jandi", "Sembhy K"),
    "low cars": ("Low Cars", "Karman"),
    "who jatt": ("Who Jatt", "Gur Sohal"),
}

# Direct provider-hosted media URLs, populated after the site operator confirmed
# permission to use DJJOhAL-hosted cover artwork and 320 KBPS MP3 links.
# These are external links only; this application does not download or proxy files.
KNOWN_MEDIA = {
    "karwa chauth": {
        "artwork_url": "https://lq.djjohal.com/covers_webp/738525/Karwa%20Chauth.webp",
        "audio_url": "https://hd1.djjohal.com/320/529469/Karwa%20Chauth%20-%20Jesann%20%28DJJOhAL.Com%29.mp3",
        "duration": "02:14", "label": "UI Films",
    },
    "on me": {
        "artwork_url": "https://lq.djjohal.com/covers_webp/738526/On%20Me.webp",
        "audio_url": "https://hd1.djjohal.com/320/529470/On%20Me%20-%20Manni%20Virdi%20%28DJJOhAL.Com%29.mp3",
        "duration": "02:13", "label": "Trap-V Records",
    },
    "for my bae": {
        "artwork_url": "https://lq.djjohal.com/covers_webp/738611/For%20My%20Bae.webp",
        "audio_url": "https://hd1.djjohal.com/320/529562/For%20My%20Bae%20-%20Ajay%20Amiwalia%20%28DJJOhAL.Com%29.mp3",
        "duration": "02:21", "label": "Ajay Amiwalia Music",
    },
    "hoshiarpur anthem": {
        "artwork_url": "https://lq.djjohal.com/covers_webp/738524/Hoshiarpur%20Anthem.webp",
        "audio_url": "https://hd1.djjohal.com/320/529468/Hoshiarpur%20Anthem%20-%20Ranjha%20Chagran%20Wala%20%28DJJOhAL.Com%29.mp3",
        "duration": "03:32", "label": "Ranjha Chagran Wala Music",
    },
    "six sunday": {
        "artwork_url": "https://lq.djjohal.com/covers_webp/738539/Six%20Sunday.webp",
        "audio_url": "https://hd1.djjohal.com/320/529487/Six%20Sunday%20-%20Yuvraj%20Goraya%2C%20Shetu%20Katal%20%28DJJOhAL.Com%29.mp3",
        "duration": "02:11", "label": "Yuvraj Goraya Music",
    },
    "kabool ae": {
        "artwork_url": "https://lq.djjohal.com/covers_webp/738540/Kabool%20Ae.webp",
        "audio_url": "https://hd1.djjohal.com/320/529488/Kabool%20Ae%20-%20Deep%20Sukh%20%28DJJOhAL.Com%29.mp3",
        "duration": "02:26", "label": "Motion RootZ Studios",
    },
    "das jandi": {
        "artwork_url": "https://lq.djjohal.com/covers_webp/738502/Das%20Jandi.webp",
        "audio_url": "https://hd1.djjohal.com/320/529427/Das%20Jandi%20-%20Sembhy%20K%20%28DJJOhAL.Com%29.mp3",
        "duration": "02:54", "label": "Raula Records",
    },
    "low cars": {
        "artwork_url": "https://lq.djjohal.com/covers_webp/738505/Low%20Cars.webp",
        "audio_url": "https://hd1.djjohal.com/320/529430/Low%20Cars%20-%20Karman%20%28DJJOhAL.Com%29.mp3",
        "duration": "02:33", "label": "Karman Records",
    },
    "who jatt": {
        "artwork_url": "https://lq.djjohal.com/covers_webp/738507/Who%20Jatt.webp",
        "audio_url": "https://hd1.djjohal.com/320/529432/Who%20Jatt%20-%20Gur%20Sohal%20%28DJJOhAL.Com%29.mp3",
        "duration": "02:05", "label": "Rhythm & Flow",
    },
}

def parse_title_and_artist(raw_title, supplied_artist=""):
    title = clean(raw_title)
    title = re.sub(r"\\s+MP3\\s+Song\\s+Download\\s*$", "", title, flags=re.I).strip()
    supplied_artist = clean(supplied_artist)

    # Prefer a real artist credit supplied by the feed, not the feed/site name.
    generic_sources = {"djjohal.com", "djjohal", "rss.app", "preet music"}
    if supplied_artist.casefold() not in generic_sources and supplied_artist:
        return title, supplied_artist

    known = KNOWN_CREDITS.get(title.casefold())
    if known:
        return known

    # Parse only an explicit, unambiguous "Song Title - Artist" / "Song Title | Artist".
    for separator in (" - ", " | "):
        if separator in title:
            left, right = title.rsplit(separator, 1)
            if left.strip() and right.strip():
                return left.strip(), right.strip()

    # Do not guess artist names from arbitrary trailing words.
    return title, ""


def explicitly_authorized(entry, field_name):
    value = entry.get(field_name, False)
    return value is True or str(value).strip().lower() in {"1", "true", "yes", "authorized", "authorised"}


def normalize_entry(entry):
    raw_title = clean(field(entry, "title", "name"))
    supplied_artist = clean(field(entry, "artist", "author", "creator", "dc_creator"))
    title, artist = parse_title_and_artist(raw_title, supplied_artist)
    source = safe_url(
        field(entry, "source_url", "link", "url"),
        SOURCE_ALLOWED_HOSTS,
        required=True,
    )
    # Media URLs are accepted only when the feed explicitly marks the relevant
    # permission as verified and the host is allow-listed by the site operator.
    audio = safe_url(
        field(entry, "audio_url", "stream_url", "enclosure_url"),
        AUDIO_ALLOWED_HOSTS,
    ) if explicitly_authorized(entry, "audio_authorized") else ""
    artwork = safe_url(
        field(entry, "artwork_url", "image", "cover", "image_url"),
        ARTWORK_ALLOWED_HOSTS,
    ) if explicitly_authorized(entry, "artwork_authorized") else ""
    if not title:
        raise ValueError("Feed item has no title")

    external_id = clean(field(entry, "id", "guid", "external_id"))
    stable = external_id or f"{title.casefold()}|{artist.casefold()}"
    fingerprint = hashlib.sha256(stable.encode("utf-8")).hexdigest()

    return {
        "fingerprint": fingerprint,
        "external_id": external_id,
        "title": title,
        "artist": artist,
        "album": clean(field(entry, "album")),
        "label": clean(field(entry, "label", "record_label")),
        "release_date": clean(field(entry, "release_date", "published", "pubDate")),
        "duration": clean(field(entry, "duration")),
        "description": "",
        "source_url": source,
        "audio_url": audio,
        "artwork_url": artwork,
    }


def parse_feed(raw: bytes):
    if FEED_FORMAT == "json" or (
        FEED_FORMAT == "auto" and raw.lstrip().startswith((b"{", b"["))
    ):
        data = json.loads(raw.decode("utf-8"))
        items = data if isinstance(data, list) else data.get(
            "items", data.get("songs", [])
        )
        if not isinstance(items, list):
            raise ValueError("JSON feed must be a list or contain an items/songs array")
        return items

    parsed = feedparser.parse(raw)
    if parsed.bozo and not parsed.entries:
        raise ValueError(f"Could not parse feed: {parsed.bozo_exception}")

    items = []
    for entry in parsed.entries:
        enclosures = entry.get("enclosures", []) or []
        audio = next(
            (
                enclosure.get("href")
                for enclosure in enclosures
                if "audio" in enclosure.get("type", "")
            ),
            "",
        )
        items.append(
            {
                "id": entry.get("id", ""),
                "title": entry.get("title", ""),
                "artist": entry.get("author", ""),
                "link": entry.get("link", ""),
                "audio_url": audio,
                "published": entry.get("published", ""),
            }
        )
    return items


def factual_seo_summary(song):
    parts = [song["title"]]
    if song["artist"]:
        parts.append(f"by {song['artist']}")
    if song["album"]:
        parts.append(f"from {song['album']}")
    if song["label"]:
        parts.append(f"released by {song['label']}")
    return (
        "Song information, artist credits and authorized listening options for "
        + " ".join(parts)
        + "."
    )


def slugify(value):
    value = re.sub(r"[^\w-]+", "-", value.lower(), flags=re.UNICODE)
    return re.sub(r"-+", "-", value).strip("-")[:160] or "song"


def wp_sync(song):
    if not all([WP_BASE_URL, WP_USERNAME, WP_APP_PASSWORD]):
        return None, None

    endpoint = f"{WP_BASE_URL}/wp-json/wp/v2/songs"
    auth = (WP_USERNAME, WP_APP_PASSWORD.replace(" ", ""))
    summary = factual_seo_summary(song)
    content = (
        f"<p>{summary}</p>"
        f'<p><a href="{song["source_url"]}" rel="nofollow noopener">'
        "Original catalogue/source page</a></p>"
    )
    if song["audio_url"]:
        content += (
            f'<p><a href="{song["audio_url"]}" rel="nofollow noopener">'
            "Listen at authorized provider</a></p>"
        )

    payload = {
        "title": song["title"],
        "status": WP_POST_STATUS if WP_POST_STATUS in ("draft", "publish") else "draft",
        "content": content,
        "excerpt": summary,
        "meta": {
            "ps_artist": song["artist"],
            "ps_album": song["album"],
            "ps_label": song["label"],
            "ps_release_date": song["release_date"],
            "ps_duration": song["duration"],
            "ps_source_url": song["source_url"],
            "ps_stream_url": song["audio_url"],
            "ps_artwork_url": song["artwork_url"],
            "ps_external_id": song["external_id"],
            "ps_rights_status": (
                "External provider link; distribution rights must be verified"
            ),
        },
    }

    with httpx.Client(timeout=25, follow_redirects=False, auth=auth) as client:
        check = client.get(
            endpoint,
            params={
                "slug": slugify(song["title"] + " " + song["artist"]),
                "context": "edit",
            },
        )
        check.raise_for_status()
        existing = check.json()
        if existing:
            post_id = existing[0]["id"]
            response = client.post(f"{endpoint}/{post_id}", json=payload)
        else:
            response = client.post(endpoint, json=payload)
        response.raise_for_status()
        result = response.json()
        return result.get("id"), result.get("link")


def frontend_html():
    frontend_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), "index.html")
    try:
        with open(frontend_path, "r", encoding="utf-8") as frontend_file:
            return frontend_file.read()
    except OSError as exc:
        raise HTTPException(
            status_code=503,
            detail=f"JattBeat frontend file is missing or unreadable: {frontend_path} ({exc})",
        )


@app.get("/", response_class=HTMLResponse)
def root():
    return HTMLResponse(content=frontend_html())


def seo_escape(value: str) -> str:
    return (str(value or "").replace("&", "&amp;").replace("<", "&lt;")
            .replace(">", "&gt;").replace('"', "&quot;").replace("'", "&#39;"))


def song_duration_iso(value: str) -> str:
    match = re.fullmatch(r"(\d{1,2}):(\d{2})", str(value or "").strip())
    if not match:
        return ""
    minutes, seconds = int(match.group(1)), int(match.group(2))
    return f"PT{minutes}M{seconds}S"


@app.get("/song/{song_path}", response_class=HTMLResponse)
def song_page(song_path: str):
    match = re.match(r"^(\d+)", song_path)
    if not match:
        raise HTTPException(status_code=404, detail="Song not found")
    song_id = int(match.group(1))
    with closing(db()) as conn:
        song = conn.execute(
            """SELECT id,title,artist,album,label,release_date,duration,artwork_url
               FROM songs WHERE id=?""",
            (song_id,),
        ).fetchone()
    if not song:
        raise HTTPException(status_code=404, detail="Song not found")

    title = str(song["title"] or "Punjabi Song").strip()
    artist = str(song["artist"] or "").strip()
    title_tag = f"{title} – {artist} | Punjabi Song | JattBeat" if artist else f"{title} | Punjabi Song | JattBeat"
    description = (
        f"Listen to {title} by {artist} on JattBeat. "
        f"Explore song details, release information, duration and more Punjabi music."
        if artist else
        f"Explore {title} on JattBeat. View song details, release information and more Punjabi music."
    )
    song_slug = re.sub(r"[^a-z0-9]+", "-", title.lower()).strip("-")
    canonical = f"{os.getenv('SITE_URL', 'https://dj-laqb.onrender.com').rstrip('/')}/song/{song_id}-{song_slug}"
    keywords = [
        title, f"{title} song", f"{title} Punjabi song", artist,
        "Punjabi songs", "Punjabi single track", "latest Punjabi songs",
        "Punjabi songs 2026", "JattBeat",
    ]
    keywords = ", ".join(dict.fromkeys(k for k in keywords if k))
    image_url = str(song["artwork_url"] or "")
    tags = (
        f'<title>{seo_escape(title_tag)}</title>\n'
        f'<meta name="description" content="{seo_escape(description)}">\n'
        f'<meta name="keywords" content="{seo_escape(keywords)}">\n'        f'<link rel="canonical" href="{seo_escape(canonical)}">\n'
        f'<meta name="robots" content="index,follow,max-image-preview:large">\n'
        f'<meta property="og:type" content="music.song">\n'
        f'<meta property="og:site_name" content="JattBeat">\n'
        f'<meta property="og:title" content="{seo_escape(title_tag)}">\n'
        f'<meta property="og:description" content="{seo_escape(description)}">\n'
        f'<meta property="og:url" content="{seo_escape(canonical)}">\n'
        f'<meta property="og:locale" content="en_IN">\n'
        f'<meta name="twitter:card" content="summary_large_image">\n'
        f'<meta name="twitter:title" content="{seo_escape(title_tag)}">\n'
        f'<meta name="twitter:description" content="{seo_escape(description)}">\n'
        + (f'<meta property="og:image" content="{seo_escape(image_url)}">\n<meta name="twitter:image" content="{seo_escape(image_url)}">\n' if image_url else "")
    )
    schema = {
        "@context": "https://schema.org",
        "@type": "MusicRecording",
        "name": title,
        "url": canonical,
        "inLanguage": "pa",
        "genre": "Punjabi",
        "publisher": {"@type": "Organization", "name": str(song["label"] or "JattBeat")},
    }
    if artist:
        schema["byArtist"] = {"@type": "Person", "name": artist}
    if image_url:
        schema["image"] = image_url
    if song["release_date"]:
        schema["datePublished"] = str(song["release_date"])[:10]
    duration_iso = song_duration_iso(song["duration"])
    if duration_iso:
        schema["duration"] = duration_iso
    if song["album"]:
        schema["inAlbum"] = {"@type": "MusicAlbum", "name": str(song["album"])}
    schema_script = '<script type="application/ld+json">' + json.dumps(schema, ensure_ascii=False).replace("</", "<\\/") + '</script>'
    html = frontend_html()
    html = re.sub(r"<title>.*?</title>", "", html, count=1, flags=re.I | re.S)
    html = html.replace("</head>", tags + schema_script + "\n</head>", 1)

    # Put song-specific text in the initial HTML response as well as in the
    # browser-side renderer, so crawlers can read the page without JS execution.
    html = html.replace('<section class="home" id="homeView">', '<section class="home hidden" id="homeView">', 1)
    html = html.replace('<section class="detail" id="detailView">', '<section class="detail active" id="detailView">', 1)
    html = html.replace('<span id="crumbTitle">Song</span>', f'<span id="crumbTitle">{seo_escape(title)}</span>', 1)
    html = html.replace('<h1 class="detail-title" id="detailTitle">Song title</h1>', f'<h1 class="detail-title" id="detailTitle">{seo_escape(title)}</h1>', 1)
    html = html.replace('<span id="detailArtist">Artist</span>', f'<span id="detailArtist">{seo_escape(artist)}</span>', 1)
    release_display = str(song["release_date"] or "Not provided")[:10]
    html = html.replace('<span id="detailDate">Release date unavailable</span>', f'<span id="detailDate">{seo_escape(release_display)}</span>', 1)
    if image_url:
        html = html.replace('id="detailCover" class="detail-cover" alt="Song cover artwork"', f'id="detailCover" class="detail-cover" src="{seo_escape(image_url)}" alt="{seo_escape(title)} cover artwork"', 1)
    info_values = [
        ("Song Title", title), ("Singer", artist or "Not provided"),
        ("Source", "JattBeat"), ("Release Date", release_display),
        ("Language", "Punjabi"), ("Duration", str(song["duration"] or "Not provided")),
        ("Music Label", str(song["label"] or "Not provided")),
    ]
    info_html = "".join(
        f'<div class="info-row"><span>{seo_escape(key)}</span><strong>{seo_escape(value)}</strong></div>'
        for key, value in info_values
    )
    html = html.replace('<div class="info-card" id="detailInfo"></div>', f'<div class="info-card" id="detailInfo">{info_html}</div>', 1)
    about = f"{title} is a Punjabi single by {artist}. Released on {release_display}, the track has a duration of {song['duration'] or 'not listed'} and is listed under {song['label'] or 'an unlisted music label'}. Explore more Punjabi music on JattBeat."
    html = html.replace('<p class="about" id="detailAbout"></p>', f'<p class="about" id="detailAbout">{seo_escape(about)}</p>', 1)
    html = html.replace('<span id="faqTitle"></span>', seo_escape(title), 1)
    return HTMLResponse(content=html)


@app.get("/health")
def health():
    return {
        "ok": True,
        "service": APP_NAME,
        "time": datetime.now(timezone.utc).isoformat(),
    }



def create_media_token(song_id: int) -> str:
    expires = int(time.time()) + MEDIA_TOKEN_TTL
    payload = f"{int(song_id)}.{expires}"
    signature = hmac.new(MEDIA_TOKEN_SECRET, payload.encode("utf-8"), hashlib.sha256).hexdigest()
    encoded = base64.urlsafe_b64encode(payload.encode("utf-8")).decode("ascii").rstrip("=")
    return f"{encoded}.{signature}"


def verify_media_token(token: str):
    try:
        encoded, supplied_signature = token.rsplit(".", 1)
        padded = encoded + "=" * (-len(encoded) % 4)
        payload = base64.urlsafe_b64decode(padded.encode("ascii")).decode("utf-8")
        song_id_text, expires_text = payload.split(".", 1)
        song_id, expires = int(song_id_text), int(expires_text)
        expected = hmac.new(
            MEDIA_TOKEN_SECRET, payload.encode("utf-8"), hashlib.sha256
        ).hexdigest()
        if not hmac.compare_digest(supplied_signature, expected):
            return None
        if expires < int(time.time()):
            return None
        return song_id
    except (ValueError, TypeError, UnicodeError):
        return None


@app.get("/media/{token}")
async def stream_song_media(token: str, download: bool = False, range_header: str = Header(default="", alias="Range")):
    song_id = verify_media_token(token)
    if song_id is None:
        raise HTTPException(status_code=403, detail="This media link is invalid or has expired. Refresh the song page.")

    with closing(db()) as conn:
        song = conn.execute("SELECT title, audio_url FROM songs WHERE id=?", (song_id,)).fetchone()
    if not song or not song["audio_url"]:
        raise HTTPException(status_code=404, detail="Audio is not available for this song.")

    media_url = song["audio_url"]
    parsed = urlparse(media_url)
    if parsed.scheme != "https" or (parsed.hostname or "").lower() not in MEDIA_HOSTS:
        raise HTTPException(status_code=403, detail="This media provider is not approved.")

    client = httpx.AsyncClient(timeout=None, follow_redirects=False)
    request_headers = {"User-Agent": USER_AGENT}
    if range_header:
        request_headers["Range"] = range_header
    try:
        upstream_request = client.build_request("GET", media_url, headers=request_headers)
        upstream = await client.send(upstream_request, stream=True)
    except httpx.HTTPError:
        await client.aclose()
        raise HTTPException(status_code=502, detail="The audio provider is temporarily unavailable.")

    if upstream.status_code not in (200, 206):
        status_code = upstream.status_code
        await upstream.aclose()
        await client.aclose()
        raise HTTPException(status_code=502, detail=f"Audio provider returned status {status_code}.")

    response_headers = {"Cache-Control": "private, no-store", "X-Content-Type-Options": "nosniff"}
    for header in ("content-length", "content-range", "accept-ranges"):
        if header in upstream.headers:
            response_headers[header.title()] = upstream.headers[header]
    filename = re.sub(r"[^A-Za-z0-9 _-]", "", song["title"] or "JattBeat Song").strip() or "JattBeat Song"
    disposition = "attachment" if download else "inline"
    response_headers["Content-Disposition"] = f'{disposition}; filename="{filename}.mp3"'
    content_type = upstream.headers.get("content-type", "audio/mpeg")

    async def relay():
        try:
            async for chunk in upstream.aiter_raw():
                yield chunk
        finally:
            await upstream.aclose()
            await client.aclose()

    return StreamingResponse(relay(), status_code=upstream.status_code, headers=response_headers, media_type=content_type)

@app.get("/api/songs")
def list_songs(limit: int = 50):
    limit = max(1, min(limit, 100))
    with closing(db()) as conn:
        count = conn.execute("SELECT COUNT(*) FROM songs").fetchone()[0]
    if count == 0:
        seed_known_catalog_if_empty()

    with closing(db()) as conn:
        rows = conn.execute(
            """SELECT id,title,artist,album,label,release_date,duration,
                      source_url,audio_url,artwork_url,wordpress_url
               FROM songs
               ORDER BY CASE WHEN release_date IS NULL OR release_date = '' THEN 1 ELSE 0 END,
                        release_date DESC, id DESC
               LIMIT ?""",
            (limit,),
        ).fetchall()
    public_items = []
    for row in rows:
        item = dict(row)
        item.pop("source_url", None)
        item.pop("audio_url", None)
        item["media_url"] = f"/media/{create_media_token(item['id'])}" if row["audio_url"] else ""
        item["source"] = "JattBeat"
        public_items.append(item)
    return {"count": len(public_items), "items": public_items}


@app.post("/admin/import")
def import_feed(x_import_token: str = Header(default="")):
    if not IMPORT_TOKEN or x_import_token != IMPORT_TOKEN:
        raise HTTPException(status_code=401, detail="Invalid import token")
    if not FEED_URL:
        raise HTTPException(
            status_code=503, detail="Set FEED_URL in Render environment"
        )

    try:
        parsed_url = urlparse(FEED_URL)
        if parsed_url.scheme != "https":
            raise ValueError("FEED_URL must use HTTPS")

        with httpx.Client(
            timeout=30,
            follow_redirects=False,
            headers={"User-Agent": USER_AGENT},
        ) as client:
            response = client.get(FEED_URL)
            response.raise_for_status()

        entries = parse_feed(response.content)
        created, skipped, errors = 0, 0, []

        for index, entry in enumerate(entries[:100]):
            try:
                song = normalize_entry(entry)
                with closing(db()) as conn:
                    existing = conn.execute(
                        "SELECT id FROM songs WHERE fingerprint=?",
                        (song["fingerprint"],),
                    ).fetchone()
                    if existing:
                        # Refresh metadata for existing feed items so corrected
                        # artist credits and newly authorized links can be applied.
                        conn.execute(
                            """UPDATE songs SET title=?, artist=?,
                               album=CASE WHEN ? != '' THEN ? ELSE album END,
                               label=CASE WHEN ? != '' THEN ? ELSE label END,
                               release_date=CASE WHEN ? != '' THEN ? ELSE release_date END,
                               duration=CASE WHEN ? != '' THEN ? ELSE duration END,
                               source_url=?,
                               audio_url=CASE WHEN ? != '' THEN ? ELSE audio_url END,
                               artwork_url=CASE WHEN ? != '' THEN ? ELSE artwork_url END
                               WHERE fingerprint=?""",
                            (
                                song["title"], song["artist"],
                                song["album"], song["album"],
                                song["label"], song["label"],
                                song["release_date"], song["release_date"],
                                song["duration"], song["duration"],
                                song["source_url"],
                                song["audio_url"], song["audio_url"],
                                song["artwork_url"], song["artwork_url"],
                                song["fingerprint"],
                            ),
                        )
                        conn.commit()
                        skipped += 1
                        continue

                    wordpress_id, wordpress_url = wp_sync(song)
                    conn.execute(
                        """INSERT INTO songs
                        (fingerprint,external_id,title,artist,album,label,
                         release_date,duration,description,source_url,audio_url,
                         artwork_url,wordpress_id,wordpress_url,created_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
                        (
                            song["fingerprint"],
                            song["external_id"],
                            song["title"],
                            song["artist"],
                            song["album"],
                            song["label"],
                            song["release_date"],
                            song["duration"],
                            song["description"],                            song["source_url"],
                            song["audio_url"],
                            song["artwork_url"],
                            wordpress_id,
                            wordpress_url,
                            datetime.now(timezone.utc).isoformat(),
                        ),
                    )
                    conn.commit()
                created += 1
            except Exception as exc:
                errors.append({"item": index, "error": str(exc)[:240]})

        return {
            "feed_items": len(entries),
            "created": created,
            "duplicates_skipped": skipped,
            "errors": errors[:20],
        }
    except Exception as exc:
        raise HTTPException(status_code=502, detail=str(exc)[:400])

# ---------------------------------------------------------------------------
# JattBeat admin: import older catalogue pages (new-only, deduplicated)
# ---------------------------------------------------------------------------
from html.parser import HTMLParser
from urllib.parse import urljoin, urlparse
from fastapi import Request

class _PageParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.links = []
        self.meta = {}
        self.title_text = []
        self.in_title = False
        self.media_links = []
    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == "a" and a.get("href"):
            self.links.append(a["href"])
        if tag == "meta":
            key = (a.get("property") or a.get("name") or a.get("itemprop") or "").lower()
            if key and a.get("content"):
                self.meta[key] = a["content"]
        if tag in ("audio", "source") and a.get("src"):
            self.media_links.append(a["src"])
        if tag == "a" and a.get("href", "").lower().split("?")[0].endswith(".mp3"):
            self.media_links.append(a["href"])
        if tag == "title":
            self.in_title = True
    def handle_endtag(self, tag):
        if tag == "title":
            self.in_title = False
    def handle_data(self, data):
        if self.in_title:
            self.title_text.append(data)

def _extract_listing_links(html, page_url):
    parser = _PageParser()
    parser.feed(html)
    found = []
    for href in parser.links:
        absolute = urljoin(page_url, href)
        parsed = urlparse(absolute)
        if parsed.scheme == "https" and parsed.hostname in {"www.djjohal.com", "djjohal.com"} and re.search(r"/single/[^/?#]+\.html$", parsed.path, re.I):
            if absolute not in found:
                found.append(absolute)
    return found

def _extract_older_song(detail_html, source_url):
    parser = _PageParser()
    parser.feed(detail_html)
    meta = parser.meta
    raw_title = clean(meta.get("og:title") or meta.get("twitter:title") or " ".join(parser.title_text))
    raw_title = re.sub(r"\s*[-|]\s*(DJJOhAL|DJJOhAL\.com).*$", "", raw_title, flags=re.I).strip()
    title, artist = parse_title_and_artist(raw_title, "")
    artwork = urljoin(source_url, meta.get("og:image") or meta.get("twitter:image") or "")
    audio = ""
    for candidate in parser.media_links + parser.links:
        candidate = urljoin(source_url, candidate)
        p = urlparse(candidate)
        if p.scheme == "https" and (p.hostname or "").lower() in MEDIA_HOSTS and p.path.lower().endswith(".mp3"):
            audio = candidate
            break
    duration = clean(meta.get("music:duration") or meta.get("duration"))
    label = clean(meta.get("music:album") or meta.get("music:label"))
    release_date = clean(meta.get("article:published_time") or meta.get("date") or meta.get("datepublished"))
    if release_date:
        release_date = release_date[:10]
    if not title:
        raise ValueError("Song title could not be read from detail page")
    if artwork:
        p = urlparse(artwork)
        if p.scheme != "https" or not p.hostname:
            artwork = ""
    external_id = source_url.rstrip("/").rsplit("/", 1)[-1]
    fingerprint = hashlib.sha256(source_url.rstrip("/").lower().encode("utf-8")).hexdigest()
    return {
        "fingerprint": fingerprint, "external_id": external_id,
        "title": title, "artist": artist, "album": "",
        "label": label, "release_date": release_date,
        "duration": duration, "description": "",
        "source_url": source_url, "audio_url": audio,
        "artwork_url": artwork,
    }

@app.get("/admin", response_class=HTMLResponse)
def older_pages_admin():
    page = """<!doctype html><html lang="en"><meta name="viewport" content="width=device-width,initial-scale=1"><title>JattBeat Admin</title>
    <style>body{margin:0;background:#090a10;color:#f7f7fb;font:16px system-ui;padding:22px}.wrap{max-width:720px;margin:auto}h1 span{color:#f20d32}.card{background:#151722;border:1px solid #343544;border-radius:16px;padding:18px;margin:18px 0}textarea,input{width:100%;box-sizing:border-box;background:#090a10;color:white;border:1px solid #414353;border-radius:10px;padding:13px;margin:8px 0 14px;font:14px system-ui}textarea{min-height:150px}button{background:#f20d32;color:white;border:0;border-radius:25px;padding:13px 22px;font-weight:800;font-size:15px}small{color:#aaa}pre{white-space:pre-wrap;overflow-wrap:anywhere;background:#090a10;padding:14px;border-radius:10px}</style>
    <div class="wrap"><h1>Jatt<span>Beat</span> Admin</h1><div class="card"><h2>Import Older Pages</h2><p>Paste one or more DJJOhAL Single Track category page URLs. New songs only; duplicates are skipped.</p>
    <label>Import token</label><input id="token" type="password" autocomplete="off" placeholder="Your Render IMPORT_TOKEN">
    <label>Category page URLs (one per line)</label><textarea id="urls">https://www.djjohal.com/category/single-track/2.html
https://www.djjohal.com/category/single-track/3.html</textarea>
    <button id="go">Import Older Pages</button><p><small>Pages are processed in the order entered. The public catalogue remains sorted newest first by release date.</small></p></div><div class="card"><h3>Import result</h3><pre id="result">Ready.</pre></div></div>
    <script>document.getElementById('go').onclick=async()=>{const b=document.getElementById('go'),out=document.getElementById('result');const token=document.getElementById('token').value;const urls=document.getElementById('urls').value.split(/\n+/).map(x=>x.trim()).filter(Boolean);if(!token||!urls.length){out.textContent='Enter the import token and at least one page URL.';return}b.disabled=true;b.textContent='Importing…';out.textContent='Fetching listing pages and song details. Please keep this page open.';try{const r=await fetch('/admin/import-pages',{method:'POST',headers:{'Content-Type':'application/json','X-Import-Token':token},body:JSON.stringify({urls})});const data=await r.json();if(!r.ok)throw new Error(data.detail||('HTTP '+r.status));out.textContent=JSON.stringify(data,null,2)}catch(e){out.textContent='Import failed: '+e.message}finally{b.disabled=false;b.textContent='Import Older Pages'}}</script></html>"""
    return HTMLResponse(page)

@app.post("/admin/import-pages")
async def import_older_pages(request: Request, x_import_token: str = Header(default="")):
    if not IMPORT_TOKEN or not hmac.compare_digest(x_import_token, IMPORT_TOKEN):
        raise HTTPException(status_code=401, detail="Invalid import token")
    try:
        payload = await request.json()
    except Exception:
        raise HTTPException(status_code=400, detail="Expected JSON containing a urls array")
    urls = payload.get("urls", []) if isinstance(payload, dict) else []
    if not isinstance(urls, list) or not urls or len(urls) > 20:
        raise HTTPException(status_code=400, detail="Provide between 1 and 20 category page URLs")
    normalized_pages = []
    for raw in urls:
        parsed = urlparse(clean(raw))
        if parsed.scheme != "https" or (parsed.hostname or "").lower() not in {"www.djjohal.com", "djjohal.com"} or not re.fullmatch(r"/category/single-track(?:/\d+)?\.html", parsed.path, re.I):
            raise HTTPException(status_code=400, detail=f"Not an approved Single Track category URL: {raw}")
        normalized_pages.append(raw)
    summary = {"pages_checked": 0, "song_pages_found": 0, "created": 0, "duplicates_skipped": 0, "errors": []}
    headers = {"User-Agent": USER_AGENT}
    async with httpx.AsyncClient(timeout=25, follow_redirects=True, headers=headers) as client:
        for page_url in normalized_pages:
            try:
                response = await client.get(page_url)
                response.raise_for_status()
                links = _extract_listing_links(response.text, str(response.url))
                summary["pages_checked"] += 1
                summary["song_pages_found"] += len(links)
                for song_url in links[:60]:
                    try:
                        detail = await client.get(song_url)
                        detail.raise_for_status()
                        song = _extract_older_song(detail.text, song_url)
                        with closing(db()) as conn:
                            existing = conn.execute("SELECT id FROM songs WHERE fingerprint=? OR source_url=?", (song["fingerprint"], song["source_url"])).fetchone()
                            if existing:
                                summary["duplicates_skipped"] += 1
                                continue
                            conn.execute("""INSERT INTO songs
                                (fingerprint,external_id,title,artist,album,label,release_date,
                                 duration,description,source_url,audio_url,artwork_url,created_at)
                                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)""",
                                (song["fingerprint"],song["external_id"],song["title"],song["artist"],
                                 song["album"],song["label"],song["release_date"],song["duration"],
                                 song["description"],song["source_url"],song["audio_url"],song["artwork_url"],
                                 datetime.now(timezone.utc).isoformat()))
                            conn.commit()
                        summary["created"] += 1
                        await asyncio.sleep(0.6)
                    except Exception as exc:
                        summary["errors"].append({"url": song_url, "error": str(exc)[:180]})
            except Exception as exc:
                summary["errors"].append({"url": page_url, "error": str(exc)[:180]})
    summary["errors"] = summary["errors"][:30]
    return summary
