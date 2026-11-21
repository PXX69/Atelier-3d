"""Application dans le navigateur (/app/) : réservée aux détenteurs de Pro, jamais en cache, manifeste et icônes."""
import json, struct, time
import requests
from conftest import *
from conftest import SITE
from test_40_shop import buy, new_buyer
from test_60_admin import admin, act


def make_pro(email, days="0"):
    a = admin()
    act(a, "license_issue", email=email, days=days)
    return Client().login(email)


def png_size(b):
    assert b[:8] == b"\x89PNG\r\n\x1a\n"
    return struct.unpack(">II", b[16:24])


def test_anonymous_is_sent_to_login_and_trailing_slash_is_enforced(fresh):
    r = Client().get("/app")
    assert r.status_code == 302 and r.headers["Location"] == "/app/"
    r = Client().get("/app/")
    assert r.status_code == 302 and r.headers["Location"] == "/compte?next=/app/"
    for p in ("/app/index.html", "/app/sw.js", "/app/x", "/APP/", "/app/../src/core.php"):
        r = Client().get(p)
        assert r.status_code in (302, 404) and "APP-TEST" not in r.text, p


def test_free_customer_gets_403_with_a_way_to_buy(fresh):
    c = Client().login("free@test.local")
    r = c.get("/app/")
    assert r.status_code == 403 and "Liagos 3D Pro est nécessaire" in r.text and 'href="/pro"' in r.text and "APP-TEST" not in r.text
    assert "noindex" in r.text


def test_pro_customer_gets_the_app_with_private_headers(fresh):
    c = make_pro("pro@test.local")
    r = c.get("/app/")
    assert r.status_code == 200 and "APP-TEST" in r.text
    assert r.headers["Cache-Control"] == "private, no-store" and r.headers["X-Frame-Options"] == "DENY"
    assert r.headers["X-Content-Type-Options"] == "nosniff" and r.headers["Referrer-Policy"] == "same-origin"
    assert r.headers["Content-Type"].startswith("text/html")
    # un client qui achète lui-même obtient le même accès tout de suite
    sql("REPLACE INTO settings(k, v) VALUES ('payments_enabled','1')")
    try:
        b = new_buyer("buyer-app@test.local")
        assert b.get("/app/").status_code == 403
        buy(b)
        assert b.get("/app/").status_code == 200
    finally:
        sql("REPLACE INTO settings(k, v) VALUES ('payments_enabled','0')")


def test_expired_and_revoked_licenses_close_the_app(fresh):
    c = make_pro("lapsing@test.local", days="7")
    assert c.get("/app/").status_code == 200
    sql("UPDATE licenses SET expires_at=%d" % (int(time.time()) - 10))
    assert c.get("/app/").status_code == 403 and c.me()["pro"] is False and c.get("/api/app-license").status_code == 403
    sql("UPDATE licenses SET expires_at=%d" % (int(time.time()) + 3600))
    assert c.get("/app/").status_code == 200
    sql("UPDATE licenses SET status='revoked'")
    assert c.get("/app/").status_code == 403


def test_missing_app_file_gives_a_clear_503_not_a_blank_page(fresh):
    c = make_pro("pro503@test.local")
    f = SITE / "storage/app/index.html"
    data = f.read_bytes()
    f.unlink()
    try:
        r = c.get("/app/")
        assert r.status_code == 503 and "momentanément indisponible" in r.text
    finally:
        f.write_bytes(data)


def test_manifest_and_icons_are_public_and_valid(fresh):
    r = Client().get("/app/manifest.webmanifest")
    assert r.status_code == 200 and r.headers["Content-Type"].startswith("application/manifest+json")
    m = r.json()
    assert m["name"] == "Liagos 3D" and m["start_url"] == "/app/" and m["scope"] == "/app/" and m["display"] == "standalone" and m["lang"] == "fr"
    assert {i["sizes"] for i in m["icons"]} == {"192x192", "512x512"}
    for i in m["icons"]:
        ic = Client().get(i["src"])
        assert ic.status_code == 200 and ic.headers["Content-Type"] == "image/png"
        w, h = png_size(ic.content)
        assert (w, h) == tuple(int(x) for x in i["sizes"].split("x")), i
    assert Client().get("/app/icons/icon-1024.png").status_code == 404
    assert Client().get("/app/icons/../../config.php").status_code in (302, 404)


def test_app_license_endpoint_matches_the_key_the_app_verifies(fresh):
    c = make_pro("keycheck@test.local")
    key = c.get("/api/app-license").json()["key"]
    r = requests.get(BASE + "/api/app-license")
    assert r.status_code == 401
    out = php_json("require 'src/core.php'; require 'src/license.php'; echo json_encode(License::verify('%s'));" % key)
    assert json.loads(out) is not None
    assert Client().get("/api/app-license").status_code == 401
