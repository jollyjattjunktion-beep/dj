from __future__ import annotations

import hashlib
import json
import os
import re
import sqlite3
from contextlib import closing
from datetime import datetime, timezone
from urllib.parse import urlparse

import feedparser
import httpx
from fastapi import FastAPI, Header, HTTPException

APP_NAME = "Preet Music Portal API"
DB_PATH = os.getenv("DB_PATH", "/tmp/preet_music_portal.sqlite3")
FEED_URL = os.getenv("FEED_URL", "").strip()
FEED_FORMAT = os.getenv("FEED_FORMAT", "auto").lower()
IMPORT_TOKEN = os.getenv("IMPORT_TOKEN", "")
WP_BASE_URL = os.getenv("WP_BASE_URL", "").rstrip("/")
WP_USERNAME = os.getenv("WP_USERNAME", "")
WP_APP_PASSWORD = os.getenv("WP_APP_PASSWORD", "")
WP_POST_STATUS = os.getenv("WP_POST_STATUS", "draft").lower()
AUDIO_ALLOWED_HOSTS = {
    h.strip().lower()
    for h in os.getenv("AUDIO_ALLOWED_HOSTS", "").split(",")
    if h.strip()
}
SOURCE_ALLOWED_HOSTS = {
    h.strip().lower()
    for h in os.getenv("SOURCE_ALLOWED_HOSTS", "").split(",")
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


@app.on_event("startup")
def startup():
    init_db()


def clean(value):
    if value is None:
        return ""
    if isinstance(value, (list, tuple)):
        value = ", ".join(str(item) for item in value)
    return re.sub(r"\\s+", " ", str(value)).strip()


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


def normalize_entry(entry):
    title = clean(field(entry, "title", "name"))
    artist = clean(field(entry, "artist", "author", "creator", "dc_creator"))
    source = safe_url(
        field(entry, "source_url", "link", "url"),
        SOURCE_ALLOWED_HOSTS,
        required=True,
    )
    audio = safe_url(
        field(entry, "audio_url", "stream_url", "enclosure_url"),
        AUDIO_ALLOWED_HOSTS,
    )
    artwork = safe_url(
        field(entry, "artwork_url", "image", "cover", "image_url"),
        SOURCE_ALLOWED_HOSTS,
    )
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
    value = re.sub(r"[^\\w-]+", "-", value.lower(), flags=re.UNICODE)
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


@app.get("/")
def root():
    return {
        "service": APP_NAME,
        "version": "0.1.0",
        "health": "/health",
        "docs": "/docs",
    }


@app.get("/health")
def health():
    return {
        "ok": True,
        "service": APP_NAME,
        "time": datetime.now(timezone.utc).isoformat(),
    }


@app.get("/api/songs")
def list_songs(limit: int = 50):
    limit = max(1, min(limit, 100))
    with closing(db()) as conn:
        rows = conn.execute(
            """SELECT id,title,artist,album,label,release_date,duration,
                      source_url,audio_url,artwork_url,wordpress_url
               FROM songs ORDER BY id DESC LIMIT ?""",
            (limit,),
        ).fetchall()
    return {"count": len(rows), "items": [dict(row) for row in rows]}


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
                            song["description"],
                            song["source_url"],
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
