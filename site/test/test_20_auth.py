"""Connexion par code, sessions, CSRF, limites, e-mails SMTP (SSL implicite et STARTTLS)."""
import json, re, time, requests
from conftest import *
from conftest import SITE


def req_code(c, email, **k):
    return c.post("/api/auth/request", {"email": email}, **k)


def test_request_rejects_invalid_email(fresh):
    c = Client()
    assert req_code(c, "pas-un-mail").status_code == 422
    assert req_code(c, "").status_code == 422
    assert req_code(c, "a" * 200 + "@test.local").status_code == 422


def test_login_flow_and_mail_details(fresh):
    c = Client()
    before = mails_count()
    assert req_code(c, "Alice@Test.Local ").status_code == 200       # majuscules et espace : normalisés
    m = wait_mail(lambda m: "alice@test.local" in m["to"], before)
    assert m, "aucun e-mail"
    assert m["from"] == "boutique@test.local", "l'expéditeur de l'enveloppe doit être la boîte authentifiée"
    assert "boutique@test.local" in m["hdr_from"] and "Liagos 3D" in m["hdr_from"]
    assert m["tls"] is True, "SMTP en SSL implicite"
    assert "contact@test.local" in m["reply_to"]
    assert "Votre code de connexion" in m["text"] and "10 minutes" in m["text"]
    assert m["subject"].startswith("Votre code")  or "code" in m["subject"].lower()
    code = re.search(r"(\d{6})", m["text"]).group(1)
    r = c.post("/api/auth/verify", {"email": "alice@test.local", "code": code[:3] + " " + code[3:]})   # espaces tolérés
    assert r.status_code == 200 and r.json()["ok"] and len(r.json()["csrf"]) >= 20
    assert any(x.name == "l3d_s" for x in c.s.cookies)
    me = c.get("/api/me").json()["user"]
    assert me["email"] == "alice@test.local" and me["admin"] is False and me["pro"] is False and me["csrf"] == r.json()["csrf"]


def test_set_cookie_flags(fresh):
    c = Client()
    before = mails_count()
    req_code(c, "flags@test.local")
    code = wait_code("flags@test.local", before)
    r = c.post("/api/auth/verify", {"email": "flags@test.local", "code": code})
    sc = r.headers["Set-Cookie"]
    assert "l3d_s=" in sc and "HttpOnly" in sc and "SameSite=Lax" in sc and "path=/" in sc.lower() and "secure" not in sc.lower()


def test_secure_cookie_name_on_https(fresh):
    c = Client()
    before = mails_count()
    c.post("/api/auth/request", {"email": "tls@test.local"}, headers={"X-Forwarded-Proto": "https"})
    code = wait_code("tls@test.local", before)
    r = c.post("/api/auth/verify", {"email": "tls@test.local", "code": code}, headers={"X-Forwarded-Proto": "https"})
    sc = r.headers["Set-Cookie"]
    assert sc.startswith("__Host-l3d_s=") and "secure" in sc.lower() and "path=/" in sc.lower() and "domain" not in sc.lower()


def test_wrong_code_then_lockout_after_5_attempts(fresh):
    c = Client()
    before = mails_count()
    req_code(c, "bob@test.local")
    good = wait_code("bob@test.local", before)
    bad = "000000" if good != "000000" else "111111"
    for _ in range(5):
        r = c.post("/api/auth/verify", {"email": "bob@test.local", "code": bad})
        assert r.status_code == 401 and r.json()["error"] == "code_invalide"
    r = c.post("/api/auth/verify", {"email": "bob@test.local", "code": good})
    assert r.status_code == 401, "après 5 essais ratés, même le bon code est refusé"
    assert c.get("/api/me").json()["user"] is None


def test_code_is_single_use_and_replaced_by_new_request(fresh):
    c = Client()
    before = mails_count()
    req_code(c, "carol@test.local")
    first = wait_code("carol@test.local", before)
    b2 = mails_count()
    req_code(c, "carol@test.local")
    second = wait_code("carol@test.local", b2)
    if first != second:
        assert c.post("/api/auth/verify", {"email": "carol@test.local", "code": first}).status_code == 401
    assert c.post("/api/auth/verify", {"email": "carol@test.local", "code": second}).status_code == 200
    assert Client().post("/api/auth/verify", {"email": "carol@test.local", "code": second}).status_code == 401, "un code ne sert qu'une fois"


