"""Harnais d'essai du site Liagos 3D : vraie base MariaDB, faux PayPal, faux serveur SMTP, serveur PHP intégré.
Lancement : cd site/test && python3 -m pytest -x -q   (MariaDB doit tourner sur 127.0.0.1:3307, base « liagos »)."""
import json, os, pathlib, re, shutil, subprocess, sys, time, hashlib, base64, socket
import pytest, requests

HERE = pathlib.Path(__file__).resolve().parent
ROOT = HERE.parent
WORK = pathlib.Path(os.environ.get("L3D_WORK", "/tmp/l3d"))
SITE = WORK / "site"
WEB, PP, SSL_PORT, TLS_PORT = 8088, 8099, 4465, 4587
BASE = f"http://127.0.0.1:{WEB}"
PPBASE = f"http://127.0.0.1:{PP}"
MAILS = WORK / "mails.jsonl"
DB = dict(host="127.0.0.1", port="3307", name="liagos", user="liagos", pw="testpw")
ADMIN = "admin@test.local"


def sql(q, *, raw=False):
    r = subprocess.run(["mysql", f"-h{DB['host']}", f"-P{DB['port']}", f"-u{DB['user']}", f"-p{DB['pw']}", DB["name"], "-N", "-B", "-e", q],
                       capture_output=True, text=True)
    if r.returncode != 0:
        raise RuntimeError(r.stderr)
    return r.stdout if raw else [l.split("\t") for l in r.stdout.splitlines() if l != ""]


def val(q):
    rows = sql(q)
    return rows[0][0] if rows else None


def wait_port(port, timeout=15):
    t = time.time() + timeout
    while time.time() < t:
        try:
            socket.create_connection(("127.0.0.1", port), 0.3).close()
            return
        except OSError:
            time.sleep(0.15)
    raise RuntimeError(f"port {port} ne répond pas")


def php_json(code):
    r = subprocess.run(["php", "-r", code], capture_output=True, text=True, cwd=SITE)
    if r.returncode != 0:
        raise RuntimeError(r.stderr or r.stdout)
    return r.stdout


def write_config(cfg):
    tmp = WORK / "cfg.json"
    tmp.write_text(json.dumps(cfg))
    out = php_json("echo \"<?php\\nreturn \" . var_export(json_decode(file_get_contents('%s'), true), true) . \";\\n\";" % tmp)
    (SITE / "config.php").write_text(out)


def read_config():
    return json.loads(php_json("echo json_encode(require 'config.php');"))


@pytest.fixture(scope="session")
def env():
    if WORK.exists():
        shutil.rmtree(WORK)
    WORK.mkdir(parents=True)
    # copie propre du site (sans config, sans essais lourds)
    shutil.copytree(ROOT, SITE, ignore=shutil.ignore_patterns("config.php", "__pycache__", "*.sqlite", ".pytest_cache", "app.log*", "outbox.jsonl"))
    for d in ("storage/keys", "storage/app", "storage/logs", "public/downloads"):
        (SITE / d).mkdir(parents=True, exist_ok=True)
    # clé de licence d'essai + clé publique correspondante dans keys.php
    pem = SITE / "storage/keys/license-private.pem"
    subprocess.run(["openssl", "ecparam", "-name", "prime256v1", "-genkey", "-noout", "-out", str(pem)], check=True, capture_output=True)
    pub = php_json("require 'src/core.php'; require 'src/license.php'; putenv('L3D_CONFIG=/nonexistent'); "
                   "$d = openssl_pkey_get_details(openssl_pkey_get_private(file_get_contents('storage/keys/license-private.pem'))); "
                   "echo b64u(\"\\x04\" . str_pad($d['ec']['x'], 32, \"\\0\", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, \"\\0\", STR_PAD_LEFT));")
    (SITE / "src/keys.php").write_text("<?php\nreturn ['public' => '%s'];\n" % pub.strip())
    (SITE / "storage/app/index.html").write_text("<!doctype html><title>app d'essai</title><p id=app>APP-TEST</p>")
    # certificat pour le faux SMTP
    subprocess.run(["openssl", "req", "-x509", "-newkey", "ec", "-pkeyopt", "ec_paramgen_curve:prime256v1", "-nodes", "-keyout", str(WORK / "key.pem"),
                    "-out", str(WORK / "cert.pem"), "-days", "2", "-subj", "/CN=localhost"], check=True, capture_output=True)
    # base vide
    tables = [r[0] for r in sql("SHOW TABLES")]
    if tables:
        sql("SET FOREIGN_KEY_CHECKS=0; " + "; ".join(f"DROP TABLE `{t}`" for t in tables))
    procs = []
    procs.append(subprocess.Popen([sys.executable, str(HERE / "fake_paypal.py"), str(PP)], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL))
    procs.append(subprocess.Popen([sys.executable, str(HERE / "fake_smtp.py"), str(SSL_PORT), str(TLS_PORT), str(MAILS), str(WORK / "cert.pem"), str(WORK / "key.pem")],
                                  stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL))
    e = dict(os.environ, PHP_CLI_SERVER_WORKERS="4")
    procs.append(subprocess.Popen(["php", "-d", "opcache.enable=0", "-S", f"127.0.0.1:{WEB}", "-t", str(SITE / "public"), str(SITE / "test/router.php")], cwd=SITE, env=e,
                                  stdout=open(WORK / "php.log", "w"), stderr=subprocess.STDOUT))
    for p in (PP, SSL_PORT, WEB):
        wait_port(p)
    yield dict(site=SITE, pem=pem, pub=pub.strip())
    for p in procs:
        p.terminate()
    for p in procs:
        try:
            p.wait(5)
        except Exception:
            p.kill()


