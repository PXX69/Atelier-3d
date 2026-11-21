<?php
declare(strict_types=1);

/** Connexion sans mot de passe : code à 6 chiffres envoyé par e-mail, session en base. */
final class Auth
{
    private static ?array $cur = null;
    private static bool $loaded = false;

    static function cookie_name(): string { return is_https() ? '__Host-l3d_s' : 'l3d_s'; }
    static function email(string $e): ?string
    {
        $e = strtolower(trim($e));
        return (strlen($e) <= 190 && filter_var($e, FILTER_VALIDATE_EMAIL)) ? $e : null;
    }
    private static function code_hash(string $email, string $code): string { return hash_hmac('sha256', $email . '|' . $code, (string)cfg('secret')); }

    /** @return array{ok?:bool,error?:string} */
    static function request_code(string $email): array
    {
        if (!rl_hit('code_ip:' . client_ip(), 3600, 20) || !rl_hit('code_mail:' . $email, 600, 3)) return ['error' => 'trop_de_demandes'];
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Db::exec('DELETE FROM login_codes WHERE email=?', [$email]);
        Db::exec('INSERT INTO login_codes(email, code_hash, expires_at, attempts, created_at, ip) VALUES (?,?,?,?,?,?)',
            [$email, self::code_hash($email, $code), time() + 600, 0, time(), client_ip()]);
        if (!Mail::login_code($email, $code)) {
            Db::exec('DELETE FROM login_codes WHERE email=?', [$email]);
            return ['error' => 'envoi_impossible'];
        }
        return ['ok' => true];
    }

    /** @return array{ok?:bool,error?:string,csrf?:string} */
    static function verify_code(string $email, string $code): array
    {
        if (!rl_hit('verify_ip:' . client_ip(), 600, 30)) return ['error' => 'trop_de_demandes'];
        $row = Db::one('SELECT id, code_hash, expires_at, attempts FROM login_codes WHERE email=?', [$email]);
        if (!$row || (int)$row['expires_at'] < time() || (int)$row['attempts'] >= 5) {
            if ($row) Db::exec('DELETE FROM login_codes WHERE id=?', [$row['id']]);
            return ['error' => 'code_invalide'];
        }
        $code = preg_replace('/\D/', '', $code);
        if (!hash_equals((string)$row['code_hash'], self::code_hash($email, (string)$code))) {
            Db::exec('UPDATE login_codes SET attempts=attempts+1 WHERE id=?', [$row['id']]);
            return ['error' => 'code_invalide'];
        }
        Db::exec('DELETE FROM login_codes WHERE id=?', [$row['id']]);
        $u = Db::one('SELECT id FROM users WHERE email=? AND deleted_at IS NULL', [$email]);
        if (!$u) {
            try { $id = Db::insert('INSERT INTO users(email, created_at) VALUES (?,?)', [$email, time()]); }
            catch (PDOException $e) { if (!Db::is_dup($e)) throw $e; $id = (int)Db::val('SELECT id FROM users WHERE email=?', [$email]); }
        } else $id = (int)$u['id'];
        Db::exec('UPDATE users SET last_login_at=? WHERE id=?', [time(), $id]);
        return ['ok' => true, 'csrf' => self::start_session($id)];
    }

    private static function start_session(int $uid): string
    {
        $tok = rand_token(32);
        $csrf = rand_token(24);
        Db::exec('DELETE FROM sessions WHERE expires_at < ?', [time()]);
        Db::exec('INSERT INTO sessions(user_id, token_hash, csrf, created_at, expires_at, ip, ua) VALUES (?,?,?,?,?,?,?)',
            [$uid, hash('sha256', $tok), $csrf, time(), time() + 30 * 86400, client_ip(), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
        setcookie(self::cookie_name(), $tok, ['expires' => time() + 30 * 86400, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
        self::$loaded = false;
        return $csrf;
    }

    /** Utilisateur connecté (ligne users + csrf) ou null. */
    static function user(): ?array
    {
        if (self::$loaded) return self::$cur;
        self::$loaded = true;
        self::$cur = null;
        $tok = $_COOKIE[self::cookie_name()] ?? '';
        if (!is_string($tok) || strlen($tok) < 20 || strlen($tok) > 100) return null;
        $r = Db::one('SELECT s.id AS sid, s.csrf, s.expires_at, u.id, u.email FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token_hash=? AND s.expires_at > ? AND u.deleted_at IS NULL', [hash('sha256', $tok), time()]);
        if ($r) {
            if ((int)$r['expires_at'] - time() < 15 * 86400) Db::exec('UPDATE sessions SET expires_at=? WHERE id=?', [time() + 30 * 86400, $r['sid']]);
            self::$cur = $r;
        }
        return self::$cur;
    }
    static function require_user(): array { $u = self::user(); if (!$u) json_out(['error' => 'non_connecte'], 401); return $u; }
    static function is_admin(?array $u = null): bool
    {
        $u = $u ?? self::user();
        if (!$u) return false;
        foreach ((array)cfg('admin_emails', []) as $a) if (strtolower((string)$a) === $u['email']) return true;
        return false;
    }
    static function check_csrf(array $u): void
    {
        $t = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? ''));
        if ($t === '' || !hash_equals((string)$u['csrf'], $t)) json_out(['error' => 'csrf'], 403);
    }
    static function logout(): void
    {
        $tok = $_COOKIE[self::cookie_name()] ?? '';
        if (is_string($tok) && $tok !== '') Db::exec('DELETE FROM sessions WHERE token_hash=?', [hash('sha256', $tok)]);
        setcookie(self::cookie_name(), '', ['expires' => time() - 3600, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
        self::$cur = null; self::$loaded = true;
    }
    /** Suppression RGPD : les commandes sont conservées (obligation comptable) mais sans lien direct avec l'e-mail. */
    static function delete_account(int $uid): void
    {
        Db::tx(function () use ($uid) {
            $anon = 'supprime-' . $uid . '@invalid.local';
            Db::exec('UPDATE users SET email=?, deleted_at=? WHERE id=?', [$anon, time(), $uid]);
            Db::exec('DELETE FROM sessions WHERE user_id=?', [$uid]);
            Db::exec('UPDATE licenses SET note=NULL WHERE user_id=?', [$uid]);
            Db::exec('UPDATE orders SET payer_email=NULL WHERE user_id=?', [$uid]);
        });
    }
}
