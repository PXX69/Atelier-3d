"""Webhook PayPal : signature, doublons, tous les types d'événements, reprise après échec, remboursements (totaux et partiels)."""
import json, time
import requests
from conftest import *
from test_40_shop import buy, create, new_buyer

HOOK = BASE + "/api/paypal/webhook"
_n = [0]


def eid():
    _n[0] += 1
    return f"WH-EVT-{int(time.time() * 1000)}-{_n[0]}"


def send(ev=None, raw=None, sig="valid-sig"):
    raw = raw if raw is not None else json.dumps(ev)
    h = {"Content-Type": "application/json", "PAYPAL-TRANSMISSION-SIG": sig, "PAYPAL-TRANSMISSION-ID": "tid-1",
         "PAYPAL-TRANSMISSION-TIME": "2026-01-01T00:00:00Z", "PAYPAL-AUTH-ALGO": "SHA256withRSA", "PAYPAL-CERT-URL": "https://api.paypal.com/v1/notifications/certs/CERT-1"}
    return requests.post(HOOK, data=raw.encode(), headers=h)


def event(kind, resource, id_=None):
    return {"id": id_ or eid(), "event_type": kind, "resource": resource}


def order_row(pub):
    return sql(f"SELECT status, provider_capture_id, note FROM orders WHERE public_id='{pub}'")[0]


def licence_status(pub):
    r = sql(f"SELECT status FROM licenses WHERE order_id=(SELECT id FROM orders WHERE public_id='{pub}')")
    return r[0][0] if r else None


def refund_event(capid, value="9.99", kind="PAYMENT.CAPTURE.REFUNDED"):
    return event(kind, {"id": "REF" + eid()[-6:], "amount": {"currency_code": "EUR", "value": value}, "status": "COMPLETED",
                        "links": [{"rel": "up", "href": f"https://api.sandbox.paypal.com/v2/payments/captures/{capid}", "method": "GET"}]})


def test_not_configured_returns_503(fresh):
    cfg = read_config()
    saved = cfg["paypal"].get("webhook_id")
    cfg["paypal"]["webhook_id"] = ""
    write_config(cfg)
    try:
        r = send(event("PAYMENT.CAPTURE.COMPLETED", {}))
        assert r.status_code == 503
    finally:
        cfg["paypal"]["webhook_id"] = saved
        write_config(cfg)
    assert pp_calls() == [], "sans identifiant de webhook, aucun appel à PayPal"


def test_bad_bodies_are_400(fresh):
    assert send(raw="pas du json").status_code == 400
    assert send(raw="[]").status_code == 400
    assert send(raw=json.dumps({"event_type": "X"})).status_code == 400          # pas d'id
    assert send(raw=json.dumps({"id": "A"})).status_code == 400                  # pas de type
    assert send(raw="x" * 300000).status_code == 400                             # corps tronqué à 256 Ko : JSON invalide
    assert val("SELECT COUNT(*) FROM webhook_events") == "0"


def test_invalid_signature_refused_and_nothing_happens(shop_open):
    c = new_buyer("wh-sig@test.local")
    poid, pub, r = buy(c)
    capid = order_row(pub)[1]
    ev = refund_event(capid)
    r = send(ev, sig="forged")
    assert r.status_code == 400 and r.json()["message"] == "signature"
    assert order_row(pub)[0] == "paid" and licence_status(pub) == "active"
    assert val("SELECT COUNT(*) FROM webhook_events") == "0", "un événement refusé n'est pas mémorisé"
    # le même événement, correctement signé, est ensuite accepté
    assert send(ev).status_code == 200 and order_row(pub)[0] == "refunded"


