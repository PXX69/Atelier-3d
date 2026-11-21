"""Sécurité transversale : redirection après connexion, en-tête Host, X-Forwarded-For, échappement, injections, tailles, CORS."""
import json, re, subprocess
import requests
from conftest import *
from conftest import SITE, ROOT


def test_after_login_redirect_only_accepts_site_paths():
    js = (ROOT / "public/js/site.js").read_text()
    rx = re.search(r"function afterLogin\(\) \{.*?if \((/\^.*?\$/)\.test\(n\)\)", js, re.S)
    assert rx, "expression de contrôle introuvable"
    script = "const rx = %s; const ok = [], bad = [];" % rx.group(1) + """
    for (const v of ["/app/", "/compte", "/a/b-c_d.e", "/"]) if (!rx.test(v)) bad.push(v);
    for (const v of ["//evil.com", "https://evil.com", "http://evil.com/", "/\\\\evil.com", "\\\\\\\\evil.com", "javascript:alert(1)", "/%2f%2fevil.com", "//\\\\evil.com",
                     "evil.com", " //evil.com", "/ app", "/app?x=https://evil.com", "/app#//evil.com", "data:text/html,x", "/a\\nb", ""]) if (rx.test(v)) ok.push(v);
    console.log(JSON.stringify({bad, ok}));"""
    out = json.loads(subprocess.run(["node", "-e", script], capture_output=True, text=True, check=True).stdout)
    assert out["bad"] == [], "chemins normaux refusés à tort"
    assert out["ok"] == [], "adresses acceptées à tort : %r" % out["ok"]


def test_host_header_never_leaks_into_mails_or_links(fresh):
    before = mails_count()
    email = "host-header@test.local"
    r = requests.post(BASE + "/api/auth/request", data=json.dumps({"email": email}), headers={"Content-Type": "application/json", "Host": "evil.example"})
    assert r.status_code in (200, 403)
    for m in read_mails()[before:]:
        assert "evil.example" not in m["text"] and "evil.example" not in m["subject"]
    page = requests.get(BASE + "/", headers={"Host": "evil.example"}).text
    assert "evil.example" not in page, "les liens du site viennent de la configuration, pas de l'en-tête Host"


def test_forged_forwarded_for_does_not_bypass_rate_limits(fresh):
    codes = []
    for i in range(26):
        r = requests.post(BASE + "/api/auth/request", data=json.dumps({"email": f"spoof{i}@test.local"}),
                          headers={"Content-Type": "application/json", "Origin": BASE, "X-Forwarded-For": f"10.9.8.{i}", "X-Real-IP": f"10.1.1.{i}", "Client-IP": f"10.2.2.{i}"})
        codes.append(r.status_code)
    assert codes[:20] == [200] * 20 and 429 in codes[20:], codes


def test_awkward_but_valid_addresses_are_escaped_everywhere(fresh):
    weird = "o'brien&co{x}@test.local"
    c = Client().login(weird)
    t = c.get("/compte").text
    assert weird not in t and "o&#039;brien&amp;co{x}@test.local" in t
    a = Client().login(ADMIN)
    for p in ("/admin/clients", "/admin", "/admin/journal"):
        assert weird not in a.get(p).text, p
    assert "o&#039;brien&amp;co{x}@test.local" in a.get("/admin/clients").text
    assert c.get("/api/me").json()["user"]["email"] == weird, "l'API renvoie la valeur exacte, le navigateur l'insère comme du texte"


def test_sql_looking_input_is_just_data(fresh):
    for e in ("a'--@test.local", "x'or'1'='1@test.local", "q\";drop@test.local"):
        c = Client()
        r = c.post("/api/auth/request", {"email": e})
        assert r.status_code in (200, 422), (e, r.text)
    for code in ("' OR '1'='1", "000000' OR 1=1 --", "1" * 400, "\x00" * 6, "１２３４５６"):
        r = Client().post("/api/auth/verify", {"email": "nobody@test.local", "code": code})
        assert r.status_code in (401, 422, 429), (code, r.status_code)
    tables = {r[0] for r in sql("SHOW TABLES")}
    assert {"users", "orders", "licenses", "sessions", "login_codes", "webhook_events", "audit", "settings", "rate_limits"} <= tables
    assert val("SELECT COUNT(*) FROM users WHERE email LIKE '%drop%'") in ("0", "1")


