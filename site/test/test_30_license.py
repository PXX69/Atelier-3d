"""Clés de licence : frappe, vérification PHP, vérification croisée WebCrypto (comme l'application), états en base."""
import json, subprocess, textwrap, time
from conftest import *
from conftest import SITE

EPOCH = 1767225600


def php_run(code, cfgpath=None):
    env = dict(os.environ)
    if cfgpath:
        env["L3D_CONFIG"] = str(cfgpath)
    r = subprocess.run(["php", "-r", "require 'src/core.php'; require 'src/license.php'; " + code], capture_output=True, text=True, cwd=SITE, env=env)
    assert r.returncode == 0, r.stderr + r.stdout
    return r.stdout


def node_verify(pub, keys):
    r = subprocess.run(["node", str(HERE / "verify_key.mjs"), pub, *keys], capture_output=True, text=True)
    assert r.returncode == 0, r.stderr
    return json.loads(r.stdout)


def mint(n=1, last=None):
    arg = "null" if last is None else str(last)
    return json.loads(php_run(f"$o=[]; for($i=0;$i<{n};$i++) $o[]=License::mint(1,{arg}); echo json_encode($o);"))


def test_unlimited_key_roundtrip(installed, env):
    k = mint()[0]
    assert k["key"].startswith("L3D1-") and k["expires_at"] is None and len(k["key_id"]) == 16
    v = json.loads(php_run(f"echo json_encode(License::verify('{k['key']}'));"))
    assert v == {"version": 1, "edition": 1, "days": 0}
    assert node_verify(env["pub"], [k["key"]]) == [{"edition": 1, "days": 0}]


def test_dated_key_semantics(installed, env):
    last = EPOCH + 100 * 86400 + 5            # un instant du 101e jour -> days = 100
    k = mint(1, last)[0]
    assert k["expires_at"] == EPOCH + 101 * 86400    # exclusif : valable tout le jour 100
    v = json.loads(php_run(f"echo json_encode(License::verify('{k['key']}'));"))
    assert v["days"] == 100
    assert node_verify(env["pub"], [k["key"]]) == [{"edition": 1, "days": 100}]


def test_out_of_range_dates_refused(installed):
    for ts in (EPOCH - 86400, EPOCH + 70000 * 86400):
        r = subprocess.run(["php", "-r", f"require 'src/core.php'; require 'src/license.php'; try {{ License::mint(1, {ts}); }} catch (Throwable $e) {{ echo $e->getMessage(); exit(3); }}"],
                           capture_output=True, text=True, cwd=SITE)
        assert r.returncode == 3 and "hors limites" in r.stdout, (r.returncode, r.stdout, r.stderr)


def test_tampering_is_detected_by_php_and_node(installed, env):
    k = mint()[0]["key"]
    body = k[5:]
    def flip(i):
        c = body[i]
        return k[:5 + i] + ("A" if c != "A" else "B") + body[i + 1:]
    bad = [flip(2), flip(10), flip(30), flip(60), "L3D2-" + body, "l3d1-" + body, k[:-4], k + "AAAA", "", "L3D1-", "L3D1-!!!!"]
    res = json.loads(php_run("$ks=" + json.dumps(bad) + "; echo json_encode(array_map(fn($k) => License::verify($k), $ks));"))
    assert res == [None] * len(bad), res
    assert node_verify(env["pub"], bad) == [None] * len(bad)


def test_whitespace_in_pasted_key_tolerated(installed, env):
    k = mint()[0]["key"]
    pasted = "  " + k[:20] + "\n" + k[20:50] + " \t" + k[50:] + "\r\n"
    assert json.loads(php_run("echo json_encode(License::verify(" + json.dumps(pasted) + "));"))["days"] == 0
    assert node_verify(env["pub"], [pasted])[0]["days"] == 0


def test_key_signed_by_other_private_key_is_refused(installed, env, tmp_path):
    other = tmp_path / "other.pem"
    subprocess.run(["openssl", "ecparam", "-name", "prime256v1", "-genkey", "-noout", "-out", str(other)], check=True, capture_output=True)
    cfg = {"license": {"private_key_path": str(other)}}
    cfile = tmp_path / "other-config.php"
    cfile.write_text("<?php return " + "['license'=>['private_key_path'=>'" + str(other) + "']];")
    k = json.loads(php_run("echo json_encode(License::mint(1, null));", cfgpath=cfile))["key"]
    assert json.loads(php_run(f"echo json_encode(License::verify('{k}'));")) is None
    assert node_verify(env["pub"], [k]) == [None]


def test_many_keys_exercise_der_edge_cases(installed, env):
    keys = [m["key"] for m in mint(400)]
    assert len(set(keys)) == 400
    res_php = json.loads(php_run("$ks=" + json.dumps(keys) + "; echo json_encode(array_map(fn($k) => License::verify($k) !== null, $ks));"))
    assert all(res_php)
    res_node = node_verify(env["pub"], keys)
    assert all(r is not None for r in res_node), "une clé fabriquée par le site est refusée par WebCrypto"


def test_public_key_matches_private_key(installed, env):
    ok = php_run("echo hash_equals(License::expected_public(), License::public_raw_from_private()) ? 'oui' : 'non';")
    assert ok == "oui"
    assert len(b64u_py(env["pub"])) == 65


def b64u_py(s):
    import base64
    return base64.urlsafe_b64decode(s + "=" * (-len(s) % 4))


def test_user_pro_states(fresh):
    c = Client().login("pro1@test.local")
    uid = int(val("SELECT id FROM users WHERE email='pro1@test.local'"))
    assert c.me()["pro"] is False
    now = int(time.time())
    # expirée
    sql(f"INSERT INTO licenses(user_id,key_id,license_key,edition,expires_at,status,source,created_at) VALUES ({uid},'e1','K1',1,{now}-100,'active','test',{now})")
    assert c.me()["pro"] is False, "une licence expirée ne donne pas Pro"
    sql(f"INSERT INTO licenses(user_id,key_id,license_key,edition,expires_at,status,source,created_at) VALUES ({uid},'e2','K2',1,NULL,'revoked','manual',{now})")
    assert c.me()["pro"] is False, "une licence révoquée ne donne pas Pro"
    sql(f"INSERT INTO licenses(user_id,key_id,license_key,edition,expires_at,status,source,created_at) VALUES ({uid},'e3','K3',1,{now}+3600,'active','test',{now})")
    assert c.me()["pro"] is True
    assert c.get("/api/app-license").json()["key"] == "K3"
    sql(f"INSERT INTO licenses(user_id,key_id,license_key,edition,expires_at,status,source,created_at) VALUES ({uid},'e4','K4',1,NULL,'active','manual',{now})")
    assert c.get("/api/app-license").json()["key"] == "K4", "la licence illimitée passe avant la licence datée"
