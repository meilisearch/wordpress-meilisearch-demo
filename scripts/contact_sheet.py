"""Writes <data dir>/contact-sheet.html: every kept image with its post and caption, for manual licence review."""
import html
import json
import re
import sys
from pathlib import Path

base = Path(sys.argv[1])
items = json.loads((base / "articles.json").read_text())
cards = []
for a in items:
    for src in [a["image"], *re.findall(r'src="(inline/[^"]+)"', a["content"])]:
        if src.startswith("fallback-"):
            continue
        cards.append(
            f'<figure><img loading="lazy" src="images/{html.escape(src)}"><figcaption><a href="{html.escape(a["source_url"])}">'
            f'{html.escape(a["title"])}</a><br><small>{html.escape(a["image_alt"])}</small></figcaption></figure>'
        )
(base / "contact-sheet.html").write_text(
    "<!doctype html><meta charset=utf-8><title>Contact sheet</title><style>body{font:13px system-ui;display:grid;"
    "grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px}img{width:100%;aspect-ratio:4/3;object-fit:cover}</style>"
    + "".join(cards),
    encoding="utf-8",
)
print(f"{len(cards)} images")
