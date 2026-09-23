<?php

declare(strict_types=1);

/**
 * Router para `php -S localhost:8080 router-dev.php` (sem Apache/.htaccess).
 * Use: scripts\start-web.ps1
 */
$uri = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');

if (str_starts_with($uri, '/api') || str_starts_with($uri, '/cobx/api')) {
    require __DIR__ . '/api/index.php';

    return true;
}

if (preg_match('#^/(?:cobx/)?assets/(.+)$#', $uri, $m)) {
    $file = __DIR__ . '/public/assets/' . $m[1];
    if (is_readable($file)) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $types = [
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
        ];
        header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
        readfile($file);

        return true;
    }
    http_response_code(404);
    echo 'Asset não encontrado';

    return true;
}

$local = __DIR__ . $uri;
if ($uri !== '/' && is_file($local)) {
    return false;
}

require __DIR__ . '/index.php';

return true;
