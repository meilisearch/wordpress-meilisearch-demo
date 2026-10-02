"""Shared helpers for the demo data scripts: deterministic RNG, HTML sanitising, images, polite HTTP."""
from __future__ import annotations

import hashlib
import io
import json
import random
import re
import time
from html import escape
from html.parser import HTMLParser
from pathlib import Path

import requests
from PIL import Image

SEED = 20261002
USER_AGENT = "meilisearch-wordpress-demo/1.0 (+https://github.com/meilisearch/meilisearch-wordpress)"
ALLOWED_TAGS = frozenset({"p", "h2", "h3", "ul", "ol", "li", "blockquote", "figure", "figcaption", "img", "a", "strong", "em"})
VOID_TAGS = frozenset({"img"})
DROP_WITH_CONTENT = frozenset({"script", "style", "iframe", "noscript", "svg", "form", "button"})
_last_request = 0.0


def rng(name: str) -> random.Random:
    digest = hashlib.sha256(f"{SEED}:{name}".encode()).digest()
    return random.Random(int.from_bytes(digest[:8], "big"))


class _Sanitizer(HTMLParser):
    def __init__(self, allowed: frozenset[str]) -> None:
        super().__init__(convert_charrefs=True)
        self.allowed = allowed
        self.out: list[str] = []
        self.drop_depth = 0

    def handle_starttag(self, tag, attrs):
        if tag in DROP_WITH_CONTENT:
            self.drop_depth += 1
            return
        if self.drop_depth or tag not in self.allowed:
            return
        kept = []
        for name, value in attrs:
            if name in ("href", "src") and value and not re.match(r"\s*(javascript|data|vbscript):", value, re.I):
                kept.append(f' {name}="{escape(value, quote=True)}"')
        self.out.append(f"<{tag}{''.join(kept)}>")

    def handle_startendtag(self, tag, attrs):
        self.handle_starttag(tag, attrs)

    def handle_endtag(self, tag):
        if tag in DROP_WITH_CONTENT:
            self.drop_depth = max(0, self.drop_depth - 1)
            return
        if self.drop_depth or tag not in self.allowed or tag in VOID_TAGS:
            return
        self.out.append(f"</{tag}>")

    def handle_data(self, data):
        if not self.drop_depth:
            self.out.append(escape(data, quote=False))


def sanitize_html(html: str, allowed: frozenset[str] = ALLOWED_TAGS) -> str:
    parser = _Sanitizer(allowed)
    parser.feed(html)
    parser.close()
    out = "".join(parser.out)
    out = re.sub(r"<(p|li|h2|h3|figcaption)>\s*</\1>", "", out)
    return re.sub(r"\s+\n|\n\s+", "\n", out).strip()


def strip_tags(html: str) -> str:
    # Insert spaces where block-level tags are removed to maintain word boundaries
    html = re.sub(r'</(?:p|li|h2|h3|figcaption|div|section|article|blockquote)>', ' ', html, flags=re.I)
    return re.sub(r"\s+", " ", sanitize_html(html, frozenset())).strip()


def word_count(html: str) -> int:
    return len(strip_tags(html).split())


def save_webp(data: bytes, dest: Path, max_side: int = 1600, quality: int = 80) -> tuple[int, int]:
    image = Image.open(io.BytesIO(data))
    image = image.convert("RGB")
    image.thumbnail((max_side, max_side), Image.LANCZOS)
    dest.parent.mkdir(parents=True, exist_ok=True)
    image.save(dest, "WEBP", quality=quality, method=6)
    return image.size


def http_get(url: str, *, cache_dir: Path, min_interval: float = 0.5) -> bytes:
    global _last_request
    cache_dir.mkdir(parents=True, exist_ok=True)
    cached = cache_dir / hashlib.sha256(url.encode()).hexdigest()
    if cached.exists():
        return cached.read_bytes()
    wait = min_interval - (time.monotonic() - _last_request)
    if wait > 0:
        time.sleep(wait)
    for attempt in range(4):
        response = requests.get(url, headers={"User-Agent": USER_AGENT}, timeout=60)
        _last_request = time.monotonic()
        if response.status_code in (429, 500, 502, 503, 504):
            time.sleep(2 ** attempt * 2)
            continue
        response.raise_for_status()
        cached.write_bytes(response.content)
        return response.content
    response.raise_for_status()
    raise RuntimeError(f"GET {url} kept failing")


def write_json(path: Path, data) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(data, indent=2, sort_keys=True, ensure_ascii=False) + "\n", encoding="utf-8")
