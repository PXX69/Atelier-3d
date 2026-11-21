<?php
declare(strict_types=1);

/*
 * Installateur de Liagos 3D (une seule fois).
 * - refuse de tourner si le site est déjà installé (config.php existe) ;
 * - exige que la clé privée des licences soit déjà envoyée dans storage/keys/ et que le « code d'installation »
 *   (affiché par l'outil de création de clés) soit saisi : personne d'autre ne peut donc l'utiliser ;
 * - écrit config.php, crée les tables, envoie un e-mail d'essai, puis tente de se supprimer.
 */
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

require_once __DIR__ . '/../src/core.php';

$configFile = L3D_ROOT . '/config.php';
$keyFile = L3D_ROOT . '/storage/keys/license-private.pem';

function page(string $title, string $body): void
{
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($title) . '</title><style>
body{margin:0;background:#f3f6f9;color:#0f1b26;font:17px/1.6 "Segoe UI",system-ui,sans-serif}
main{max-width:760px;margin:0 auto;padding:1.5rem 1rem 4rem}
h1{font-size:1.8rem;margin:.4em 0}h2{font-size:1.2rem;margin:1.8em 0 .4em}
fieldset{border:1px solid #d3dce5;border-radius:12px;background:#fff;margin:1rem 0;padding:.6rem 1.2rem 1.2rem}
legend{font-weight:700;padding:0 .4rem}
label{display:block;font-weight:700;margin:.9rem 0 .2rem}
small{display:block;color:#475766;margin:.1rem 0 .3rem;font-weight:400}
input,select{width:100%;box-sizing:border-box;font:inherit;padding:.55rem .7rem;border:2px solid #d3dce5;border-radius:8px;min-height:44px;background:#fff;color:inherit}
input:focus,select:focus,button:focus{outline:3px solid #1a73e8;outline-offset:1px}
button{font:700 1.05rem inherit;margin-top:1.4rem;padding:.7rem 1.6rem;border:0;border-radius:10px;background:#bf4407;color:#fff;cursor:pointer;min-height:46px}
button[disabled]{opacity:.5;cursor:not-allowed}
ul.c{list-style:none;padding:0}ul.c li{padding:.25rem 0}
.ok{color:#0e7566;font-weight:700}.ko{color:#b3261e;font-weight:700}
.err{background:#fde7e5;border-left:5px solid #b3261e;padding:.7rem 1rem;border-radius:6px}
.good{background:#e1f4f0;border-left:5px solid #0e7566;padding:.7rem 1rem;border-radius:6px}
code{background:#e8eef3;padding:.1em .35em;border-radius:4px;word-break:break-all}
details{margin:.6rem 0}summary{cursor:pointer;font-weight:700}
@media (prefers-color-scheme:dark){body{background:#0d141c;color:#e8eef4}fieldset,input,select{background:#141e29;border-color:#26333f}small{color:#a3b3c2}code{background:#26333f}}
</style></head><body><main>' . $body . '</main></body></html>';
}

/* ---------- déjà installé ---------- */
if (is_file($configFile)) {
    $gone = @unlink(__FILE__);
    page('Déjà installé', '<h1>Liagos 3D est déjà installé</h1><p>Le site est configuré. ' . ($gone ? 'Ce fichier d\'installation vient d\'être supprimé.' : 'Supprimez le fichier <code>public/install.php</code> de votre hébergement.') . '</p><p><a href="/">Aller sur le site</a></p>');
    exit;
}

/* ---------- vérifications ---------- */
$checks = [];
$add = function (string $label, bool $ok, string $help = '') use (&$checks): bool { $checks[] = [$label, $ok, $help]; return $ok; };
$add('PHP 8.1 ou plus récent (ici : ' . PHP_VERSION . ')', PHP_VERSION_ID >= 80100, 'Dans l\'espace IONOS, choisissez PHP 8.2 ou plus récent pour ce site.');
foreach (['pdo_mysql' => 'PDO MySQL', 'curl' => 'cURL (appels PayPal)', 'openssl' => 'OpenSSL (clés de licence)', 'json' => 'JSON'] as $ext => $name)
    $add('Extension ' . $name, extension_loaded($ext), 'Extension PHP absente : activez-la dans la configuration PHP de l\'hébergement.');
$writable = is_writable(L3D_ROOT) || is_writable($configFile);
$add('Dossier du site accessible en écriture (pour config.php)', $writable, 'Vérifiez les droits du dossier (755) sur l\'hébergement.');
@mkdir(L3D_ROOT . '/storage/logs', 0775, true);
$add('Dossier storage/ accessible en écriture', is_writable(L3D_ROOT . '/storage') && is_writable(L3D_ROOT . '/storage/logs'), 'Droits 755 ou 775 sur storage/ et ses sous-dossiers.');

$keyOk = false; $installCode = ''; $pubOk = false;
if (is_file($keyFile)) {
    $pem = (string)file_get_contents($keyFile);
    $k = $pem !== '' ? @openssl_pkey_get_private($pem) : false;
    $d = $k ? openssl_pkey_get_details($k) : null;
    if ($d && ($d['type'] ?? 0) === OPENSSL_KEYTYPE_EC && ($d['ec']['curve_name'] ?? '') === 'prime256v1') {
        $keyOk = true;
        $installCode = strtoupper(substr(hash('sha256', $pem), 0, 10));
        $raw = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $pubOk = hash_equals(b64u($raw), (string)((require __DIR__ . '/../src/keys.php')['public'] ?? ''));
    }
}
$add('Clé privée des licences présente (storage/keys/license-private.pem) et valide', $keyOk, 'Envoyez le fichier license-private.pem dans le dossier storage/keys/ de l\'hébergement.');
$add('Clé publique du site (src/keys.php) identique à celle de la clé privée', $pubOk, 'Le fichier src/keys.php doit contenir la clé publique qui correspond à votre clé privée (voir le guide).');
$ready = !in_array(false, array_column($checks, 1), true);

/* ---------- traitement du formulaire ---------- */
$v = fn(string $k, string $d = ''): string => trim((string)($_POST[$k] ?? $d));
$error = '';
$done = null;
if ($ready && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $given = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $v('code')) ?? '');
        if (!hash_equals($installCode, $given)) { sleep(2); throw new RuntimeException('Le code d\'installation est incorrect.'); }

        $url = rtrim($v('site_url'), '/');
        $host = (string)parse_url($url, PHP_URL_HOST);
        if (!preg_match('#^https://[a-z0-9.-]+$#i', $url) && !preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?$#', $url)) throw new RuntimeException('L\'adresse du site doit commencer par https:// (exemple : https://liagos3d.com), sans « / » à la fin.');
        $admins = array_values(array_filter(array_map('strtolower', array_map('trim', explode(',', $v('admins'))))));
        if (!$admins) throw new RuntimeException('Indiquez au moins une adresse e-mail d\'administrateur.');
        foreach ($admins as $a) if (!filter_var($a, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Adresse d\'administrateur invalide : ' . $a);
        $price = (float)str_replace(',', '.', $v('price', '9.99'));
        if ($price < 0.5 || $price > 500) throw new RuntimeException('Le prix doit être compris entre 0,50 et 500 euros.');

        $db = ['driver' => 'mysql', 'host' => $v('db_host'), 'name' => $v('db_name'), 'user' => $v('db_user'), 'pass' => (string)($_POST['db_pass'] ?? '')];
        if ($v('db_port') !== '') $db['port'] = (int)$v('db_port');
        if ($db['host'] === '' || $db['name'] === '' || $db['user'] === '') throw new RuntimeException('Complétez les informations de la base de données.');
        try {
            new PDO('mysql:host=' . $db['host'] . (isset($db['port']) ? ';port=' . $db['port'] : '') . ';dbname=' . $db['name'] . ';charset=utf8mb4', $db['user'], $db['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]);
        } catch (PDOException $e) { throw new RuntimeException('Connexion à la base impossible : ' . preg_replace('/\s+/', ' ', $e->getMessage())); }

        $secure = $v('mail_secure', 'ssl') === 'tls' ? 'tls' : 'ssl';
        $mail = ['transport' => $v('mail_host') !== '' ? 'smtp' : 'phpmail', 'host' => $v('mail_host'), 'port' => (int)$v('mail_port', $secure === 'ssl' ? '465' : '587'),
            'secure' => $secure, 'user' => $v('mail_user'), 'pass' => (string)($_POST['mail_pass'] ?? ''),
            'from' => $v('mail_from', $v('mail_user')), 'from_name' => $v('mail_name', 'Liagos 3D'), 'reply_to' => $v('mail_reply'), 'verify_peer' => true];
        if ($mail['transport'] === 'smtp' && (!filter_var($mail['from'], FILTER_VALIDATE_EMAIL) || $mail['user'] === '' || $mail['pass'] === '')) throw new RuntimeException('Courrier : indiquez la boîte e-mail (adresse + mot de passe) qui enverra les messages.');

        $cfg = [
            'site_url' => $url,
            'secret' => bin2hex(random_bytes(32)),
            'db' => $db,
            'admin_emails' => $admins,
            'mail' => $mail,
            'paypal' => ['env' => $v('pp_env') === 'live' ? 'live' : 'sandbox', 'client_id' => $v('pp_client'), 'secret' => $v('pp_secret'), 'webhook_id' => $v('pp_webhook'), 'merchant_id' => '', 'api_base' => '', 'csp' => 'nonce'],
            'license' => ['private_key_path' => $keyFile],
            'price' => ['cents' => (int)round($price * 100), 'currency' => 'EUR'],
            'legal' => [],
            'debug' => false,
        ];
        foreach (['editeur', 'statut', 'adresse', 'immat', 'tva', 'contact_email', 'directeur', 'hebergeur'] as $k) $cfg['legal'][$k] = $v('lg_' . $k);

        /* config.php : lisible par vous seul, écrit d'un seul coup */
        $tmp = $configFile . '.tmp' . bin2hex(random_bytes(3));
        if (@file_put_contents($tmp, "<?php\n// Écrit par install.php. Contient des secrets : ne jamais publier ce fichier.\nreturn " . var_export($cfg, true) . ";\n", LOCK_EX) === false) throw new RuntimeException('Impossible d\'écrire config.php : vérifiez les droits du dossier.');
        @chmod($tmp, 0600);
        if (!@rename($tmp, $configFile)) { @unlink($tmp); throw new RuntimeException('Impossible de créer config.php.'); }

        try {
            require_once __DIR__ . '/../src/app.php';
            cfg(null, null, true);
            Db::migrate();
            Settings::set('payments_enabled', '0');
            Settings::set('vat_default_bp', '0');
            Settings::set('vat_map', '{}');
            Settings::set('price_cents', (string)$cfg['price']['cents']);
            Audit::log('installation', 'site_installe', 'PHP ' . PHP_VERSION . ' · ' . $url);
        } catch (Throwable $e) {
            @unlink($configFile);   /* on repart de zéro : le formulaire sera de nouveau proposé */
            throw new RuntimeException('Création des tables impossible : ' . preg_replace('/\s+/', ' ', $e->getMessage()));
        }
        $mailOk = Mail::send($admins[0], 'Liagos 3D : l\'installation a réussi', "Bonjour,\n\nL'envoi d'e-mails fonctionne depuis votre site Liagos 3D.\nVous pouvez maintenant vous connecter à l'administration : " . $url . "/admin\n\nLes ventes sont fermées tant que vous ne les ouvrez pas.\n");
        $done = ['admin' => $admins[0], 'url' => $url, 'mail' => $mailOk, 'paypal' => $cfg['paypal']['client_id'] !== '' && $cfg['paypal']['secret'] !== '', 'webhook' => $cfg['paypal']['webhook_id'] !== ''];
    } catch (Throwable $e) { $error = $e->getMessage(); }
}

/* ---------- affichage ---------- */
if ($done) {
    $gone = @unlink(__FILE__);
    $li = fn(bool $ok, string $t) => '<li><span class="' . ($ok ? 'ok">✓' : 'ko">✗') . '</span> ' . $t . '</li>';
    page('Installation terminée', '<h1>Installation terminée</h1><div class="good"><strong>Le site est prêt.</strong> Les ventes restent fermées tant que vous ne les ouvrez pas.</div>
<ul class="c">' .
        $li($done['mail'], $done['mail'] ? 'E-mail d\'essai envoyé à ' . h($done['admin']) . ' (vérifiez aussi les courriers indésirables).' : 'L\'e-mail d\'essai n\'est pas parti : vérifiez la section « mail » de <code>config.php</code> (serveur, port, mot de passe) et le fichier <code>storage/logs/app.log</code>.') .
        $li($done['paypal'], $done['paypal'] ? 'Identifiants PayPal enregistrés.' : 'PayPal : à ajouter plus tard dans <code>config.php</code> (section « paypal »).') .
        $li($done['webhook'], $done['webhook'] ? 'Webhook PayPal enregistré.' : 'Webhook PayPal : à ajouter dans <code>config.php</code> après sa création chez PayPal.') .
        $li((bool)$gone, $gone ? 'Le fichier d\'installation a été supprimé.' : 'Supprimez maintenant <code>public/install.php</code> de l\'hébergement.') .
        '</ul><h2>Et maintenant</h2><ol><li>Ouvrez <a href="' . h($done['url']) . '/admin">' . h($done['url']) . '/admin</a> et connectez-vous avec <strong>' . h($done['admin']) . '</strong> (un code arrive par e-mail).</li><li>Suivez la liste de contrôle affichée sur le tableau de bord.</li></ol>');
    exit;
}

$sel = fn(string $name, string $val, string $def = '') => h($_POST[$name] ?? $def) === h($val) ? ' selected' : '';
$in = function (string $name, string $label, string $def = '', string $help = '', string $type = 'text', bool $req = false): string {
    $val = $type === 'password' ? '' : h($_POST[$name] ?? $def);
    return '<label for="' . $name . '">' . h($label) . '</label>' . ($help ? '<small>' . $help . '</small>' : '') .
        '<input id="' . $name . '" name="' . $name . '" type="' . $type . '" value="' . $val . '"' . ($type === 'password' ? ' autocomplete="new-password"' : ' autocomplete="off"') . ($req ? ' required' : '') . '>';
};

ob_start();
echo '<h1>Installation de Liagos 3D</h1><p>Quelques minutes, une seule fois. Rien n\'est publié : les ventes restent fermées.</p><h2>Vérifications</h2><ul class="c">';
foreach ($checks as [$label, $ok, $help]) echo '<li><span class="' . ($ok ? 'ok">✓' : 'ko">✗') . '</span> ' . h($label) . (!$ok && $help ? '<br><small>' . h($help) . '</small>' : '') . '</li>';
echo '</ul>';
if ($error) echo '<p class="err" role="alert">' . h($error) . '</p>';
if (!$ready) { echo '<p>Corrigez les points marqués ✗, puis rechargez cette page.</p>'; page('Installation de Liagos 3D', (string)ob_get_clean()); exit; }
?>
<form method="post" autocomplete="off">
<fieldset><legend>Sécurité</legend>
<?= $in('code', 'Code d\'installation', '', 'Affiché par l\'outil qui a créé votre clé de licence (dossier Liagos-3D-prive). Il prouve que vous êtes bien le propriétaire du site.', 'text', true) ?>
</fieldset>
<fieldset><legend>Le site</legend>
<?= $in('site_url', 'Adresse du site', 'https://' . ($_SERVER['HTTP_HOST'] ?? 'liagos3d.com'), 'Exemple : https://liagos3d.com', 'text', true) ?>
<?= $in('admins', 'Adresse(s) e-mail des administrateurs', '', 'Seules ces adresses accèdent à l\'administration. Séparez-les par une virgule.', 'text', true) ?>
<?= $in('price', 'Prix de Liagos 3D Pro (TTC, en euros)', '9,99', 'Modifiable ensuite dans l\'administration.', 'text', true) ?>
</fieldset>
<fieldset><legend>Base de données MySQL (créée dans votre espace IONOS)</legend>
<?= $in('db_host', 'Serveur', '', 'Exemple IONOS : db5000000000.hosting-data.io', 'text', true) ?>
<?= $in('db_port', 'Port (laisser vide : 3306)') ?>
<?= $in('db_name', 'Nom de la base', '', 'Exemple : dbs1234567', 'text', true) ?>
<?= $in('db_user', 'Utilisateur', '', 'Exemple : dbu1234567', 'text', true) ?>
<?= $in('db_pass', 'Mot de passe', '', '', 'password', true) ?>
</fieldset>
<fieldset><legend>E-mails envoyés par le site (boîte créée chez IONOS)</legend>
<?= $in('mail_host', 'Serveur SMTP', 'smtp.ionos.fr', 'À vérifier dans l\'aide IONOS : smtp.ionos.fr (France) ou smtp.ionos.com. Vide = envoi simple par le serveur web (déconseillé).') ?>
<label for="mail_secure">Sécurité</label>
<select id="mail_secure" name="mail_secure"><option value="ssl"<?= $sel('mail_secure', 'ssl', 'ssl') ?>>SSL, port 465 (recommandé)</option><option value="tls"<?= $sel('mail_secure', 'tls', 'ssl') ?>>STARTTLS, port 587</option></select>
<?= $in('mail_port', 'Port (laisser vide : 465 ou 587 selon la sécurité)') ?>
<?= $in('mail_user', 'Adresse de la boîte e-mail', '', 'Exemple : boutique@liagos3d.com', 'text') ?>
<?= $in('mail_pass', 'Mot de passe de la boîte', '', '', 'password') ?>
<?= $in('mail_name', 'Nom affiché de l\'expéditeur', 'Liagos 3D') ?>
<?= $in('mail_reply', 'Adresse de réponse (facultatif)', '', 'Où arrivent les réponses de vos clients.') ?>
</fieldset>
<fieldset><legend>PayPal (peut être complété plus tard dans config.php)</legend>
<label for="pp_env">Mode</label>
<select id="pp_env" name="pp_env"><option value="sandbox"<?= $sel('pp_env', 'sandbox', 'sandbox') ?>>Test (sandbox) : aucun vrai paiement</option><option value="live"<?= $sel('pp_env', 'live', 'sandbox') ?>>Réel</option></select>
<?= $in('pp_client', 'Client ID') ?>
<?= $in('pp_secret', 'Secret', '', '', 'password') ?>
<?= $in('pp_webhook', 'Webhook ID', '', 'Donné par PayPal quand vous créez le webhook (adresse : votre-site/api/paypal/webhook).') ?>
</fieldset>
<details><summary>Mentions légales (facultatif ici, à compléter avant l'ouverture des ventes)</summary><fieldset><legend>Éditeur du site</legend>
<?= $in('lg_editeur', 'Nom ou raison sociale') ?>
<?= $in('lg_statut', 'Statut (exemple : société, entrepreneur individuel…)') ?>
<?= $in('lg_adresse', 'Adresse') ?>
<?= $in('lg_immat', 'Immatriculation (numéro d\'enregistrement)') ?>
<?= $in('lg_tva', 'Numéro de TVA') ?>
<?= $in('lg_contact_email', 'E-mail de contact') ?>
<?= $in('lg_directeur', 'Directeur de la publication') ?>
<?= $in('lg_hebergeur', 'Hébergeur (nom, adresse, téléphone)', '', 'Recopiez les mentions légales publiées par IONOS pour votre contrat.') ?>
</fieldset></details>
<button type="submit">Installer</button>
</form>
<?php
page('Installation de Liagos 3D', (string)ob_get_clean());
