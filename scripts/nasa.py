"""Builds data/blog from science.nasa.gov (US government work, public domain): ~1,000 articles across six topics."""
from __future__ import annotations

import argparse
import html as htmllib
import json
import re
import sys
from pathlib import Path
from urllib.parse import unquote, urlparse

import requests

import common

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / "data" / "blog"
CACHE = Path(__file__).resolve().parent / ".cache" / "nasa"
API = "https://science.nasa.gov/wp-json/wp/v2"
EMBED = "_embed=wp:featuredmedia,wp:term,author"
INLINE_MAX_IMAGES = 2  # per article; keeps data/blog/images small enough for the repo
INLINE_MAX_SIDE = 800
NASA_CREDIT = re.compile(r"\b(nasa|jpl|stsci|esa|csa|goddard|caltech|jhuapl|swri|usgs|noaa)\b", re.I)


def assign_topic(category_names: list[str], topics: list[dict]) -> str | None:
    names = set(category_names)
    for topic in topics:
        if names.intersection(topic["categories"]):
            return topic["slug"]
    return None


def is_excluded(category_names: list[str], excluded: list[str]) -> bool:
    return bool(set(category_names).intersection(excluded))


def is_third_party(caption: str, filename: str, patterns: list[str]) -> bool:
    name = unquote(filename).lower()
    text = (caption or "").lower()
    if any(p.lower() in text or p.lower() in name for p in patterns):
        return True
    credit = re.search(r"credit\s*:\s*(.+)$", caption or "", re.I | re.S)
    if credit and not NASA_CREDIT.search(credit.group(1)):
        return True
    file_credit = re.search(r"credit[_\s-]+([^/]+?)\.(jpe?g|png|webp|gif|tiff?)$", name)
    if file_credit and not NASA_CREDIT.search(file_credit.group(1)):
        return True
    return False


_FIGURE = re.compile(r"<figure>(.*?)</figure>", re.S)
_IMG = re.compile(r'<img src="([^"]*)">')
_CAPTION = re.compile(r"<figcaption>(.*?)</figcaption>", re.S)


def rewrite_images(content: str, decide) -> str:
    """decide(src, caption) -> new src, or None to drop the image (and its figure)."""

    def figure(match: re.Match) -> str:
        inner = match.group(1)
        img = _IMG.search(inner)
        if not img:
            # A figure whose image lost its src (sanitiser) has nothing to show.
            return "" if "<img>" in inner else match.group(0)
        caption = common.strip_tags(_CAPTION.search(inner).group(1)) if _CAPTION.search(inner) else ""
        new_src = decide(htmllib.unescape(img.group(1)), caption)
        if new_src is None:
            return ""
        return "<figure>" + inner.replace(img.group(0), f'<img src="{new_src}">', 1) + "</figure>"

    content = _FIGURE.sub(figure, content)

    def bare(match: re.Match) -> str:
        if match.group(1).startswith("inline/"):
            return match.group(0)
        new_src = decide(htmllib.unescape(match.group(1)), "")
        return "" if new_src is None else f'<img src="{new_src}">'

    return _IMG.sub(bare, content).replace("<img>", "")


def select(posts: list[dict], quotas: dict[str, int]) -> list[dict]:
    """Newest first per topic, deduplicated by id, capped by quota; the newest post with a real image per topic is sticky."""
    seen: set[int] = set()
    per_topic: dict[str, list[dict]] = {}
    for post in sorted(posts, key=lambda p: p["date_gmt"], reverse=True):
        if post["id"] in seen:
            continue
        bucket = per_topic.setdefault(post["topic"], [])
        if len(bucket) >= quotas.get(post["topic"], 0):
            continue
        seen.add(post["id"])
        bucket.append(dict(post, sticky=False))
    for bucket in per_topic.values():
        for post in bucket:
            if not post["image_is_fallback"]:
                post["sticky"] = True
                break
    return sorted((p for b in per_topic.values() for p in b), key=lambda p: p["date_gmt"], reverse=True)


def _save(data: bytes, dest: Path, max_side: int = 1600) -> None:
    """Convert once: an existing file is the deterministic result of an earlier run."""
    if not dest.exists():
        common.save_webp(data, dest, max_side=max_side)


def _json(url: str):
    return json.loads(common.http_get(url, cache_dir=CACHE))


def category_ids(names: set[str]) -> dict[str, int]:
    found: dict[str, int] = {}
    page = 1
    while True:
        batch = _json(f"{API}/categories?per_page=100&page={page}&_fields=id,name")
        if not batch:
            break
        for c in batch:
            name = htmllib.unescape(c["name"])
            if name in names:
                found[name] = c["id"]
        page += 1
        if page > 30:
            break
    missing = names - found.keys()
    if missing:
        sys.exit(f"nasa_topics.json lists categories that do not exist on science.nasa.gov: {sorted(missing)}")
    return found


def month_windows(start: tuple[int, int] = (2026, 10), end_year: int = 2008):
    """Calendar months, newest first, as (after, before) timestamps. Date windows avoid the API's very slow deep offsets."""
    year, month = start
    while year >= end_year:
        ny, nm = (year + 1, 1) if month == 12 else (year, month + 1)
        yield f"{year}-{month:02d}-01T00:00:00", f"{ny}-{nm:02d}-01T00:00:00"
        year, month = (year - 1, 12) if month == 1 else (year, month - 1)


def window_posts(cat_ids: str, after: str, before: str):
    page = 1
    while True:
        try:
            batch = _json(f"{API}/posts?categories={cat_ids}&per_page=50&page={page}&after={after}&before={before}&orderby=date&order=desc&{EMBED}")
        except requests.HTTPError as error:
            if error.response is not None and error.response.status_code == 400:  # past the last page
                return
            raise
        yield from batch
        if len(batch) < 50:
            return
        page += 1