def test_raw_body_is_forwarded_verbatim_to_paypal(shop_open):
    raw = '{"id":"WH-RAW-1",   "event_type":"PAYMENT.CAPTURE.COMPLETED",\n "resource":{"id":"NOPE","note":"é\\u00e9 \\/ \\"x\\""} }'
    r = send(raw=raw)
    assert r.status_code == 200 and r.json()["message"] == "commande inconnue"
    calls = [x for x in pp_calls() if x["path"] == "/v1/notifications/verify-webhook-signature"]
    assert len(calls) == 1
    body = calls[0]["body"]
    assert '"webhook_event":' + raw + "}" in body, "PayPal vérifie la signature sur les octets exacts reçus"
    d = json.loads(body)
    assert d["transmission_sig"] == "valid-sig" and d["webhook_id"] == "WH-TEST" and d["transmission_id"] == "tid-1"
    assert d["cert_url"].startswith("https://api.paypal.com/") and d["auth_algo"] == "SHA256withRSA" and d["transmission_time"] == "2026-01-01T00:00:00Z"


def test_unknown_order_is_acknowledged(shop_open):
    r = send(event("PAYMENT.CAPTURE.REFUNDED", {"id": "X1", "links": [{"rel": "up", "href": "https://x/v2/payments/captures/INCONNU"}]}))
    assert r.status_code == 200 and r.json()["message"] == "commande inconnue"
    assert val("SELECT COUNT(*) FROM orders") == "0"


def test_duplicate_event_is_processed_once(shop_open):
    c = new_buyer("wh-dup@test.local")
    poid, pub, r = buy(c)
    ev = refund_event(order_row(pub)[1])
    before = mails_count()
    r1, r2 = send(ev), send(ev)
    assert r1.status_code == 200 and r1.json()["message"] == "ok"
    assert r2.status_code == 200 and r2.json()["message"] == "déjà traité"
    time.sleep(0.6)
    assert len([m for m in read_mails()[before:] if "Remboursement" in m["subject"]]) == 1
    assert val("SELECT COUNT(*) FROM webhook_events") == "1"


def test_completed_fulfils_an_order_never_captured_by_the_browser(shop_open):
    c = new_buyer("wh-done@test.local")
    r = create(c)
    poid, pub = r.json()["id"], r.json()["order"]
    assert order_row(pub)[0] == "created"
    before = mails_count()
    ev = event("PAYMENT.CAPTURE.COMPLETED", {"id": "CAPWEB1", "status": "COMPLETED", "custom_id": pub, "amount": {"currency_code": "EUR", "value": "9.99"},
                                             "supplementary_data": {"related_ids": {"order_id": poid}}})
    assert send(ev).status_code == 200
    assert order_row(pub)[:2] == ["paid", "CAPWEB1"] and licence_status(pub) == "active"
    assert c.me()["pro"] is True
    assert wait_mail(lambda m: "Votre licence" in m["subject"] and "wh-done@test.local" in m["to"], before)
    # un second événement identique (autre identifiant) ne délivre pas une deuxième licence
    assert send(event("PAYMENT.CAPTURE.COMPLETED", {"id": "CAPWEB1", "custom_id": pub, "amount": {"currency_code": "EUR", "value": "9.99"}})).status_code == 200
    assert val("SELECT COUNT(*) FROM licenses") == "1"


def test_completed_with_wrong_amount_alerts_admin(shop_open):
    c = new_buyer("wh-amt@test.local")
    r = create(c)
    poid, pub = r.json()["id"], r.json()["order"]
    before = mails_count()
    send(event("PAYMENT.CAPTURE.COMPLETED", {"id": "CAPBAD", "custom_id": pub, "amount": {"currency_code": "EUR", "value": "1.00"}}))
    assert order_row(pub)[0] == "created" and licence_status(pub) is None
    m = wait_mail(lambda m: "Montant inattendu" in m["subject"], before)
    assert m and m["to"] == [ADMIN]


