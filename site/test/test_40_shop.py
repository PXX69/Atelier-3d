"""Achat PayPal (faux PayPal) : création, encaissement, contrôles, doublons, concurrence, licence et e-mails."""
import json, threading, time, re
from conftest import *
from conftest import SITE


def vat_of(gross, bp):
    return (gross * bp + (10000 + bp) // 2) // (10000 + bp)


def new_buyer(email):
    return Client().login(email)


def create(c, consent=True):
    return c.post("/api/paypal/create", {"consent": consent})


def capture(c, poid):
    return c.post("/api/paypal/capture", {"orderID": poid})


def buy(c, **sc):
    """Achat complet réussi. Retourne (poid, public_id, réponse de capture)."""
    if sc:
        pp_control(scenario=sc)
    r = create(c)
    assert r.status_code == 200, r.text
    poid, pub = r.json()["id"], r.json()["order"]
    return poid, pub, capture(c, poid)


def test_create_requires_login_csrf_consent_and_open_shop(shop_open):
    c = Client()
    assert create(c).status_code == 401
    c = new_buyer("shop1@test.local")
    c.csrf = "faux"
    assert create(c).status_code == 403
    c.csrf = c.me()["csrf"]
    r = create(c, consent=False)
    assert r.status_code == 422 and r.json()["error"] == "consentement_requis"
    assert val("SELECT COUNT(*) FROM orders") == "0", "pas de commande sans consentement"


def test_create_refused_when_shop_closed(fresh):
    c = new_buyer("closed@test.local")
    r = create(c)
    assert r.status_code == 503 and r.json()["error"] == "paiement_indisponible"
    assert val("SELECT COUNT(*) FROM orders") == "0"
    assert pp_calls() == [], "aucun appel à PayPal quand la vente est fermée"


def test_create_sends_correct_order_to_paypal(shop_open):
    c = new_buyer("shop2@test.local")
    r = create(c)
    assert r.status_code == 200
    poid, pub = r.json()["id"], r.json()["order"]
    assert re.fullmatch(r"LG-[A-HJKMNP-Z2-9]{10}", pub)
    row = sql(f"SELECT status, amount_cents, currency, provider, provider_order_id, consent_at FROM orders WHERE public_id='{pub}'")[0]
    assert row[:5] == ["created", "999", "EUR", "paypal", poid] and int(row[5]) > 0
    calls = [x for x in pp_calls() if x["path"] == "/v2/checkout/orders"]
    assert len(calls) == 1
    body = json.loads(calls[0]["body"])
    pu = body["purchase_units"][0]
    assert body["intent"] == "CAPTURE" and pu["amount"] == {"currency_code": "EUR", "value": "9.99"}
    assert pu["custom_id"] == pub and pu["invoice_id"] == pub
    assert body["application_context"]["shipping_preference"] == "NO_SHIPPING"
    assert calls[0]["request_id"] == pub, "clé d'idempotence PayPal"
    assert any(x["path"] == "/v1/oauth2/token" for x in pp_calls())


def test_capture_success_issues_license_mail_and_vat(shop_open, env):
    c = new_buyer("shop3@test.local")
    before = mails_count()
    poid, pub, r = buy(c)
    assert r.status_code == 200
    j = r.json()
    assert j["ok"] and j["status"] == "paid" and j["order"] == pub and j["key"].startswith("L3D1-")
    o = sql(f"SELECT status, provider_capture_id, payer_email, country, vat_bp, vat_cents, paid_at FROM orders WHERE public_id='{pub}'")[0]
    assert o[0] == "paid" and o[1].startswith("CAP") and o[2] == "acheteur@example.com" and o[3] == "FR"
    assert int(o[4]) == 2000 and int(o[5]) == vat_of(999, 2000) and int(o[6]) > 0
    lic = sql(f"SELECT status, source, edition, expires_at, license_key FROM licenses WHERE order_id=(SELECT id FROM orders WHERE public_id='{pub}')")
    assert len(lic) == 1 and lic[0][0] == "active" and lic[0][1] == "purchase" and lic[0][2] == "1" and lic[0][3] == "NULL" and lic[0][4] == j["key"]
    m = wait_mail(lambda m: "Votre licence" in m["subject"], before)
    assert m and m["to"] == ["shop3@test.local"], "la clé part vers l'e-mail du COMPTE, pas celui de PayPal"
    assert j["key"] in m["text"] and pub in m["text"] and "9,99" in m["text"] and "TVA" in m["text"]
    assert c.me()["pro"] is True
    assert c.get("/api/app-license").json()["key"] == j["key"]
    assert json.loads(php_run_verify(j["key"])) is not None
    assert "Payée" in c.get("/compte").text and j["key"] in c.get("/compte").text


def php_run_verify(key):
    import subprocess
    r = subprocess.run(["php", "-r", "require 'src/core.php'; require 'src/license.php'; echo json_encode(License::verify('%s'));" % key], capture_output=True, text=True, cwd=SITE)
    return r.stdout


def test_vat_by_country(shop_open):
    c = new_buyer("de@test.local")
    poid, pub, r = buy(c, country="DE")
    assert r.status_code == 200
    assert sql(f"SELECT country, vat_bp, vat_cents FROM orders WHERE public_id='{pub}'")[0] == ["DE", "1900", str(vat_of(999, 1900))]


def test_duplicate_capture_returns_same_key_once(shop_open):
    c = new_buyer("dup@test.local")
    before = mails_count()
    poid, pub, r1 = buy(c)
    r2 = capture(c, poid)
    r3 = capture(c, poid)
    assert r2.status_code == r3.status_code == 200 and r1.json()["key"] == r2.json()["key"] == r3.json()["key"]
    assert val("SELECT COUNT(*) FROM licenses") == "1"
    time.sleep(0.5)
    assert len([m for m in read_mails()[before:] if "Votre licence" in m["subject"]]) == 1


def test_concurrent_captures_give_one_license(shop_open):
    c = new_buyer("race@test.local")
    before = mails_count()
    r = create(c)
    poid = r.json()["id"]
    out = []
    def go():
        out.append(capture(c, poid))
    ts = [threading.Thread(target=go) for _ in range(5)]
    [t.start() for t in ts]; [t.join() for t in ts]
    assert all(x.status_code == 200 for x in out), [x.text for x in out]
    assert len({x.json()["key"] for x in out}) == 1
    assert val("SELECT COUNT(*) FROM licenses") == "1" and val("SELECT COUNT(*) FROM orders WHERE status='paid'") == "1"
    time.sleep(0.5)
    assert len([m for m in read_mails()[before:] if "Votre licence" in m["subject"]]) == 1


def test_other_users_cannot_capture_my_order(shop_open):
    a, b = new_buyer("a@test.local"), new_buyer("b@test.local")
    poid = create(a).json()["id"]
    r = capture(b, poid)
    assert r.status_code == 404 and r.json()["error"] == "commande_inconnue"
    assert val("SELECT COUNT(*) FROM licenses") == "0"
    for bad in ("../../etc/passwd", "x", "a" * 70, "ORD'; DROP TABLE orders;--", ""):
        assert capture(a, bad).status_code == 404
    assert val("SELECT COUNT(*) FROM orders") == "1"


def test_already_pro_cannot_buy_again(shop_open):
    c = new_buyer("twice@test.local")
    buy(c)
    r = create(c)
    assert r.status_code == 409 and r.json()["error"] == "deja_pro"


def test_amount_mismatch_fails_order_alerts_admin(shop_open):
    c = new_buyer("wrong@test.local")
    before = mails_count()
    poid, pub, r = buy(c, capture="wrong_amount")
    assert r.status_code == 422 and r.json()["error"] == "verification_echouee"
    assert sql(f"SELECT status FROM orders WHERE public_id='{pub}'")[0][0] == "failed"
    assert val("SELECT COUNT(*) FROM licenses") == "0" and c.me()["pro"] is False
    assert wait_mail(lambda m: ADMIN in m["to"] and "incohérente" in m["subject"], before)


def test_custom_id_mismatch_fails_order(shop_open):
    c = new_buyer("cid@test.local")
    poid, pub, r = buy(c, capture_custom_id="LG-AUTRECMD99")
    assert r.status_code == 422 and r.json()["error"] == "verification_echouee"
    assert val("SELECT COUNT(*) FROM licenses") == "0"


def test_merchant_check(shop_open):
    cfg = read_config()
    cfg["paypal"]["merchant_id"] = "AUTRE"
    write_config(cfg)
    try:
        c = new_buyer("merch@test.local")
        poid, pub, r = buy(c)
        assert r.status_code == 422 and r.json()["error"] == "verification_echouee"
        cfg["paypal"]["merchant_id"] = "MERCH123"
        write_config(cfg)
        c2 = new_buyer("merch2@test.local")
        poid, pub, r = buy(c2)
        assert r.status_code == 200 and r.json()["status"] == "paid"
    finally:
        cfg["paypal"]["merchant_id"] = ""
        write_config(cfg)


def test_declined_payment_can_be_retried(shop_open):
    c = new_buyer("declined@test.local")
    poid, pub, r = buy(c, capture="declined")
    assert r.status_code == 422 and r.json()["error"] == "paiement_refuse" and r.json()["restart"] is True
    assert sql(f"SELECT status FROM orders WHERE public_id='{pub}'")[0][0] == "created"
    pp_control(scenario={"capture": "ok"})
    r = capture(c, poid)
    assert r.status_code == 200 and r.json()["status"] == "paid"


def test_paypal_error_on_capture_is_502_and_order_stays_open(shop_open):
    c = new_buyer("err@test.local")
    poid, pub, r = buy(c, capture="error")
    assert r.status_code == 502 and r.json()["error"] == "paypal_erreur"
    assert sql(f"SELECT status FROM orders WHERE public_id='{pub}'")[0][0] == "created"
    pp_control(scenario={"capture": "ok"})
    assert capture(c, poid).json()["status"] == "paid"


def test_pending_capture_then_webhook_completes(shop_open):
    c = new_buyer("pend@test.local")
    before = mails_count()
    poid, pub, r = buy(c, capture="pending")
    assert r.status_code == 200 and r.json() == {"ok": True, "status": "pending"}
    assert sql(f"SELECT status, provider_capture_id, payer_email, country FROM orders WHERE public_id='{pub}'")[0][0] == "pending"
    assert val("SELECT COUNT(*) FROM licenses") == "0" and c.me()["pro"] is False
    capid = val(f"SELECT provider_capture_id FROM orders WHERE public_id='{pub}'")
    ev = {"id": "WH-PEND-1", "event_type": "PAYMENT.CAPTURE.COMPLETED", "resource": {"id": capid, "amount": {"currency_code": "EUR", "value": "9.99"}, "custom_id": pub}}
    r = requests.post(BASE + "/api/paypal/webhook", data=json.dumps(ev), headers={"PAYPAL-TRANSMISSION-SIG": "valid-sig", "Content-Type": "application/json"})
    assert r.status_code == 200
    assert sql(f"SELECT status, payer_email, country FROM orders WHERE public_id='{pub}'")[0] == ["paid", "acheteur@example.com", "FR"], "les infos de l'acheteur reçues à la capture sont conservées"
    assert c.me()["pro"] is True
    assert wait_mail(lambda m: "Votre licence" in m["subject"], before)
    # redemander la capture depuis le navigateur renvoie simplement la clé
    assert capture(c, poid).json()["status"] == "paid"


def test_paypal_create_failure_is_502_and_marked_failed(shop_open):
    c = new_buyer("cf@test.local")
    pp_control(scenario={"create_fail": True})
    r = create(c)
    assert r.status_code == 502 and r.json()["error"] == "paypal_erreur"
    assert val("SELECT status FROM orders") == "failed"
    assert "création PayPal" in val("SELECT note FROM orders")


def test_bad_paypal_credentials_are_502_and_logged(shop_open):
    cfg = read_config()
    cfg["paypal"]["secret"] = "mauvais"
    write_config(cfg)
    try:
        r = create(new_buyer("cred@test.local"))
        assert r.status_code == 502
        assert "paypal jeton" in (SITE / "storage/logs/app.log").read_text()
    finally:
        cfg["paypal"]["secret"] = "test-secret"
        write_config(cfg)


def test_create_rate_limit(shop_open):
    c = new_buyer("rl@test.local")
    codes = [create(c).status_code for _ in range(16)]
    assert codes[:15] == [200] * 15 and codes[15] == 429


def test_shop_page_for_logged_in_buyer(shop_open):
    c = new_buyer("page@test.local")
    t = c.get("/pro").text
    assert 'id="consent"' in t and 'id="paypal-buttons"' in t and 'data-logged="1"' in t and 'data-pro="0"' in t
    buy(c)
    t = c.get("/pro").text
    assert 'data-pro="1"' in t and "Vous avez déjà Liagos 3D Pro" in t and 'id="paypal-buttons"' not in t


def test_price_change_applies_to_new_orders(shop_open):
    sql("REPLACE INTO settings(k,v) VALUES ('price_cents','1250')")
    c = new_buyer("price@test.local")
    poid, pub, r = buy(c)
    assert r.status_code == 200
    body = json.loads([x for x in pp_calls() if x["path"] == "/v2/checkout/orders"][0]["body"])
    assert body["purchase_units"][0]["amount"]["value"] == "12.50"
    assert val(f"SELECT amount_cents FROM orders WHERE public_id='{pub}'") == "1250"
