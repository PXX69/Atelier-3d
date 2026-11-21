#!/usr/bin/env python3
"""Faux serveur SMTP pour les essais : SSL implicite (port A) et STARTTLS (port B). Les messages reçus vont dans un fichier .jsonl."""
import base64, email, json, socket, ssl, sys, threading, time
from email import policy

USER, PASS = "boutique@test.local", "mailpw"
OUT = sys.argv[3] if len(sys.argv) > 3 else "/tmp/l3d/mails.jsonl"
CERT, KEY = sys.argv[4] if len(sys.argv) > 4 else "/tmp/l3d/cert.pem", sys.argv[5] if len(sys.argv) > 5 else "/tmp/l3d/key.pem"
CTX = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
CTX.load_cert_chain(CERT, KEY)


class Conn:
    def __init__(self, sock, starttls_ok):
        self.s = sock
        self.f = sock.makefile("rwb", buffering=0)
        self.starttls_ok = starttls_ok

    def send(self, t):
        self.f.write((t + "\r\n").encode())

    def line(self):
        l = self.f.readline()
        return l.decode(errors="replace").rstrip("\r\n") if l else None


def handle(sock, implicit, starttls_ok):
    try:
        if implicit:
            sock = CTX.wrap_socket(sock, server_side=True)
        c = Conn(sock, starttls_ok)
        c.send("220 fake.smtp ESMTP")
        authed, tls, mailfrom, rcpt = False, implicit, None, []
        while True:
            l = c.line()
            if l is None:
                return
            u = l.upper()
            if u.startswith("EHLO") or u.startswith("HELO"):
                c.send("250-fake.smtp")
                if starttls_ok and not tls:
                    c.send("250-STARTTLS")
                c.send("250 AUTH LOGIN PLAIN")
            elif u == "STARTTLS" and starttls_ok and not tls:
                c.send("220 go")
                sock = CTX.wrap_socket(sock, server_side=True)
                c = Conn(sock, starttls_ok)
                tls = True
            elif u == "AUTH LOGIN":
                c.send("334 VXNlcm5hbWU6")
                user = base64.b64decode(c.line() or "").decode()
                c.send("334 UGFzc3dvcmQ6")
                pw = base64.b64decode(c.line() or "").decode()
                if user == USER and pw == PASS:
                    authed = True
                    c.send("235 ok")
                else:
                    c.send("535 auth failed")
            elif u.startswith("MAIL FROM:"):
                if not authed:
                    c.send("530 auth required")
                    continue
                mailfrom = l[10:].strip().strip("<>")
                c.send("250 ok")
            elif u.startswith("RCPT TO:"):
                rcpt.append(l[8:].strip().strip("<>"))
                c.send("250 ok")
            elif u == "DATA":
                c.send("354 end with .")
                data = []
                while True:
                    d = c.line()
                    if d is None or d == ".":
                        break
                    data.append(d[1:] if d.startswith("..") else d)
                raw = "\r\n".join(data) + "\r\n"
                msg = email.message_from_string(raw, policy=policy.default)
                body = msg.get_body(preferencelist=("plain",))
                rec = {"from": mailfrom, "to": rcpt, "subject": str(msg["Subject"]), "text": body.get_content() if body else "",
                       "hdr_from": str(msg["From"]), "tls": tls, "at": time.time(), "reply_to": str(msg["Reply-To"] or "")}
                with open(OUT, "a") as fh:
                    fh.write(json.dumps(rec, ensure_ascii=False) + "\n")
                c.send("250 queued")
                rcpt = []
            elif u == "QUIT":
                c.send("221 bye")
                return
            else:
                c.send("250 ok")
    except Exception:
        pass
    finally:
        try:
            sock.close()
        except Exception:
            pass


def serve(port, implicit, starttls_ok):
    srv = socket.socket()
    srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    srv.bind(("127.0.0.1", port))
    srv.listen(20)
    while True:
        s, _ = srv.accept()
        threading.Thread(target=handle, args=(s, implicit, starttls_ok), daemon=True).start()


if __name__ == "__main__":
    pa, pb = int(sys.argv[1]), int(sys.argv[2])
    threading.Thread(target=serve, args=(pa, True, False), daemon=True).start()
    serve(pb, False, True)
