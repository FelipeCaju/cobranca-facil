<?php

declare(strict_types=1);

function env(string $key, ?string $default = null): ?string
{
    $v = getenv($key);
    if ($v !== false && $v !== '') {
        return $v;
    }

    return $default;
}

function load_env_file(string $path): void
{
    if (!is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$k, $raw] = explode('=', $line, 2);
        $k = trim($k);
        if ($k === '') {
            continue;
        }
        $raw = trim($raw);
        $v = '';
        if ($raw !== '') {
            $q = $raw[0];
            if ($q === '"' || $q === "'") {
                $end = strpos($raw, $q, 1);
                $v = $end === false ? substr($raw, 1) : substr($raw, 1, $end - 1);
            } else {
                $hash = strpos($raw, '#');
                $v = $hash === false ? $raw : rtrim(substr($raw, 0, $hash));
            }
        }
        if (getenv($k) === false) {
            putenv("$k=$v");
        }
    }
}

load_env_file(__DIR__ . '/../.env');
require_once __DIR__ . '/lib/secrets.php';

function json_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

function json_response(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function uuid_v4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
