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


def _is_safe_url(url: str) -> bool:
    """Check if URL is safe (allowlist approach).

    Allow http, https, mailto schemes or relative URLs (no scheme).
    """
    # Remove control chars and whitespace [\x00-\x20]
    cleaned = re.sub(r'[\x00-\x20]', '', url)
    if not cleaned:
        return False

    # Find the first ':' in the cleaned URL
    scheme_end = cleaned.find(':')

    # Find the first '/', '?', or '#' in the cleaned URL
    path_start = len(cleaned)
    for char in ['/', '?', '#']:
        pos = cleaned.find(char)
        if pos != -1 and pos < path_start:
            path_start = pos

    # If no ':' or ':' comes after '/', '?', or '#', it's a relative URL (safe)
    if scheme_end == -1 or scheme_end >= path_start:
        return True

    # Has a scheme, check if it's allowed
    scheme = cleaned[:scheme_end].lower()
    return scheme in ('http', 'https', 'mailto')


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
            if name in ("href", "src") and value and _is_safe_url(value):
                kept.append(f' {name}="{escape(value, quote=True)}"')
            elif name == "alt" and tag == "img" and value is not None:
                kept.append(f' alt="{escape(value, quote=True)}"')
        self.out.append(f"<{tag}{''.join(kept)}>")

    def handle_startendtag(self, tag, attrs):
        if tag in DROP_WITH_CONTENT:
            # Don't increment drop_depth for self-closing tags
            return
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
    out = unwrap_block_links(out)
    out = re.sub(r"<a(?: [^>]*)?>(?:\s|<img>)*</a>", "", out)  # links whose content was all dropped
    out = re.sub(r"<(p|li|h2|h3|figcaption)>\s*</\1>", "", out)
    return re.sub(r"\s+\n|\n\s+", "\n", out).strip()


class _TextCollector(HTMLParser):
    """Extract text from HTML, handling word boundaries and entity unescaping."""
    BLOCK_TAGS = frozenset({"p", "div", "br", "li", "ul", "ol", "h1", "h2", "h3", "h4", "h5", "h6", "tr", "td", "th", "figure", "figcaption", "blockquote", "section", "article"})

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.out: list[str] = []
        self.drop_depth = 0

    def handle_starttag(self, tag, attrs):
        if tag in DROP_WITH_CONTENT:
            self.drop_depth += 1
            return
        if self.drop_depth:
            return
        if tag in self.BLOCK_TAGS:
            self.out.append(" ")

    def handle_startendtag(self, tag, attrs):
        if tag in DROP_WITH_CONTENT:
            # Don't change drop_depth for self-closing tags
            return
        if self.drop_depth:
            return
        if tag in self.BLOCK_TAGS:
            self.out.append(" ")

    def handle_endtag(self, tag):
        if tag in DROP_WITH_CONTENT:
            self.drop_depth = max(0, self.drop_depth - 1)
            return
        if self.drop_depth:
            return
        if tag in self.BLOCK_TAGS:
            self.out.append(" ")

    def handle_data(self, data):
        if not self.drop_depth:
            self.out.append(data)


_BLOCK_LINK = re.compile(r"<a(?: [^>]*)?>((?:(?!</a>).)*?<(?:h[1-6]|p|ul|ol|li|figure|blockquote)\b(?:(?!</a>).)*)</a>", re.S)


def unwrap_block_links(html: str) -> str:
    """Links can't contain block elements (browsers split them into empty links): keep the content, drop the link."""
    return _BLOCK_LINK.sub(r"\1", html)


def strip_tags(html: str) -> str:
    parser = _TextCollector()
    parser.feed(html)
    parser.close()
    text = "".join(parser.out)
    # Collapse whitespace
    return re.sub(r"\s+", " ", text).strip()


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
