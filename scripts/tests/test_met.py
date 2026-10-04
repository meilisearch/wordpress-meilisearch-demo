import met


def test_medium_buckets():
    assert met.bucket_medium("Polychrome woodblock print; ink and color on paper") == "Woodblock print"
    assert met.bucket_medium("Oil on canvas") == "Oil on canvas"
    assert met.bucket_medium("Oil on wood") == "Oil on wood"
    assert met.bucket_medium("Tempera and gold on wood") == "Tempera"
    assert met.bucket_medium("Watercolor and graphite") == "Watercolor"
    assert met.bucket_medium("Etching and drypoint") == "Etching & engraving"
    assert met.bucket_medium("Lithograph") == "Lithograph"
    assert met.bucket_medium("Albumen silver print from glass negative") == "Photograph"
    assert met.bucket_medium("Pen and brown ink") == "Ink"
    assert met.bucket_medium("Black chalk on blue paper") == "Chalk, charcoal & pastel"
    assert met.bucket_medium("Oil on copper") == "Oil, other"
    assert met.bucket_medium("") == "Mixed media"


def test_century():
    assert met.century(1889) == "19th century"
    assert met.century(1901) == "20th century"
    assert met.century(1800) == "18th century"
    assert met.century(1621) == "17th century"
    assert met.century(1211) == "13th century"
    assert met.century(-300) == "3rd century BCE"
    assert met.century(-301) == "4th century BCE"
    assert met.century(0) == "1st century"


def test_category():
    assert met.category("Prints", "Asian Art") == "Japanese Prints"
    assert met.category("Prints", "Drawings and Prints") == "Prints"
    assert met.category("Paintings", "European Paintings") == "Paintings"


def test_orientation():
    assert met.orientation(1000, 800) == "landscape"
    assert met.orientation(800, 1000) == "portrait"
    assert met.orientation(1000, 1030) == "square"


def test_variations_prices_and_labels():
    product = met.build_product(
        {"objectID": 436535, "title": "Wheat Field with Cypresses", "artistDisplayName": "Vincent van Gogh",
         "objectDate": "1889", "objectBeginDate": 1889, "department": "European Paintings", "classification": "Paintings",
         "medium": "Oil on canvas", "culture": "", "dimensions": "28 7/8 × 36 3/4 in.", "creditLine": "Purchase, 1993",
         "tags": [{"term": "Landscapes"}, {"term": "Cypresses"}], "objectURL": "https://www.metmuseum.org/art/collection/search/436535",
         "isHighlight": True},
        (1600, 1250),
    )
    assert product["category"] == "Paintings" and product["featured"] is True
    assert product["orientation"] == "landscape"
    assert len(product["variations"]) == 12
    first = product["variations"][0]
    assert (first["size_label"], first["finish_label"], first["regular_price"]) == ("30 × 24 cm", "Unframed", 45)
    last = product["variations"][-1]
    assert (last["size_label"], last["finish_label"], last["regular_price"]) == ("100 × 80 cm", "Oak frame", 260)
    assert first["sku"] == "MET-436535-S-U" and last["sku"] == "MET-436535-XL-O"
    assert product["price_regular"] == 45
    assert product["tags"] == ["Cypresses", "Landscapes"]


def test_build_product_is_deterministic():
    obj = {"objectID": 1, "title": "T", "artistDisplayName": "", "objectDate": "", "objectBeginDate": 1850,
           "department": "Drawings and Prints", "classification": "Prints", "medium": "Etching", "culture": "",
           "dimensions": "", "creditLine": "", "tags": None, "objectURL": "", "isHighlight": False}
    a, b = met.build_product(obj, (800, 1000)), met.build_product(obj, (800, 1000))
    assert a == b
    assert a["artist"] == "Unknown artist"
    assert a["variations"][0]["size_label"] == "24 × 30 cm"


