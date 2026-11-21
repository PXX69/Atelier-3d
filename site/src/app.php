<?php
declare(strict_types=1);

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/license.php';
require_once __DIR__ . '/shop.php';
require_once __DIR__ . '/pages.php';
require_once __DIR__ . '/legal.php';
require_once __DIR__ . '/admin.php';

function same_origin_or_die(): void
{
    $o = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    $host = (string)parse_url('//' . (string)($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
    if ($o !== '' && strcasecmp((string)parse_url($o, PHP_URL_HOST), $host) !== 0) json_out(['error' => 'origine'], 403);
}

function serve_app(): void
{
    $u = Auth::user();
    if (!$u) { header('Location: /compte?next=/app/'); return; }
    if (!License::user_pro((int)$u['id'])) {
        http_response_code(403);
        layout('Liagos 3D Pro requis', '<section class="wrap narrow"><h1>Liagos 3D Pro est nécessaire</h1><p>L\'utilisation dans le navigateur est réservée aux détenteurs de Liagos 3D Pro.</p><p><a class="btn primary" href="/pro">Voir Liagos 3D Pro</a> <a class="btn" href="/telecharger">Télécharger la version gratuite</a></p></section>', ['noindex' => true]);
        return;
    }
    $f = storage('app/index.html');
    if (!is_file($f)) {
        http_response_code(503);
        layout('Application indisponible', '<section class="wrap narrow"><h1>Application momentanément indisponible</h1><p>Réessayez dans quelques minutes.</p></section>', ['noindex' => true]);
        return;
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    readfile($f);
}

function route(): void
{
    $rawPath = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $path = '/' . trim((string)preg_replace('#/+#', '/', rawurldecode($rawPath)), '/');
    $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($m === 'HEAD') $m = 'GET';

    if (!cfg('db')) { header('Location: /install.php'); return; }
    if ($path === '/healthz') { header('Content-Type: text/plain'); echo 'ok'; return; }

    if (strncmp($path, '/api/', 5) === 0) { api($path, $m); return; }
    if ($path === '/admin' || strncmp($path, '/admin/', 7) === 0) { Admin::handle($path, $_SERVER['REQUEST_METHOD'] === 'POST' ? 'POST' : $m); return; }
    if ($path === '/app') {
        if (substr($rawPath, -1) !== '/') { header('Location: /app/'); return; }   // les liens relatifs de l'application (icônes, manifeste) en ont besoin
        serve_app(); return;
    }
    if ($path === '/app/manifest.webmanifest') {
        header('Content-Type: application/manifest+json; charset=utf-8'); header('Cache-Control: public, max-age=86400');
        echo json_encode(['name' => 'Liagos 3D', 'short_name' => 'Liagos 3D', 'lang' => 'fr', 'start_url' => '/app/', 'scope' => '/app/', 'display' => 'standalone',
            'background_color' => '#101820', 'theme_color' => '#101820',
            'icons' => [['src' => '/app/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'], ['src' => '/app/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png']]], JSON_UNESCAPED_SLASHES);
        return;
    }
    if (preg_match('#^/app/icons/(icon-192|icon-512)\.png$#', $path, $mm)) { header('Content-Type: image/png'); header('Cache-Control: public, max-age=86400'); readfile(__DIR__ . '/../public/img/' . $mm[1] . '.png'); return; }
    if ($m !== 'GET') { http_response_code(405); return; }

    switch ($path) {
        case '/': page_home(); return;
        case '/pro': page_pro(); return;
        case '/telecharger': page_download(); return;
        case '/compte': page_account(); return;
        case '/mentions-legales': page_legal('mentions'); return;
        case '/cgv': page_legal('cgv'); return;
        case '/confidentialite': page_legal('confidentialite'); return;
    }
    page_404();
}

function api(string $path, string $m): void
{
    if ($path === '/api/paypal/webhook' && $m === 'POST') {
        [$code, $msg] = Shop::webhook((string)file_get_contents('php://input', false, null, 0, 262144));
        json_out(['message' => $msg], $code);
    }
    if ($path === '/api/me' && $m === 'GET') {
        $u = Auth::user();
        json_out(['user' => $u ? ['email' => $u['email'], 'admin' => Auth::is_admin($u), 'pro' => (bool)License::user_pro((int)$u['id']), 'csrf' => $u['csrf']] : null]);
    }
    if ($path === '/api/app-license' && $m === 'GET') {
        $u = Auth::require_user();
        $l = License::user_pro((int)$u['id']);
        if (!$l) json_out(['error' => 'pro_requis'], 403);
        json_out(['key' => $l['license_key']]);
    }
    if ($path === '/api/account/export' && $m === 'GET') {
        $u = Auth::require_user();
        header('Content-Disposition: attachment; filename="liagos3d-mes-donnees.json"');
        json_out(['compte' => ['email' => $u['email']],
            'commandes' => Db::all('SELECT public_id, status, amount_cents, currency, country, created_at, paid_at, refunded_at FROM orders WHERE user_id=?', [$u['id']]),
            'licences' => Db::all('SELECT key_id, license_key, edition, expires_at, status, source, created_at FROM licenses WHERE user_id=?', [$u['id']])]);
    }
    if ($m !== 'POST') json_out(['error' => 'methode'], 405);
    same_origin_or_die();
    $d = read_json();
    switch ($path) {
        case '/api/auth/request':
            $e = Auth::email((string)($d['email'] ?? ''));
            if (!$e) json_out(['error' => 'email_invalide'], 422);
            $r = Auth::request_code($e);
            if (isset($r['error'])) json_out($r, $r['error'] === 'trop_de_demandes' ? 429 : 503);
            json_out(['ok' => true]);
        case '/api/auth/verify':
            $e = Auth::email((string)($d['email'] ?? ''));
            if (!$e) json_out(['error' => 'email_invalide'], 422);
            $r = Auth::verify_code($e, (string)($d['code'] ?? ''));
            if (isset($r['error'])) json_out($r, $r['error'] === 'trop_de_demandes' ? 429 : 401);
            json_out($r);
        case '/api/auth/logout':
            $u = Auth::require_user(); Auth::check_csrf($u); Auth::logout(); json_out(['ok' => true]);
        case '/api/account/delete':
            $u = Auth::require_user(); Auth::check_csrf($u);
            Audit::log($u['email'], 'compte_supprime_par_le_client', (string)$u['id']);
            Auth::delete_account((int)$u['id']); Auth::logout(); json_out(['ok' => true]);
        case '/api/paypal/create':
            $u = Auth::require_user(); Auth::check_csrf($u);
            if (empty($d['consent'])) json_out(['error' => 'consentement_requis'], 422);
            try { $r = Shop::create_order($u); } catch (Throwable $e) { logmsg('create: ' . $e->getMessage()); json_out(['error' => 'paypal_erreur'], 502); }
            json_out($r, (int)($r['code'] ?? 200));
        case '/api/paypal/capture':
            $u = Auth::require_user(); Auth::check_csrf($u);
            $id = (string)($d['orderID'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_-]{5,64}$/', $id)) json_out(['error' => 'commande_inconnue'], 404);
            try { $r = Shop::capture($u, $id); } catch (Throwable $e) { logmsg('capture: ' . $e->getMessage()); json_out(['error' => 'paypal_erreur'], 502); }
            json_out($r, (int)($r['code'] ?? 200));
    }
    json_out(['error' => 'introuvable'], 404);
}
