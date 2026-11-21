<?php
declare(strict_types=1);

/** Back-office : tableau de bord, commandes, licences, clients, fichiers, réglages, journal. */
final class Admin
{
    private static function nav(string $cur): string
    {
        $items = ['' => 'Tableau de bord', 'commandes' => 'Commandes', 'licences' => 'Licences', 'clients' => 'Clients', 'fichiers' => 'Fichiers', 'reglages' => 'Réglages', 'journal' => 'Journal'];
        $o = '<nav class="subnav" aria-label="Administration">';
        foreach ($items as $k => $l) $o .= '<a href="/admin' . ($k ? '/' . $k : '') . '"' . ($k === $cur ? ' aria-current="page"' : '') . '>' . h($l) . '</a>';
        return $o . '</nav>';
    }
    private static function page(string $cur, string $title, string $html): void
    {
        $m = isset($_GET['m']) ? '<p class="flash" role="status">' . h(substr((string)$_GET['m'], 0, 300)) . '</p>' : '';
        layout($title . ' — Administration', '<section class="wrap admin"><h1>' . h($title) . '</h1>' . self::nav($cur) . $m . $html . '</section>', ['page' => 'admin', 'noindex' => true]);
    }
    private static function tbl(array $head, array $rows, string $empty = 'Rien à afficher.'): string
    {
        if (!$rows) return '<p>' . h($empty) . '</p>';
        $o = '<div class="tablewrap" role="region" tabindex="0" aria-label="' . h('Tableau : ' . implode(', ', array_filter(array_slice($head, 0, 3)))) . '"><table><thead><tr>';
        foreach ($head as $c) $o .= '<th>' . h($c) . '</th>';
        $o .= '</tr></thead><tbody>';
        foreach ($rows as $r) { $o .= '<tr>'; foreach ($r as $c) $o .= '<td>' . $c . '</td>'; $o .= '</tr>'; }
        return $o . '</tbody></table></div>';
    }
    private static function btn(string $do, array $f, string $label, string $cls = '', string $confirm = ''): string
    {
        $u = Auth::user();
        $o = '<form method="post" action="/admin/action" class="inline"' . ($confirm ? ' data-confirm="' . h($confirm) . '"' : '') . '><input type="hidden" name="csrf" value="' . h($u['csrf']) . '"><input type="hidden" name="do" value="' . h($do) . '">';
        foreach ($f as $k => $v) $o .= '<input type="hidden" name="' . h($k) . '" value="' . h((string)$v) . '">';
        return $o . '<button class="btn ' . h($cls) . '" type="submit">' . h($label) . '</button></form>';
    }
    private static function status(string $s): string
    {
        $l = ['created' => 'En attente', 'pending' => 'Validation', 'paid' => 'Payée', 'refunded' => 'Remboursée', 'disputed' => 'Litige', 'failed' => 'Échec', 'cancelled' => 'Annulée'][$s] ?? $s;
        return '<span class="pill ' . h($s) . '">' . h($l) . '</span>';
    }

    static function handle(string $path, string $method): void
    {
        $u = Auth::user();
        if (!$u) { header('Location: /compte'); exit; }
        if (!Auth::is_admin($u)) { http_response_code(403); layout('Accès refusé', '<section class="wrap narrow"><h1>Accès refusé</h1><p>Ce compte n\'a pas accès à l\'administration.</p></section>', ['noindex' => true]); return; }
        if ($method === 'POST' && $path === '/admin/action') { self::action($u); return; }
        if ($method !== 'GET' && $method !== 'HEAD') { http_response_code(405); return; }
        $p = trim(substr($path, 6), '/');
        if (preg_match('#^export/(commandes|clients)\.csv$#', $p, $m)) { self::csv($m[1]); return; }
        if (preg_match('#^commandes/(\d+)$#', $p, $m)) { self::order((int)$m[1]); return; }
        match ($p) {
            '' => self::dashboard(), 'commandes' => self::orders(), 'licences' => self::licenses(), 'clients' => self::customers(),
            'fichiers' => self::files(), 'reglages' => self::settings(), 'journal' => self::journal(),
            default => page_404(),
        };
    }

