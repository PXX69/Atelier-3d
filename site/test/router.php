<?php
// Routeur pour « php -S » : imite les règles .htaccess (fichiers existants servis tels quels, le reste passe par index.php).
$root = __DIR__ . '/../public';
$p = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$f = $root . $p;
if ($p !== '/' && is_file($f)) return false;
chdir($root);
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $root . '/index.php';
