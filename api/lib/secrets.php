<?php

declare(strict_types=1);

const COBX_SECRET_PREFIX = 'enc:v1:';

function cobx_secret_key(): string
{
    $material = (string) (env('APP_ENCRYPTION_KEY', '') ?: env('JWT_SECRET', ''));
    if ($material === '') {
        throw new RuntimeException('APP_ENCRYPTION_KEY ou JWT_SECRET precisa estar configurado.');
    }
    return hash('sha256', $material, true);
}

/** Criptografa segredos em repouso usando AES-256-GCM. Valores vazios continuam vazios. */
function cobx_secret_encrypt(?string $plain): ?string
{
    if ($plain === null || $plain === '') return $plain;
    if (str_starts_with($plain, COBX_SECRET_PREFIX)) return $plain;
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', cobx_secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) throw new RuntimeException('Não foi possível criptografar o segredo.');
    return COBX_SECRET_PREFIX . base64_encode($iv . $tag . $cipher);
}

/** Aceita dados legados em texto puro para permitir migração gradual. */
function cobx_secret_decrypt(?string $stored): ?string
{
    if ($stored === null || $stored === '' || !str_starts_with($stored, COBX_SECRET_PREFIX)) return $stored;
    $raw = base64_decode(substr($stored, strlen(COBX_SECRET_PREFIX)), true);
    if ($raw === false || strlen($raw) < 29) throw new RuntimeException('Segredo criptografado inválido.');
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', cobx_secret_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    if ($plain === false) throw new RuntimeException('Não foi possível descriptografar o segredo.');
    return $plain;
}

