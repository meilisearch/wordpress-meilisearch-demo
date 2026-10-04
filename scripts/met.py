"""Builds data/shop from The Met Open Access (CC0): ~1,500 public-domain artworks sold as variable print products.

Metadata comes from the Met's MetObjects.csv; images come from Wikimedia Commons (the Met's own CC0 uploads,
linked through Wikidata's "Met object ID" property). The Met Collection API is not used: its bot protection
blocks bulk fetching.
"""
from __future__ import annotations

import argparse
import csv
import io
import json
import re
import sys
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

import common

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / "data" / "shop"
CACHE = Path(__file__).resolve().parent / ".cache" / "met"
CSV_URL = "https://media.githubusercontent.com/media/metmuseum/openaccess/master/MetObjects.csv"
WIKIDATA = "https://query.wikidata.org/sparql"
WIKIDATA_QUERY = "SELECT ?met ?img WHERE { ?item wdt:P3634 ?met ; wdt:P18 ?img . }"
TARGET = 1500
CLASSES = ("Paintings", "Prints", "Drawings", "Photographs")
SIZES = [("S", 30, 24, 45), ("M", 50, 40, 70), ("L", 70, 56, 110), ("XL", 100, 80, 160)]
FINISHES = [("U", "Unframed", 0), ("B", "Black frame", 50), ("O", "Oak frame", 100)]
MEDIUM_RULES = [
    (r"woodblock", "Woodblock print"),
    (r"oil on canvas", "Oil on canvas"),
    (r"oil on (wood|panel|oak)", "Oil on wood"),
    (r"tempera", "Tempera"),
    (r"watercolou?r|gouache", "Watercolor"),
    (r"etching|engraving|drypoint|aquatint|mezzotint", "Etching & engraving"),
    (r"lithograph", "Lithograph"),
    (r"albumen|gelatin silver|salted paper|daguerreotype|platinum print|photograph|cyanotype", "Photograph"),
    (r"chalk|charcoal|pastel|graphite|crayon", "Chalk, charcoal & pastel"),
    (r"\bink\b", "Ink"),
    (r"\boil\b", "Oil, other"),
]
REVIEW_TEXTS = {
    5: ["Stunning print, the colours are spot on.", "Looks even better framed on the wall.", "Beautiful paper and very sharp detail."],
    4: ["Lovely print, arrived well packed.", "Great quality for the price.", "Very happy, slightly warmer tones than on screen."],
    3: ["Nice, but smaller than I imagined.", "Good print, frame could be sturdier."],
    2: ["Colours a little flat compared to the original."],
}


def bucket_medium(medium: str) -> str:
    text = (medium or "").lower()
    for pattern, label in MEDIUM_RULES:
        if re.search(pattern, text):
            return label
    return "Mixed media"


def _ordinal(n: int) -> str:
    suffix = "th" if 10 <= n % 100 <= 20 else {1: "st", 2: "nd", 3: "rd"}.get(n % 10, "th")
    return f"{n}{suffix}"


def century(year: int) -> str:
    if year < 0:
        return f"{_ordinal((-year - 1) // 100 + 1)} century BCE"
    return f"{_ordinal(max(year - 1, 0) // 100 + 1)} century"


def category(classification: str, department: str) -> str:
    if classification == "Prints" and department == "Asian Art":
        return "Japanese Prints"
    return classification


def orientation(width: int, height: int) -> str:
    if width > height * 1.05:
        return "landscape"
    if height > width * 1.05:
        return "portrait"
    return "square"


def _size_label(long: int, short: int, orient: str) -> str:
    if orient == "landscape":
        return f"{long} × {short} cm"
    if orient == "portrait":
        return f"{short} × {long} cm"
    return f"{long} × {long} cm"


def build_product(obj: dict, image_size: tuple[int, int]) -> dict:
    oid = int(obj["objectID"])
    r = common.rng(f"met-product-{oid}")
    orient = orientation(*image_size)
    classification = (obj.get("classification") or "").split("|")[0]
    on_sale = r.random() < 0.10
    variations = []
    for size_code, long, short, base in SIZES:
        for finish_code, finish_label, extra in FINISHES:
            regular = base + extra
            variations.append({
                "size_code": size_code,
                "size_label": _size_label(long, short, orient),
                "finish_code": finish_code,
                "finish_label": finish_label,
                "sku": f"MET-{oid}-{size_code}-{finish_code}",
                "regular_price": regular,
                "sale_price": round(regular * 0.8) if on_sale else None,
                "in_stock": r.random() >= 0.03,
            })
    reviews = []
    for _ in range(r.randint(0, 12)):
        rating = r.choices([5, 4, 3, 2], weights=[55, 30, 10, 5])[0]
        reviews.append({"rating": rating, "text": r.choice(REVIEW_TEXTS[rating]), "days_ago": r.randint(3, 700)})
    begin = int(obj.get("objectBeginDate") or 0)
    return {
        "id": oid,
        "title": obj.get("title") or "Untitled",
        "artist": obj.get("artistDisplayName") or "Unknown artist",
        "date": obj.get("objectDate") or "",
        "begin_date": begin,
        "department": obj.get("department") or "",
        "classification": classification,
        "category": category(classification, obj.get("department") or ""),
        "medium": obj.get("medium") or "",
        "medium_bucket": bucket_medium(obj.get("medium") or ""),
        "century": century(begin),
        "culture": obj.get("culture") or "",
        "dimensions": obj.get("dimensions") or "",
        "credit_line": obj.get("creditLine") or "",
        "tags": sorted({t["term"] for t in (obj.get("tags") or []) if t.get("term")}),
        "object_url": obj.get("objectURL") or "",
        "featured": bool(obj.get("isHighlight")),
        "image": f"{oid}.webp",
        "image_size": list(image_size),
        "orientation": orient,
        "price_regular": variations[0]["regular_price"],
        "on_sale": on_sale,
        "variations": variations,
        "reviews": reviews,
    }