def test_order_approved_triggers_the_capture(shop_open):
    c = new_buyer("wh-appr@test.local")
    r = create(c)
    poid, pub = r.json()["id"], r.json()["order"]
    assert send(event("CHECKOUT.ORDER.APPROVED", {"id": poid, "status": "APPROVED", "purchase_units": [{"custom_id": pub}]})).status_code == 200
    assert order_row(pub)[0] == "paid" and licence_status(pub) == "active"
    assert any(x["path"].endswith("/capture") for x in pp_calls())
    # le navigateur qui revient ensuite reçoit la même clé (pas de double encaissement)
    r2 = c.post("/api/paypal/capture", {"orderID": poid})
    assert r2.status_code == 200 and r2.json()["key"].startswith("L3D1-")
    assert val("SELECT COUNT(*) FROM licenses") == "1"
    assert len([x for x in pp_calls() if x["path"].endswith("/capture")]) == 1


def test_event_is_released_when_paypal_fails_so_the_retry_works(shop_open):
    c = new_buyer("wh-retry@test.local")
    r = create(c)
    poid, pub = r.json()["id"], r.json()["order"]
    ev = event("CHECKOUT.ORDER.APPROVED", {"id": poid, "status": "APPROVED", "purchase_units": [{"custom_id": pub}]})
    pp_control(scenario={"capture": "error"})
    r1 = send(ev)
    assert r1.status_code == 500, "PayPal doit recevoir une erreur pour renvoyer l'événement"
    assert order_row(pub)[0] == "created"
    assert val("SELECT COUNT(*) FROM webhook_events") == "0", "l'événement n'est pas considéré comme traité"
    pp_control(scenario={"capture": "ok"})
    r2 = send(ev)
    assert r2.status_code == 200 and r2.json()["message"] == "ok"
    assert order_row(pub)[0] == "paid" and licence_status(pub) == "active"


def test_declined_capture_via_webhook_is_final(shop_open):
    c = new_buyer("wh-decl@test.local")
    r = create(c)
    poid, pub = r.json()["id"], r.json()["order"]
    pp_control(scenario={"capture": "declined"})
    assert send(event("CHECKOUT.ORDER.APPROVED", {"id": poid, "purchase_units": [{"custom_id": pub}]})).status_code == 200
    assert order_row(pub)[0] == "created" and licence_status(pub) is None


def test_pending_then_completed(shop_open):
    c = new_buyer("wh-pend@test.local")
    r = create(c)
    poid, pub = r.json()["id"], r.json()["order"]
    assert send(event("PAYMENT.CAPTURE.PENDING", {"id": "CAPPEND", "custom_id": pub, "amount": {"currency_code": "EUR", "value": "9.99"}})).status_code == 200
    assert order_row(pub)[:2] == ["pending", "CAPPEND"] and licence_status(pub) is None
    assert c.me()["pro"] is False
    assert send(event("PAYMENT.CAPTURE.COMPLETED", {"id": "CAPPEND", "custom_id": pub, "amount": {"currency_code": "EUR", "value": "9.99"}})).status_code == 200
    assert order_row(pub)[0] == "paid" and licence_status(pub) == "active" and c.me()["pro"] is True


def test_denied_marks_failed_and_never_licenses(shop_open):
    c = new_buyer("wh-den@test.local")
    r = create(c)
    pub = r.json()["order"]
    assert send(event("PAYMENT.CAPTURE.DENIED", {"id": "CAPDEN", "custom_id": pub})).status_code == 200
    st = order_row(pub)
    assert st[0] == "failed" and "refusé" in st[2] and licence_status(pub) is None
    # un événement COMPLETED tardif ne ressuscite pas une commande échouée
    send(event("PAYMENT.CAPTURE.COMPLETED", {"id": "CAPDEN", "custom_id": pub, "amount": {"currency_code": "EUR", "value": "9.99"}}))
    assert order_row(pub)[0] == "failed" and licence_status(pub) is None


