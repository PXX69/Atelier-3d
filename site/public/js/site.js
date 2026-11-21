/* Liagos 3D : connexion par code, copie de la clé, déconnexion, suppression de compte. */
(function () {
  "use strict";
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var csrf = (($("meta[name=csrf]") || {}).content) || "";
  var TEXT = {
    email_invalide: "Cette adresse e-mail ne semble pas valide.",
    trop_de_demandes: "Trop de tentatives. Patientez quelques minutes, puis réessayez.",
    envoi_impossible: "Nous n'avons pas pu envoyer l'e-mail. Réessayez dans un instant.",
    code_invalide: "Ce code est incorrect ou a expiré. Demandez-en un nouveau.",
    non_connecte: "Votre session a expiré. Reconnectez-vous.",
    csrf: "Votre session a expiré. Rechargez la page.",
    origine: "Demande refusée. Rechargez la page.",
    reseau: "Connexion impossible. Vérifiez votre réseau, puis réessayez."
  };

  /* Envoi JSON : ne rejette jamais, renvoie {status, json}. */
  function post(url, data) {
    return fetch(url, {
      method: "POST", credentials: "same-origin",
      headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
      body: JSON.stringify(data || {})
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) { return { status: r.status, json: j || {} }; });
    }, function () { return { status: 0, json: { error: "reseau" } }; });
  }
  function say(el, text, bad) {
    if (!el) return;
    el.textContent = text || "";
    el.classList.toggle("bad", !!bad);
  }
  function busy(btn, on) {
    if (!btn) return;
    btn.disabled = on;
    btn.setAttribute("aria-busy", on ? "true" : "false");
  }
  window.L3D = { post: post, say: say, busy: busy, text: TEXT };

  var live = document.createElement("div");
  live.className = "sr"; live.setAttribute("role", "status"); live.setAttribute("aria-live", "polite");
  document.body.appendChild(live);

  /* Où aller après la connexion : ?next=/app/ (chemin du site seulement), sinon on recharge la page. */
  function afterLogin() {
    var n = new URLSearchParams(location.search).get("next") || "";
    if (/^\/(?!\/)[A-Za-z0-9\/_.\-]*$/.test(n)) location.href = n; else location.reload();
  }

  document.querySelectorAll("form[data-login]").forEach(function (form) {
    var s1 = $("[data-step='1']", form), s2 = $("[data-step='2']", form), msg = $("[data-msg]", form);
    var mail = $("input[name=email]", form), code = $("input[name=code]", form), email = "";
    function step(n) {
      s1.hidden = n !== 1; s2.hidden = n !== 2;
      (n === 1 ? mail : code).focus();
    }
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      var second = !s2.hidden, btn = $(".step:not([hidden]) button[type=submit]", form);
      if (btn.disabled) return;
      if (!second) {
        email = mail.value.trim();
        if (!/^\S+@\S+\.\S+$/.test(email)) { say(msg, TEXT.email_invalide, true); mail.focus(); return; }
        busy(btn, true); say(msg, "Envoi du code…");
        post("/api/auth/request", { email: email }).then(function (r) {
          busy(btn, false);
          if (r.json.ok) {
            say(msg, "Code envoyé à " + email + ". Il est valable 10 minutes. Pensez aux courriers indésirables.");
            code.value = ""; step(2);
          } else say(msg, TEXT[r.json.error] || TEXT.reseau, true);
        });
      } else {
        var c = code.value.replace(/\D/g, "");
        if (c.length !== 6) { say(msg, "Saisissez les 6 chiffres du code.", true); code.focus(); return; }
        busy(btn, true); say(msg, "Vérification…");
        post("/api/auth/verify", { email: email, code: c }).then(function (r) {
          if (r.json.ok) { say(msg, "Connexion réussie."); afterLogin(); return; }
          busy(btn, false);
          say(msg, TEXT[r.json.error] || TEXT.reseau, true);
          code.focus(); code.select();
        });
      }
    });
    $("[data-back]", form).addEventListener("click", function () { say(msg, ""); step(1); });
  });

  document.addEventListener("click", function (e) {
    var t = e.target.closest ? e.target : e.target.parentElement;
    var b = t && t.closest("[data-copy]");
    if (b) {
      var src = $(b.getAttribute("data-copy"));
      if (!src) return;
      var txt = src.textContent.trim(), label = b.textContent;
      var ok = function () {
        b.textContent = "Copiée"; live.textContent = "Clé copiée dans le presse-papiers.";
        setTimeout(function () { b.textContent = label; live.textContent = ""; }, 2500);
      };
      var fallback = function () {
        var r = document.createRange(); r.selectNodeContents(src);
        var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(r);
        try { if (document.execCommand("copy")) ok(); } catch (_) { /* la clé reste sélectionnée */ }
      };
      if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(txt).then(ok, fallback); else fallback();
      return;
    }
    if (t && t.closest("[data-logout]")) {
      post("/api/auth/logout", {}).then(function () { location.href = "/"; });
      return;
    }
    var d = t && t.closest("[data-delete]");
    if (d) {
      var out = d.closest("details") ? $("[data-msg]", d.closest("details")) : null;
      if (!window.confirm("Supprimer définitivement votre compte ? Cette action est irréversible.")) return;
      busy(d, true);
      post("/api/account/delete", {}).then(function (r) {
        if (r.json.ok) { location.href = "/"; return; }
        busy(d, false); say(out, TEXT[r.json.error] || TEXT.reseau, true);
      });
    }
  });

  /* Formulaires d'administration à confirmer (data-confirm="message"). */
  document.addEventListener("submit", function (e) {
    var m = e.target.getAttribute && e.target.getAttribute("data-confirm");
    if (m && !window.confirm(m)) e.preventDefault();
  }, true);
})();
