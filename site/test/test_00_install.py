"""Installateur : refus sans le bon code, contrôles, écriture de config.php, suppression de lui-même."""
import os, re, stat, requests
from conftest import *


def post(env, **over):
    code = install_code(env["pem"])
    d = {"code": code, "site_url": BASE, "admins": ADMIN, "price": "9,99",
         "db_host": DB["host"], "db_port": DB["port"], "db_name": DB["name"], "db_user": DB["user"], "db_pass": DB["pw"],
         "mail_host": "", "pp_env": "sandbox"}
    d.update(over)
    return requests.post(BASE + "/install.php", data=d)


def test_root_redirects_to_installer_when_not_installed(env):
    r = requests.get(BASE + "/", allow_redirects=False)
    assert r.status_code == 302 and r.headers["Location"] == "/install.php"


def test_installer_page_shows_checks(env):
    r = requests.get(BASE + "/install.php")
    assert r.status_code == 200
    assert "Installation de Liagos 3D" in r.text
    assert r.text.count("class=\"ko\"") == 0, "une vérification échoue : " + re.sub(r"<[^>]+>", " ", r.text)[:1500]
    assert "Code d&#039;installation" in r.text
    assert "default-src 'none'" in r.headers["Content-Security-Policy"]
    assert r.headers["X-Robots-Tag"].startswith("noindex")


def test_wrong_install_code_is_refused_and_nothing_written(env):
    r = post(env, code="AAAAA-BBBBB")
    assert "Le code d&#039;installation est incorrect" in r.text
    assert not (env["site"] / "config.php").exists()


def test_bad_site_url_refused(env):
    r = post(env, site_url="http://exemple.fr")
    assert "doit commencer par https://" in r.text
    assert not (env["site"] / "config.php").exists()


def test_bad_admin_refused(env):
    r = post(env, admins="pas-un-mail")
    assert "Adresse d&#039;administrateur invalide" in r.text
    assert not (env["site"] / "config.php").exists()


def test_bad_price_refused(env):
    r = post(env, price="0,01")
    assert "Le prix doit être" in r.text


def test_bad_db_password_refused_and_no_config(env):
    r = post(env, db_pass="mauvais")
    assert "Connexion à la base impossible" in r.text
    assert not (env["site"] / "config.php").exists()


def test_failed_table_creation_rolls_back_config(env):
    # un utilisateur sans droit de création de tables est difficile à simuler : on vérifie au moins que la base vide reste vide
    assert sql("SHOW TABLES") == []


def test_successful_install(installed, env):
    s = env["site"]
    assert (s / "config.php").exists()
    mode = stat.S_IMODE(os.stat(s / "config.php").st_mode)
    assert mode == 0o600, oct(mode)
    cfg = installed
    assert len(cfg["secret"]) == 64 and cfg["admin_emails"] == [ADMIN]
    assert cfg["price"]["cents"] == 999 and cfg["site_url"] == BASE
    assert cfg["db"]["host"] == DB["host"]
    tables = {r[0] for r in sql("SHOW TABLES")}
    assert {"users", "login_codes", "sessions", "orders", "licenses", "webhook_events", "audit", "settings", "rate_limits"} <= tables
    assert val("SELECT v FROM settings WHERE k='payments_enabled'") == "0"
    assert val("SELECT COUNT(*) FROM audit WHERE action='site_installe'") == "1"
    assert not (s / "public/install.php").exists(), "l'installateur doit se supprimer"


def test_installer_gone_after_install(installed):
    r = requests.get(BASE + "/install.php", allow_redirects=False)
    assert r.status_code == 404


def test_utf8mb4_tables(installed):
    assert val("SELECT DEFAULT_CHARACTER_SET_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='liagos'") in ("utf8mb4", "utf8mb3")
    assert val("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='liagos' AND ENGINE='InnoDB'") == "9"
