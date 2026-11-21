"""Règles .htaccess vérifiées avec un vrai Apache + PHP (comme sur l'hébergement) : HTTPS forcé, dossiers privés, fichiers sensibles,
cache, cookies sécurisés. Ignoré si Apache n'est pas installé (apt install apache2 libapache2-mod-php)."""
import os, pathlib, re, shutil, subprocess, time
import pytest, requests
import urllib3
from conftest import *
from conftest import SITE, WORK

urllib3.disable_warnings()
MODS = "/usr/lib/apache2/modules"
A = "http://127.0.0.1:8090"      # racine web = site/public (cas IONOS : domaine relié au dossier /public)
A_TLS = "https://127.0.0.1:8453"
B = "http://127.0.0.1:8091"      # racine web = site/ (cas du domaine relié à la racine : .htaccess de la racine)
B_TLS = "https://127.0.0.1:8454"
P443 = "http://127.0.0.1:443"    # HTTP simple sur le port 443 (comportement d'un frontal qui ne transmet aucun en-tête)

pytestmark = pytest.mark.skipif(not shutil.which("apache2"), reason="Apache non installé")


@pytest.fixture(scope="module")
def apache(installed):
    ap = WORK / "apache"
    ap.mkdir(exist_ok=True)
    mods = ["mpm_prefork", "authz_core", "dir", "mime", "alias", "rewrite", "headers", "env", "setenvif", "ssl", "log_config", "unixd", "authz_host", "access_compat"]
    load = "\n".join(f"LoadModule {m}_module {MODS}/mod_{m}.so" for m in mods if (pathlib.Path(MODS) / f"mod_{m}.so").exists())
    vh = lambda port, root, tls: f"""
<VirtualHost 127.0.0.1:{port}>
    DocumentRoot "{root}"
    {'SSLEngine on' if tls else ''}
    {f'SSLCertificateFile "{WORK}/cert.pem"' if tls else ''}
    {f'SSLCertificateKeyFile "{WORK}/key.pem"' if tls else ''}
    <Directory "{root}">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>"""
    conf = f"""ServerRoot "{ap}"
ServerName localhost
PidFile "{ap}/apache.pid"
ErrorLog "{ap}/error.log"
LogLevel warn
User www-data
Group www-data
Listen 127.0.0.1:8090
Listen 127.0.0.1:8091
Listen 127.0.0.1:8453
Listen 127.0.0.1:8454
Listen 127.0.0.1:443
{load}
LoadModule php_module {MODS}/libphp8.3.so
TypesConfig /etc/mime.types
DirectoryIndex index.php index.html
<FilesMatch "\\.php$">
    SetHandler application/x-httpd-php
</FilesMatch>
<Directory />
    AllowOverride None
    Require all denied
</Directory>
SSLRandomSeed startup builtin
{vh(8090, SITE / 'public', False)}
{vh(8453, SITE / 'public', True)}
{vh(8091, SITE, False)}
{vh(8454, SITE, True)}
{vh(443, SITE / 'public', False)}
"""
    (ap / "apache.conf").write_text(conf)
    # www-data doit pouvoir lire le site et écrire dans storage/
    subprocess.run(["chmod", "-R", "a+rX", str(WORK)], check=True)
    subprocess.run(["chown", "-R", "www-data:www-data", str(SITE / "storage"), str(SITE / "public/downloads"), str(ap)], check=True)
    for d in (WORK, SITE):
        os.chmod(d, 0o755)
    r = subprocess.run(["apache2", "-f", str(ap / "apache.conf"), "-k", "start"], capture_output=True, text=True)
    assert r.returncode == 0, r.stderr
    for port in (8090, 8091, 8453, 8454, 443):
        wait_port(port, 20)
    yield ap
    subprocess.run(["apache2", "-f", str(ap / "apache.conf"), "-k", "stop"], capture_output=True)
    time.sleep(0.5)
    subprocess.run(["chown", "-R", "root:root", str(SITE / "storage"), str(SITE / "public/downloads")])


def get(url, **k):
    k.setdefault("allow_redirects", False)
    k.setdefault("verify", False)
    k.setdefault("timeout", 15)
    return requests.get(url, **k)


