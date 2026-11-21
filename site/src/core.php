<?php
declare(strict_types=1);

const L3D_ROOT = __DIR__ . '/..';
date_default_timezone_set('Europe/Paris');
/* Jamais d'erreur PHP affichée aux visiteurs (chemins, requêtes) : tout va dans storage/logs/php-errors.log. */
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
@ini_set('expose_php', '0');
if (!headers_sent()) header_remove('X-Powered-By');
@ini_set('log_errors', '1');
if (is_dir(__DIR__ . '/../storage/logs')) @ini_set('error_log', __DIR__ . '/../storage/logs/php-errors.log');

/* ---------- configuration ---------- */
function cfg(?string $path = null, $default = null, bool $reload = false)
{
    static $c = null;
    if ($c === null || $reload) {
        $f = getenv('L3D_CONFIG') ?: L3D_ROOT . '/config.php';
        $c = is_file($f) ? (require $f) : [];
        if (!is_array($c)) $c = [];
    }
    if ($path === null) return $c;
    $v = $c;
    foreach (explode('.', $path) as $p) {
        if (!is_array($v) || !array_key_exists($p, $v)) return $default;
        $v = $v[$p];
    }
    return $v;
}
function storage(string $p = ''): string { return (cfg('storage_dir') ?: L3D_ROOT . '/storage') . ($p !== '' ? '/' . ltrim($p, '/') : ''); }

