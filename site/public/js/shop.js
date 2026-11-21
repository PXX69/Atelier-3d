/* Liagos 3D : achat avec les boutons PayPal. Tout est vérifié côté serveur ; ici on affiche seulement. */
(function () {
  "use strict";
  var box = document.getElementById("achat");
  if (!box || box.getAttribute("data-enabled") !== "1" || box.getAttribute("data-logged") !== "1" || box.getAttribute("data-pro") === "1") return;
  var L = window.L3D, consent = document.getElementById("consent"), holder = document.getElementById("paypal-buttons");
  var msg = box.querySelector("[data-msg]"), done = document.getElementById("done");
  if (!L || !consent || !holder) return;

  var TEXT = {
    paiement_refuse: "Le paiement a été refusé. Essayez un autre moyen de paiement.",
    verification_echouee: "Le paiement n'a pas pu être vérifié. Écrivez-nous en indiquant l'heure de l'achat : nous réglons le problème.",
    deja_pro: "Vous avez déjà Liagos 3D Pro.",
    paiement_indisponible: "La vente est momentanément fermée.",
    consentement_requis: "Cochez la case des conditions de vente pour continuer.",
    paypal_erreur: "PayPal ne répond pas pour le moment. Réessayez dans un instant.",
    commande_invalide: "Cette commande ne peut plus être payée. Recommencez l'achat.",
    commande_inconnue: "Commande introuvable. Recommencez l'achat."
  };
  function oops(code) { L.say(msg, TEXT[code] || L.text[code] || "Une erreur est survenue. Réessayez dans un instant.", true); }

  function success(key) {
    holder.hidden = true;
    var label = consent.closest("label"); if (label) label.hidden = true;
    L.say(msg, "");
    if (key) {
      document.getElementById("newkey").textContent = key;
      done.hidden = false; done.setAttribute("tabindex", "-1"); done.focus();
    } else {
      L.say(msg, "Merci ! Votre paiement est enregistré. Votre clé arrive par e-mail et dans votre espace client (« Mon compte »).");
    }
  }

  function buttons() {
    window.paypal.Buttons({
      style: { layout: "vertical", shape: "rect", label: "pay", height: 48 },
      onClick: function (data, actions) {
        if (!consent.checked) {
          L.say(msg, TEXT.consentement_requis, true); consent.focus();
          return actions.reject();
        }
        L.say(msg, "");
        return actions.resolve();
      },
      createOrder: function () {
        return L.post("/api/paypal/create", { consent: true }).then(function (r) {
          if (!r.json.id) { oops(r.json.error); throw new Error(r.json.error || "create"); }
          return r.json.id;
        });
      },
      onApprove: function (data) {
        L.say(msg, "Validation du paiement…");
        return L.post("/api/paypal/capture", { orderID: data.orderID }).then(function (r) {
          var j = r.json;
          if (j.ok && j.status === "paid") success(j.key);
          else if (j.ok && j.status === "pending") L.say(msg, "PayPal valide encore votre paiement. Votre clé sera envoyée par e-mail dès qu'il sera confirmé.");
          else oops(j.error);
        });
      },
      onCancel: function () { L.say(msg, "Paiement annulé. Vous pouvez recommencer quand vous voulez."); },
      onError: function () { if (!msg.classList.contains("bad")) oops("paypal_erreur"); }
    }).render(holder);
  }

  var s = document.createElement("script");
  s.src = "https://www.paypal.com/sdk/js?client-id=" + encodeURIComponent(box.getAttribute("data-client-id")) +
    "&currency=" + encodeURIComponent(box.getAttribute("data-currency")) + "&intent=capture&components=buttons&locale=fr_FR";
  var nonce = box.getAttribute("data-nonce");
  if (nonce) s.setAttribute("data-csp-nonce", nonce);
  s.onload = function () { if (window.paypal && window.paypal.Buttons) buttons(); else oops("paypal_erreur"); };
  s.onerror = function () { oops("paypal_erreur"); };
  document.head.appendChild(s);
})();
