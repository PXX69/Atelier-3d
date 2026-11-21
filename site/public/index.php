<?php
declare(strict_types=1);

require __DIR__ . '/../src/app.php';

set_exception_handler(function (Throwable $e): void {
    logmsg('ERREUR ' . get_class($e) . ' : ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    if (!headers_sent()) http_response_code(500);
    $json = strpos((string)($_SERVER['REQUEST_URI'] ?? ''), '/api/') === 0;
    if ($json) { header('Content-Type: application/json'); echo '{"error":"erreur_serveur"}'; return; }
    echo '<!doctype html><meta charset="utf-8"><title>Erreur</title><p style="font:16px sans-serif;margin:2rem">Une erreur est survenue. Réessayez dans un instant.</p>';
});

route();