def test_expired_code_refused(fresh):
    c = Client()
    before = mails_count()
    req_code(c, "dave@test.local")
    code = wait_code("dave@test.local", before)
    sql("UPDATE login_codes SET expires_at = UNIX_TIMESTAMP() - 5 WHERE email='dave@test.local'")
    assert c.post("/api/auth/verify", {"email": "dave@test.local", "code": code}).status_code == 401


def test_code_for_other_email_refused(fresh):
    c = Client()
    before = mails_count()
    req_code(c, "erin@test.local")
    code = wait_code("erin@test.local", before)
    assert c.post("/api/auth/verify", {"email": "mallory@test.local", "code": code}).status_code == 401


def test_request_rate_limit_per_email(fresh):
    c = Client()
    for i in range(3):
        assert req_code(c, "frank@test.local").status_code == 200
    r = req_code(c, "frank@test.local")
    assert r.status_code == 429 and r.json()["error"] == "trop_de_demandes"


def test_request_rate_limit_per_ip(fresh):
    c = Client()
    codes = [req_code(c, f"ip{i}@test.local").status_code for i in range(22)]
    assert codes[:20] == [200] * 20 and codes[20] == 429


def test_verify_rate_limit_per_ip(fresh):
    c = Client()
    codes = [c.post("/api/auth/verify", {"email": "x@test.local", "code": "123456"}).status_code for _ in range(32)]
    assert codes[0] == 401 and codes[-1] == 429


def test_csrf_required_for_logout_and_delete(fresh):
    c = Client().login("grace@test.local")
    assert c.post("/api/auth/logout", headers={"X-CSRF-Token": "faux"}).status_code == 403
    assert c.post("/api/account/delete", headers={"X-CSRF-Token": ""}).status_code == 403
    assert c.me() is not None
    assert c.post("/api/auth/logout").status_code == 200
    assert c.get("/api/me").json()["user"] is None


def test_not_logged_in_is_401(fresh):
    c = Client()
    assert c.post("/api/auth/logout").status_code == 401
    assert c.post("/api/paypal/create", {"consent": True}).status_code == 401
    assert c.get("/api/app-license").status_code == 401
    assert c.get("/api/account/export").status_code == 401


def test_cross_origin_post_refused(fresh):
    c = Client()
    r = c.post("/api/auth/request", {"email": "evil@test.local"}, headers={"Origin": "https://evil.example"})
    assert r.status_code == 403 and r.json()["error"] == "origine"
    assert c.post("/api/auth/request", {"email": "evil@test.local"}, headers={"Origin": BASE}).status_code == 200
    # sans en-tête Origin (outil en ligne de commande) : accepté, la protection repose alors sur le CSRF/le code
    r = requests.post(BASE + "/api/auth/request", data=json.dumps({"email": "noorigin@test.local"}), headers={"Content-Type": "application/json"})
    assert r.status_code == 200


def test_content_type_must_be_json(fresh):
    r = requests.post(BASE + "/api/auth/request", data="email=a@test.local", headers={"Content-Type": "application/x-www-form-urlencoded", "Origin": BASE})
    assert r.status_code == 415


def test_garbage_json_is_handled(fresh):
    for body in ("{", "null", "[]", "123", '"x"', "{" * 1000, "\xff\xfe"):
        r = requests.post(BASE + "/api/auth/request", data=body.encode("latin-1"), headers={"Content-Type": "application/json", "Origin": BASE})
        assert r.status_code in (422, 415), (body, r.status_code)
    assert requests.post(BASE + "/api/auth/request", data=b"x" * 200000, headers={"Content-Type": "application/json", "Origin": BASE}).status_code == 422


def test_get_on_post_endpoints_and_unknown_api(fresh):
    assert requests.get(BASE + "/api/auth/request").status_code == 405
    c = Client()
    assert c.post("/api/inconnu").status_code == 404
    assert requests.get(BASE + "/api/inconnu").status_code == 405


def test_session_expiry(fresh):
    c = Client().login("heidi@test.local")
    sql("UPDATE sessions SET expires_at = UNIX_TIMESTAMP() - 1")
    assert c.get("/api/me").json()["user"] is None


