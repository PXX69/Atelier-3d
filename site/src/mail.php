<?php
declare(strict_types=1);

/** Envoi d'e-mails : SMTP authentifié (boîte IONOS), mail() en secours, fichier pour les essais. */
final class Mail
{
    static function send(string $to, string $subject, string $text): bool
    {
        $m = cfg('mail', []);
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
        $transport = $m['transport'] ?? (!empty($m['host']) ? 'smtp' : 'phpmail');
        $host = parse_url(site_url(), PHP_URL_HOST) ?: 'localhost';
        $from = trim((string)($m['from'] ?? ('noreply@' . $host)));
        $fromName = str_replace(["\r", "\n", '"'], '', (string)($m['from_name'] ?? 'Liagos 3D'));
        $subject = str_replace(["\r", "\n"], ' ', $subject);

        if ($transport === 'file') {
            $f = $m['outbox'] ?? storage('logs/outbox.jsonl');
            @mkdir(dirname($f), 0775, true);
            file_put_contents($f, json_encode(['to' => $to, 'subject' => $subject, 'text' => $text, 'at' => time()], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
            return true;
        }
        $domain = substr((string)strrchr($from, '@'), 1) ?: $host;
        $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $head = [
            'Date: ' . date('r'),
            'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . $encSubject,
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'Auto-Submitted: auto-generated',
        ];
        if (!empty($m['reply_to']) && filter_var($m['reply_to'], FILTER_VALIDATE_EMAIL)) $head[] = 'Reply-To: <' . $m['reply_to'] . '>';
        $body = chunk_split(base64_encode($text), 76, "\r\n");

        if ($transport === 'phpmail') {
            $extra = array_filter($head, fn($l) => strncmp($l, 'To:', 3) !== 0 && strncmp($l, 'Subject:', 8) !== 0);
            $ok = @mail($to, $encSubject, $body, implode("\r\n", $extra), '-f' . $from);
            if (!$ok) logmsg('mail(): échec d\'envoi');
            return $ok;
        }
        $data = implode("\r\n", $head) . "\r\n\r\n" . $body;
        try { return self::smtp($m, $from, $to, $data); }
        catch (Throwable $e) { logmsg('smtp: ' . $e->getMessage()); return false; }
    }

    private static function smtp(array $m, string $from, string $to, string $data): bool
    {
        $secure = $m['secure'] ?? 'ssl';
        $host = (string)($m['host'] ?? '');
        $port = (int)($m['port'] ?? ($secure === 'ssl' ? 465 : 587));
        $verify = (bool)($m['verify_peer'] ?? true);
        $ctx = stream_context_create(['ssl' => ['verify_peer' => $verify, 'verify_peer_name' => $verify, 'peer_name' => $host, 'allow_self_signed' => !$verify]]);
        $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $en, $es, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) throw new RuntimeException("connexion impossible ($host:$port) $es");
        stream_set_timeout($fp, 20);
        $rd = function () use ($fp): string {
            $o = '';
            while (($l = fgets($fp, 2048)) !== false) { $o .= $l; if (strlen($l) < 4 || $l[3] !== '-') break; }
            return $o;
        };
        $step = function (string $send, string $expect, string $label) use ($fp, $rd): string {
            if ($send !== '') fwrite($fp, $send . "\r\n");
            $r = $rd();
            if (strncmp($r, $expect, strlen($expect)) !== 0 && !($expect === '250' && strncmp($r, '251', 3) === 0)) throw new RuntimeException("$label : " . trim(substr($r, 0, 200)));
            return $r;
        };
        $name = parse_url(site_url(), PHP_URL_HOST) ?: 'localhost';
        $step('', '220', 'accueil');
        $step('EHLO ' . $name, '250', 'EHLO');
        if ($secure === 'tls') {
            $step('STARTTLS', '220', 'STARTTLS');
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) throw new RuntimeException('chiffrement TLS refusé');
            $step('EHLO ' . $name, '250', 'EHLO');
        }
        if (!empty($m['user'])) {
            $step('AUTH LOGIN', '334', 'AUTH');
            $step(base64_encode((string)$m['user']), '334', 'identifiant');
            $step(base64_encode((string)($m['pass'] ?? '')), '235', 'mot de passe');
        }
        $step('MAIL FROM:<' . $from . '>', '250', 'MAIL FROM');
        $step('RCPT TO:<' . $to . '>', '250', 'RCPT TO');
        $step('DATA', '354', 'DATA');
        $data = preg_replace("/\r\n|\r|\n/", "\r\n", $data);
        $data = preg_replace('/(^|\r\n)\./', '$1..', $data);
        $step($data . "\r\n.", '250', 'message');
        @fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return true;
    }

    /* ----- messages ----- */
    static function login_code(string $to, string $code): bool
    {
        return self::send($to, "Votre code de connexion Liagos 3D : $code",
            "Bonjour,\n\nVotre code de connexion : $code\n\nIl est valable 10 minutes. Si vous n'avez rien demandé, ignorez ce message : personne ne peut se connecter sans ce code.\n\nLiagos 3D\n" . site_url('/') . "\n");
    }
    static function license(string $to, string $key, array $order): bool
    {
        $vat = $order['vat_cents'] ? ' dont TVA ' . money((int)$order['vat_cents'], $order['currency']) : '';
        return self::send($to, 'Votre licence Liagos 3D Pro',
            "Bonjour,\n\nMerci pour votre achat. Voici votre clé de licence Liagos 3D Pro :\n\n$key\n\n"
            . "Pour l'activer sur Windows ou Android : ouvrez Liagos 3D, menu « Liagos 3D Pro », collez la clé puis validez.\n"
            . "Dans un navigateur : connectez-vous sur " . site_url('/compte') . " puis ouvrez l'application.\n\n"
            . "Reçu — commande " . $order['public_id'] . " du " . fmt_date((int)$order['paid_at'], false) . " : " . money((int)$order['amount_cents'], $order['currency']) . " TTC$vat.\n\n"
            . "Votre clé reste disponible à tout moment dans votre espace client : " . site_url('/compte') . "\n"
            . "Une question : " . (cfg('legal.contact_email') ?: (cfg('mail.reply_to') ?: 'répondez à ce message')) . "\n\nLiagos 3D\n");
    }
    static function refund(string $to, array $order): bool
    {
        return self::send($to, 'Remboursement de votre commande Liagos 3D',
            "Bonjour,\n\nVotre commande " . $order['public_id'] . " (" . money((int)$order['amount_cents'], $order['currency']) . ") a été remboursée. La licence associée est désactivée.\n"
            . "Le montant apparaît sur votre moyen de paiement sous quelques jours selon votre banque ou PayPal.\n\nLiagos 3D\n");
    }
    static function admin_alert(string $subject, string $text): void
    {
        foreach ((array)cfg('admin_emails', []) as $a) self::send((string)$a, '[Liagos 3D] ' . $subject, $text . "\n");
    }
}
