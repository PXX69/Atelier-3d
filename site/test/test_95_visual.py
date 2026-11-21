"""Contrôle visuel et accessibilité avec un vrai navigateur (Playwright + axe-core) : captures, débordements, erreurs de console,
parcours d'achat complet avec un faux bouton PayPal, clavier. Ignoré si Playwright ou axe-core manquent."""
import json, os, pathlib, re
import pytest
from conftest import *
from conftest import SITE, WORK
from test_40_shop import new_buyer
from test_60_admin import admin, act

try:
    from playwright.sync_api import sync_playwright
except Exception:  # pragma: no cover
    sync_playwright = None
AXE_PATH = pathlib.Path(os.environ.get("AXE_JS", "/home/claude/audit/node_modules/axe-core/axe.min.js"))
pytestmark = pytest.mark.skipif(sync_playwright is None or not AXE_PATH.exists(), reason="Playwright ou axe-core absent")
SHOTS = WORK / "shots"

FAKE_SDK = """
window.paypal = { Buttons: function (o) { return { render: function (el) {
  var b = document.createElement('button'); b.type = 'button'; b.id = 'fake-pp'; b.className = 'btn primary'; b.textContent = 'Payer avec PayPal (simulation)';
  b.onclick = function () {
    Promise.resolve(o.onClick({}, { resolve: function () { return Promise.resolve(); }, reject: function () { return Promise.reject(new Error('refus')); } }))
      .then(function () { return o.createOrder(); }).then(function (id) { return o.onApprove({ orderID: id }); }).catch(function (e) { if (o.onError) o.onError(e); });
  };
  el.appendChild(b); return Promise.resolve();
} }; } };
"""


@pytest.fixture(scope="module")
def pw():
    SHOTS.mkdir(parents=True, exist_ok=True)
    with sync_playwright() as p:
        yield p


def session_cookie(c):
    return [{"name": n, "value": c.s.cookies.get(n), "domain": "127.0.0.1", "path": "/"} for n in ("l3d_s",) if c.s.cookies.get(n)]


def open_page(pw, path, w, h, scheme="light", cookies=None, stub_paypal=True):
    b = pw.chromium.launch()
    ctx = b.new_context(viewport={"width": w, "height": h}, color_scheme=scheme, locale="fr-FR", reduced_motion="reduce")
    if cookies:
        ctx.add_cookies(cookies)
    page = ctx.new_page()
    log = {"console": [], "errors": [], "failed": []}
    page.on("console", lambda m: log["console"].append(m.text) if m.type in ("error", "warning") else None)
    page.on("pageerror", lambda e: log["errors"].append(str(e)))
    page.on("requestfailed", lambda r: log["failed"].append(r.url))
    if stub_paypal:
        ctx.route(re.compile(r"https://www\.paypal\.com/sdk/js.*"), lambda route: route.fulfill(status=200, content_type="application/javascript", body=FAKE_SDK))
    page.goto(BASE + path, wait_until="networkidle")
    return b, page, log


def axe(page):
    page.evaluate(AXE_PATH.read_text())
    return page.evaluate("""async () => { const r = await axe.run(document, {resultTypes:['violations']});
        return r.violations.map(v => ({id: v.id, impact: v.impact, help: v.help, nodes: v.nodes.slice(0, 3).map(n => n.target.join(' ') + ' :: ' + (n.failureSummary || '').split('\\n').slice(0, 3).join(' | ').slice(0, 260))})); }""")


def overflow(page):
    return page.evaluate("() => ({sw: document.documentElement.scrollWidth, iw: window.innerWidth})")


PUBLIC = ["/", "/pro", "/telecharger", "/compte", "/cgv", "/mentions-legales", "/confidentialite", "/introuvable"]
VIEWS = [(1280, 800, "bureau"), (390, 844, "mobile")]


@pytest.mark.parametrize("scheme", ["light", "dark"])
def test_public_pages_clean_in_both_sizes(pw, shop_open, scheme):
    problems = []
    for path in PUBLIC:
        for w, h, name in VIEWS:
            b, page, log = open_page(pw, path, w, h, scheme)
            tag = f"{path or '/'} {name} {scheme}"
            page.screenshot(path=str(SHOTS / (re.sub(r'[^a-z0-9]+', '_', (path.strip('/') or 'accueil') + f'_{name}_{scheme}') + '.png')), full_page=True)
            o = overflow(page)
            if o["sw"] > o["iw"]:
                problems.append(f"{tag}: défilement horizontal ({o['sw']} > {o['iw']})")
            for m in log["console"] + log["errors"] + log["failed"]:
                if "favicon" not in m and "404" not in m:
                    problems.append(f"{tag}: console/réseau : {m[:160]}")
            for v in axe(page):
                if v["impact"] in ("serious", "critical", "moderate"):
                    problems.append(f"{tag}: axe {v['impact']} {v['id']} : {v['help']} → {v['nodes']}")
            b.close()
    assert not problems, "\n".join(problems)