/* ---------- petits outils ---------- */
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function b64u(string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }
function b64u_dec(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true); }
function rand_token(int $bytes = 32): string { return b64u(random_bytes($bytes)); }
function is_https(): bool { return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || (string)($_SERVER['SERVER_PORT'] ?? '') === '443'; }
function client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 64); }
function site_url(string $path = ''): string { return rtrim((string)cfg('site_url', ''), '/') . $path; }
function money(int $cents, string $cur = 'EUR'): string { return number_format($cents / 100, 2, ',', "\u{202F}") . "\u{00A0}" . ($cur === 'EUR' ? '€' : $cur); }
function fmt_date(?int $ts, bool $time = true): string { return $ts ? date($time ? 'd/m/Y H:i' : 'd/m/Y', $ts) : '—'; }
function json_out($data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    if (is_https()) header('Strict-Transport-Security: max-age=31536000');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function read_json(): array
{
    $ct = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (strpos($ct, 'application/json') !== 0) json_out(['error' => 'type_invalide'], 415);
    $raw = file_get_contents('php://input', false, null, 0, 65536);
    $d = json_decode((string)$raw, true);
    return is_array($d) ? $d : [];
}
function logmsg(string $m): void
{
    $f = storage('logs/app.log');
    @mkdir(dirname($f), 0775, true);
    if (@filesize($f) > 2_000_000) @rename($f, $f . '.1');
    @file_put_contents($f, date('c') . ' ' . str_replace(["\r", "\n"], ' ', $m) . "\n", FILE_APPEND | LOCK_EX);
}

/* ---------- base de données (MySQL en production, SQLite pour les essais) ---------- */
final class Db
{
    private static ?PDO $pdo = null;
    public static string $driver = 'mysql';

    static function pdo(): PDO
    {
        if (self::$pdo) return self::$pdo;
        $c = cfg('db');
        if (!$c) throw new RuntimeException('Base de données non configurée');
        self::$driver = $c['driver'] ?? 'mysql';
        if (self::$driver === 'sqlite') {
            $pdo = new PDO('sqlite:' . $c['path']);
            $pdo->exec('PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL;');
        } else {
            $dsn = 'mysql:host=' . $c['host'] . (isset($c['port']) ? ';port=' . (int)$c['port'] : '') . ';dbname=' . $c['name'] . ';charset=utf8mb4';
            $pdo = new PDO($dsn, $c['user'], $c['pass']);
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return self::$pdo = $pdo;
    }
    static function q(string $sql, array $p = []): PDOStatement { $s = self::pdo()->prepare($sql); $s->execute($p); return $s; }
    static function all(string $sql, array $p = []): array { return self::q($sql, $p)->fetchAll(); }
    static function one(string $sql, array $p = []): ?array { $r = self::q($sql, $p)->fetch(); return $r === false ? null : $r; }
    static function val(string $sql, array $p = []) { $r = self::q($sql, $p)->fetch(PDO::FETCH_NUM); return $r === false ? null : $r[0]; }
    static function exec(string $sql, array $p = []): int { return self::q($sql, $p)->rowCount(); }
    static function insert(string $sql, array $p = []): int { self::q($sql, $p); return (int)self::pdo()->lastInsertId(); }
    static function tx(callable $fn)
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try { $r = $fn(); $pdo->commit(); return $r; }
        catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
    static function is_dup(PDOException $e): bool { return in_array((string)$e->getCode(), ['23000', '23505', '19'], true) || stripos($e->getMessage(), 'UNIQUE') !== false || stripos($e->getMessage(), 'Duplicate') !== false; }

    static function migrate(): void
    {
        self::pdo();
        $sq = self::$driver === 'sqlite';
        $pk = $sq ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT AUTO_INCREMENT PRIMARY KEY';
        $i = $sq ? 'INTEGER' : 'BIGINT';
        $tail = $sq ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        $t = [
            "users (id $pk, email VARCHAR(190) NOT NULL UNIQUE, created_at $i NOT NULL, last_login_at $i, deleted_at $i)",
            "login_codes (id $pk, email VARCHAR(190) NOT NULL, code_hash CHAR(64) NOT NULL, expires_at $i NOT NULL, attempts INT NOT NULL DEFAULT 0, created_at $i NOT NULL, ip VARCHAR(64))",
            "sessions (id $pk, user_id $i NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, csrf VARCHAR(64) NOT NULL, created_at $i NOT NULL, expires_at $i NOT NULL, ip VARCHAR(64), ua VARCHAR(255))",
            "orders (id $pk, public_id VARCHAR(32) NOT NULL UNIQUE, user_id $i NOT NULL, status VARCHAR(20) NOT NULL, amount_cents INT NOT NULL, currency CHAR(3) NOT NULL, provider VARCHAR(20) NOT NULL, provider_order_id VARCHAR(64) UNIQUE, provider_capture_id VARCHAR(64), payer_email VARCHAR(190), country CHAR(2), vat_bp INT NOT NULL DEFAULT 0, vat_cents INT NOT NULL DEFAULT 0, consent_at $i, created_at $i NOT NULL, paid_at $i, refunded_at $i, note TEXT)",
            "licenses (id $pk, user_id $i NOT NULL, order_id $i, key_id VARCHAR(32) NOT NULL UNIQUE, license_key TEXT NOT NULL, edition INT NOT NULL DEFAULT 1, expires_at $i, status VARCHAR(12) NOT NULL, source VARCHAR(12) NOT NULL, note VARCHAR(255), created_at $i NOT NULL, revoked_at $i)",
            "webhook_events (event_id VARCHAR(80) PRIMARY KEY, type VARCHAR(64), received_at $i NOT NULL)",
            "audit (id $pk, at $i NOT NULL, actor VARCHAR(190), action VARCHAR(64) NOT NULL, detail TEXT)",
            "settings (k VARCHAR(64) PRIMARY KEY, v TEXT)",
            "rate_limits (k VARCHAR(190) PRIMARY KEY, window_start $i NOT NULL, hits INT NOT NULL)",
        ];
        foreach ($t as $def) self::pdo()->exec('CREATE TABLE IF NOT EXISTS ' . $def . $tail);
    }
}

final class Settings
{
    private static ?array $c = null;
    static function all(): array { if (self::$c === null) { self::$c = []; foreach (Db::all('SELECT k, v FROM settings') as $r) self::$c[$r['k']] = $r['v']; } return self::$c; }
    static function get(string $k, string $d = ''): string { $a = self::all(); return array_key_exists($k, $a) && $a[$k] !== null ? (string)$a[$k] : $d; }
    static function set(string $k, string $v): void
    {
        if (Db::exec('UPDATE settings SET v=? WHERE k=?', [$v, $k]) === 0) {
            try { Db::exec('INSERT INTO settings(k, v) VALUES (?, ?)', [$k, $v]); }
            catch (PDOException $e) { if (!Db::is_dup($e)) throw $e; Db::exec('UPDATE settings SET v=? WHERE k=?', [$v, $k]); }
        }
        self::$c = null;
    }
}

final class Audit
{
    static function log(string $actor, string $action, string $detail = ''): void
    {
        try { Db::exec('INSERT INTO audit(at, actor, action, detail) VALUES (?,?,?,?)', [time(), substr($actor, 0, 190), substr($action, 0, 64), substr($detail, 0, 4000)]); }
        catch (Throwable $e) { logmsg('audit: ' . $e->getMessage()); }
    }
}

/** Limiteur simple : true = autorisé. */
function rl_hit(string $key, int $window, int $max): bool
{
    $now = time();
    $key = substr($key, 0, 190);
    $row = Db::one('SELECT window_start, hits FROM rate_limits WHERE k=?', [$key]);
    if (!$row || (int)$row['window_start'] + $window <= $now) {
        if ($row) Db::exec('UPDATE rate_limits SET window_start=?, hits=1 WHERE k=?', [$now, $key]);
        else { try { Db::exec('INSERT INTO rate_limits(k, window_start, hits) VALUES (?,?,1)', [$key, $now]); } catch (PDOException $e) { if (!Db::is_dup($e)) throw $e; } }
        return true;
    }
    if ((int)$row['hits'] >= $max) return false;
    Db::exec('UPDATE rate_limits SET hits=hits+1 WHERE k=?', [$key]);
    return true;
}
