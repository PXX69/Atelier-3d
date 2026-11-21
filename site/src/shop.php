<?php
declare(strict_types=1);

/** Client PayPal REST (Orders v2) — appels serveur uniquement. */
final class PayPal
{
    static function base(): string
    {
        $b = cfg('paypal.api_base');
        if ($b) return rtrim((string)$b, '/');
        return cfg('paypal.env', 'sandbox') === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }
    static function configured(): bool { return (bool)(cfg('paypal.client_id') && cfg('paypal.secret')); }

    static function http(string $method, string $path, ?string $body = null, array $headers = [], ?string $basic = null): array
    {
        $ch = curl_init(self::base() . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers), CURLOPT_USERAGENT => 'Liagos3D-Shop/1.0']);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        if ($basic) curl_setopt($ch, CURLOPT_USERPWD, $basic);
        $out = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($out === false) { logmsg("paypal réseau: $err"); return ['status' => 0, 'json' => null, 'raw' => $err]; }
        $j = json_decode((string)$out, true);
        return ['status' => $status, 'json' => is_array($j) ? $j : null, 'raw' => (string)$out];
    }
    static function token(): string
    {
        static $t = null, $exp = 0;
        if ($t && $exp > time() + 60) return $t;
        $r = self::http('POST', '/v1/oauth2/token', 'grant_type=client_credentials', ['Content-Type: application/x-www-form-urlencoded'], cfg('paypal.client_id') . ':' . cfg('paypal.secret'));
        if ($r['status'] !== 200 || empty($r['json']['access_token'])) {
            logmsg('paypal jeton: ' . $r['status'] . ' ' . substr($r['raw'], 0, 300));
            throw new RuntimeException('PayPal : authentification impossible (vérifiez Client ID, Secret et le mode test/réel)');
        }
        $exp = time() + (int)($r['json']['expires_in'] ?? 300);
        return $t = (string)$r['json']['access_token'];
    }
    static function api(string $method, string $path, ?array $payload = null, array $extra = []): array
    {
        $body = $payload !== null ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ($method === 'POST' ? '{}' : null);
        $r = self::http($method, $path, $body, array_merge(['Authorization: Bearer ' . self::token(), 'Content-Type: application/json'], $extra));
        if ($r['status'] >= 400 || $r['status'] === 0) logmsg("paypal $method $path -> {$r['status']} " . substr($r['raw'], 0, 400));
        return $r;
    }
    /** Vérification de signature par PayPal lui-même (le corps brut est transmis tel quel). */
    static function verify_webhook(string $raw): bool
    {
        $h = fn(string $n): string => (string)($_SERVER['HTTP_' . $n] ?? '');
        $body = '{"auth_algo":' . json_encode($h('PAYPAL_AUTH_ALGO')) . ',"cert_url":' . json_encode($h('PAYPAL_CERT_URL'))
            . ',"transmission_id":' . json_encode($h('PAYPAL_TRANSMISSION_ID')) . ',"transmission_sig":' . json_encode($h('PAYPAL_TRANSMISSION_SIG'))
            . ',"transmission_time":' . json_encode($h('PAYPAL_TRANSMISSION_TIME')) . ',"webhook_id":' . json_encode((string)cfg('paypal.webhook_id'))
            . ',"webhook_event":' . $raw . '}';
        $r = self::http('POST', '/v1/notifications/verify-webhook-signature', $body, ['Authorization: Bearer ' . self::token(), 'Content-Type: application/json']);
        return ($r['json']['verification_status'] ?? '') === 'SUCCESS';
    }
}

/** Commandes, encaissement, licences, remboursements. */
final class Shop
{
    static function price(): array
    {
        return ['cents' => (int)Settings::get('price_cents', (string)cfg('price.cents', 999)), 'currency' => (string)cfg('price.currency', 'EUR')];
    }
    static function enabled(): bool { return Settings::get('payments_enabled', '0') === '1' && PayPal::configured(); }
    static function public_id(): string
    {
        $a = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $s = '';
        for ($i = 0; $i < 10; $i++) $s .= $a[random_int(0, strlen($a) - 1)];
        return 'LG-' . $s;
    }
    static function vat(int $gross, ?string $country): array
    {
        $map = json_decode(Settings::get('vat_map', '{}'), true);
        $bp = (int)(is_array($map) && $country && isset($map[$country]) ? $map[$country] : Settings::get('vat_default_bp', '0'));
        return [$bp, $bp > 0 ? (int)round($gross * $bp / (10000 + $bp)) : 0];
    }