def test_sale_and_stock_rates_over_many_products():
    products = [
        met.build_product({"objectID": i, "title": "T", "artistDisplayName": "A", "objectDate": "", "objectBeginDate": 1700,
                           "department": "European Paintings", "classification": "Paintings", "medium": "Oil on canvas",
                           "culture": "", "dimensions": "", "creditLine": "", "tags": [], "objectURL": "", "isHighlight": False}, (1000, 800))
        for i in range(2000)
    ]
    sale = sum(p["on_sale"] for p in products) / len(products)
    out = sum(not v["in_stock"] for p in products for v in p["variations"]) / (12 * len(products))
    assert 0.07 < sale < 0.13
    assert 0.015 < out < 0.045
    on_sale = next(p for p in products if p["on_sale"])
    assert all(v["sale_price"] == round(v["regular_price"] * 0.8) for v in on_sale["variations"])
    assert all(0 <= len(p["reviews"]) <= 12 for p in products)
    assert all(1 <= r["rating"] <= 5 for p in products for r in p["reviews"])


def test_select_candidates_prioritises_highlights_and_balances_departments():
    rows = [
        {"Object ID": "1", "Is Public Domain": "True", "Is Highlight": "False", "Is Timeline Work": "False", "Department": "A", "Classification": "Paintings"},
        {"Object ID": "2", "Is Public Domain": "True", "Is Highlight": "True", "Is Timeline Work": "False", "Department": "B", "Classification": "Prints|Ephemera"},
        {"Object ID": "3", "Is Public Domain": "False", "Is Highlight": "True", "Is Timeline Work": "False", "Department": "A", "Classification": "Paintings"},
        {"Object ID": "4", "Is Public Domain": "True", "Is Highlight": "False", "Is Timeline Work": "True", "Department": "A", "Classification": "Drawings"},
        {"Object ID": "5", "Is Public Domain": "True", "Is Highlight": "False", "Is Timeline Work": "False", "Department": "A", "Classification": "Sculpture"},
        {"Object ID": "6", "Is Public Domain": "True", "Is Highlight": "False", "Is Timeline Work": "False", "Department": "C", "Classification": "Photographs"},
    ]
    assert met.select_candidates(rows)[:2] == [2, 4]
    assert sorted(met.select_candidates(rows)) == [1, 2, 4, 6]


def test_obj_from_row_maps_csv_columns_to_api_fields():
    row = {"Object ID": "436535", "Title": "Wheat Field with Cypresses", "Artist Display Name": "Vincent van Gogh",
           "Object Date": "1889", "Object Begin Date": "1889", "Department": "European Paintings",
           "Classification": "Paintings", "Medium": "Oil on canvas", "Culture": "", "Dimensions": "73.2 x 93.4 cm",
           "Credit Line": "Purchase, 1993", "Tags": "Landscapes|Cypresses", "Link Resource": "http://www.metmuseum.org/art/collection/search/436535",
           "Is Highlight": "True", "Is Public Domain": "True"}
    obj = met.obj_from_row(row)
    assert obj["objectID"] == 436535 and obj["objectBeginDate"] == 1889
    assert obj["artistDisplayName"] == "Vincent van Gogh" and obj["creditLine"] == "Purchase, 1993"
    assert obj["tags"] == [{"term": "Landscapes"}, {"term": "Cypresses"}]
    assert obj["isHighlight"] is True and obj["isPublicDomain"] is True
    assert obj["objectURL"] == "https://www.metmuseum.org/art/collection/search/436535"
    assert met.build_product(obj, (1600, 1250))["tags"] == ["Cypresses", "Landscapes"]


def test_obj_from_row_handles_pipes_and_blanks():
    row = {"Object ID": "1", "Title": "", "Artist Display Name": "A|B", "Object Begin Date": "", "Tags": "",
           "Is Highlight": "False", "Is Public Domain": "True", "Classification": "Prints"}
    obj = met.obj_from_row(row)
    assert obj["artistDisplayName"] == "A" and obj["objectBeginDate"] == 0 and obj["tags"] == []


def test_commons_url_is_a_standard_cdn_thumbnail():
    # md5("Wheat_Field_with_Cypresses_MET_DP-42549-001.jpg") starts with the two hex digits used in the path.
    import hashlib
    name = "Wheat_Field_with_Cypresses_MET_DP-42549-001.jpg"
    h = hashlib.md5(name.encode()).hexdigest()
    assert met.commons_url("Wheat Field with Cypresses MET DP-42549-001.jpg") == (
        f"https://upload.wikimedia.org/wikipedia/commons/thumb/{h[0]}/{h[:2]}/{name}/1280px-{name}"
    )


def test_commons_url_tiff_thumbnails_are_jpeg():
    url = met.commons_url("Some scan.tif")
    assert url.endswith("/1280px-Some_scan.tif.jpg")
