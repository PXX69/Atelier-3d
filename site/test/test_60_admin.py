"""Back-office : accès, CSRF, licences, commandes, remboursements, réglages, fichiers, exports, suppression de compte, XSS et injections."""
import csv, io, json, pathlib, re, time
from urllib.parse import parse_qs, urlparse, unquote
import requests
from conftest import *
from conftest import SITE
from test_40_shop import buy, create, new_buyer
from test_50_webhook import order_row, licence_status

PAGES = ["", "/commandes", "/licences", "/clients", "/fichiers", "/reglages", "/journal"]


def admin():
    return Client().login(ADMIN)


def act(a, do, files=None, **fields):
    """Envoie une action du back-office. Retourne (code, message flash, adresse de retour)."""
    r = a.form("/admin/action", dict(do=do, **fields), files=files)
    loc = r.headers.get("Location", "")
    q = parse_qs(urlparse(loc).query)
    return r.status_code, unquote(q.get("m", [""])[0]), loc


def lic_key(email):
    return val(f"SELECT license_key FROM licenses WHERE user_id=(SELECT id FROM users WHERE email='{email}') ORDER BY id DESC LIMIT 1")


# ---------------------------------------------------------------- accès
def test_anonymous_is_sent_to_login_and_customer_is_refused(fresh):
    for p in PAGES:
        r = Client().get("/admin" + p)
        assert r.status_code == 302 and r.headers["Location"] == "/compte"
    c = Client().login("simple-client@test.local")
    for p in PAGES + ["/export/commandes.csv", "/export/clients.csv", "/commandes/1"]:
        r = c.get("/admin" + p)
        assert r.status_code == 403 and "Accès refusé" in r.text, p
    r = c.form("/admin/action", {"do": "license_issue", "email": "x@test.local"})
    assert r.status_code == 403, "un client ne peut déclencher aucune action"
    assert val("SELECT COUNT(*) FROM licenses") == "0"


def test_admin_pages_render(shop_open):
    a = admin()
    c = new_buyer("pages-buyer@test.local")
    buy(c)
    for p in PAGES:
        r = a.get("/admin" + p)
        assert r.status_code == 200, p
        assert "<h1>" in r.text and "noindex" in r.text, p
        assert "no-store" in r.headers.get("Cache-Control", ""), "pages privées : jamais en cache"
        assert "default-src 'self'" in r.headers["Content-Security-Policy"] and r.headers["X-Frame-Options"] == "DENY"
    assert a.get("/admin/inconnu").status_code == 404
    assert a.get("/admin/commandes/99999").status_code == 404
    assert a.get("/admin/commandes/abc").status_code == 404
    d = a.get("/admin").text
    assert "Commandes payées" in d and "Avant d'ouvrir la vente" in d and "9,99" in d
    assert re.search(r"La clé de signature des licences correspond à l'application</span>|correspond à l'application<span class=\"sr\"> : fait", d.replace("&#039;", "'")), "clé de signature reconnue"
    oid = val("SELECT id FROM orders LIMIT 1")
    o = a.get(f"/admin/commandes/{oid}").text
    assert "Rembourser via PayPal" in o and "Renvoyer la clé" in o and "Transaction PayPal" in o and "L3D1-" in o


def test_admin_post_requires_csrf_and_unknown_action_is_harmless(fresh):
    a = admin()
    r = a.s.post(BASE + "/admin/action", data={"do": "license_issue", "email": "nocsrf@test.local"}, allow_redirects=False)
    assert r.status_code == 403
    r = a.s.post(BASE + "/admin/action", data={"do": "license_issue", "email": "nocsrf@test.local", "csrf": "faux"}, allow_redirects=False)
    assert r.status_code == 403
    assert val("SELECT COUNT(*) FROM users WHERE email='nocsrf@test.local'") == "0"
    code, msg, loc = act(a, "pas-une-action")
    assert code == 302 and msg == "Action inconnue."
    assert a.get("/admin/action").status_code == 404


def test_secrets_never_appear_in_admin_pages_or_logs(shop_open):
    a = admin()
    secrets = ["mailpw", "test-secret", "testpw", read_config()["secret"]]
    for p in PAGES:
        t = a.get("/admin" + p).text
        for s in secrets:
            assert s not in t, f"secret visible dans /admin{p}"
    log = pathlib.Path(SITE / "storage/logs/app.log")
    if log.exists():
        t = log.read_text(errors="replace")
        for s in secrets:
            assert s not in t, "secret écrit dans le journal technique"


