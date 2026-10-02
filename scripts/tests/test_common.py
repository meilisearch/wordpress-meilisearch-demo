import io
from pathlib import Path

from PIL import Image

import common


def test_rng_is_deterministic_per_name():
    assert [common.rng("a").random() for _ in range(1)] == [common.rng("a").random()]
    assert common.rng("a").random() != common.rng("b").random()


def test_sanitize_keeps_allowed_tags_and_only_href_src():
    html = '<div class="x"><p style="color:red">Hi <a href="https://nasa.gov" onclick="x()">there</a></p><script>alert(1)</script><img src="a.jpg" onerror="x"></div>'
    out = common.sanitize_html(html)
    assert out == '<p>Hi <a href="https://nasa.gov">there</a></p><img src="a.jpg">'


def test_sanitize_drops_javascript_urls():
    assert common.sanitize_html('<p><a href="javascript:alert(1)">x</a></p>') == "<p><a>x</a></p>"


def test_sanitize_blocks_javascript_with_tab():
    """Test that tabs in scheme are blocked (XSS prevention)."""
    assert common.sanitize_html('<p><a href="java\tscript:alert(1)">x</a></p>') == "<p><a>x</a></p>"


def test_sanitize_blocks_javascript_with_entity():
    """Test that entity-escaped tabs in scheme are blocked (XSS prevention)."""
    # &#9; is a tab character - the parser unescapes it
    assert common.sanitize_html('<p><a href="java&#9;script:alert(1)">x</a></p>') == "<p><a>x</a></p>"


def test_sanitize_blocks_javascript_with_control_char():
    """Test that control characters in scheme are blocked (XSS prevention)."""
    assert common.sanitize_html('<p><a href="\x01javascript:alert(1)">x</a></p>') == "<p><a>x</a></p>"


def test_sanitize_allows_relative_src():
    """Test that relative image URLs are allowed."""
    assert common.sanitize_html('<img src="inline/1-1.webp">') == '<img src="inline/1-1.webp">'


def test_sanitize_allows_https_href():
    """Test that https URLs are allowed."""
    assert common.sanitize_html('<a href="https://example.com">link</a>') == '<a href="https://example.com">link</a>'


def test_sanitize_self_closing_svg():
    """Test that self-closing drop tags don't cause incomplete output."""
    assert common.sanitize_html("<p>a<svg/>b</p><p>c</p>") == "<p>ab</p><p>c</p>"


def test_sanitize_unwraps_unknown_tags_but_keeps_text():
    assert common.sanitize_html("<section><p>One <span>two</span></p></section>") == "<p>One two</p>"


def test_word_count_ignores_markup():
    assert common.word_count("<p>One <strong>two</strong></p><p>three</p>") == 3


def test_strip_tags_unescapes_entities():
    """Test that HTML entities are unescaped when stripping tags."""
    assert common.strip_tags("<p>Tom &amp; Jerry</p>") == "Tom & Jerry"


def test_strip_tags_adds_space_at_br():
    """Test that <br> tags create word boundaries."""
    assert common.strip_tags("a<br>b") == "a b"


def test_strip_tags_adds_space_at_block_boundaries():
    """Test that block element boundaries create word boundaries."""
    assert common.strip_tags("<p>one</p><p>two</p>") == "one two"


def test_strip_tags_removes_drop_content():
    """Test that DROP_WITH_CONTENT tags and their content are removed."""
    assert common.strip_tags("x<script>bad()</script>y") == "xy"


def test_save_webp_resizes_longest_side(tmp_path: Path):
    buf = io.BytesIO()
    Image.new("RGB", (4000, 1000), "red").save(buf, "JPEG")
    w, h = common.save_webp(buf.getvalue(), tmp_path / "x.webp")
    assert (w, h) == (1600, 400)
    assert Image.open(tmp_path / "x.webp").format == "WEBP"


def test_save_webp_never_upscales(tmp_path: Path):
    buf = io.BytesIO()
    Image.new("RGB", (800, 600), "blue").save(buf, "PNG")
    assert common.save_webp(buf.getvalue(), tmp_path / "y.webp") == (800, 600)


def test_write_json_is_stable(tmp_path: Path):
    common.write_json(tmp_path / "a.json", {"b": 1, "a": "é"})
    assert (tmp_path / "a.json").read_text(encoding="utf-8") == '{\n  "a": "é",\n  "b": 1\n}\n'
