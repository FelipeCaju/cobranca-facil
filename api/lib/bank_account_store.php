<?php

declare(strict_types=1);

require_once __DIR__ . '/secrets.php';

function cobx_bank_json_encode(array $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function cobx_bank_credentials_decrypt(?string $stored): array
{
    $plain = cobx_secret_decrypt($stored);
    if ($plain === null || trim($plain) === '') return [];
    $value = json_decode($plain, true, 64, JSON_THROW_ON_ERROR);
    return is_array($value) ? $value : [];
}

function cobx_bank_credentials_encrypt(array $credentials): ?string
{
    $clean = [];
    foreach ($credentials as $key => $value) {
        if (!is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1) continue;
        if (is_scalar($value) || $value === null) $clean[$key] = $value;
    }
    return $clean === [] ? null : cobx_secret_encrypt(cobx_bank_json_encode($clean));
}

function cobx_bank_provider_config_decode(?string $stored): array
{
    if ($stored === null || trim($stored) === '') return [];
    $value = json_decode($stored, true, 64, JSON_THROW_ON_ERROR);
    return is_array($value) ? $value : [];
}

function cobx_bank_provider_config_encode(array $config): ?string
{
    $clean = [];
    foreach ($config as $key => $value) {
        if (!is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1) continue;
        if (is_scalar($value) || $value === null || is_array($value)) $clean[$key] = $value;
    }
    return $clean === [] ? null : cobx_bank_json_encode($clean);
}

/** Junta o cofre extensível às colunas legadas sem quebrar conectores atuais. */
function cobx_bank_account_hydrate(array $row): array
{
    $credentials = cobx_bank_credentials_decrypt($row['credentials_encrypted'] ?? null);
    $legacy = [
        'api_key' => cobx_secret_decrypt($row['api_key'] ?? null),
        'webhook_secret' => cobx_secret_decrypt($row['webhook_secret'] ?? null),
    ];
    foreach ($legacy as $key => $value) {
        if (!array_key_exists($key, $credentials) && $value !== null && $value !== '') $credentials[$key] = $value;
    }
    $row['credentials'] = $credentials;
    $row['provider_config'] = cobx_bank_provider_config_decode($row['provider_config'] ?? null);
    $row['api_key'] = $credentials['api_key'] ?? $legacy['api_key'] ?? null;
    $row['webhook_secret'] = $credentials['webhook_secret'] ?? $legacy['webhook_secret'] ?? null;
    return $row;
}

function cobx_bank_credential_presence(array $credentials): array
{
    $out = [];
    foreach ($credentials as $key => $value) $out[$key] = trim((string) $value) !== '';
    return $out;
}