    /* ---------- pages ---------- */
    static function dashboard(): void
    {
        $paid = Db::one("SELECT COUNT(*) AS n, COALESCE(SUM(amount_cents),0) AS s FROM orders WHERE status='paid'");
        $stats = [
            ['Commandes payées', (string)$paid['n']], ['Chiffre d\'affaires TTC', money((int)$paid['s'])],
            ['Clients', (string)Db::val('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL')],
            ['Licences actives', (string)Db::val("SELECT COUNT(*) FROM licenses WHERE status='active' AND (expires_at IS NULL OR expires_at > ?)", [time()])],
        ];
        $o = '<div class="stats">';
        foreach ($stats as [$l, $v]) $o .= '<div class="stat"><span>' . h($l) . '</span><strong>' . h($v) . '</strong></div>';
        $o .= '</div>';
        $keyOk = false;
        try { $keyOk = hash_equals(License::expected_public(), License::public_raw_from_private()); } catch (Throwable $e) {}
        $legalOk = trim((string)cfg('legal.editeur')) !== '' && trim((string)cfg('legal.adresse')) !== '' && trim((string)cfg('legal.contact_email')) !== '';
        $checks = [
            ['La clé de signature des licences correspond à l\'application', $keyOk],
            ['PayPal : Client ID et Secret renseignés', PayPal::configured()],
            ['PayPal : identifiant de webhook renseigné', (bool)cfg('paypal.webhook_id')],
            ['PayPal : mode réel (live) — sinon c\'est le mode test', cfg('paypal.env') === 'live'],
            ['E-mails : envoi configuré', !empty(cfg('mail.host')) || cfg('mail.transport') === 'file'],
            ['Mentions légales : éditeur, adresse et contact renseignés', $legalOk],
            ['TVA renseignée dans les réglages', Settings::get('vat_default_bp', '0') !== '0' || Settings::get('vat_map', '{}') !== '{}'],
            ['Application navigateur déposée', is_file(storage('app/index.html'))],
            ['Fichiers Windows et Android déposés', (bool)(Files::latest('win-setup') && Files::latest('android'))],
        ];
        $o .= '<h2>Avant d\'ouvrir la vente</h2><ul class="checks">';
        foreach ($checks as [$l, $ok]) $o .= '<li class="' . ($ok ? 'ok' : 'todo') . '"><span aria-hidden="true">' . ($ok ? '✔' : '○') . '</span> ' . h($l) . '<span class="sr"> : ' . ($ok ? 'fait' : 'à faire') . '</span></li>';
        $o .= '</ul><p>La vente est actuellement <strong>' . (Shop::enabled() ? 'ouverte' : 'fermée') . '</strong>. <a href="/admin/reglages">Réglages</a></p>';
        $rows = [];
        foreach (Db::all('SELECT o.*, u.email FROM orders o JOIN users u ON u.id=o.user_id ORDER BY o.id DESC LIMIT 8') as $r)
            $rows[] = [h(fmt_date((int)$r['created_at'])), '<a href="/admin/commandes/' . (int)$r['id'] . '">' . h($r['public_id']) . '</a>', h($r['email']), h(money((int)$r['amount_cents'], $r['currency'])), self::status($r['status'])];
        $o .= '<h2>Dernières commandes</h2>' . self::tbl(['Date', 'Référence', 'Client', 'Montant', 'État'], $rows, 'Aucune commande pour l\'instant.');
        self::page('', 'Tableau de bord', $o);
    }

