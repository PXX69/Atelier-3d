"""Pages publiques, en-têtes de sécurité, routes."""
import re, requests
from conftest import *

PAGES = ["/", "/pro", "/telecharger", "/compte", "/mentions-legales", "/cgv", "/confidentialite"]


def test_public_pages_ok(installed):
    for p in PAGES:
        r = requests.get(BASE + p)
        assert r.status_code == 200, p
        assert r.headers["Content-Type"].startswith("text/html")
        assert "<title>" in r.text and 'lang="fr"' in r.text
        assert "%%NONCE%%" not in r.text


def test_unknown_page_404_and_noindex(installed):
    r = requests.get(BASE + "/nimporte-quoi")
    assert r.status_code == 404 and 'content="noindex"' in r.text


def test_healthz(installed):
    assert requests.get(BASE + "/healthz").text == "ok"


def test_security_headers_default_csp(installed):
    r = requests.get(BASE + "/")
    h = r.headers
    assert h["X-Content-Type-Options"] == "nosniff" and h["X-Frame-Options"] == "DENY"
    csp = h["Content-Security-Policy"]
    assert "script-src 'self'" in csp and "unsafe-inline" not in csp.split("style-src")[0] and "frame-ancestors 'none'" in csp
    assert "paypal" not in csp
    assert "Permissions-Policy" in h and h["Referrer-Policy"] == "strict-origin-when-cross-origin"
    assert "Strict-Transport-Security" not in h     # HTTP en essai


def test_hsts_when_https_forwarded(installed):
    r = requests.get(BASE + "/", headers={"X-Forwarded-Proto": "https"})
    assert "max-age=31536000" in r.headers.get("Strict-Transport-Security", "")


def test_pro_page_csp_has_nonce_and_paypal(installed):
    r = requests.get(BASE + "/pro")
    csp = r.headers["Content-Security-Policy"]
    m = re.search(r"script-src 'self' 'nonce-([A-Za-z0-9_-]+)' https://\*\.paypal\.com https://\*\.paypalobjects\.com", csp)
    assert m, csp
    assert f'data-nonce="{m.group(1)}"' in r.text, "le nonce de la page doit être celui de l'en-tête"
    assert "frame-src https://*.paypal.com" in csp and "connect-src 'self' https://*.paypal.com" in csp
    # un nonce différent à chaque visite
    r2 = requests.get(BASE + "/pro")
    assert re.search(r"nonce-([A-Za-z0-9_-]+)", r2.headers["Content-Security-Policy"]).group(1) != m.group(1)


@pytest.mark.parametrize("mode,expect", [("compat", "'unsafe-inline'"), ("off", None)])
def test_pro_csp_modes(installed, mode, expect):
    cfg = read_config()
    orig = cfg["paypal"]["csp"]
    cfg["paypal"]["csp"] = mode
    write_config(cfg)
    try:
        r = requests.get(BASE + "/pro")
        if expect:
            assert expect in r.headers["Content-Security-Policy"] and "nonce-" not in r.headers["Content-Security-Policy"]
        else:
            assert "Content-Security-Policy" not in r.headers
    finally:
        cfg["paypal"]["csp"] = orig
        write_config(cfg)


def test_pro_closed_shows_soon_message(fresh):
    r = requests.get(BASE + "/pro")
    i = r.text.find('id="achat"')
    assert 'data-enabled="0"' in r.text and "La vente n'est pas encore ouverte" in r.text, r.text[i - 80:i + 700]
    assert "paypal-buttons" not in r.text and 'data-client-id=""' in r.text


def test_pro_open_logged_out_shows_login(shop_open):
    r = requests.get(BASE + "/pro")
    assert 'data-enabled="1"' in r.text and 'data-client-id="test-client"' in r.text and "data-login" in r.text
    assert "Mode test PayPal" in r.text


def test_price_displayed_follows_settings(fresh):
    sql("REPLACE INTO settings(k,v) VALUES ('price_cents','1250')")
    try:
        assert "12,50" in requests.get(BASE + "/pro").text
    finally:
        sql("REPLACE INTO settings(k,v) VALUES ('price_cents','999')")


def test_static_assets_have_cache_busting(installed):
    r = requests.get(BASE + "/")
    css = re.findall(r'href="(/css/site\.css\?v=\d+)"', r.text)
    js = re.findall(r'src="(/js/site\.js\?v=\d+)"', r.text)
    assert css and js
    for u in css + js:
        assert requests.get(BASE + u).status_code == 200


def test_legal_pages_show_config_and_placeholders(installed):
    t = requests.get(BASE + "/mentions-legales").text
    assert "Éditeur d&#039;essai" in t and "1 rue d&#039;essai" in t
    c = requests.get(BASE + "/cgv").text
    assert "9,99" in c


def test_method_not_allowed(installed):
    assert requests.put(BASE + "/pro").status_code == 405
    assert requests.delete(BASE + "/").status_code == 405


def test_head_requests(installed):
    r = requests.head(BASE + "/pro")
    assert r.status_code == 200


def test_home_has_iso_playground_and_no_inline_script(installed):
    t = requests.get(BASE + "/").text
    assert 'id="iso"' in t and "/js/iso.js" in t
    assert "<script>" not in t, "pas de script en ligne (CSP stricte)"
    assert not re.search(r'\son\w+="', t), "pas de gestionnaire d'événement en ligne"


def test_logged_pages_are_not_cached(installed):
    c = Client().login("cache@test.local")
    r = c.get("/compte")
    assert "no-store" in r.headers["Cache-Control"] and "private" in r.headers["Cache-Control"]
    assert 'name="csrf"' in r.text