# ---------------------------------------------------------------- licences
def test_issue_unlimited_license_creates_user_sends_mail_and_works(fresh):
    a = admin()
    before = mails_count()
    code, msg, loc = act(a, "license_issue", email="Ami@Test.Local", days="0", note="pour un ami", send="1")
    assert code == 302 and loc.startswith("/admin/licences") and "Licence créée pour ami@test.local" in msg and "E-mail envoyé" in msg
    row = sql("SELECT source, edition, expires_at, status, note FROM licenses")[0]
    assert row == ["manual", "1", "NULL", "active", "pour un ami"]
    m = wait_mail(lambda m: "Votre licence" in m["subject"], before)
    assert m and m["to"] == ["ami@test.local"] and lic_key("ami@test.local") in m["text"]
    assert "manuelle" in m["text"]
    ami = Client().login("ami@test.local")
    assert ami.me()["pro"] is True and ami.get("/api/app-license").json()["key"] == lic_key("ami@test.local")
    assert ami.get("/app/").status_code == 200
    assert "licence_creee" in [r[0] for r in sql("SELECT action FROM audit")]


def test_issue_timed_license_and_clamping(fresh):
    a = admin()
    act(a, "license_issue", email="essai@test.local", days="7")
    src, exp = sql("SELECT source, expires_at FROM licenses")[0]
    assert src == "test"
    # la clé compte en jours entiers (UTC) : la fin tombe à la fin du jour prévu, jamais avant la durée demandée
    assert 0 <= int(exp) - (time.time() + 7 * 86400) < 86400 + 120
    act(a, "license_issue", email="long@test.local", days="99999")
    exp2 = int(sql("SELECT expires_at FROM licenses WHERE user_id=(SELECT id FROM users WHERE email='long@test.local')")[0][0])
    assert 0 <= exp2 - (time.time() + 3650 * 86400) < 86400 + 120, "durée plafonnée à 10 ans"
    act(a, "license_issue", email="neg@test.local", days="-5")
    assert sql("SELECT expires_at FROM licenses WHERE user_id=(SELECT id FROM users WHERE email='neg@test.local')")[0][0] == "NULL", "durée négative → illimitée, jamais une date passée"
    # clé à durée limitée : l'application la lit comme limitée (jour de fin inclus)
    out = json.loads(php_json("require 'src/core.php'; require 'src/license.php'; echo json_encode(License::verify('%s'));" % lic_key("essai@test.local")))
    assert out and out.get("days", 0) > 0


def test_issue_without_mail_and_invalid_email(fresh):
    a = admin()
    before = mails_count()
    code, msg, _ = act(a, "license_issue", email="silencieux@test.local", days="0")
    assert "Licence créée" in msg and "E-mail" not in msg
    time.sleep(0.5)
    assert not [m for m in read_mails()[before:] if "Votre licence" in m["subject"]]
    for bad in ("pas-un-mail", "", "a@b", "x@y.z\nBcc: evil@test.local", "<script>@test.local"):
        n = val("SELECT COUNT(*) FROM users")
        code, msg, _ = act(a, "license_issue", email=bad, days="0")
        assert msg == "Adresse e-mail invalide.", repr(bad)
        assert val("SELECT COUNT(*) FROM users") == n
    assert val("SELECT COUNT(*) FROM licenses") == "1"


def test_revoke_and_restore_toggle_access(fresh):
    a = admin()
    act(a, "license_issue", email="toggle@test.local", days="0")
    lid = val("SELECT id FROM licenses")
    t = Client().login("toggle@test.local")
    assert t.me()["pro"] is True
    code, msg, _ = act(a, "license_revoke", license_id=lid)
    assert msg == "Licence révoquée." and t.me()["pro"] is False and t.get("/app/").status_code == 403 and t.get("/api/app-license").status_code == 403
    code, msg, _ = act(a, "license_restore", license_id=lid)
    assert msg == "Licence réactivée." and t.me()["pro"] is True
    assert {"license_revoke", "license_restore"} <= {r[0] for r in sql("SELECT action FROM audit")}
    page = a.get("/admin/licences").text
    assert "Révoquer" in page and "toggle@test.local" in page and "Illimitée" in page
    assert lic_key("toggle@test.local") not in page, "la liste n'affiche que le début de la clé"