    static function orders(): void
    {
        $q = trim((string)($_GET['q'] ?? ''));
        $w = ''; $p = [];
        if ($q !== '') { $w = ' WHERE o.public_id LIKE ? OR u.email LIKE ? OR o.payer_email LIKE ?'; $like = '%' . addcslashes($q, '%_') . '%'; $p = [$like, $like, $like]; }
        $rows = [];
        foreach (Db::all('SELECT o.*, u.email FROM orders o JOIN users u ON u.id=o.user_id' . $w . ' ORDER BY o.id DESC LIMIT 200', $p) as $r)
            $rows[] = [h(fmt_date((int)$r['created_at'])), '<a href="/admin/commandes/' . (int)$r['id'] . '">' . h($r['public_id']) . '</a>', h($r['email']), h(money((int)$r['amount_cents'], $r['currency'])), h((string)$r['country']), self::status($r['status'])];
        $o = '<form class="search" method="get"><label class="sr" for="q">Rechercher</label><input id="q" name="q" value="' . h($q) . '" placeholder="Référence ou e-mail"> <button class="btn" type="submit">Rechercher</button> <a class="btn link" href="/admin/export/commandes.csv">Exporter en CSV</a></form>';
        self::page('commandes', 'Commandes', $o . self::tbl(['Date', 'Référence', 'Client', 'Montant', 'Pays', 'État'], $rows, 'Aucune commande.'));
    }

    static function order(int $id): void
    {
        $o = Db::one('SELECT o.*, u.email FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id=?', [$id]);
        if (!$o) { page_404(); return; }
        $dl = [['Référence', h($o['public_id'])], ['État', self::status($o['status'])], ['Client', h($o['email'])], ['Montant', h(money((int)$o['amount_cents'], $o['currency'])) . ($o['vat_cents'] ? ' dont TVA ' . h(money((int)$o['vat_cents'], $o['currency'])) . ' (' . h(number_format($o['vat_bp'] / 100, 2, ',', '')) . ' %)' : '')],
            ['Pays de l\'acheteur', h((string)$o['country'] ?: '—')], ['E-mail PayPal', h((string)$o['payer_email'] ?: '—')], ['Commande PayPal', h((string)$o['provider_order_id'] ?: '—')], ['Transaction PayPal', h((string)$o['provider_capture_id'] ?: '—')],
            ['Créée', h(fmt_date((int)$o['created_at']))], ['Payée', h(fmt_date((int)$o['paid_at']))], ['Remboursée', h(fmt_date((int)$o['refunded_at']))], ['Consentement CGV', h(fmt_date((int)$o['consent_at']))], ['Note', h((string)$o['note'] ?: '—')]];
        $h = '<dl class="kv">';
        foreach ($dl as [$k, $v]) $h .= '<dt>' . h($k) . '</dt><dd>' . $v . '</dd>';
        $h .= '</dl><div class="actions">';
        if ($o['status'] === 'paid') {
            $h .= self::btn('refund', ['order_id' => $id], 'Rembourser via PayPal', 'danger', 'Rembourser cette commande via PayPal et désactiver la licence ?');
            $h .= self::btn('mark_refunded', ['order_id' => $id], 'Marquer comme remboursée (déjà fait dans PayPal)', '', 'Marquer comme remboursée et désactiver la licence ?');
            $h .= self::btn('resend_license', ['order_id' => $id], 'Renvoyer la clé par e-mail');
        }
        if (in_array($o['status'], ['created', 'pending'], true)) $h .= self::btn('reconcile', ['order_id' => $id], 'Vérifier chez PayPal');
        $h .= '</div>';
        $lic = Db::all('SELECT * FROM licenses WHERE order_id=?', [$id]);
        $h .= '<h2>Licence liée</h2>' . self::tbl(['Clé', 'État'], array_map(fn($l) => ['<code>' . h($l['license_key']) . '</code>', h($l['status'])], $lic), 'Aucune licence.');
        self::page('commandes', 'Commande ' . $o['public_id'], $h);
    }

