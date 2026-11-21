#!/usr/bin/env python3
"""Faux PayPal (API Orders v2 + webhooks) pour les essais locaux. Ne parle à personne d'autre."""
import json, sys, threading, uuid, base64
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

CLIENT, SECRET, WEBHOOK_ID, MERCHANT = "test-client", "test-secret", "WH-TEST", "MERCH123"
LOCK = threading.Lock()
S = {"orders": {}, "captures": {}, "calls": [], "scenario": {}, "tokens": 0}


def j(h, code, obj):
    b = json.dumps(obj).encode()
    h.send_response(code)
    h.send_header("Content-Type", "application/json")
    h.send_header("Content-Length", str(len(b)))
    h.end_headers()
    h.wfile.write(b)


class H(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, *a):
        pass

    def body(self):
        n = int(self.headers.get("Content-Length") or 0)
        return self.rfile.read(n).decode() if n else ""

    def record(self, raw):
        with LOCK:
            S["calls"].append({"method": self.command, "path": self.path, "body": raw,
                               "request_id": self.headers.get("PayPal-Request-Id"), "auth": bool(self.headers.get("Authorization"))})

    def authed(self):
        return (self.headers.get("Authorization") or "") == "Bearer tok-ok"

    def do_GET(self):
        raw = ""
        if self.path == "/__calls":
            return j(self, 200, S["calls"])
        self.record(raw)
        if not self.authed():
            return j(self, 401, {"name": "AUTHENTICATION_FAILURE"})
        if self.path.startswith("/v2/checkout/orders/"):
            o = S["orders"].get(self.path.split("/")[4])
            return j(self, 200, o) if o else j(self, 404, {"name": "RESOURCE_NOT_FOUND"})
        j(self, 404, {"name": "NOT_FOUND"})

    def do_POST(self):
        raw = self.body()
        if self.path == "/__control":
            with LOCK:
                d = json.loads(raw or "{}")
                if d.get("reset"):
                    S["orders"].clear(); S["captures"].clear(); S["calls"].clear(); S["scenario"].clear()
                S["scenario"].update(d.get("scenario", {}))
            return j(self, 200, {"ok": True})
        self.record(raw)
        sc = S["scenario"]
        if self.path == "/v1/oauth2/token":
            exp = "Basic " + base64.b64encode(f"{CLIENT}:{SECRET}".encode()).decode()
            if self.headers.get("Authorization") != exp:
                return j(self, 401, {"error": "invalid_client"})
            with LOCK: S["tokens"] += 1
            return j(self, 200, {"access_token": "tok-ok", "token_type": "Bearer", "expires_in": 32400})
        if not self.authed():
            return j(self, 401, {"name": "AUTHENTICATION_FAILURE"})
        if self.path == "/v1/notifications/verify-webhook-signature":
            d = json.loads(raw)
            ok = d.get("transmission_sig") == "valid-sig" and d.get("webhook_id") == WEBHOOK_ID and isinstance(d.get("webhook_event"), dict) and d["webhook_event"].get("id")
            return j(self, 200, {"verification_status": "SUCCESS" if ok else "FAILURE"})
        if self.path == "/v2/checkout/orders":
            if sc.get("create_fail"):
                return j(self, 500, {"name": "INTERNAL_SERVER_ERROR"})
            d = json.loads(raw)
            pu = d["purchase_units"][0]
            oid = "ORD" + uuid.uuid4().hex[:14].upper()
            o = {"id": oid, "status": "CREATED", "intent": d.get("intent"),
                 "purchase_units": [{"reference_id": pu.get("reference_id"), "custom_id": pu.get("custom_id"), "invoice_id": pu.get("invoice_id"),
                                      "amount": pu["amount"], "payee": {"merchant_id": MERCHANT}}],
                 "app_ctx": d.get("application_context"), "links": []}
            with LOCK: S["orders"][oid] = o
            return j(self, 201, {"id": oid, "status": "CREATED", "links": []})
        if self.path.startswith("/v2/checkout/orders/") and self.path.endswith("/capture"):
            oid = self.path.split("/")[4]
            o = S["orders"].get(oid)
            if not o:
                return j(self, 404, {"name": "RESOURCE_NOT_FOUND"})
            if o["status"] == "COMPLETED":
                return j(self, 422, {"name": "UNPROCESSABLE_ENTITY", "details": [{"issue": "ORDER_ALREADY_CAPTURED"}]})
            mode = sc.get("capture", "ok")
            if mode == "declined":
                return j(self, 422, {"name": "UNPROCESSABLE_ENTITY", "details": [{"issue": "INSTRUMENT_DECLINED"}]})
            if mode == "error":
                return j(self, 500, {"name": "INTERNAL_SERVER_ERROR"})
            amt = dict(o["purchase_units"][0]["amount"])
            if mode == "wrong_amount":
                amt["value"] = "1.00"
            cid = "CAP" + uuid.uuid4().hex[:14].upper()
            st = "PENDING" if mode == "pending" else "COMPLETED"
            cap = {"id": cid, "status": st, "amount": amt}
            with LOCK:
                S["captures"][cid] = {"order": oid, "refunded": False}
                o["status"] = "COMPLETED"
                o["purchase_units"][0]["payments"] = {"captures": [cap]}
                o["payer"] = {"email_address": sc.get("payer_email", "acheteur@example.com"), "address": {"country_code": sc.get("country", "FR")}}
            if sc.get("capture_custom_id"):
                o["purchase_units"][0]["custom_id"] = sc["capture_custom_id"]
            return j(self, 201, o)
        if self.path.startswith("/v2/payments/captures/") and self.path.endswith("/refund"):
            cid = self.path.split("/")[4]
            if sc.get("refund_fail") or cid not in S["captures"]:
                return j(self, 422, {"name": "UNPROCESSABLE_ENTITY", "details": [{"issue": "CAPTURE_FULLY_REFUNDED"}]})
            S["captures"][cid]["refunded"] = True
            return j(self, 201, {"id": "REF" + uuid.uuid4().hex[:10].upper(), "status": "COMPLETED"})
        j(self, 404, {"name": "NOT_FOUND"})


if __name__ == "__main__":
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 8099
    ThreadingHTTPServer(("127.0.0.1", port), H).serve_forever()
