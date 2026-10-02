import nasa

TOPICS = [
    {"slug": "missions", "categories": ["Artemis"]},
    {"slug": "solar-system", "categories": ["Mars", "Saturn"]},
]
PATTERNS = ["via flickr", "cc by", "getty", "©"]


def test_assign_topic_uses_priority_order():
    assert nasa.assign_topic(["Saturn", "Artemis"], TOPICS) == "missions"
    assert nasa.assign_topic(["Mars"], TOPICS) == "solar-system"
    assert nasa.assign_topic(["Hubble"], TOPICS) is None


def test_excluded_categories():
    assert nasa.is_excluded(["APOD", "Mars"], ["Photojournal", "APOD"])
    assert not nasa.is_excluded(["Mars"], ["Photojournal", "APOD"])


def test_third_party_by_pattern():
    assert nasa.is_third_party("Meteors in 2017. Ben Goldstein via Flickr CC BY-NC-SA", "x.jpg", PATTERNS)
    assert nasa.is_third_party("", "Orionid_Ben%20Goldstein%20via%20Flickr_CC%20BY-NC-SA.jpg", PATTERNS)


def test_third_party_by_non_nasa_credit():
    assert nasa.is_third_party("A rocket. Credit: John Smith", "rocket.jpg", PATTERNS)
    assert nasa.is_third_party("", "Night scene_credit_Bill Dunford.png", PATTERNS)


def test_nasa_credit_is_fine():
    assert not nasa.is_third_party("Saturn's rings. Credit: NASA/JPL-Caltech/Space Science Institute", "PIA1.jpg", PATTERNS)
    assert not nasa.is_third_party("Pinwheel galaxy. Image credit: NASA, ESA, CSA, STScI", "m101.jpg", PATTERNS)
    assert not nasa.is_third_party("A false-color satellite image of the river.", "flood.jpg", PATTERNS)


def test_rewrite_figures_drops_rejected_and_renames_kept():
    html = (
        '<p>Intro</p>'
        '<figure><img src="https://x/a.jpg"><figcaption>Credit: NASA</figcaption></figure>'
        '<figure><img src="https://x/b.jpg"><figcaption>Photo via Flickr</figcaption></figure>'
        '<p>End</p>'
    )
    kept = []

    def decide(src, caption):
        if nasa.is_third_party(caption, src, PATTERNS):
            return None
        kept.append(src)
        return f"inline/1-{len(kept)}.webp"

    out = nasa.rewrite_images(html, decide)
    assert out == '<p>Intro</p><figure><img src="inline/1-1.webp"><figcaption>Credit: NASA</figcaption></figure><p>End</p>'
    assert kept == ["https://x/a.jpg"]


def test_rewrite_handles_bare_images():
    out = nasa.rewrite_images('<p>a <img src="https://x/c.jpg"> b</p>', lambda src, cap: None)
    assert out == "<p>a  b</p>"


def test_rewrite_removes_srcless_images():
    out = nasa.rewrite_images('<p>a <img> b</p><figure><img><figcaption>c</figcaption></figure>', lambda src, cap: "inline/x.webp")
    assert "<img>" not in out
    assert out.startswith("<p>a  b</p>")


def test_select_respects_quotas_dedupes_and_marks_one_sticky_per_topic():
    posts = [
        {"id": 3, "topic": "missions", "date_gmt": "2026-03-01T00:00:00", "image_is_fallback": False},
        {"id": 2, "topic": "missions", "date_gmt": "2026-02-01T00:00:00", "image_is_fallback": False},
        {"id": 1, "topic": "missions", "date_gmt": "2026-01-01T00:00:00", "image_is_fallback": False},
        {"id": 3, "topic": "missions", "date_gmt": "2026-03-01T00:00:00", "image_is_fallback": False},
        {"id": 9, "topic": "solar-system", "date_gmt": "2026-04-01T00:00:00", "image_is_fallback": True},
        {"id": 8, "topic": "solar-system", "date_gmt": "2026-03-15T00:00:00", "image_is_fallback": False},
    ]
    chosen = nasa.select(posts, {"missions": 2, "solar-system": 5})
    assert [p["id"] for p in chosen] == [9, 8, 3, 2]
    assert {p["id"]: p["sticky"] for p in chosen} == {9: False, 8: True, 3: True, 2: False}


def test_author_normalisation():
    assert nasa._author(None) == "NASA Science"
    assert nasa._author("AMANDA BARNETT") == "Amanda Barnett"
    assert nasa._author("Alicia Cermak") == "Alicia Cermak"


def test_strip_leading_nav_removes_breadcrumbs_and_menus():
    html = ('<ol><li><a href="/">Science</a></li><li>Title…</li></ol>'
            '<ul><li><a href="/w">Webb</a></li><li><ul><li><a href="/n">Latest News</a></li></ul></li></ul>'
            '<p>Real text with a <a href="/x">link</a>.</p><ul><li>Kept list</li></ul>')
    assert nasa.strip_leading_nav(html) == '<p>Real text with a <a href="/x">link</a>.</p><ul><li>Kept list</li></ul>'


def test_strip_leading_nav_keeps_content_that_starts_with_text():
    html = '<p>Intro</p><ul><li><a href="/a">A</a></li></ul>'
    assert nasa.strip_leading_nav(html) == html


def test_strip_leading_nav_keeps_a_leading_list_of_real_items():
    html = '<ul><li>Launch: 2026</li><li>Crew: four</li></ul><p>Text</p>'
    assert nasa.strip_leading_nav(html) == html