# ---------------------------------------------------------------- HTTPS
@pytest.mark.parametrize("root", [A, B])
def test_http_is_redirected_to_https(apache, root):
    r = get(root + "/compte?x=1")
    assert r.status_code == 301 and r.headers["Location"].startswith("https://127.0.0.1:") and r.headers["Location"].endswith("/compte?x=1")


@pytest.mark.parametrize("root", [A, B])
def test_no_redirect_loop_behind_a_proxy_header_or_on_port_443(apache, root):
    r = get(root + "/healthz", headers={"X-Forwarded-Proto": "https"})
    assert r.status_code == 200 and r.text == "ok"
    r = get(P443 + "/healthz")
    assert r.status_code == 200 and r.text == "ok", "sur le port 443, jamais de redirection (évite une boucle)"


@pytest.mark.parametrize("tls", [A_TLS, B_TLS])
def test_https_pages_and_security_headers(apache, tls):
    r = get(tls + "/")
    assert r.status_code == 200 and "Liagos 3D" in r.text
    assert r.headers["Strict-Transport-Security"].startswith("max-age=")
    assert r.headers["X-Content-Type-Options"] == "nosniff" and r.headers["X-Frame-Options"] == "DENY"
    assert "default-src 'self'" in r.headers["Content-Security-Policy"] and "frame-ancestors 'none'" in r.headers["Content-Security-Policy"]
    assert "X-Powered-By" not in r.headers, "la version de PHP n'est pas annoncée"
    for p in ("/pro", "/telecharger", "/compte", "/mentions-legales", "/cgv", "/confidentialite"):
        assert get(tls + p).status_code == 200, p
    assert get(tls + "/introuvable").status_code == 404
    assert get(tls + "/index.php/x").status_code == 404


def test_secure_session_cookie_over_https_and_port_443(apache):
    for base in (A_TLS, P443):
        before = mails_count()
        email = f"cookie-{abs(hash(base)) % 9999}@test.local"
        s = requests.Session()
        h = {"Content-Type": "application/json", "Origin": base}
        assert s.post(base + "/api/auth/request", json={"email": email}, headers=h, verify=False).status_code == 200
        code = wait_code(email, before)
        r = s.post(base + "/api/auth/verify", json={"email": email, "code": code}, headers=h, verify=False)
        assert r.status_code == 200, r.text
        ck = r.headers["Set-Cookie"]
        assert ck.startswith("__Host-l3d_s="), ck
        low = ck.lower()
        assert "secure" in low and "httponly" in low and "samesite=lax" in low and "path=/" in low and "domain=" not in low, "attributs exigés par le préfixe __Host-"
        assert r.headers["Strict-Transport-Security"].startswith("max-age=")


# ---------------------------------------------------------------- dossiers privés
@pytest.mark.parametrize("p", ["/config.php", "/config.sample.php", "/src/core.php", "/src/keys.php", "/storage/keys/license-private.pem", "/storage/logs/app.log",
                               "/storage/app/index.html", "/tools/cles-licence.html", "/test/conftest.py", "/LISEZMOI.md", "/.htaccess", "/.git/config", "/.env",
                               "/public/../config.php", "/%2e%2e/config.php", "/src/", "/storage/"])
def test_private_files_are_not_served_when_the_domain_points_at_the_site_root(apache, p):
    r = get(B_TLS + p)
    assert r.status_code in (400, 403, 404), (p, r.status_code)
    assert "<?php" not in r.text and "PRIVATE KEY" not in r.text


def test_the_site_still_works_from_the_root_variant(apache):
    r = get(B_TLS + "/")
    assert r.status_code == 200 and "Liagos 3D" in r.text
    assert get(B_TLS + "/css/site.css").status_code == 200 and get(B_TLS + "/healthz").text == "ok"
    assert get(B_TLS + "/api/me").json() == {"user": None}


@pytest.mark.parametrize("p", ["/config.php", "/src/core.php", "/storage/logs/app.log", "/tools/cles-licence.html", "/test/conftest.py", "/LISEZMOI.md",
                               "/.htaccess", "/.git/HEAD", "/.env", "/storage/keys/license-private.pem"])
def test_private_files_are_unreachable_when_the_domain_points_at_public(apache, p):
    r = get(A_TLS + p)
    assert r.status_code in (403, 404), (p, r.status_code)
    assert "<?php" not in r.text and "PRIVATE KEY" not in r.text