def obj_from_row(row: dict) -> dict:
    """A MetObjects.csv row in the shape of the Met Collection API object build_product() reads."""
    first = lambda value: (value or "").split("|")[0].strip()
    try:
        begin = int(row.get("Object Begin Date") or 0)
    except ValueError:
        begin = 0
    url = (row.get("Link Resource") or "").replace("http://", "https://", 1)
    return {
        "objectID": int(row["Object ID"]),
        "title": row.get("Title") or "",
        "artistDisplayName": first(row.get("Artist Display Name")),
        "objectDate": row.get("Object Date") or "",
        "objectBeginDate": begin,
        "department": row.get("Department") or "",
        "classification": row.get("Classification") or "",
        "medium": row.get("Medium") or "",
        "culture": first(row.get("Culture")),
        "dimensions": row.get("Dimensions") or "",
        "creditLine": row.get("Credit Line") or "",
        "tags": [{"term": t.strip()} for t in (row.get("Tags") or "").split("|") if t.strip()],
        "objectURL": url,
        "isHighlight": row.get("Is Highlight") == "True",
        "isPublicDomain": row.get("Is Public Domain") == "True",
    }


def commons_url(filename: str, width: int = 1280) -> str:
    """Direct CDN URL of a standard-width Commons thumbnail (pre-rendered and cached, unlike Special:FilePath)."""
    import hashlib
    from urllib.parse import quote
    name = filename.replace(" ", "_")
    h = hashlib.md5(name.encode()).hexdigest()
    suffix = ".jpg" if name.lower().endswith((".tif", ".tiff")) else ""
    return f"https://upload.wikimedia.org/wikipedia/commons/thumb/{h[0]}/{h[:2]}/{quote(name)}/{width}px-{quote(name)}{suffix}"


def wikidata_images() -> dict[int, str]:
    """Met object ID -> Commons file name, from Wikidata (one cached query)."""
    from urllib.parse import quote, unquote
    raw = common.http_get(f"{WIKIDATA}?format=json&query={quote(WIKIDATA_QUERY)}", cache_dir=CACHE, min_interval=1.0)
    images: dict[int, str] = {}
    for b in json.loads(raw)["results"]["bindings"]:
        try:
            oid = int(b["met"]["value"])
        except ValueError:
            continue
        images.setdefault(oid, unquote(b["img"]["value"].rsplit("/", 1)[-1]))
    return images


def select_candidates(rows) -> list[int]:
    tiers: list[dict[str, list[int]]] = [{}, {}, {}]
    for row in rows:
        if row.get("Is Public Domain") != "True":
            continue
        if (row.get("Classification") or "").split("|")[0] not in CLASSES:
            continue
        tier = 0 if row.get("Is Highlight") == "True" else 1 if row.get("Is Timeline Work") == "True" else 2
        tiers[tier].setdefault(row.get("Department") or "", []).append(int(row["Object ID"]))
    ordered: list[int] = []
    for tier_index, tier in enumerate(tiers):
        r = common.rng(f"met-order-{tier_index}")
        queues = {dept: r.sample(ids, len(ids)) for dept, ids in sorted(tier.items())}
        while any(queues.values()):  # round-robin across departments
            for dept in sorted(queues):
                if queues[dept]:
                    ordered.append(queues[dept].pop(0))
    return ordered


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--refresh", action="store_true", help="re-select objects instead of replaying data/shop/manifest.json")
    args = parser.parse_args()
    raw = common.http_get(CSV_URL, cache_dir=CACHE)
    rows = {int(r["Object ID"]): r for r in csv.DictReader(io.StringIO(raw.decode("utf-8-sig")))}
    images = wikidata_images()
    manifest_path = OUT / "manifest.json"
    if manifest_path.exists() and not args.refresh:
        candidates = json.loads(manifest_path.read_text())["ids"]
    else:
        candidates = [oid for oid in select_candidates(rows.values()) if oid in images]
    eligible = [oid for oid in candidates if oid in images and rows[oid].get("Is Public Domain") == "True"]

    def fetch(oid: int):
        try:
            data = common.http_get(commons_url(images[oid]), cache_dir=CACHE / "img", min_interval=0)
            return oid, common.save_webp(data, OUT / "images" / f"{oid}.webp")
        except Exception as error:
            print(f"skip image {oid}: {error}", file=sys.stderr)
            return oid, None

    # Downloads and WebP encoding run 8 at a time; results are consumed in candidate order, so the
    # selection stays deterministic.
    products = []
    with ThreadPoolExecutor(max_workers=8) as pool:
        for start in range(0, len(eligible), 64):
            if len(products) >= TARGET:
                break
            for oid, size in pool.map(fetch, eligible[start:start + 64]):
                if size and len(products) < TARGET:
                    products.append(build_product(obj_from_row(rows[oid]), size))
            print(f"{len(products)} products", file=sys.stderr)
    keep = {p["image"] for p in products}
    for path in (OUT / "images").glob("*.webp"):
        if path.name not in keep:
            path.unlink()
    products.sort(key=lambda p: p["id"])
    common.write_json(OUT / "artworks.json", products)
    common.write_json(manifest_path, {"ids": [p["id"] for p in products]})
    print(f"{len(products)} products", file=sys.stderr)


if __name__ == "__main__":
    main()
