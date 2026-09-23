<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
if (is_readable(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
require __DIR__ . '/db.php';
require __DIR__ . '/jwt.php';
require __DIR__ . '/routes/auth.php';
require __DIR__ . '/routes/company.php';
require __DIR__ . '/routes/admin_master.php';
require __DIR__ . '/routes/cron.php';
require __DIR__ . '/routes/webhooks.php';
require __DIR__ . '/routes/theme.php';
require __DIR__ . '/routes/public_plans.php';
require_once __DIR__ . '/routes/public_charge.php';

try {
    $pdo = db();
} catch (Throwable $e) {
    json_response(503, ['error' => 'Banco de dados indisponível']);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = is_string($uri) ? $uri : '/';

// Funciona em /api/... ou /cobx/api/... (subpasta no Apache)
$path = '';
if (preg_match('#/api/(.+)$#u', $uri, $m)) {
    $path = trim($m[1], '/');
} elseif (preg_match('#/api$#u', $uri)) {
    $path = '';
} else {
    json_response(404, ['error' => 'Rota não encontrada']);
}

$segments = $path === '' ? [] : explode('/', $path);

if (($segments[0] ?? '') === 'cron') {
    handle_cron($pdo, $method, $segments);
}

if (($segments[0] ?? '') === 'theme') {
    handle_theme($pdo, $method);
}

if (($segments[0] ?? '') === 'public' && ($segments[1] ?? '') === 'plans') {
    handle_public_plans($pdo, $method);
}
if (($segments[0] ?? '') === 'public' && ($segments[1] ?? '') === 'charge') {
    handle_public_charge($pdo, $method, $segments);
}

if (($segments[0] ?? '') === 'auth') {
    handle_auth($pdo, ['method' => $method, 'segments' => $segments]);
}

if (($segments[0] ?? '') === 'webhooks') {
    handle_webhooks($pdo, $method, array_slice($segments, 1));
}

if (($segments[0] ?? '') === 'company') {
    handle_company($pdo, $method, array_slice($segments, 1));
}

if (($segments[0] ?? '') === 'admin') {
    handle_admin($pdo, $method, array_slice($segments, 1));
}

json_response(404, ['error' => 'Rota não encontrada']);