def install_code(pem_path):
    return hashlib.sha256(pathlib.Path(pem_path).read_bytes()).hexdigest()[:10].upper()


@pytest.fixture(scope="session")
def installed(env):
    """Installe le site par le formulaire (c'est aussi le test de l'installateur), puis le branche sur les faux services."""
    code = install_code(env["pem"])
    r = requests.post(BASE + "/install.php", data={
        "code": code[:5] + "-" + code[5:], "site_url": BASE, "admins": ADMIN, "price": "9,99",
        "db_host": DB["host"], "db_port": DB["port"], "db_name": DB["name"], "db_user": DB["user"], "db_pass": DB["pw"],
        "mail_host": "", "pp_env": "sandbox", "pp_client": "test-client", "pp_secret": "test-secret", "pp_webhook": "WH-TEST",
        "lg_editeur": "Éditeur d'essai", "lg_adresse": "1 rue d'essai", "lg_contact_email": "contact@test.local"})
    assert "Installation terminée" in r.text, r.text[:2000]
    cfg = read_config()
    cfg["mail"].update(transport="smtp", host="127.0.0.1", port=SSL_PORT, secure="ssl", user="boutique@test.local", **{"pass": "mailpw"},
                       **{"from": "boutique@test.local", "reply_to": "contact@test.local", "verify_peer": False})
    cfg["paypal"].update(api_base=PPBASE, env="sandbox")
    write_config(cfg)
    return cfg


class Client:
    """Navigateur minimal : cookies + CSRF."""
    def __init__(self):
        self.s = requests.Session()
        self.csrf = None
        self.email = None

    def get(self, p, **k):
        return self.s.get(BASE + p, allow_redirects=k.pop("allow_redirects", False), **k)

    def post(self, p, data=None, **k):
        h = {"Content-Type": "application/json", "Origin": BASE}
        if self.csrf:
            h["X-CSRF-Token"] = self.csrf
        h.update(k.pop("headers", {}))
        return self.s.post(BASE + p, data=json.dumps(data if data is not None else {}), headers=h, allow_redirects=False, **k)

    def form(self, p, data, files=None):
        d = dict(data)
        d.setdefault("csrf", self.csrf)
        return self.s.post(BASE + p, data=d, files=files, allow_redirects=False, headers={"Origin": BASE})

    def me(self):
        return self.get("/api/me").json()["user"]

    def login(self, email):
        before = mails_count()
        r = self.post("/api/auth/request", {"email": email})
        assert r.status_code == 200, r.text
        code = wait_code(email, before)
        r = self.post("/api/auth/verify", {"email": email, "code": code})
        assert r.status_code == 200, r.text
        self.csrf = r.json()["csrf"]
        self.email = email
        return self


def read_mails():
    if not MAILS.exists():
        return []
    return [json.loads(l) for l in MAILS.read_text().splitlines() if l.strip()]


def mails_count():
    return len(read_mails())


def wait_code(email, before, timeout=8):
    t = time.time() + timeout
    while time.time() < t:
        for m in read_mails()[before:]:
            if email in m["to"]:
                mm = re.search(r"code de connexion : (\d{6})", m["text"])
                if mm:
                    return mm.group(1)
        time.sleep(0.15)
    raise AssertionError("aucun e-mail de code reçu pour " + email)


def wait_mail(pred, before, timeout=8):
    t = time.time() + timeout
    while time.time() < t:
        for m in read_mails()[before:]:
            if pred(m):
                return m
        time.sleep(0.15)
    return None


def pp_control(**d):
    requests.post(PPBASE + "/__control", json=d)


def pp_calls():
    return requests.get(PPBASE + "/__calls").json()


@pytest.fixture()
def fresh(installed):
    """Remet à zéro les limiteurs et le faux PayPal avant chaque essai."""
    sql("DELETE FROM rate_limits; DELETE FROM licenses; DELETE FROM orders; DELETE FROM sessions; DELETE FROM login_codes; DELETE FROM users; DELETE FROM webhook_events; DELETE FROM audit")
    pp_control(reset=True)
    return installed


@pytest.fixture()
def shop_open(fresh):
    sql("REPLACE INTO settings(k, v) VALUES ('payments_enabled','1'),('price_cents','999'),('vat_default_bp','2000'),('vat_map','{\"DE\":1900}')")
    yield
    sql("REPLACE INTO settings(k, v) VALUES ('payments_enabled','0')")