def test_body_size_methods_and_content_type_limits(fresh):
    hdr = {"Content-Type": "application/json", "Origin": BASE}
    r = requests.post(BASE + "/api/auth/request", data='{"email":"' + "a" * 70000 + '@test.local"}', headers=hdr)
    assert r.status_code == 422, "corps plus grand que 64 Ko : lu comme vide"
    r = requests.post(BASE + "/api/auth/request", data="email=x@test.local", headers={"Content-Type": "application/x-www-form-urlencoded", "Origin": BASE})
    assert r.status_code == 415
    for m in ("PUT", "DELETE", "PATCH"):
        assert requests.request(m, BASE + "/api/auth/request", headers=hdr).status_code in (404, 405), m
    assert requests.get(BASE + "/api/auth/request").status_code == 405
    assert requests.post(BASE + "/api/inconnu", data="{}", headers=hdr).status_code == 404
    r = requests.post(BASE + "/api/auth/request", data="[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[", headers=hdr)
    assert r.status_code == 422
    r = requests.post(BASE + "/api/auth/request", data='{"email":["a@b.c"]}', headers=hdr)
    assert r.status_code == 422


def test_cross_origin_requests_are_refused_and_no_cors_is_offered(fresh):
    r = requests.post(BASE + "/api/auth/request", data=json.dumps({"email": "x@test.local"}),
                      headers={"Content-Type": "application/json", "Origin": "https://evil.example"})
    assert r.status_code == 403 and r.json()["error"] == "origine"
    for p in ("/", "/api/me", "/api/auth/request", "/app/manifest.webmanifest", "/healthz"):
        for method in ("GET", "OPTIONS"):
            rr = requests.request(method, BASE + p, headers={"Origin": "https://evil.example", "Access-Control-Request-Method": "POST"})
            assert "Access-Control-Allow-Origin" not in rr.headers, (method, p)


def test_security_headers_on_every_kind_of_page(fresh):
    for p in ("/", "/pro", "/telecharger", "/compte", "/cgv", "/mentions-legales", "/confidentialite", "/zzz"):
        r = requests.get(BASE + p)
        assert r.headers["X-Content-Type-Options"] == "nosniff" and r.headers["X-Frame-Options"] == "DENY", p
        csp = r.headers["Content-Security-Policy"]
        assert "default-src" in csp and "frame-ancestors 'none'" in csp and "base-uri 'none'" in csp and "form-action 'self'" in csp, p
        if p != "/pro":
            assert "script-src 'self';" in csp and "unsafe-inline'" not in csp.split("style-src")[0], p
        assert r.headers["Referrer-Policy"] and r.headers["Permissions-Policy"], p
    r = requests.get(BASE + "/pro")
    assert "nonce-" in r.headers["Content-Security-Policy"] and "paypal.com" in r.headers["Content-Security-Policy"]
    assert "unsafe-eval" not in r.headers["Content-Security-Policy"]


def test_pages_have_no_inline_script_outside_the_purchase_page(fresh):
    for p in ("/", "/telecharger", "/compte", "/cgv"):
        t = requests.get(BASE + p).text
        assert not re.search(r"<script(?![^>]*\bsrc=)[^>]*>", t), p
        assert not re.search(r"\son(click|load|error|mouseover)=", t, re.I), p


def test_session_cookie_over_plain_http_in_tests_is_httponly_lax(fresh):
    c = Client()
    before = mails_count()
    c.post("/api/auth/request", {"email": "ck@test.local"})
    code = wait_code("ck@test.local", before)
    r = c.post("/api/auth/verify", {"email": "ck@test.local", "code": code})
    ck = r.headers["Set-Cookie"].lower()
    assert ck.startswith("l3d_s=") and "httponly" in ck and "samesite=lax" in ck and "path=/" in ck
    token = re.match(r"l3d_s=([^;]+)", r.headers["Set-Cookie"]).group(1)
    assert val("SELECT COUNT(*) FROM sessions WHERE token_hash='%s'" % token) == "0", "le jeton n'est jamais stocké en clair"
    import hashlib
    assert val("SELECT COUNT(*) FROM sessions WHERE token_hash='%s'" % hashlib.sha256(token.encode()).hexdigest()) == "1"