# ---------------------------------------------------------------- commandes
def test_admin_refund_through_paypal(shop_open):
    a = admin()
    c = new_buyer("refund-me@test.local")
    poid, pub, r = buy(c)
    oid = val(f"SELECT id FROM orders WHERE public_id='{pub}'")
    before = mails_count()
    code, msg, loc = act(a, "refund", order_id=oid)
    assert msg == "Remboursement effectué, licence désactivée." and loc.startswith(f"/admin/commandes/{oid}")
    assert order_row(pub)[0] == "refunded" and licence_status(pub) == "revoked" and c.me()["pro"] is False
    calls = [x for x in pp_calls() if x["path"].endswith("/refund")]
    assert len(calls) == 1 and calls[0]["request_id"] == pub + "-refund"
    assert wait_mail(lambda m: "Remboursement" in m["subject"] and "refund-me@test.local" in m["to"], before)
    # second clic : PayPal n'est pas rappelé
    code, msg, _ = act(a, "refund", order_id=oid)
    assert "ne peut pas être remboursée" in msg and len([x for x in pp_calls() if x["path"].endswith("/refund")]) == 1


def test_admin_refund_refused_by_paypal_changes_nothing(shop_open):
    a = admin()
    c = new_buyer("refund-fail@test.local")
    poid, pub, r = buy(c)
    pp_control(scenario={"refund_fail": True})
    code, msg, _ = act(a, "refund", order_id=val(f"SELECT id FROM orders WHERE public_id='{pub}'"))
    assert "PayPal a refusé le remboursement" in msg
    assert order_row(pub)[0] == "paid" and licence_status(pub) == "active" and c.me()["pro"] is True
    assert any("ÉCHEC" in r[0] for r in sql("SELECT detail FROM audit WHERE action='remboursement'"))


def test_mark_refunded_and_resend_license(shop_open):
    a = admin()
    c = new_buyer("manual-ref@test.local")
    poid, pub, r = buy(c)
    oid = val(f"SELECT id FROM orders WHERE public_id='{pub}'")
    before = mails_count()
    code, msg, _ = act(a, "resend_license", order_id=oid)
    assert msg == "E-mail envoyé."
    m = wait_mail(lambda m: "Votre licence" in m["subject"], before)
    assert m and r.json()["key"] in m["text"]
    code, msg, _ = act(a, "mark_refunded", order_id=oid)
    assert msg == "Commande marquée comme remboursée." and order_row(pub)[0] == "refunded" and c.me()["pro"] is False
    assert not [x for x in pp_calls() if x["path"].endswith("/refund")], "« déjà remboursée dans PayPal » n'appelle pas PayPal"
    code, msg, _ = act(a, "resend_license", order_id=oid)
    assert "Envoi impossible" in msg, "plus de licence active à renvoyer"
    code, msg, _ = act(a, "refund", order_id="999999")
    assert msg == "Commande introuvable."


def test_reconcile_recovers_a_payment_the_browser_never_reported(shop_open):
    a = admin()
    c = new_buyer("lost@test.local")
    r = create(c)
    poid, pub = r.json()["id"], r.json()["order"]
    oid = val(f"SELECT id FROM orders WHERE public_id='{pub}'")
    code, msg, _ = act(a, "reconcile", order_id=oid)
    assert msg == "Statut chez PayPal : CREATED · statut ici : created" and order_row(pub)[0] == "created"
    # l'acheteur paie chez PayPal mais ferme l'onglet avant le retour sur le site
    requests.post(PPBASE + f"/v2/checkout/orders/{poid}/capture", headers={"Authorization": "Bearer tok-ok", "Content-Type": "application/json"}, data="{}")
    before = mails_count()
    code, msg, _ = act(a, "reconcile", order_id=oid)
    assert msg == "Paiement confirmé : licence délivrée." and order_row(pub)[0] == "paid" and licence_status(pub) == "active"
    assert wait_mail(lambda m: "Votre licence" in m["subject"] and "lost@test.local" in m["to"], before) and c.me()["pro"] is True
    code, msg, _ = act(a, "reconcile", order_id=oid)
    assert "statut ici : paid" in msg, "déjà réglée : rien n'est rejoué"
    assert val("SELECT COUNT(*) FROM licenses") == "1"