def terms(post: dict) -> list[str]:
    groups = post.get("_embedded", {}).get("wp:term", [])
    return [htmllib.unescape(t["name"]) for g in groups for t in g if t.get("taxonomy") == "category"]


def _author(name: str | None) -> str:
    if not name:
        return "NASA Science"
    return name.title() if name.isupper() else name


def build(post: dict, topic: str, cfg: dict, patterns: list[str], topics_by_slug: dict) -> dict | None:
    names = terms(post)
    if is_excluded(names, cfg["excluded_categories"]):
        return None
    content = common.sanitize_html(post["content"]["rendered"])
    if common.word_count(content) < cfg["min_words"]:
        return None
    pid = post["id"]
    counter = {"n": 0}

    def decide(src: str, caption: str):
        counter["n"] += 1  # numbered in document order, so a file name always means the same image
        if counter["n"] > INLINE_MAX_IMAGES:
            return None
        if is_third_party(caption, urlparse(src).path.rsplit("/", 1)[-1], patterns):
            return None
        try:
            data = common.http_get(src, cache_dir=CACHE / "img")
            rel = f"inline/{pid}-{counter['n']}.webp"
            _save(data, OUT / "images" / rel, max_side=INLINE_MAX_SIDE)
        except Exception:
            return None
        return rel

    content = rewrite_images(content, decide)
    image, alt, fallback = None, "", True
    media = (post.get("_embedded", {}).get("wp:featuredmedia") or [{}])[0]
    src = media.get("source_url")
    caption = common.strip_tags((media.get("caption") or {}).get("rendered", ""))
    if src and not is_third_party(caption, urlparse(src).path.rsplit("/", 1)[-1], patterns):
        try:
            _save(common.http_get(src, cache_dir=CACHE / "img"), OUT / "images" / f"{pid}.webp")
            image, alt, fallback = f"{pid}.webp", media.get("alt_text") or caption[:200], False
        except Exception:
            pass
    if image is None:
        image, alt = f"fallback-{topic}.webp", topics_by_slug[topic]["name"]
    authors = post.get("_embedded", {}).get("author") or [{}]
    return {
        "id": pid,
        "slug": post["slug"],
        "title": common.strip_tags(htmllib.unescape(post["title"]["rendered"])),
        "date_gmt": post["date_gmt"],
        "author": _author(authors[0].get("name")),
        "topic": topic,
        "tags": sorted(set(names) - {"Uncategorized"}),
        "excerpt": common.strip_tags(htmllib.unescape(post["excerpt"]["rendered"]))[:400],
        "content": content,
        "image": image,
        "image_alt": alt,
        "image_is_fallback": fallback,
        "source_url": post["link"],
    }


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--refresh", action="store_true", help="re-select posts instead of replaying data/blog/manifest.json")
    args = parser.parse_args()
    cfg = json.loads((Path(__file__).parent / "nasa_topics.json").read_text())
    patterns = [l.strip() for l in (Path(__file__).parent / "nasa_third_party.txt").read_text().splitlines() if l.strip()]
    topics_by_slug = {t["slug"]: t for t in cfg["topics"]}
    ids = category_ids({c for t in cfg["topics"] for c in t["categories"]})
    for t in cfg["topics"]:
        _save(common.http_get(t["fallback_image"], cache_dir=CACHE / "img"), OUT / "images" / f"fallback-{t['slug']}.webp")

    manifest_path = OUT / "manifest.json"
    candidates: list[dict] = []
    if manifest_path.exists() and not args.refresh:
        manifest = json.loads(manifest_path.read_text())
        for slug, post_ids in manifest["topics"].items():
            for i in range(0, len(post_ids), 50):
                chunk = ",".join(map(str, post_ids[i:i + 50]))
                for post in _json(f"{API}/posts?include={chunk}&per_page=50&{EMBED}"):
                    built = build(post, slug, cfg, patterns, topics_by_slug)
                    if built:
                        candidates.append(built)
    else:
        for topic in cfg["topics"]:
            cat_ids = ",".join(str(ids[c]) for c in topic["categories"])
            kept = 0
            for after, before in month_windows():
                if kept >= topic["quota"] * 1.3:
                    break
                for post in window_posts(cat_ids, after, before):
                    if assign_topic(terms(post), cfg["topics"]) != topic["slug"]:
                        continue
                    built = build(post, topic["slug"], cfg, patterns, topics_by_slug)
                    if built:
                        candidates.append(built)
                        kept += 1
                print(f"{topic['slug']}: {after[:7]}, {kept} candidates", file=sys.stderr)

    chosen = select(candidates, {t["slug"]: t["quota"] for t in cfg["topics"]})
    keep_files = {p["image"] for p in chosen} | {m for p in chosen for m in re.findall(r'src="(inline/[^"]+)"', p["content"])}
    for path in (OUT / "images").rglob("*.webp"):
        rel = path.relative_to(OUT / "images").as_posix()
        if rel not in keep_files and not rel.startswith("fallback-"):
            path.unlink()
    common.write_json(OUT / "articles.json", chosen)
    common.write_json(manifest_path, {"topics": {t["slug"]: sorted(p["id"] for p in chosen if p["topic"] == t["slug"]) for t in cfg["topics"]}})
    print(f"{len(chosen)} articles, {sum(1 for p in chosen if p['image_is_fallback'])} with a fallback image", file=sys.stderr)


if __name__ == "__main__":
    main()