def test_full_refund_revokes_license_and_mails_customer(shop_open):
    c = new_buyer("wh-ref@test.local")
    poid, pub, r = buy(c)
    assert c.me()["pro"] is True
    before = mails_count()
    assert send(refund_event(order_row(pub)[1])).status_code == 200
    assert order_row(pub)[0] == "refunded" and licence_status(pub) == "revoked"
    assert c.me()["pro"] is False
    assert c.get("/api/app-license").status_code == 403
    m = wait_mail(lambda m: "Remboursement" in m["subject"], before)
    assert m and m["to"] == ["wh-ref@test.local"] and pub in m["text"]
    assert "Remboursée" in c.get("/compte").text or "rembours" in c.get("/compte").text.lower()
    # on peut racheter après un remboursement
    poid2, pub2, r2 = buy(c)
    assert r2.status_code == 200 and c.me()["pro"] is True


def test_partial_refund_keeps_license_and_alerts_admin(shop_open):
    c = new_buyer("wh-part@test.local")
    poid, pub, r = buy(c)
    before = mails_count()
    assert send(refund_event(order_row(pub)[1], value="2.00")).status_code == 200
    assert order_row(pub)[0] == "paid" and licence_status(pub) == "active" and c.me()["pro"] is True
    m = wait_mail(lambda m: "Remboursement partiel" in m["subject"], before)
    assert m and m["to"] == [ADMIN] and "2.00" in m["text"]
    assert "remboursement_partiel" in "".join(r[0] for r in sql("SELECT action FROM audit"))


def test_reversal_revokes_license(shop_open):
    c = new_buyer("wh-rev@test.local")
    poid, pub, r = buy(c)
    capid = order_row(pub)[1]
    assert send(event("PAYMENT.CAPTURE.REVERSED", {"id": capid, "amount": {"currency_code": "EUR", "value": "9.99"}, "custom_id": pub})).status_code == 200
    st = order_row(pub)
    assert st[0] == "refunded" and "annulé" in st[2] and licence_status(pub) == "revoked" and c.me()["pro"] is False


def test_dispute_keeps_license_and_alerts_then_refund_closes_it(shop_open):
    c = new_buyer("wh-disp@test.local")
    poid, pub, r = buy(c)
    capid = order_row(pub)[1]
    before = mails_count()
    assert send(event("CUSTOMER.DISPUTE.CREATED", {"dispute_id": "PP-D-1", "disputed_transactions": [{"seller_transaction_id": capid}]})).status_code == 200
    assert order_row(pub)[0] == "disputed" and licence_status(pub) == "active" and c.me()["pro"] is True
    m = wait_mail(lambda m: "Litige PayPal" in m["subject"], before)
    assert m and m["to"] == [ADMIN] and pub in m["subject"]
    # l'issue du litige en faveur de l'acheteur arrive sous forme de remboursement
    assert send(refund_event(capid)).status_code == 200
    assert order_row(pub)[0] == "refunded" and licence_status(pub) == "revoked"


def test_two_refund_events_one_mail(shop_open):
    c = new_buyer("wh-two@test.local")
    poid, pub, r = buy(c)
    capid = order_row(pub)[1]
    before = mails_count()
    send(refund_event(capid))
    send(refund_event(capid))
    send(event("PAYMENT.CAPTURE.REVERSED", {"id": capid, "amount": {"currency_code": "EUR", "value": "9.99"}}))
    time.sleep(0.6)
    assert len([m for m in read_mails()[before:] if "Remboursement" in m["subject"]]) == 1


def test_webhook_ignores_unknown_event_types(shop_open):
    c = new_buyer("wh-unk@test.local")
    poid, pub, r = buy(c)
    assert send(event("BILLING.SUBSCRIPTION.CREATED", {"id": order_row(pub)[1]})).status_code == 200
    assert order_row(pub)[0] == "paid" and licence_status(pub) == "active"


def test_webhook_is_get_protected_and_has_no_cookie_requirements(shop_open):
    # GET sur l'adresse du webhook : refusé proprement ; pas de session ni de CSRF exigés pour POST (PayPal n'en a pas)
    assert requests.get(HOOK).status_code == 405
    r = requests.post(HOOK, data="{}", headers={"Content-Type": "application/json"})
    assert r.status_code == 400