def test_orders_search_is_safe_and_exact(shop_open):
    a = admin()
    c = new_buyer("find-me@test.local")
    poid, pub, r = buy(c)
    other = new_buyer("other@test.local")
    create(other)
    t = a.get("/admin/commandes", params={"q": "find-me"}).text
    assert pub in t and "other@test.local" not in t
    assert pub in a.get("/admin/commandes", params={"q": pub[:6]}).text
    for q in ("%", "_", "' OR 1=1 --", "\\", "%' UNION SELECT 1,2,3 -- ", "<script>alert(1)</script>", '"><img src=x onerror=alert(1)>'):
        resp = a.get("/admin/commandes", params={"q": q})
        assert resp.status_code == 200, q
        assert "<script>alert(1)</script>" not in resp.text and "<img src=x" not in resp.text, "recherche affichée sans échappement"
        assert pub not in resp.text and "find-me@test.local" not in resp.text, f"{q!r} ne doit rien trouver"
    assert val("SELECT COUNT(*) FROM orders") == "2"


def test_flash_message_is_escaped(fresh):
    a = admin()
    t = a.get("/admin/reglages", params={"m": '<script>alert(1)</script><b onmouseover="x">'}).text
    assert "<script>alert(1)</script>" not in t and "&lt;script&gt;" in t
    long = a.get("/admin/reglages", params={"m": "A" * 5000}).text
    assert "A" * 301 not in long, "message tronqué à 300 caractères"


# ---------------------------------------------------------------- réglages
def test_save_settings_parses_and_bounds_values(fresh):
    a = admin()
    def save(**kw):
        d = dict(price="9,99", vat_default="0", vat_map="")
        d.update(kw)
        return act(a, "save_settings", **d)
    code, msg, _ = save(price="12,50", vat_default="20", vat_map="FR 20\nde 19,6\nXX\nFRA 20\n  it   22 %  \n\nES abc\n5 20")
    assert code == 302 and msg.startswith("Réglages enregistrés")
    assert val("SELECT v FROM settings WHERE k='price_cents'") == "1250"
    assert val("SELECT v FROM settings WHERE k='vat_default_bp'") == "2000"
    assert json.loads(val("SELECT v FROM settings WHERE k='vat_map'")) == {"FR": 2000, "DE": 1960, "IT": 2200}
    assert val("SELECT v FROM settings WHERE k='payments_enabled'") == "0", "case décochée = vente fermée"
    page = a.get("/admin/reglages").text
    assert 'value="12,50"' in page and "DE 19.6" in page
    for bad in ("0,50", "abc", "", "2000", "-5"):
        save(price=bad)
        assert val("SELECT v FROM settings WHERE k='price_cents'") == "1250", f"prix {bad!r} refusé"
    save(vat_default="99")
    assert val("SELECT v FROM settings WHERE k='vat_default_bp'") == "5000", "TVA plafonnée à 50 %"
    save(vat_default="-3")
    assert val("SELECT v FROM settings WHERE k='vat_default_bp'") == "0"
    save(vat_default="19,6")
    assert val("SELECT v FROM settings WHERE k='vat_default_bp'") == "1960"
    code, msg, _ = save(payments_enabled="1")
    assert val("SELECT v FROM settings WHERE k='payments_enabled'") == "1" and "Attention" not in msg
    assert "ouverte" in a.get("/admin/reglages").text
    save()
    assert val("SELECT v FROM settings WHERE k='payments_enabled'") == "0"
    sql("DELETE FROM settings")


def test_opening_the_shop_without_paypal_keeps_it_closed(fresh):
    cfg = read_config()
    saved = dict(cfg["paypal"])
    cfg["paypal"].update(client_id="", secret="")
    write_config(cfg)
    try:
        a = admin()
        code, msg, _ = act(a, "save_settings", price="9,99", vat_default="0", vat_map="", payments_enabled="1")
        assert "PayPal n'est pas configuré" in msg
        c = Client().login("no-paypal@test.local")
        assert c.post("/api/paypal/create", {"consent": True}).status_code == 503
        assert "pas encore ouverte" in c.get("/pro").text or "n'est pas encore ouverte" in c.get("/pro").text
    finally:
        cfg["paypal"] = saved
        write_config(cfg)
        sql("DELETE FROM settings")


