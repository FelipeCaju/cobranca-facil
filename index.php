<?php

declare(strict_types=1);

require_once __DIR__ . '/install/Installer.php';

function cobx_serve_dist_file(string $relativePath): void
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === '' || str_contains($relativePath, '..')) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Arquivo nao encontrado.';
        exit;
    }

    $file = __DIR__ . '/dist/' . $relativePath;
    if (!is_readable($file) || !is_file($file)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Arquivo nao encontrado em dist/.';
        exit;
    }

    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $types = [
        'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'json' => 'application/json; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
        'map' => 'application/json; charset=utf-8',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];

    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

$requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$requestPath = is_string($requestPath) ? $requestPath : '/';
if (preg_match('#/(?:cobx/)?assets/(.+)$#', $requestPath, $assetMatch)) {
    cobx_serve_dist_file('assets/' . $assetMatch[1]);
}
if (preg_match('#/(?:cobx/)?(favicon\.png|robots\.txt)$#', $requestPath, $staticMatch)) {
    cobx_serve_dist_file($staticMatch[1]);
}

if (!CobxInstaller::isInstalled()) {
    $base = CobxInstaller::detectRequestBase();
    if (!preg_match('#/install/#', (string) ($_SERVER['REQUEST_URI'] ?? ''))) {
        header('Location: ' . ($base !== '' ? $base : '') . '/install/');
        exit;
    }
}

/**
 * Serve o build React (dist/index.html) para todas as rotas da SPA.
 * Não alterar o HTML aqui — o ficheiro em dist/ deve ser idêntico ao que o Vite gera.
 */
$distIndex = __DIR__ . '/dist/index.html';
if (!is_readable($distIndex)) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><title>Cobx</title>';
    echo '<style>body{font-family:system-ui,sans-serif;max-width:40rem;margin:3rem auto;padding:0 1rem;line-height:1.5}</style></head><body>';
    echo '<h1>Instalação necessária</h1>';
    echo '<p>Abra o instalador:</p><p><a href="' . htmlspecialchars(CobxInstaller::installUrl(), ENT_QUOTES) . '">Instalar CobrançaFácil</a></p>';
    echo '<p>Ou execute <code>npm run build:root</code> no projeto.</p>';
    echo '</body></html>';
    exit;
}

$html = file_get_contents($distIndex);
if ($html === false) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo 'Erro ao ler o frontend.';
    exit;
}

// Remove qualquer saída acidental antes do HTML (avisos PHP, BOM, etc.)
if (preg_match('#(?=<(?:!doctype|html))#i', $html, $m, PREG_OFFSET_CAPTURE)) {
    $html = substr($html, (int) $m[0][1]);
}

header('Content-Type: text/html; charset=utf-8');
echo $html;
