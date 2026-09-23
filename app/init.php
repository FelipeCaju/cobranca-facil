<?php

declare(strict_types=1);

define('COBX_WEB_APP', true);

require_once __DIR__ . '/../api/common.php';
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/lib/request.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/** @var PDO|null */
$GLOBALS['cobx_pdo'] = null;

function app_pdo(): PDO
{
    if ($GLOBALS['cobx_pdo'] instanceof PDO) {
        return $GLOBALS['cobx_pdo'];
    }
    $GLOBALS['cobx_pdo'] = db();

    return $GLOBALS['cobx_pdo'];
}

function app_base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $env = rtrim((string) env('APP_URL', ''), '/');
    if ($env !== '' && preg_match('#^(https?://[^/]+)(/.*)$#', $env, $m)) {
        $base = rtrim($m[2], '/');
        if ($base === '') {
            $base = '';
        }

        return $base;
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (preg_match('#^(/.+)/index\.php$#', $script, $m)) {
        $base = $m[1];
    } else {
        $uri = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
        $base = str_starts_with($uri, '/cobx') ? '/cobx' : '';
    }

    return $base;
}

function app_url(string $path = ''): string
{
    $base = app_base_path();
    $p = $path === '' ? '' : (str_starts_with($path, '/') ? $path : '/' . $path);

    return $base . $p;
}

function app_route(): string
{
    $uri = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
    $base = app_base_path();
    if ($base !== '' && str_starts_with($uri, $base)) {
        $uri = substr($uri, strlen($base)) ?: '/';
    }
    $route = trim($uri, '/');

    return $route === '' ? 'home' : $route;
}

function app_redirect(string $path, int $code = 302): void
{
    header('Location: ' . app_url($path), true, $code);
    exit;
}

function app_view(string $name, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    $viewFile = __DIR__ . '/views/' . $name . '.php';
    if (!is_readable($viewFile)) {
        http_response_code(500);
        echo 'Vista não encontrada: ' . e($name);
        exit;
    }
    require $viewFile;
}

function app_render(string $view, array $vars = [], ?string $title = null): void
{
    $vars['page_title'] = $title ?? ($vars['page_title'] ?? 'CobrançaFácil');
    $vars['user'] = app_user();
    $vars['menu'] = app_menu_items($vars['user']);
    ob_start();
    app_view($view, $vars);
    $content = ob_get_clean();
    app_view('layout', array_merge($vars, ['content' => $content]));
}