def test_test_mail_and_purge(fresh):
    a = admin()
    before = mails_count()
    code, msg, _ = act(a, "test_mail")
    assert msg == f"E-mail de test envoyé à {ADMIN}."
    assert wait_mail(lambda m: m["subject"] == "Test Liagos 3D" and m["to"] == [ADMIN], before)
    now = int(time.time())
    uid = val("SELECT id FROM users WHERE email='%s'" % ADMIN)
    sql(f"INSERT INTO login_codes(email, code_hash, expires_at, created_at) VALUES ('old@test.local','x',{now - 5000},{now - 6000})")
    sql(f"INSERT INTO orders(public_id,user_id,status,amount_cents,currency,provider,created_at) VALUES ('LG-OLDORDER1',{uid},'created',999,'EUR','paypal',{now - 4 * 3600})")
    sql(f"INSERT INTO orders(public_id,user_id,status,amount_cents,currency,provider,created_at) VALUES ('LG-NEWORDER1',{uid},'created',999,'EUR','paypal',{now - 600})")
    sql(f"INSERT INTO webhook_events(event_id,type,received_at) VALUES ('OLD-EVT','X',{now - 100 * 86400}),('NEW-EVT','X',{now})")
    code, msg, _ = act(a, "purge")
    assert msg.startswith("Nettoyage terminé") and "1 commandes abandonnées" in msg
    assert order_row("LG-OLDORDER1")[0] == "cancelled" and order_row("LG-NEWORDER1")[0] == "created"
    assert val("SELECT COUNT(*) FROM login_codes WHERE email='old@test.local'") == "0"
    assert val("SELECT COUNT(*) FROM webhook_events WHERE event_id='OLD-EVT'") == "0" and val("SELECT COUNT(*) FROM webhook_events WHERE event_id='NEW-EVT'") == "1"


# ---------------------------------------------------------------- fichiers
def test_download_files_upload_validation_and_delete(fresh):
    a = admin()
    d = SITE / "public/downloads"
    for f in d.glob("*"):
        f.unlink()
    files = lambda name, data=b"MZ\x90\x00fake": {"file": (name, data, "application/octet-stream")}
    code, msg, _ = act(a, "file_upload", kind="download", files=files("Liagos-3D-Installation-1.0.exe"))
    assert msg == "Fichier ajouté : Liagos-3D-Installation-1.0.exe" and (d / "Liagos-3D-Installation-1.0.exe").read_bytes().startswith(b"MZ")
    act(a, "file_upload", kind="download", files=files("Liagos-3D.apk", b"PK\x03\x04apk"))
    for bad in ("evil.php", "shell.phtml", ".htaccess", "nom avec espace.exe", "noext", "a.exe.php", "x.EXE.txt", "-rf.exe\x00.php", "é.exe"):
        code, msg, _ = act(a, "file_upload", kind="download", files=files(bad))
        assert "Nom de fichier refusé" in msg, bad
    # un chemin dans le nom : seul le nom de fichier compte, rien ne sort du dossier
    code, msg, _ = act(a, "file_upload", kind="download", files=files("../../src/pwned.exe"))
    assert msg == "Fichier ajouté : pwned.exe" and (d / "pwned.exe").exists() and not (SITE / "src/pwned.exe").exists()
    assert sorted(p.name for p in d.glob("*")) == ["Liagos-3D-Installation-1.0.exe", "Liagos-3D.apk", "pwned.exe"]
    page = a.get("/admin/fichiers").text
    assert "Liagos-3D.apk" in page and "Liagos-3D-Installation-1.0.exe" in page
    dl = Client().get("/telecharger").text
    assert "Liagos-3D.apk" in dl and "/downloads/Liagos-3D.apk" in dl and re.search(r"[0-9a-f]{64}", dl), "empreinte SHA-256 affichée"
    assert Client().get("/downloads/Liagos-3D.apk").content == b"PK\x03\x04apk"
    # suppression : seulement dans le dossier des téléchargements
    code, msg, _ = act(a, "file_delete", name="../config.php")
    assert msg == "Fichier introuvable." and (SITE / "config.php").exists()
    code, msg, _ = act(a, "file_delete", name="../../src/core.php")
    assert msg == "Fichier introuvable." and (SITE / "src/core.php").exists()
    code, msg, _ = act(a, "file_delete", name="pwned.exe")
    assert msg == "Fichier supprimé." and not (d / "pwned.exe").exists()
    code, msg, _ = act(a, "file_upload", kind="download")
    assert msg == "Envoi impossible."
    for f in d.glob("*"):
        f.unlink()


