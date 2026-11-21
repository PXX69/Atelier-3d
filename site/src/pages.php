<?php
declare(strict_types=1);

/* ---------- fichiers à télécharger ---------- */
final class Files
{
    static function dir(): string { return (string)(cfg('downloads_dir') ?: L3D_ROOT . '/public/downloads'); }
    static function ok_name(string $n): bool { return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,119}\.(exe|apk|aab|msi|zip)$/i', $n); }
    static function list(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/*') ?: [] as $f) {
            $n = basename($f);
            if (is_file($f) && self::ok_name($n)) $out[] = ['name' => $n, 'size' => (int)filesize($f), 'mtime' => (int)filemtime($f), 'kind' => self::kind($n)];
        }
        usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
        return $out;
    }
    static function kind(string $n): ?string
    {
        if (preg_match('/\.apk$/i', $n)) return 'android';
        if (preg_match('/portable.*\.exe$/i', $n)) return 'win-portable';
        if (preg_match('/\.(exe|msi)$/i', $n)) return 'win-setup';
        return null;
    }
    static function sha(string $n): string
    {
        $f = self::dir() . '/' . $n;
        $cache = storage('downloads-sha.json');
        $c = is_file($cache) ? (json_decode((string)file_get_contents($cache), true) ?: []) : [];
        $k = $n . '|' . filesize($f) . '|' . filemtime($f);
        if (!isset($c[$k])) { $c = [$k => hash_file('sha256', $f)] + array_slice($c, 0, 20); @file_put_contents($cache, json_encode($c)); }
        return $c[$k];
    }
    static function size(int $b): string { return $b >= 1048576 ? number_format($b / 1048576, 1, ',', '') . ' Mo' : number_format(max($b, 1) / 1024, 0, ',', '') . ' Ko'; }
    static function latest(string $kind): ?array { foreach (self::list() as $f) if ($f['kind'] === $kind) return $f; return null; }
}

/* ---------- gabarit ---------- */
/** Adresse d'un fichier statique avec sa date de modification : le navigateur le garde en cache jusqu'à ce qu'il change. */
function asset(string $p): string
{
    $f = L3D_ROOT . '/public' . $p;
    return $p . '?v=' . (is_file($f) ? (int)filemtime($f) : 1);
}

/** En-têtes de sécurité. $nonce non vide : page d'achat (le script PayPal doit pouvoir s'exécuter). */
function send_headers(string $nonce = ''): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if (is_https()) header('Strict-Transport-Security: max-age=31536000');
    if ($nonce === '') {
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
        return;
    }
    // Page d'achat : liste officielle de PayPal (developer.paypal.com/sdk/js/csp). Si les boutons n'apparaissent pas,
    // régler paypal.csp sur "compat" (autorise le code en ligne) ; "off" désactive la politique sur cette seule page.
    $mode = (string)cfg('paypal.csp', 'nonce');
    if ($mode === 'off') return;
    $pp = 'https://*.paypal.com https://*.paypalobjects.com';
    $inline = $mode === 'compat' ? "'unsafe-inline'" : "'nonce-$nonce'";
    header("Content-Security-Policy: default-src 'self'; script-src 'self' $inline $pp; style-src 'self' $inline $pp; connect-src 'self' $pp; frame-src $pp; child-src $pp; img-src 'self' data: $pp; font-src 'self' data:; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
}

function layout(string $title, string $body, array $o = []): void
{
    $u = Auth::user();
    $page = $o['page'] ?? '';
    $desc = $o['desc'] ?? 'Liagos 3D : modélisez en 3D en assemblant des formes, puis imprimez. Windows, Android et navigateur.';
    $nonce = !empty($o['paypal']) ? rand_token(16) : '';
    send_headers($nonce);
    $body = str_replace('%%NONCE%%', $nonce, $body);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: ' . ($u ? 'private, no-store' : 'no-cache'));
    $nav = ['/pro' => 'Pro', '/telecharger' => 'Télécharger', '/compte' => $u ? 'Mon compte' : 'Connexion'];
    if ($u && Auth::is_admin($u)) $nav['/admin'] = 'Administration';
    ?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?></title>
<meta name="description" content="<?= h($desc) ?>">
<meta name="theme-color" content="#101820">
<link rel="icon" href="/img/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/img/icon-192.png">
<link rel="preload" href="/fonts/archivo-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= h(asset('/css/site.css')) ?>">
<?php if ($u): ?><meta name="csrf" content="<?= h($u['csrf']) ?>"><?php endif; ?>
<?php if (!empty($o['noindex'])): ?><meta name="robots" content="noindex"><?php endif; ?>
</head>
<body data-page="<?= h($page) ?>">
<a class="skip" href="#main">Aller au contenu</a>
<header class="top"><div class="wrap bar">
  <a class="brand" href="/"><img src="/img/logo.svg" width="34" height="34" alt=""><span>Liagos<b>3D</b></span></a>
  <nav aria-label="Navigation principale"><?php foreach ($nav as $href => $label): ?><a href="<?= h($href) ?>"<?= $page === trim($href, '/') ? ' aria-current="page"' : '' ?>><?= h($label) ?></a><?php endforeach; ?></nav>
</div></header>
<main id="main"><?= $body ?></main>
<footer class="foot"><div class="wrap">
  <p><strong>Liagos 3D</strong> — la modélisation 3D par assemblage de formes.</p>
  <p class="links"><a href="/mentions-legales">Mentions légales</a><a href="/cgv">Conditions de vente</a><a href="/confidentialite">Confidentialité</a></p>
</div></footer>
<script src="<?= h(asset('/js/site.js')) ?>" defer></script>
<?php foreach ((array)($o['scripts'] ?? []) as $s): ?><script src="<?= h(asset($s)) ?>" defer></script><?php endforeach; ?>
</body>
</html>
<?php
}

function login_form(string $intro = ''): string
{
    ob_start(); ?>
<form class="login" data-login novalidate>
  <?php if ($intro): ?><p><?= h($intro) ?></p><?php endif; ?>
  <div class="step" data-step="1">
    <label for="lg-mail">Votre adresse e-mail</label>
    <input id="lg-mail" name="email" type="email" inputmode="email" autocomplete="email" required maxlength="190" placeholder="vous@exemple.fr">
    <button class="btn primary" type="submit">Recevoir mon code</button>
  </div>
  <div class="step" data-step="2" hidden>
    <label for="lg-code">Code reçu par e-mail (6 chiffres)</label>
    <input id="lg-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="123456">
    <button class="btn primary" type="submit">Me connecter</button>
    <button class="btn link" type="button" data-back>Changer d'adresse e-mail</button>
  </div>
  <p class="msg" role="status" aria-live="polite" data-msg></p>
</form>
<?php return (string)ob_get_clean();
}

/* ---------- accueil ---------- */
function page_home(): void
{
    $p = Shop::price();
    ob_start(); ?>
<section class="hero wrap">
  <div class="hero-copy">
    <h1>Assemblez des formes.<br>Imprimez en 3D.</h1>
    <p class="lead">Liagos 3D est un logiciel de modélisation fait pour les débutants : vous posez des formes, vous les assemblez, vous creusez des trous, puis vous envoyez le fichier à votre imprimante 3D.</p>
    <p class="cta"><a class="btn primary" href="/telecharger">Télécharger gratuitement</a><a class="btn" href="/pro">Découvrir Pro</a></p>
    <p class="fine">Windows et Android. La version gratuite est complète pour démarrer.</p>
  </div>
  <div class="play" id="play">
    <svg id="iso" viewBox="0 -30 520 400" role="group" aria-label="Grille de blocs en 3D : flèches pour choisir une case, Entrée pour y poser un bloc."></svg>
    <div class="tools" role="group" aria-label="Essayer l'assemblage">
      <button type="button" data-mode="add" aria-pressed="true">Ajouter</button>
      <button type="button" data-mode="del" aria-pressed="false">Retirer</button>
      <span class="sw" role="group" aria-label="Couleur">
        <button type="button" data-c="0" aria-pressed="true" aria-label="Orange"></button>
        <button type="button" data-c="1" aria-pressed="false" aria-label="Turquoise"></button>
        <button type="button" data-c="2" aria-pressed="false" aria-label="Ardoise"></button>
      </span>
      <button type="button" data-act="rand">Un bloc au hasard</button>
      <button type="button" data-act="clear">Tout vider</button>
    </div>
    <p class="hint" id="hint" aria-live="polite">Essayez : cliquez sur la grille pour empiler des blocs.</p>
  </div>
</section>

<section class="wrap split" id="fonctions">
  <div class="col">
    <h2>Gratuit, sans limite de durée</h2>
    <ul class="ticks">
      <li>Formes de base : cube, sphère, cylindre, cône, pyramide, rampe, anneau</li>
      <li>Trous et découpes : soustrayez une forme d'une autre</li>
      <li>Déplacer, tourner, changer la taille, choisir plusieurs objets, grouper, dupliquer, fusionner</li>
      <li>Peindre vos objets en couleur</li>
      <li>Transformer une image en objet</li>
      <li>Régler votre imprimante et la taille du plateau</li>
      <li>Mesurer, annuler, rétablir, enregistrer et rouvrir vos projets</li>
      <li>Exporter en STL pour votre logiciel d'impression</li>
    </ul>
  </div>
  <div class="col pro">
    <h2>Pro, <?= h(money($p['cents'], $p['currency'])) ?> une seule fois</h2>
    <ul class="ticks">
      <li>Sculpter et gomme : modeler la matière à la main</li>
      <li>Dessin 3D : tracé, dessin libre, stylet</li>
      <li>Assistant : décrivez un objet, il le construit</li>
      <li>Importer vos fichiers STL et 3MF</li>
      <li>Plateaux multiples et découpe en plusieurs parties</li>
      <li>Export 3MF</li>
      <li>Couleurs de l'interface à votre goût</li>
      <li>Utilisation dans le navigateur, sur tout ordinateur</li>
    </ul>
    <p><a class="btn primary" href="/pro">Voir Liagos 3D Pro</a></p>
  </div>
</section>

<section class="wrap where">
  <h2>Où l'utiliser</h2>
  <dl>
    <dt>Windows</dt><dd>Installez-le ou lancez la version portable. Fonctionne sans connexion.</dd>
    <dt>Android</dt><dd>Installez l'application sur votre téléphone ou votre tablette, stylet compris.</dd>
    <dt>Navigateur</dt><dd>Réservé aux détenteurs de Pro : connectez-vous et travaillez depuis n'importe quel ordinateur.</dd>
  </dl>
  <p><a class="btn" href="/telecharger">Télécharger pour Windows ou Android</a></p>
</section>

<section class="wrap faq">
  <h2>Questions fréquentes</h2>
  <details><summary>Faut-il savoir dessiner en 3D ?</summary><p>Non. Vous partez de formes simples que vous assemblez, comme des briques. Les formes en « trou » servent à creuser.</p></details>
  <details><summary>Est-ce que ça marche sans internet ?</summary><p>Oui. Les applications Windows et Android fonctionnent hors connexion, et la clé Pro se vérifie sans connexion aussi.</p></details>
  <details><summary>Que contient la version gratuite ?</summary><p>Tout ce qu'il faut pour modéliser un objet simple et l'exporter en STL. Pro ajoute les outils avancés.</p></details>
  <details><summary>Comment payer ?</summary><p>Par PayPal, avec ou sans compte PayPal selon ce que PayPal vous propose. Vous recevez votre clé tout de suite, par e-mail et dans votre espace client.</p></details>
</section>
<?php
    layout('Liagos 3D — modélisation 3D facile pour l\'impression 3D', (string)ob_get_clean(), ['page' => 'home', 'scripts' => ['/js/iso.js']]);
}

/* ---------- page Pro et achat ---------- */
function page_pro(): void
{
    $p = Shop::price();
    $u = Auth::user();
    $pro = $u ? License::user_pro((int)$u['id']) : null;
    $enabled = Shop::enabled();
    $sandbox = cfg('paypal.env', 'sandbox') !== 'live';
    ob_start(); ?>
<section class="wrap pro-head">
  <h1>Liagos 3D Pro</h1>
  <p class="lead">Les outils avancés, une seule fois, pour tous vos appareils.</p>
  <p class="price"><strong><?= h(money($p['cents'], $p['currency'])) ?></strong> TTC · achat unique, sans abonnement</p>
</section>
<section class="wrap split">
  <div class="col">
    <h2>Ce que Pro débloque</h2>
    <ul class="ticks">
      <li><strong>Modelage</strong> : sculpter, gomme</li>
      <li><strong>Dessin 3D</strong> : tracé, dessin libre, stylet</li>
      <li><strong>Création</strong> : assistant, import STL et 3MF, plateaux multiples</li>
      <li><strong>Impression</strong> : export 3MF, découpe en plusieurs parties</li>
      <li><strong>Confort</strong> : couleurs de l'interface</li>
      <li><strong>Navigateur</strong> : l'application s'ouvre depuis n'importe quel ordinateur, après connexion</li>
    </ul>
  </div>
  <div class="col">
    <h2>Comment ça se passe</h2>
    <ol class="steps">
      <li>Vous vous identifiez avec votre e-mail (un code, pas de mot de passe).</li>
      <li>Vous payez avec PayPal.</li>
      <li>Votre clé arrive aussitôt par e-mail et reste dans votre espace client.</li>
      <li>Vous collez la clé dans l'application Windows ou Android, ou vous ouvrez le navigateur.</li>
    </ol>
  </div>
</section>
<section class="wrap buy" id="achat"
  data-enabled="<?= $enabled ? '1' : '0' ?>" data-client-id="<?= $enabled ? h(cfg('paypal.client_id')) : '' ?>" data-currency="<?= h($p['currency']) ?>"
  data-logged="<?= $u ? '1' : '0' ?>" data-pro="<?= $pro ? '1' : '0' ?>" data-nonce="%%NONCE%%">
  <h2>Acheter Liagos 3D Pro</h2>
  <?php if ($pro): ?>
    <p class="ok">Vous avez déjà Liagos 3D Pro. <a href="/compte">Retrouver ma clé</a></p>
  <?php elseif (!$enabled): ?>
    <p class="soon"><strong>La vente n'est pas encore ouverte.</strong> Revenez bientôt : la version gratuite est disponible dès maintenant.</p>
    <p><a class="btn" href="/telecharger">Télécharger la version gratuite</a></p>
  <?php else: ?>
    <?php if ($sandbox): ?><p class="warn" role="note">Mode test PayPal : aucun vrai paiement n'est effectué.</p><?php endif; ?>
    <?php if (!$u): ?>
      <?= login_form('Pour acheter, identifiez-vous avec votre e-mail : la clé sera liée à votre compte.') ?>
    <?php else: ?>
      <p>Connecté en tant que <strong><?= h($u['email']) ?></strong>.</p>
      <label class="check"><input type="checkbox" id="consent"> <span>J'ai lu et j'accepte les <a href="/cgv" target="_blank" rel="noopener">conditions de vente</a>. Je demande l'accès immédiat à Liagos 3D Pro et je reconnais perdre mon droit de rétractation dès que la clé m'est fournie.</span></label>
      <div id="paypal-buttons" aria-live="polite"></div>
      <p class="msg" role="status" aria-live="polite" data-msg></p>
      <div id="done" hidden><p class="ok"><strong>Merci !</strong> Votre clé de licence :</p><p><code id="newkey"></code> <button class="btn" type="button" data-copy="#newkey">Copier la clé</button></p><p><a class="btn primary" href="/compte">Ouvrir mon espace client</a></p></div>
    <?php endif; ?>
  <?php endif; ?>
</section>
<section class="wrap faq">
  <h2>Questions sur l'achat</h2>
  <details><summary>Sur quels appareils puis-je l'utiliser ?</summary><p>Avec une seule clé, sur vos propres appareils Windows et Android, et dans le navigateur après connexion à votre compte.</p></details>
  <details><summary>Les mises à jour sont-elles comprises ?</summary><p>Oui, les mises à jour de Liagos 3D sont incluses.</p></details>
  <details><summary>Et si j'ai un problème ?</summary><p>Écrivez-nous : nous vous répondons et, si besoin, nous vous remboursons dans le cadre des <a href="/cgv">conditions de vente</a>.</p></details>
  <details><summary>Je n'ai pas reçu mon e-mail.</summary><p>Vérifiez vos courriers indésirables. Votre clé est de toute façon affichée dans votre espace client dès que le paiement est validé.</p></details>
</section>
<?php
    layout('Liagos 3D Pro — acheter', (string)ob_get_clean(), ['page' => 'pro', 'scripts' => ['/js/shop.js'], 'paypal' => true,
        'desc' => 'Liagos 3D Pro : sculpture, dessin 3D, assistant, import STL/3MF, export 3MF et accès navigateur. Achat unique.']);
}

/* ---------- téléchargements ---------- */
function page_download(): void
{
    $win = Files::latest('win-setup'); $portable = Files::latest('win-portable'); $apk = Files::latest('android');
    $row = function (string $label, string $help, ?array $f): string {
        ob_start(); ?>
<li class="dl">
  <div><h3><?= h($label) ?></h3><p><?= h($help) ?></p>
  <?php if ($f): ?><p class="fine"><?= h(Files::size($f['size'])) ?> · mis à jour le <?= h(fmt_date($f['mtime'], false)) ?> · empreinte SHA-256 : <code><?= h(Files::sha($f['name'])) ?></code></p><?php endif; ?></div>
  <?php if ($f): ?><a class="btn primary" href="/downloads/<?= h(rawurlencode($f['name'])) ?>" download>Télécharger</a><?php else: ?><span class="btn off" aria-disabled="true">Bientôt disponible</span><?php endif; ?>
</li>
<?php return (string)ob_get_clean(); };
    ob_start(); ?>
<section class="wrap">
  <h1>Télécharger Liagos 3D</h1>
  <p class="lead">Gratuit pour démarrer. Pro se débloque ensuite avec votre clé.</p>
  <h2 class="sr">Choisir votre version</h2>
  <ul class="dls">
    <?= $row('Windows — installation', 'Installe Liagos 3D et crée un raccourci.', $win) ?>
    <?= $row('Windows — version portable', 'Un seul fichier à lancer, sans installation.', $portable) ?>
    <?= $row('Android', 'Fichier APK pour téléphone ou tablette.', $apk) ?>
  </ul>
  <h2>Avant d'installer</h2>
  <ul class="ticks">
    <li><strong>Windows</strong> peut afficher un avertissement « Windows a protégé votre ordinateur » : choisissez « Informations complémentaires » puis « Exécuter quand même ».</li>
    <li><strong>Android</strong> demande d'autoriser l'installation depuis cette source : acceptez pour ce téléchargement, puis ouvrez le fichier.</li>
    <li>Vous pouvez comparer l'empreinte SHA-256 affichée avec celle de votre fichier pour vérifier qu'il n'a pas été modifié.</li>
    <li>Dans le navigateur ? C'est réservé à Pro : <a href="/pro">voir Pro</a> ou <a href="/compte">ouvrir mon compte</a>.</li>
  </ul>
</section>
<?php
    layout('Télécharger Liagos 3D pour Windows et Android', (string)ob_get_clean(), ['page' => 'telecharger']);
}

/* ---------- espace client ---------- */
function page_account(): void
{
    $u = Auth::user();
    ob_start();
    if (!$u) { ?>
<section class="wrap narrow">
  <h1>Mon compte</h1>
  <?= login_form('Connectez-vous avec votre adresse e-mail : nous vous envoyons un code, pas de mot de passe à retenir.') ?>
</section>
<?php } else {
        $pro = License::user_pro((int)$u['id']);
        $orders = Db::all('SELECT * FROM orders WHERE user_id=? AND status<>? ORDER BY id DESC', [$u['id'], 'failed']);
        ?>
<section class="wrap narrow">
  <h1>Mon compte</h1>
  <p>Connecté : <strong><?= h($u['email']) ?></strong> <button class="btn link" type="button" data-logout>Se déconnecter</button></p>

  <h2>Licence</h2>
  <?php if ($pro): ?>
    <p class="ok">Liagos 3D Pro est <strong>active</strong><?= $pro['expires_at'] ? ' jusqu\'au ' . h(fmt_date((int)$pro['expires_at'] - 1, false)) : '' ?>.</p>
    <p><code id="key"><?= h($pro['license_key']) ?></code></p>
    <p><button class="btn" type="button" data-copy="#key">Copier la clé</button> <a class="btn primary" href="/app">Ouvrir l'application dans le navigateur</a></p>
    <details open><summary>Activer la clé dans l'application</summary>
      <ol class="steps"><li>Ouvrez Liagos 3D sur Windows ou Android.</li><li>Ouvrez le menu, puis « Liagos 3D Pro ».</li><li>Collez la clé et validez : les outils Pro se débloquent, même sans connexion.</li></ol>
    </details>
  <?php else: ?>
    <p>Vous utilisez la version gratuite. <a class="btn primary" href="/pro">Passer à Pro</a></p>
  <?php endif; ?>

  <h2>Commandes</h2>
  <?php if (!$orders): ?><p>Aucune commande pour l'instant.</p><?php else: ?>
  <div class="tablewrap"><table><thead><tr><th>Date</th><th>Référence</th><th>Montant</th><th>État</th></tr></thead><tbody>
  <?php foreach ($orders as $o): ?><tr><td><?= h(fmt_date((int)($o['paid_at'] ?: $o['created_at']), false)) ?></td><td><?= h($o['public_id']) ?></td><td><?= h(money((int)$o['amount_cents'], $o['currency'])) ?></td><td><?= h(['created' => 'En attente', 'pending' => 'En cours de validation', 'paid' => 'Payée', 'refunded' => 'Remboursée', 'disputed' => 'Litige en cours', 'cancelled' => 'Annulée'][$o['status']] ?? $o['status']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>

  <h2>Mes données</h2>
  <p><a href="/api/account/export" download="liagos3d-mes-donnees.json">Télécharger mes données</a></p>
  <details><summary>Supprimer mon compte</summary>
    <p>Votre adresse e-mail et vos sessions sont effacées. Vos licences ne seront plus accessibles depuis le site ; les informations de commande sont conservées sans votre e-mail, comme la loi l'impose pour la comptabilité.</p>
    <p><button class="btn danger" type="button" data-delete>Supprimer définitivement mon compte</button></p>
    <p class="msg" role="status" data-msg></p>
  </details>
</section>
<?php }
    layout('Mon compte — Liagos 3D', (string)ob_get_clean(), ['page' => 'compte', 'noindex' => true]);
}

function page_404(): void
{
    http_response_code(404);
    layout('Page introuvable — Liagos 3D', '<section class="wrap narrow"><h1>Page introuvable</h1><p>Cette page n\'existe pas ou a été déplacée.</p><p><a class="btn primary" href="/">Retour à l\'accueil</a></p></section>', ['noindex' => true]);
}
