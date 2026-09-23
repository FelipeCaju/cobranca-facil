<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/brand_accent.php';
require_once __DIR__ . '/../lib/system_branding.php';

/** Cor de marca e nome do sistema (público — login + painel). */
function handle_theme(PDO $pdo, string $method): void
{
    if ($method !== 'GET') {
        json_response(405, ['error' => 'Método não permitido']);
    }

    json_response(200, [
        'brand_accent' => brand_accent_read($pdo),
        'system_name' => cobx_system_name($pdo),
        'system_description' => cobx_system_description($pdo),
    ]);
}