    static function licenses(): void
    {
        $rows = [];
        foreach (Db::all('SELECT l.*, u.email FROM licenses l JOIN users u ON u.id=l.user_id ORDER BY l.id DESC LIMIT 300') as $l) {
            $act = $l['status'] === 'active' ? self::btn('license_revoke', ['license_id' => $l['id']], 'Révoquer', 'danger', 'Révoquer cette licence ? Elle ne donnera plus accès au navigateur ; les applications déjà activées hors ligne continuent de fonctionner.') : self::btn('license_restore', ['license_id' => $l['id']], 'Réactiver');
            $rows[] = [h(fmt_date((int)$l['created_at'], false)), h($l['email']), h($l['source']), $l['expires_at'] ? h(fmt_date((int)$l['expires_at'] - 1, false)) : 'Illimitée', h($l['status']), '<code class="k">' . h(substr($l['license_key'], 0, 14)) . '…</code>', $act];
        }
        $u = Auth::user();
        $f = '<h2>Créer une licence</h2><form method="post" action="/admin/action" class="stack"><input type="hidden" name="csrf" value="' . h($u['csrf']) . '"><input type="hidden" name="do" value="license_issue">'
            . '<label for="li-mail">E-mail du bénéficiaire</label><input id="li-mail" name="email" type="email" required maxlength="190">'
            . '<label for="li-days">Durée</label><select id="li-days" name="days"><option value="0">Illimitée</option><option value="7">7 jours (essai)</option><option value="30">30 jours</option><option value="90">90 jours</option><option value="365">1 an</option></select>'
            . '<label for="li-note">Note (facultatif)</label><input id="li-note" name="note" maxlength="200">'
            . '<label class="check"><input type="checkbox" name="send" value="1" checked> Envoyer la clé par e-mail</label><button class="btn primary" type="submit">Créer la licence</button></form>';
        self::page('licences', 'Licences', $f . '<h2>Toutes les licences</h2>' . self::tbl(['Créée', 'Client', 'Origine', 'Fin', 'État', 'Clé', ''], $rows, 'Aucune licence.'));
    }

    static function customers(): void
    {
        $rows = [];
        foreach (Db::all('SELECT u.*, (SELECT COUNT(*) FROM orders o WHERE o.user_id=u.id AND o.status=\'paid\') AS n_o, (SELECT COUNT(*) FROM licenses l WHERE l.user_id=u.id AND l.status=\'active\') AS n_l FROM users u WHERE u.deleted_at IS NULL ORDER BY u.id DESC LIMIT 300') as $c)
            $rows[] = [h($c['email']), h(fmt_date((int)$c['created_at'], false)), h(fmt_date((int)$c['last_login_at'])), (string)(int)$c['n_o'], (string)(int)$c['n_l'], self::btn('user_delete', ['user_id' => $c['id']], 'Supprimer', 'danger', 'Supprimer ce compte (e-mail effacé, licences inaccessibles) ?')];
        self::page('clients', 'Clients', '<p><a class="btn" href="/admin/export/clients.csv">Exporter en CSV</a></p>' . self::tbl(['E-mail', 'Inscrit le', 'Dernière connexion', 'Achats', 'Licences actives', ''], $rows, 'Aucun client.'));
    }