    static function create_order(array $user): array
    {
        if (!self::enabled()) return ['error' => 'paiement_indisponible', 'code' => 503];
        if (License::user_pro((int)$user['id'])) return ['error' => 'deja_pro', 'code' => 409];
        if (!rl_hit('order_user:' . $user['id'], 3600, 15)) return ['error' => 'trop_de_demandes', 'code' => 429];
        $p = self::price();
        $pub = self::public_id();
        $oid = Db::insert('INSERT INTO orders(public_id, user_id, status, amount_cents, currency, provider, consent_at, created_at) VALUES (?,?,?,?,?,?,?,?)',
            [$pub, $user['id'], 'created', $p['cents'], $p['currency'], 'paypal', time(), time()]);
        $r = PayPal::api('POST', '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [['reference_id' => 'liagos3d-pro', 'custom_id' => $pub, 'invoice_id' => $pub, 'description' => 'Liagos 3D Pro — licence',
                'amount' => ['currency_code' => $p['currency'], 'value' => number_format($p['cents'] / 100, 2, '.', '')]]],
            'application_context' => ['brand_name' => 'Liagos 3D', 'locale' => 'fr-FR', 'shipping_preference' => 'NO_SHIPPING', 'user_action' => 'PAY_NOW'],
        ], ['PayPal-Request-Id: ' . $pub]);
        if (!in_array($r['status'], [200, 201], true) || empty($r['json']['id'])) {
            Db::exec("UPDATE orders SET status='failed', note=? WHERE id=?", [substr('création PayPal : ' . $r['status'] . ' ' . $r['raw'], 0, 500), $oid]);
            return ['error' => 'paypal_erreur', 'code' => 502];
        }
        Db::exec('UPDATE orders SET provider_order_id=? WHERE id=?', [$r['json']['id'], $oid]);
        return ['id' => $r['json']['id'], 'order' => $pub];
    }

    static function capture(array $user, string $poid): array
    {
        $o = Db::one('SELECT * FROM orders WHERE provider_order_id=?', [$poid]);
        if (!$o || (int)$o['user_id'] !== (int)$user['id']) return ['error' => 'commande_inconnue', 'code' => 404];
        return self::capture_order($o);
    }
    private static function capture_order(array $o): array
    {
        if ($o['status'] === 'paid') return self::result($o);
        if (!in_array($o['status'], ['created', 'pending'], true)) return ['error' => 'commande_invalide', 'code' => 409];
        $poid = (string)$o['provider_order_id'];
        $r = PayPal::api('POST', '/v2/checkout/orders/' . rawurlencode($poid) . '/capture', null, ['PayPal-Request-Id: ' . $o['public_id'] . '-cap']);
        if ($r['status'] === 422) {
            $issue = (string)($r['json']['details'][0]['issue'] ?? '');
            if ($issue !== 'ORDER_ALREADY_CAPTURED') return ['error' => $issue === 'INSTRUMENT_DECLINED' ? 'paiement_refuse' : 'paypal_erreur', 'code' => 422, 'restart' => $issue === 'INSTRUMENT_DECLINED'];
            $r = PayPal::api('GET', '/v2/checkout/orders/' . rawurlencode($poid));
        }
        if (!in_array($r['status'], [200, 201], true) || !is_array($r['json'])) return ['error' => 'paypal_erreur', 'code' => 502];
        return self::settle($o, $r['json']);
    }

    /** Contrôle la réponse PayPal puis délivre la licence. */
    static function settle(array $o, array $j): array
    {
        $pu = $j['purchase_units'][0] ?? [];
        $cap = $pu['payments']['captures'][0] ?? null;
        if (!$cap) return ['error' => 'paypal_erreur', 'code' => 502];
        $merchant = (string)cfg('paypal.merchant_id', '');
        $bad = ($cap['amount']['currency_code'] ?? '') !== $o['currency']
            || ($cap['amount']['value'] ?? '') !== number_format($o['amount_cents'] / 100, 2, '.', '')
            || ($pu['custom_id'] ?? '') !== $o['public_id']
            || ($merchant !== '' && ($pu['payee']['merchant_id'] ?? '') !== $merchant);
        if ($bad) {
            Db::exec("UPDATE orders SET status='failed', note='Incohérence entre la commande et la réponse PayPal' WHERE id=? AND status IN ('created','pending')", [$o['id']]);
            Audit::log('système', 'commande_incoherente', $o['public_id']);
            Mail::admin_alert('Commande incohérente ' . $o['public_id'], 'Le montant, la devise ou le vendeur ne correspondent pas. Vérifiez la transaction PayPal ' . ($cap['id'] ?? '?') . '.');
            return ['error' => 'verification_echouee', 'code' => 422];
        }
        $payer = $j['payer'] ?? [];
        $meta = ['capture_id' => (string)($cap['id'] ?? ''), 'payer_email' => $payer['email_address'] ?? null, 'country' => $payer['address']['country_code'] ?? null];
        $st = (string)($cap['status'] ?? '');
        if ($st === 'COMPLETED') return self::result(self::fulfill((int)$o['id'], $meta));
        if ($st === 'PENDING') {
            Db::exec("UPDATE orders SET status='pending', provider_capture_id=?, payer_email=?, country=? WHERE id=? AND status='created'", [$meta['capture_id'], $meta['payer_email'], $meta['country'], $o['id']]);
            return ['ok' => true, 'status' => 'pending'];
        }
        Db::exec("UPDATE orders SET status='failed', note=? WHERE id=? AND status IN ('created','pending')", ['Capture ' . $st, $o['id']]);
        return ['error' => 'paiement_refuse', 'code' => 422, 'restart' => true];
    }

    /** Passe la commande à « payée » (une seule fois) et crée la licence. */
    static function fulfill(int $oid, array $meta): array
    {
        $o0 = Db::one('SELECT * FROM orders WHERE id=?', [$oid]);
        [$bp, $vat] = self::vat((int)$o0['amount_cents'], $meta['country'] ?? null);
        $claimed = Db::exec("UPDATE orders SET status='paid', paid_at=?, provider_capture_id=COALESCE(?, provider_capture_id), payer_email=COALESCE(?, payer_email), country=COALESCE(?, country), vat_bp=?, vat_cents=? WHERE id=? AND status IN ('created','pending')",
            [time(), $meta['capture_id'] ?? null, $meta['payer_email'] ?? null, $meta['country'] ?? null, $bp, $vat, $oid]);
        $o = Db::one('SELECT * FROM orders WHERE id=?', [$oid]);
        if ($claimed === 1) {
            $lic = License::issue((int)$o['user_id'], $oid, 'purchase');
            Audit::log('système', 'commande_payee', $o['public_id'] . ' · licence ' . $lic['key_id']);
            $email = (string)Db::val('SELECT email FROM users WHERE id=?', [$o['user_id']]);
            if (!Mail::license($email, $lic['license_key'], $o)) { logmsg('e-mail de licence non envoyé pour ' . $o['public_id']); Mail::admin_alert('E-mail de licence non envoyé', 'Commande ' . $o['public_id'] . ' : le client peut retrouver sa clé dans son espace client.'); }
        }
        return $o;
    }
    /** Rapproche une commande avec PayPal (si le client a payé mais que la clé n'est pas arrivée). */
    static function reconcile(array $o): string
    {
        if (!$o['provider_order_id']) return 'Aucune commande PayPal liée.';
        $r = PayPal::api('GET', '/v2/checkout/orders/' . rawurlencode((string)$o['provider_order_id']));
        if ($r['status'] !== 200 || !is_array($r['json'])) return 'PayPal ne trouve pas cette commande (code ' . $r['status'] . ').';
        $st = (string)($r['json']['status'] ?? '?');
        if ($st === 'COMPLETED' && in_array($o['status'], ['created', 'pending'], true)) { $res = self::settle($o, $r['json']); return empty($res['error']) ? 'Paiement confirmé : licence délivrée.' : 'Échec : ' . $res['error']; }
        return 'Statut chez PayPal : ' . $st . ' · statut ici : ' . $o['status'];
    }
    static function result(array $o): array
    {
        $lic = null;
        for ($i = 0; $i < 10 && !$lic; $i++) { $lic = Db::one('SELECT * FROM licenses WHERE order_id=? ORDER BY id DESC', [$o['id']]); if (!$lic) usleep(200000); }
        return ['ok' => true, 'status' => $o['status'], 'order' => $o['public_id'], 'key' => $lic['license_key'] ?? null];
    }

    static function mark_refunded(array $o, string $why): void
    {
        $n = Db::exec("UPDATE orders SET status='refunded', refunded_at=?, note=? WHERE id=? AND status IN ('paid','disputed')", [time(), substr($why, 0, 250), $o['id']]);
        if ($n !== 1) return;
        Db::exec("UPDATE licenses SET status='revoked', revoked_at=? WHERE order_id=? AND status='active'", [time(), $o['id']]);
        Audit::log('système', 'commande_remboursee', $o['public_id'] . ' · ' . $why);
        $email = (string)Db::val('SELECT email FROM users WHERE id=?', [$o['user_id']]);
        if (!str_ends_with($email, '@invalid.local')) Mail::refund($email, $o);
    }
    static function refund(array $o): array
    {
        if ($o['status'] !== 'paid' || !$o['provider_capture_id']) return ['error' => 'Cette commande ne peut pas être remboursée automatiquement.'];
        $r = PayPal::api('POST', '/v2/payments/captures/' . rawurlencode((string)$o['provider_capture_id']) . '/refund', null, ['PayPal-Request-Id: ' . $o['public_id'] . '-refund']);
        if (!in_array($r['status'], [200, 201], true)) return ['error' => 'PayPal a refusé le remboursement (' . ($r['json']['details'][0]['issue'] ?? $r['status']) . ').'];
        self::mark_refunded($o, 'Remboursement depuis le back-office');
        return ['ok' => true];
    }

    /* ---------- webhook ---------- */
    /** @return array{0:int,1:string} */
    static function webhook(string $raw): array
    {
        if (!PayPal::configured() || !cfg('paypal.webhook_id')) return [503, 'webhook non configuré'];
        $ev = json_decode($raw, true);
        if (!is_array($ev) || empty($ev['id']) || empty($ev['event_type'])) return [400, 'JSON invalide'];
        try { if (!PayPal::verify_webhook($raw)) { logmsg('webhook: signature refusée'); return [400, 'signature']; } }
        catch (Throwable $e) { logmsg('webhook: ' . $e->getMessage()); return [500, 'vérification impossible']; }
        try { Db::exec('INSERT INTO webhook_events(event_id, type, received_at) VALUES (?,?,?)', [substr((string)$ev['id'], 0, 80), substr((string)$ev['event_type'], 0, 64), time()]); }
        catch (PDOException $e) { if (Db::is_dup($e)) return [200, 'déjà traité']; throw $e; }

        $eid = substr((string)$ev['id'], 0, 80);
        /* En cas d'échec temporaire, l'événement est « libéré » pour que PayPal puisse le renvoyer. */
        $release = function () use ($eid): void { try { Db::exec('DELETE FROM webhook_events WHERE event_id=?', [$eid]); } catch (Throwable $e) { logmsg('webhook: libération impossible'); } };
        try {
            return self::webhook_event($ev, $release);
        } catch (Throwable $e) {
            logmsg('webhook ' . (string)$ev['event_type'] . ' : ' . $e->getMessage());
            $release();
            return [500, 'traitement'];
        }
    }

    /** @return array{0:int,1:string} */
    private static function webhook_event(array $ev, callable $release): array
    {
        $res = is_array($ev['resource'] ?? null) ? $ev['resource'] : [];
        $type = (string)$ev['event_type'];
        $find = function () use ($res, $type): ?array {
            $ids = array_filter([(string)($res['id'] ?? ''), (string)($res['supplementary_data']['related_ids']['order_id'] ?? ''), (string)($res['custom_id'] ?? '')]);
            foreach ((array)($res['links'] ?? []) as $l) if (($l['rel'] ?? '') === 'up') $ids[] = basename((string)parse_url((string)$l['href'], PHP_URL_PATH));
            foreach ((array)($res['disputed_transactions'] ?? []) as $d) $ids[] = (string)($d['seller_transaction_id'] ?? '');
            foreach ($ids as $id) {
                $o = Db::one('SELECT * FROM orders WHERE provider_order_id=? OR provider_capture_id=? OR public_id=?', [$id, $id, $id]);
                if ($o) return $o;
            }
            return null;
        };
        $o = $find();
        if (!$o) { logmsg("webhook $type : commande introuvable"); return [200, 'commande inconnue']; }

        switch ($type) {
            case 'CHECKOUT.ORDER.APPROVED':
                if (in_array($o['status'], ['created', 'pending'], true)) {
                    $r = self::capture_order($o);
                    if ((int)($r['code'] ?? 0) === 502) { $release(); return [500, 'capture']; }
                }
                break;
            case 'PAYMENT.CAPTURE.COMPLETED':
                if (in_array($o['status'], ['created', 'pending'], true)) {
                    $ok = ($res['amount']['currency_code'] ?? '') === $o['currency'] && ($res['amount']['value'] ?? '') === number_format($o['amount_cents'] / 100, 2, '.', '');
                    if ($ok) self::fulfill((int)$o['id'], ['capture_id' => (string)($res['id'] ?? ''), 'payer_email' => null, 'country' => null]);
                    else Mail::admin_alert('Montant inattendu ' . $o['public_id'], 'Notification PayPal avec un montant différent de la commande.');
                }
                break;
            case 'PAYMENT.CAPTURE.PENDING':
                Db::exec("UPDATE orders SET status='pending', provider_capture_id=? WHERE id=? AND status='created'", [(string)($res['id'] ?? ''), $o['id']]);
                break;
            case 'PAYMENT.CAPTURE.DENIED':
                Db::exec("UPDATE orders SET status='failed', note='Paiement refusé par PayPal' WHERE id=? AND status IN ('created','pending')", [$o['id']]);
                break;
            case 'PAYMENT.CAPTURE.REFUNDED':
            case 'PAYMENT.CAPTURE.REVERSED':
                $val = (string)($res['amount']['value'] ?? '');
                $full = number_format($o['amount_cents'] / 100, 2, '.', '');
                if ($type === 'PAYMENT.CAPTURE.REFUNDED' && $val !== '' && (float)$val + 0.0001 < (float)$full) {
                    /* remboursement partiel (geste commercial) : la licence reste valable */
                    Audit::log('système', 'remboursement_partiel', $o['public_id'] . ' · ' . $val);
                    Mail::admin_alert('Remboursement partiel ' . $o['public_id'], 'PayPal signale un remboursement de ' . $val . ' ' . $o['currency'] . ' sur ' . $full . '. La licence reste active ; révoquez-la depuis le back-office si nécessaire.');
                    break;
                }
                self::mark_refunded($o, $type === 'PAYMENT.CAPTURE.REVERSED' ? 'Paiement annulé par PayPal' : 'Remboursement signalé par PayPal');
                break;
            case 'CUSTOMER.DISPUTE.CREATED':
                Db::exec("UPDATE orders SET status='disputed' WHERE id=? AND status='paid'", [$o['id']]);
                Audit::log('système', 'litige', $o['public_id']);
                Mail::admin_alert('Litige PayPal ' . $o['public_id'], 'Un litige a été ouvert pour cette commande. Répondez dans votre compte PayPal ; la licence reste active tant que vous ne la révoquez pas.');
                break;
        }
        return [200, 'ok'];
    }
}
