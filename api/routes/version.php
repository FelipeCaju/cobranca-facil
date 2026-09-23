<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/version.php';

function handle_version(string $method): never
{
    if ($method !== 'GET') {
        json_response(405, ['error' => 'Método não permitido']);
    }

    json_response(200, cobx_version_payload());
}