    static function files(): void
    {
        $u = Auth::user();
        $rows = [];
        foreach (Files::list() as $f) $rows[] = [h($f['name']), h(Files::size($f['size'])), h(fmt_date($f['mtime'])), h((string)($f['kind'] ?? '—')), self::btn('file_delete', ['name' => $f['name']], 'Supprimer', 'danger', 'Supprimer ce fichier du site ?')];
        $app = storage('app/index.html');
        $up = fn(string $kind, string $label) => '<form method="post" action="/admin/action" enctype="multipart/form-data" class="stack"><input type="hidden" name="csrf" value="' . h($u['csrf']) . '"><input type="hidden" name="do" value="file_upload"><input type="hidden" name="kind" value="' . $kind . '"><label for="f-' . $kind . '">' . h($label) . '</label><input id="f-' . $kind . '" name="file" type="file" required><button class="btn primary" type="submit">Envoyer</button></form>';
        $o = '<h2>Téléchargements publics</h2>' . self::tbl(['Fichier', 'Taille', 'Date', 'Type', ''], $rows, 'Aucun fichier déposé.')
            . $up('download', 'Ajouter un fichier (.exe, .apk…)') . '<p class="fine">Limite d\'envoi du serveur : ' . h((string)ini_get('upload_max_filesize')) . '. Pour un fichier plus gros, déposez-le par SFTP dans le dossier <code>public/downloads</code>.</p>'
            . '<h2>Application navigateur (Pro)</h2><p>' . (is_file($app) ? 'Fichier en place : ' . h(Files::size((int)filesize($app))) . ', mis à jour le ' . h(fmt_date((int)filemtime($app))) : 'Aucun fichier en place.') . '</p>'
            . $up('app', 'Remplacer l\'application (fichier index.html de Liagos 3D)');
        self::page('fichiers', 'Fichiers', $o);
    }

    static function settings(): void
    {
        $u = Auth::user();
        $p = Shop::price();
        $vm = json_decode(Settings::get('vat_map', '{}'), true) ?: [];
        $lines = '';
        foreach ($vm as $c => $bp) $lines .= $c . ' ' . rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.') . "\n";
        $en = Shop::enabled();
        $o = '<form method="post" action="/admin/action" class="stack"><input type="hidden" name="csrf" value="' . h($u['csrf']) . '"><input type="hidden" name="do" value="save_settings">'
            . '<h2>Vente</h2><label class="check"><input type="checkbox" name="payments_enabled" value="1"' . (Settings::get('payments_enabled', '0') === '1' ? ' checked' : '') . '> Vente ouverte (affiche les boutons PayPal)</label>'
            . '<p class="fine">État réel : ' . ($en ? 'ouverte' : 'fermée') . (PayPal::configured() ? '' : ' — PayPal n\'est pas encore configuré dans config.php') . '. Mode PayPal : <strong>' . h((string)cfg('paypal.env', 'sandbox')) . '</strong>.</p>'
            . '<label for="s-price">Prix TTC en euros</label><input id="s-price" name="price" inputmode="decimal" value="' . h(number_format($p['cents'] / 100, 2, ',', '')) . '">'
            . '<h2>TVA incluse dans le prix</h2><p class="fine">À définir avec votre expert-comptable. Le prix est TTC : la TVA est extraite du prix selon le pays de l\'acheteur.</p>'
            . '<label for="s-vat">Taux par défaut (%)</label><input id="s-vat" name="vat_default" inputmode="decimal" value="' . h(rtrim(rtrim(number_format((int)Settings::get('vat_default_bp', '0') / 100, 2, '.', ''), '0'), '.')) . '">'
            . '<label for="s-map">Taux par pays (une ligne « FR 20 », « DE 19 »…)</label><textarea id="s-map" name="vat_map" rows="5" maxlength="2000">' . h(trim($lines)) . '</textarea>'
            . '<button class="btn primary" type="submit">Enregistrer</button></form>'
            . '<h2>Connexions</h2><dl class="kv"><dt>Adresse du webhook PayPal</dt><dd><code>' . h(site_url('/api/paypal/webhook')) . '</code></dd><dt>PayPal Client ID</dt><dd>' . h(substr((string)cfg('paypal.client_id'), 0, 8)) . '…</dd><dt>E-mail</dt><dd>' . h((string)(cfg('mail.host') ?: cfg('mail.transport') ?: 'mail() du serveur')) . ' · ' . h((string)cfg('mail.from')) . '</dd><dt>Base de données</dt><dd>' . h(Db::$driver) . '</dd><dt>PHP</dt><dd>' . h(PHP_VERSION) . '</dd></dl>'
            . '<div class="actions">' . self::btn('test_mail', [], 'Envoyer un e-mail de test à ' . $u['email']) . self::btn('purge', [], 'Nettoyer les données périmées') . '</div>';
        self::page('reglages', 'Réglages', $o);
    }

