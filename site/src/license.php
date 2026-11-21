<?php
declare(strict_types=1);

/**
 * Clés de licence Liagos 3D Pro — mêmes règles que l'application (vérification hors ligne) :
 *  clé = "L3D1-" + base64url( charge(12 octets) || signature ECDSA P-256/SHA-256 (r||s, 64 octets) )
 *  charge = version(1) · édition(1) · jour d'expiration depuis le 01/01/2026 (uint16, 0 = illimitée) · 8 octets aléatoires
 *  signé   = "Liagos3D-Pro-v1\0" || charge
 */
final class License
{
    const PREFIX = 'L3D1-';
    const CTX = "Liagos3D-Pro-v1\0";
    const EPOCH = 1767225600; // 2026-01-01T00:00:00Z

    static function private_key()
    {
        $p = cfg('license.private_key_path') ?: storage('keys/license-private.pem');
        $pem = is_file($p) ? (string)file_get_contents($p) : '';
        $k = $pem !== '' ? openssl_pkey_get_private($pem) : false;
        if (!$k) throw new RuntimeException('Clé de signature des licences introuvable ou illisible');
        return $k;
    }
    static function public_raw_from_private(): string
    {
        $d = openssl_pkey_get_details(self::private_key());
        if (!$d || ($d['type'] ?? 0) !== OPENSSL_KEYTYPE_EC) throw new RuntimeException('La clé doit être une clé ECDSA P-256');
        return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    }
    /** Clé publique attendue (celle qui est dans l'application), 65 octets. */
    static function expected_public(): string
    {
        $b = cfg('license.public_key') ?: ((require __DIR__ . '/keys.php')['public'] ?? '');
        return b64u_dec((string)$b);
    }
    static function der_to_raw(string $der): string
    {
        $o = 2;
        if (ord($der[0]) !== 0x30) throw new RuntimeException('signature DER invalide');
        if (ord($der[1]) & 0x80) $o += ord($der[1]) & 0x7f;
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            if (ord($der[$o++]) !== 0x02) throw new RuntimeException('signature DER invalide');
            $l = ord($der[$o++]);
            $v = ltrim(substr($der, $o, $l), "\0");
            $o += $l;
            if (strlen($v) > 32) throw new RuntimeException('signature DER invalide');
            $out .= str_pad($v, 32, "\0", STR_PAD_LEFT);
        }
        return $out;
    }
    static function raw_to_der(string $raw): string
    {
        $enc = function (string $v): string { $v = ltrim($v, "\0"); if ($v === '' || ord($v[0]) & 0x80) $v = "\0" . $v; return "\x02" . chr(strlen($v)) . $v; };
        $b = $enc(substr($raw, 0, 32)) . $enc(substr($raw, 32, 32));
        return "\x30" . chr(strlen($b)) . $b;
    }
    static function day_of(int $ts): int { return intdiv($ts - self::EPOCH, 86400); }

    /** Fabrique une clé. $lastDayTs : instant situé dans le dernier jour de validité (null = illimitée). */
    static function mint(int $edition = 1, ?int $lastDayTs = null): array
    {
        $days = 0;
        if ($lastDayTs !== null) {
            $days = self::day_of($lastDayTs);
            if ($days < 1 || $days > 65535) throw new InvalidArgumentException('Date d\'expiration hors limites');
        }
        $id = random_bytes(8);
        $payload = chr(1) . chr($edition) . pack('n', $days) . $id;
        if (!openssl_sign(self::CTX . $payload, $der, self::private_key(), OPENSSL_ALGO_SHA256)) throw new RuntimeException('Signature impossible');
        return [
            'key' => self::PREFIX . b64u($payload . self::der_to_raw($der)),
            'key_id' => bin2hex($id),
            'edition' => $edition,
            'expires_at' => $days ? self::EPOCH + ($days + 1) * 86400 : null, // instant exclusif
        ];
    }
    /** Vérifie une clé avec la clé publique attendue. Retourne ['edition','days'] ou null. */
    static function verify(string $key): ?array
    {
        $key = preg_replace('/\s+/', '', $key);
        if (strncmp($key, self::PREFIX, 5) !== 0) return null;
        $raw = b64u_dec(substr($key, 5));
        if (strlen($raw) !== 76) return null;
        $pub = self::expected_public();
        if (strlen($pub) !== 65) return null;
        $spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $pub;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
        if (openssl_verify(self::CTX . substr($raw, 0, 12), self::raw_to_der(substr($raw, 12)), $pem, OPENSSL_ALGO_SHA256) !== 1) return null;
        return ['version' => ord($raw[0]), 'edition' => ord($raw[1]), 'days' => unpack('n', substr($raw, 2, 2))[1]];
    }

    static function issue(int $userId, ?int $orderId, string $source, int $edition = 1, ?int $lastDayTs = null, string $note = ''): array
    {
        $m = self::mint($edition, $lastDayTs);
        $id = Db::insert('INSERT INTO licenses(user_id, order_id, key_id, license_key, edition, expires_at, status, source, note, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$userId, $orderId, $m['key_id'], $m['key'], $edition, $m['expires_at'], 'active', $source, substr($note, 0, 255), time()]);
        return Db::one('SELECT * FROM licenses WHERE id=?', [$id]);
    }
    static function revoke(int $id, bool $revoked = true): void
    {
        Db::exec('UPDATE licenses SET status=?, revoked_at=? WHERE id=?', [$revoked ? 'revoked' : 'active', $revoked ? time() : null, $id]);
    }
    /** Licence Pro valide (active et non expirée) d'un utilisateur. */
    static function user_pro(int $userId): ?array
    {
        return Db::one("SELECT * FROM licenses WHERE user_id=? AND status='active' AND (expires_at IS NULL OR expires_at > ?) ORDER BY (expires_at IS NULL) DESC, expires_at DESC, id DESC", [$userId, time()]);
    }
}
