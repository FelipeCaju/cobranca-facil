<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

if (!defined('COBX_WEB_APP')) {
    header('Content-Type: application/json; charset=utf-8');

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = [
        'http://localhost',
        'http://localhost:4173',
        'http://localhost:5173',
        'http://localhost:8080',
        'http://127.0.0.1',
        'http://127.0.0.1:4173',
        'http://127.0.0.1:5173',
        'http://127.0.0.1:8080',
        'http://[::1]:4173',
        'http://[::1]:5173',
        'http://[::1]:8080',
        'http://cobx.test',
        'http://cobx.test:5173',
        'http://cobx.test:8080',
    ];
    $appUrl = rtrim((string) env('APP_URL', ''), '/');
    if ($appUrl !== '') {
        $allowed[] = $appUrl;
    }
    if ($origin !== '' && in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

if (empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $alt = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? getenv('HTTP_AUTHORIZATION');
    if (is_string($alt) && $alt !== '') {
        $_SERVER['HTTP_AUTHORIZATION'] = $alt;
    }
}