def test_sensitive_extensions_inside_public_are_denied(apache):
    pub = SITE / "public"
    names = ["leak.log", "dump.sql", "data.sqlite", "notes.md", "k.pem", "old.bak", "events.jsonl", "x.db"]
    for n in names:
        (pub / n).write_text("SECRET-CONTENT")
    try:
        for n in names:
            r = get(A_TLS + "/" + n)
            assert r.status_code == 403 and "SECRET-CONTENT" not in r.text, n
        (pub / ".hidden").write_text("SECRET-CONTENT")
        assert get(A_TLS + "/.hidden").status_code == 403
        (pub / ".well-known").mkdir(exist_ok=True)
        (pub / ".well-known/security.txt").write_text("Contact: mailto:contact@test.local\n")
        assert get(A_TLS + "/.well-known/security.txt").status_code == 200, "le dossier .well-known reste accessible (certificats, sécurité)"
    finally:
        for n in names + [".hidden"]:
            (pub / n).unlink(missing_ok=True)
        shutil.rmtree(pub / ".well-known", ignore_errors=True)


# ---------------------------------------------------------------- téléchargements
def test_downloads_folder_is_safe(apache):
    d = SITE / "public/downloads"
    (d / "Liagos-3D.apk").write_bytes(b"PK\x03\x04apk")
    (d / "setup.exe").write_bytes(b"MZ")
    (d / "evil.php").write_text("<?php echo 'EXECUTED';")
    try:
        assert get(A_TLS + "/downloads/").status_code in (403, 404), "pas de liste des fichiers"
        r = get(A_TLS + "/downloads/Liagos-3D.apk")
        assert r.status_code == 200 and r.content == b"PK\x03\x04apk"
        assert r.headers["Content-Type"] == "application/vnd.android.package-archive" and r.headers["Content-Disposition"] == "attachment" and r.headers["X-Content-Type-Options"] == "nosniff"
        r = get(A_TLS + "/downloads/setup.exe")
        assert r.status_code == 200 and r.headers["Content-Type"] == "application/octet-stream"
        r = get(A_TLS + "/downloads/evil.php")
        assert r.status_code in (403, 404) and "EXECUTED" not in r.text, "un script déposé dans downloads ne s'exécute jamais"
        dl = get(A_TLS + "/telecharger").text
        assert "Liagos-3D.apk" in dl
    finally:
        for f in d.glob("*"):
            if f.name not in (".htaccess", ".gitignore", ".gitkeep"):
                f.unlink()


# ---------------------------------------------------------------- cache
def test_static_asset_cache_headers(apache):
    page = get(A_TLS + "/").text
    css = re.search(r'href="(/css/site\.css\?v=\d+)"', page)
    assert css, "feuille de style versionnée (?v=date)"
    r = get(A_TLS + css.group(1))
    assert r.status_code == 200 and "immutable" in r.headers["Cache-Control"] and "max-age=31536000" in r.headers["Cache-Control"]
    r = get(A_TLS + "/fonts/archivo-latin.woff2")
    assert r.status_code == 200 and "immutable" in r.headers["Cache-Control"]
    r = get(A_TLS + "/img/icon-192.png")
    assert r.status_code == 200 and r.headers["Content-Type"] == "image/png" and "max-age=604800" in r.headers["Cache-Control"]
    assert "no-cache" in get(A_TLS + "/").headers["Cache-Control"], "les pages ne restent jamais en cache périmé"
    r = get(A_TLS + "/app/manifest.webmanifest")
    assert r.status_code == 200 and r.headers["Content-Type"].startswith("application/manifest+json") and r.json()["start_url"] == "/app/"


# ---------------------------------------------------------------- erreurs
def test_php_errors_are_never_shown(apache):
    # une requête qui déclenche une erreur d'API ne montre ni chemin ni trace
    r = requests.post(A_TLS + "/api/paypal/webhook", data="{broken", headers={"Content-Type": "application/json"}, verify=False)
    assert r.status_code in (400, 503) and "/tmp/" not in r.text and "Stack trace" not in r.text and "Warning" not in r.text
    r = get(A_TLS + "/api/me", headers={"Cookie": "__Host-l3d_s=" + "x" * 5000})
    assert r.status_code == 200 and "Warning" not in r.text and "Notice" not in r.text
    r = get(A_TLS + "/?" + "a[]=1&" * 3000)
    assert r.status_code in (200, 414) and "/tmp/" not in r.text