def test_browser_app_upload_is_validated_and_served_to_pro_only(fresh):
    a = admin()
    app = SITE / "storage/app/index.html"
    original = app.read_bytes()
    try:
        code, msg, _ = act(a, "file_upload", kind="app", files={"file": ("index.html", b"MZ binaire", "text/html")})
        assert "n'est pas une application Liagos 3D" in msg and app.read_bytes() == original
        code, msg, _ = act(a, "file_upload", kind="app", files={"file": ("index.html", b"<script>alert(1)</script>", "text/html")})
        assert "n'est pas une application" in msg and app.read_bytes() == original
        new = b"<!DOCTYPE html><title>v2</title><p id=app>APP-V2</p>"
        code, msg, _ = act(a, "file_upload", kind="app", files={"file": ("n'importe-quoi.bin", new, "application/octet-stream")})
        assert msg == "Application navigateur remplacée." and app.read_bytes() == new
        assert not (SITE / "storage/app/index.html.tmp").exists()
        act(a, "license_issue", email="pro-user@test.local", days="0")
        p = Client().login("pro-user@test.local")
        r = p.get("/app/")
        assert r.status_code == 200 and "APP-V2" in r.text and "no-store" in r.headers["Cache-Control"]
        free = Client().login("free-user@test.local")
        r = free.get("/app/")
        assert r.status_code == 403 and "APP-V2" not in r.text
    finally:
        app.write_bytes(original)


# ---------------------------------------------------------------- clients et exports
def test_delete_customer_anonymises_and_protects_admin_self(shop_open):
    a = admin()
    c = new_buyer("gdpr@test.local")
    poid, pub, r = buy(c)
    uid = val("SELECT id FROM users WHERE email='gdpr@test.local'")
    admin_id = val(f"SELECT id FROM users WHERE email='{ADMIN}'")
    code, msg, _ = act(a, "user_delete", user_id=admin_id)
    assert msg == "Vous ne pouvez pas supprimer votre propre compte ici." and a.me()["email"] == ADMIN
    code, msg, _ = act(a, "user_delete", user_id=uid)
    assert msg == "Compte supprimé."
    assert sql(f"SELECT email, deleted_at IS NOT NULL FROM users WHERE id={uid}")[0] == [f"supprime-{uid}@invalid.local", "1"]
    assert val(f"SELECT COUNT(*) FROM sessions WHERE user_id={uid}") == "0" and c.get("/api/me").json()["user"] is None
    assert val(f"SELECT payer_email IS NULL FROM orders WHERE public_id='{pub}'") == "1", "adresse PayPal effacée, commande conservée"
    assert "gdpr@test.local" not in a.get("/admin/clients").text and "gdpr@test.local" not in a.get("/admin/commandes").text
    # l'adresse peut être réutilisée : nouveau compte, sans l'ancienne licence
    again = Client().login("gdpr@test.local")
    assert again.me()["pro"] is False


def test_csv_exports_are_excel_safe(shop_open):
    a = admin()
    for e in ("=cmd@test.local", "+evil@test.local", "-evil@test.local"):
        act(a, "license_issue", email=e, days="0")
    c = new_buyer("csv-buyer@test.local")
    buy(c, country="DE")
    for what, head in (("clients", "E-mail"), ("commandes", "Référence")):
        r = a.get(f"/admin/export/{what}.csv")
        assert r.status_code == 200 and r.headers["Content-Type"].startswith("text/csv") and "attachment" in r.headers["Content-Disposition"]
        assert r.content.startswith(b"\xef\xbb\xbf"), "BOM UTF-8 pour Excel"
        rows = list(csv.reader(io.StringIO(r.content.decode("utf-8-sig")), delimiter=";"))
        assert rows[0][0] == head
        if what == "clients":
            cells = [x[0] for x in rows[1:]]
            for e in ("=cmd@test.local", "+evil@test.local", "-evil@test.local"):
                assert "'" + e in cells and e not in cells, "une cellule ne doit jamais commencer par = + - @"
            assert "csv-buyer@test.local" in cells
        else:
            assert len(rows) == 2 and rows[1][2] == "paid" and rows[1][3] == "9,99" and rows[1][7] == "DE" and rows[1][5] == "1,60"
            assert rows[1][4] == "EUR" and rows[1][8] == "csv-buyer@test.local" and rows[1][10].startswith("ORD") and rows[1][11].startswith("CAP")
    assert a.get("/admin/export/autre.csv").status_code == 404


def test_journal_lists_actions_and_webhooks(shop_open):
    a = admin()
    c = new_buyer("journal@test.local")
    poid, pub, r = buy(c)
    act(a, "license_issue", email="j2@test.local", days="0")
    t = a.get("/admin/journal").text
    assert "commande_payee" in t and "licence_creee" in t and pub in t and "j2@test.local" in t
    sql("INSERT INTO webhook_events(event_id,type,received_at) VALUES ('EVT-JOURNAL-1','PAYMENT.CAPTURE.COMPLETED',%d)" % int(time.time()))
    assert "EVT-JOURNAL-1" in a.get("/admin/journal").text