    static function journal(): void
    {
        $a = array_map(fn($r) => [h(fmt_date((int)$r['at'])), h($r['actor']), h($r['action']), h($r['detail'])], Db::all('SELECT * FROM audit ORDER BY id DESC LIMIT 150'));
        $w = array_map(fn($r) => [h(fmt_date((int)$r['received_at'])), h($r['type']), h($r['event_id'])], Db::all('SELECT * FROM webhook_events ORDER BY received_at DESC LIMIT 50'));
        $log = is_file(storage('logs/app.log')) ? implode("\n", array_slice(file(storage('logs/app.log'), FILE_IGNORE_NEW_LINES) ?: [], -60)) : '';
        self::page('journal', 'Journal', '<h2>Actions</h2>' . self::tbl(['Date', 'Auteur', 'Action', 'Détail'], $a, 'Rien pour l\'instant.') . '<h2>Notifications PayPal reçues</h2>' . self::tbl(['Date', 'Type', 'Identifiant'], $w, 'Aucune notification reçue.') . '<h2>Journal technique (60 dernières lignes)</h2><pre class="log" tabindex="0" aria-label="Journal technique">' . h($log ?: 'Vide.') . '</pre>');
    }

    /* ---------- exports ---------- */
    private static function csv(string $what): void
    {
        $cell = fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v;
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="liagos3d-' . $what . '-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        if ($what === 'commandes') {
            fputcsv($out, ['Référence', 'Date de paiement', 'État', 'Montant TTC', 'Devise', 'TVA', 'Taux TVA %', 'Pays', 'E-mail du compte', 'E-mail PayPal', 'Commande PayPal', 'Transaction PayPal'], ';');
            foreach (Db::all('SELECT o.*, u.email FROM orders o JOIN users u ON u.id=o.user_id ORDER BY o.id') as $r)
                fputcsv($out, array_map($cell, [$r['public_id'], $r['paid_at'] ? date('Y-m-d H:i', (int)$r['paid_at']) : '', $r['status'], number_format($r['amount_cents'] / 100, 2, ',', ''), $r['currency'], number_format($r['vat_cents'] / 100, 2, ',', ''), number_format($r['vat_bp'] / 100, 2, ',', ''), (string)$r['country'], $r['email'], (string)$r['payer_email'], (string)$r['provider_order_id'], (string)$r['provider_capture_id']]), ';');
        } else {
            fputcsv($out, ['E-mail', 'Inscrit le', 'Dernière connexion'], ';');
            foreach (Db::all('SELECT * FROM users WHERE deleted_at IS NULL ORDER BY id') as $r) fputcsv($out, array_map($cell, [$r['email'], date('Y-m-d', (int)$r['created_at']), $r['last_login_at'] ? date('Y-m-d H:i', (int)$r['last_login_at']) : '']), ';');
        }
        fclose($out);
    }

    /* ---------- actions ---------- */
    private static function back(string $to, string $msg): never { header('Location: /admin' . ($to ? '/' . $to : '') . '?m=' . rawurlencode($msg)); exit; }

    private static function action(array $u): void
    {
        $t = (string)($_POST['csrf'] ?? '');
        if ($t === '' || !hash_equals((string)$u['csrf'], $t)) { http_response_code(403); echo 'Session expirée : rechargez la page.'; return; }
        $do = (string)($_POST['do'] ?? '');
        $who = $u['email'];
        $order = fn() => Db::one('SELECT * FROM orders WHERE id=?', [(int)($_POST['order_id'] ?? 0)]);
        switch ($do) {
            case 'refund':
                $o = $order() ?: self::back('commandes', 'Commande introuvable.');
                $r = Shop::refund($o); Audit::log($who, 'remboursement', $o['public_id'] . (isset($r['error']) ? ' ÉCHEC ' . $r['error'] : ''));
                self::back('commandes/' . $o['id'], $r['error'] ?? 'Remboursement effectué, licence désactivée.');
            case 'mark_refunded':
                $o = $order() ?: self::back('commandes', 'Commande introuvable.');
                Shop::mark_refunded($o, 'Marquée remboursée par l\'administrateur'); Audit::log($who, 'marque_remboursee', $o['public_id']);
                self::back('commandes/' . $o['id'], 'Commande marquée comme remboursée.');
            case 'reconcile':
                $o = $order() ?: self::back('commandes', 'Commande introuvable.');
                self::back('commandes/' . $o['id'], Shop::reconcile($o));
            case 'resend_license':
                $o = $order() ?: self::back('commandes', 'Commande introuvable.');
                $l = Db::one("SELECT * FROM licenses WHERE order_id=? AND status='active' ORDER BY id DESC", [$o['id']]);
                $email = (string)Db::val('SELECT email FROM users WHERE id=?', [$o['user_id']]);
                self::back('commandes/' . $o['id'], $l && Mail::license($email, $l['license_key'], $o) ? 'E-mail envoyé.' : 'Envoi impossible (voir le journal).');
            case 'license_revoke': case 'license_restore':
                $id = (int)($_POST['license_id'] ?? 0);
                License::revoke($id, $do === 'license_revoke'); Audit::log($who, $do, (string)$id);
                self::back('licences', $do === 'license_revoke' ? 'Licence révoquée.' : 'Licence réactivée.');
            case 'license_issue':
                $email = Auth::email((string)($_POST['email'] ?? '')) ?? self::back('licences', 'Adresse e-mail invalide.');
                $days = max(0, min(3650, (int)($_POST['days'] ?? 0)));
                $uid = (int)Db::val('SELECT id FROM users WHERE email=? AND deleted_at IS NULL', [$email]);
                if (!$uid) $uid = Db::insert('INSERT INTO users(email, created_at) VALUES (?,?)', [$email, time()]);
                try { $lic = License::issue($uid, null, $days ? 'test' : 'manual', 1, $days ? time() + $days * 86400 : null, (string)($_POST['note'] ?? '')); }
                catch (Throwable $e) { logmsg('licence: ' . $e->getMessage()); self::back('licences', 'Création impossible : ' . $e->getMessage()); }
                Audit::log($who, 'licence_creee', $email . ' · ' . ($days ? $days . ' j' : 'illimitée'));
                $sent = '';
                if (!empty($_POST['send'])) $sent = Mail::license($email, $lic['license_key'], ['public_id' => 'manuelle', 'paid_at' => time(), 'amount_cents' => 0, 'currency' => 'EUR', 'vat_cents' => 0]) ? ' E-mail envoyé.' : ' E-mail non envoyé.';
                self::back('licences', 'Licence créée pour ' . $email . '.' . $sent);
            case 'user_delete':
                $id = (int)($_POST['user_id'] ?? 0);
                if ($id === (int)$u['id']) self::back('clients', 'Vous ne pouvez pas supprimer votre propre compte ici.');
                Auth::delete_account($id); Audit::log($who, 'compte_supprime', (string)$id);
                self::back('clients', 'Compte supprimé.');
            case 'file_upload':
                self::upload();
            case 'file_delete':
                $n = (string)($_POST['name'] ?? '');
                if (Files::ok_name($n) && is_file(Files::dir() . '/' . $n)) { unlink(Files::dir() . '/' . $n); Audit::log($who, 'fichier_supprime', $n); self::back('fichiers', 'Fichier supprimé.'); }
                self::back('fichiers', 'Fichier introuvable.');
            case 'save_settings':
                Settings::set('payments_enabled', !empty($_POST['payments_enabled']) ? '1' : '0');
                $price = (int)round((float)str_replace(',', '.', (string)($_POST['price'] ?? '0')) * 100);
                if ($price >= 100 && $price <= 100000) Settings::set('price_cents', (string)$price);
                Settings::set('vat_default_bp', (string)max(0, min(5000, (int)round((float)str_replace(',', '.', (string)($_POST['vat_default'] ?? '0')) * 100))));
                $map = [];
                foreach (preg_split('/\R/', (string)($_POST['vat_map'] ?? '')) as $ln)
                    if (preg_match('/^\s*([A-Za-z]{2})\s+(\d+(?:[.,]\d+)?)\s*%?\s*$/', $ln, $m)) $map[strtoupper($m[1])] = (int)round((float)str_replace(',', '.', $m[2]) * 100);
                Settings::set('vat_map', json_encode((object)$map));
                Audit::log($who, 'reglages', 'vente=' . Settings::get('payments_enabled') . ' prix=' . Settings::get('price_cents', '999'));
                self::back('reglages', 'Réglages enregistrés.' . (!empty($_POST['payments_enabled']) && !PayPal::configured() ? ' Attention : PayPal n\'est pas configuré, la vente reste fermée.' : ''));
            case 'test_mail':
                self::back('reglages', Mail::send($u['email'], 'Test Liagos 3D', "Si vous lisez ce message, l'envoi d'e-mails fonctionne.\n") ? 'E-mail de test envoyé à ' . $u['email'] . '.' : 'Échec de l\'envoi : voir le journal technique.');
            case 'purge':
                $n = Db::exec('DELETE FROM login_codes WHERE expires_at < ?', [time()]) + Db::exec('DELETE FROM sessions WHERE expires_at < ?', [time()]) + Db::exec('DELETE FROM rate_limits WHERE window_start < ?', [time() - 86400]) + Db::exec('DELETE FROM webhook_events WHERE received_at < ?', [time() - 90 * 86400]);
                $c = Db::exec("UPDATE orders SET status='cancelled' WHERE status='created' AND created_at < ?", [time() - 3 * 3600]);
                self::back('reglages', "Nettoyage terminé ($n lignes supprimées, $c commandes abandonnées annulées).");
        }
        self::back('', 'Action inconnue.');
    }

    private static function upload(): never
    {
        $f = $_FILES['file'] ?? null;
        $kind = (string)($_POST['kind'] ?? '');
        if (!$f || ($f['error'] ?? 4) !== UPLOAD_ERR_OK) self::back('fichiers', ($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'Fichier trop gros pour le serveur (limite ' . ini_get('upload_max_filesize') . ') : déposez-le par SFTP.' : 'Envoi impossible.');
        $who = Auth::user()['email'];
        if ($kind === 'app') {
            $head = strtolower((string)file_get_contents($f['tmp_name'], false, null, 0, 64));
            if (strpos($head, '<!doctype html') === false || $f['size'] > 30_000_000) self::back('fichiers', 'Ce fichier n\'est pas une application Liagos 3D (index.html) valide.');
            @mkdir(storage('app'), 0775, true);
            $tmp = storage('app/index.html.tmp');
            if (!move_uploaded_file($f['tmp_name'], $tmp) || !rename($tmp, storage('app/index.html'))) self::back('fichiers', 'Écriture impossible.');
            Audit::log($who, 'app_navigateur_remplacee', (string)$f['size']);
            self::back('fichiers', 'Application navigateur remplacée.');
        }
        $name = basename((string)$f['name']);
        if (!Files::ok_name($name)) self::back('fichiers', 'Nom de fichier refusé (lettres, chiffres, point, tiret ; extensions .exe .apk .aab .msi .zip).');
        @mkdir(Files::dir(), 0775, true);
        if (!move_uploaded_file($f['tmp_name'], Files::dir() . '/' . $name)) self::back('fichiers', 'Écriture impossible.');
        Audit::log($who, 'fichier_ajoute', $name);
        self::back('fichiers', 'Fichier ajouté : ' . $name);
    }
}