def test_session_is_extended_when_old(fresh):
    c = Client().login("ivan@test.local")
    sql("UPDATE sessions s JOIN users u ON u.id=s.user_id SET s.expires_at = UNIX_TIMESTAMP() + 86400 WHERE u.email='ivan@test.local'")
    assert c.me()["email"] == "ivan@test.local"
    exp = int(val("SELECT s.expires_at FROM sessions s JOIN users u ON u.id=s.user_id WHERE u.email='ivan@test.local'"))
    assert exp > time.time() + 20 * 86400


def test_tampered_session_cookie(fresh):
    c = Client().login("judy@test.local")
    c.s.cookies.clear()
    c.s.cookies.set("l3d_s", "x" * 43)
    assert c.get("/api/me").json()["user"] is None
    c.s.cookies.clear()
    c.s.cookies.set("l3d_s", "x")                       # trop court
    assert c.get("/api/me").json()["user"] is None


def test_session_tokens_are_hashed_in_db(fresh):
    c = Client().login("ken@test.local")
    tok = [x for x in c.s.cookies if x.name == "l3d_s"][0].value
    assert val(f"SELECT COUNT(*) FROM sessions WHERE token_hash='{tok}'") == "0"
    assert val("SELECT COUNT(*) FROM sessions WHERE CHAR_LENGTH(token_hash)=64") != "0"
    assert val("SELECT COUNT(*) FROM login_codes WHERE CHAR_LENGTH(code_hash)=64") is not None


def test_admin_flag(fresh):
    c = Client().login(ADMIN)
    assert c.me()["admin"] is True


def test_smtp_starttls_path(fresh):
    cfg = read_config()
    orig = dict(cfg["mail"])
    cfg["mail"].update(port=TLS_PORT, secure="tls")
    write_config(cfg)
    try:
        c = Client()
        before = mails_count()
        assert req_code(c, "starttls@test.local").status_code == 200
        m = wait_mail(lambda m: "starttls@test.local" in m["to"], before)
        assert m and m["tls"] is True
    finally:
        cfg["mail"] = orig
        write_config(cfg)


def test_smtp_wrong_password_returns_503_and_leaves_no_code(fresh):
    cfg = read_config()
    orig = dict(cfg["mail"])
    cfg["mail"]["pass"] = "mauvais"
    write_config(cfg)
    try:
        before = mails_count()
        r = req_code(Client(), "nomail@test.local")
        assert r.status_code == 503 and r.json()["error"] == "envoi_impossible", (r.text, read_mails()[before:], (SITE / "storage/logs/app.log").read_text()[-800:], read_config()["mail"])
        assert val("SELECT COUNT(*) FROM login_codes WHERE email='nomail@test.local'") == "0"
        assert "smtp" in (SITE / "storage/logs/app.log").read_text().lower()
    finally:
        cfg["mail"] = orig
        write_config(cfg)


def test_smtp_server_down_returns_503(fresh):
    cfg = read_config()
    orig = dict(cfg["mail"])
    cfg["mail"].update(port=1)
    write_config(cfg)
    try:
        assert req_code(Client(), "down@test.local").status_code == 503
    finally:
        cfg["mail"] = orig
        write_config(cfg)


def test_account_delete_anonymises(fresh):
    c = Client().login("zed@test.local")
    uid = val("SELECT id FROM users WHERE email='zed@test.local'")
    r = c.post("/api/account/delete")
    assert r.status_code == 200
    assert val(f"SELECT email FROM users WHERE id={uid}") == f"supprime-{uid}@invalid.local"
    assert val(f"SELECT COUNT(*) FROM sessions WHERE user_id={uid}") == "0"
    assert c.get("/api/me").json()["user"] is None
    # on peut se réinscrire avec le même e-mail : nouveau compte
    c2 = Client().login("zed@test.local")
    assert val("SELECT COUNT(*) FROM users WHERE email='zed@test.local'") == "1"


def test_account_export_contents(fresh):
    c = Client().login("export@test.local")
    r = c.get("/api/account/export")
    assert r.status_code == 200 and "attachment" in r.headers["Content-Disposition"]
    j = r.json()
    assert j["compte"]["email"] == "export@test.local" and j["commandes"] == [] and j["licences"] == []