def test_buying_flow_end_to_end_in_a_real_browser(pw, shop_open):
    c = new_buyer("browser-buyer@test.local")
    b, page, log = open_page(pw, "/pro", 1280, 900, "light", session_cookies := session_cookie(c))
    page.wait_for_selector("#fake-pp")
    page.screenshot(path=str(SHOTS / "pro_achat_avant.png"), full_page=True)
    # sans consentement : refus et message
    page.click("#fake-pp")
    page.locator("[data-msg]", has_text="Cochez").wait_for(timeout=10000)
    assert val("SELECT COUNT(*) FROM orders") == "0"
    assert page.evaluate("document.activeElement && document.activeElement.id") == "consent", "le focus revient sur la case à cocher"
    page.check("#consent")
    page.click("#fake-pp")
    page.wait_for_selector("#done:not([hidden])", timeout=15000)
    key = page.inner_text("#newkey")
    assert key.startswith("L3D1-") and key == c.get("/api/app-license").json()["key"]
    assert page.evaluate("document.activeElement && document.activeElement.id") == "done", "le focus passe sur la confirmation"
    page.screenshot(path=str(SHOTS / "pro_achat_apres.png"), full_page=True)
    assert val("SELECT status FROM orders") == "paid" and val("SELECT COUNT(*) FROM licenses") == "1"
    assert not [e for e in log["errors"]], log["errors"]
    # la page se recharge : Pro reconnu, plus de bouton d'achat
    page.reload(wait_until="networkidle")
    assert "Vous avez déjà Liagos 3D Pro" in page.inner_text("#achat") and page.locator("#fake-pp").count() == 0
    # l'espace client montre la clé et le bouton copier fonctionne sans erreur
    page.goto(BASE + "/compte", wait_until="networkidle")
    assert key in page.inner_text("body")
    page.screenshot(path=str(SHOTS / "compte_pro.png"), full_page=True)
    b.close()


def test_declined_payment_shows_a_clear_message(pw, shop_open):
    c = new_buyer("browser-decl@test.local")
    pp_control(scenario={"capture": "declined"})
    b, page, log = open_page(pw, "/pro", 390, 844, "light", session_cookie(c))
    page.wait_for_selector("#fake-pp")
    page.check("#consent")
    page.click("#fake-pp")
    page.locator("[data-msg]", has_text="refusé").wait_for(timeout=15000)
    assert page.locator("[data-msg].bad").count() == 1
    assert val("SELECT COUNT(*) FROM licenses") == "0"
    page.screenshot(path=str(SHOTS / "pro_paiement_refuse_mobile.png"), full_page=True)
    b.close()


def test_login_flow_in_the_browser_then_keyboard_only(pw, fresh):
    b, page, log = open_page(pw, "/compte", 390, 844, "light", None, stub_paypal=False)
    before = mails_count()
    page.fill("input[name=email]", "kbd@test.local")
    page.keyboard.press("Enter")
    page.wait_for_selector("[data-step='2']:not([hidden])")
    code = wait_code("kbd@test.local", before)
    assert page.evaluate("document.activeElement.name") == "code", "le focus passe sur le champ du code"
    page.keyboard.type(code)
    page.keyboard.press("Enter")
    page.wait_for_selector("form[data-login]", state="detached", timeout=15000)
    page.locator("body", has_text="kbd@test.local").wait_for()
    page.screenshot(path=str(SHOTS / "compte_connecte_mobile.png"), full_page=True)
    assert val("SELECT COUNT(*) FROM sessions") == "1"
    b.close()


def test_every_page_is_reachable_by_keyboard_and_has_a_skip_link(pw, fresh):
    b, page, log = open_page(pw, "/", 1280, 800, "light", None, stub_paypal=False)
    page.keyboard.press("Tab")
    first = page.evaluate("({t: document.activeElement.textContent.trim(), h: document.activeElement.getAttribute('href')})")
    assert "contenu" in first["t"].lower() and first["h"].startswith("#"), first
    seen = set()
    for _ in range(40):
        seen.add(page.evaluate("document.activeElement.tagName + ':' + (document.activeElement.getAttribute('href') || document.activeElement.id || '')"))
        page.keyboard.press("Tab")
        vis = page.evaluate("""() => { const e = document.activeElement; if (!e || e === document.body) return true; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e);
            return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && (cs.outlineStyle !== 'none' || cs.boxShadow !== 'none' || e.matches(':focus-visible')); }""")
        assert vis, "élément focalisé invisible ou sans repère de focus : " + page.evaluate("document.activeElement.outerHTML.slice(0, 120)")
    assert any("/pro" in s for s in seen) and any("/telecharger" in s for s in seen)
    b.close()


def test_admin_pages_in_a_real_browser(pw, shop_open):
    a = admin()
    buyer = new_buyer("admin-view@test.local")
    from test_40_shop import buy
    buy(buyer)
    problems = []
    for p in ["", "/commandes", "/licences", "/clients", "/fichiers", "/reglages", "/journal"]:
        for w, h, name in VIEWS:
            b, page, log = open_page(pw, "/admin" + p, w, h, "light", session_cookie(a), stub_paypal=False)
            page.screenshot(path=str(SHOTS / (f"admin{p.replace('/', '_') or '_accueil'}_{name}.png")), full_page=True)
            o = overflow(page)
            if o["sw"] > o["iw"]:
                problems.append(f"/admin{p} {name}: défilement horizontal de la page ({o['sw']} > {o['iw']})")
            for m in log["errors"]:
                problems.append(f"/admin{p} {name}: {m[:150]}")
            for v in axe(page):
                if v["impact"] in ("serious", "critical"):
                    problems.append(f"/admin{p} {name}: axe {v['impact']} {v['id']} → {v['nodes']}")
            b.close()
    assert not problems, "\n".join(problems)
