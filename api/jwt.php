<?php

declare(strict_types=1);

function jwt_secret(): string
{
    $s = env('JWT_SECRET');
    if ($s === null || $s === '') {
        throw new RuntimeException('JWT_SECRET não configurado no .env');
    }
    return $s;
}

function b64url_encode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function b64url_decode(string $str): string
{
    $pad = 4 - (strlen($str) % 4);
    if ($pad < 4) {
        $str .= str_repeat('=', $pad);
    }
    $b = base64_decode(strtr($str, '-_', '+/'), true);
    if ($b === false) {
        return '';
    }
    return $b;
}

/** @param array<string, mixed> $claims */
function jwt_encode(array $claims, int $ttlSeconds = 604800): string
{
    $secret = jwt_secret();
    $now = time();
    $payload = array_merge($claims, ['iat' => $now, 'exp' => $now + $ttlSeconds]);
    $header = b64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256'], JSON_THROW_ON_ERROR));
    $body = b64url_encode(json_encode($payload, JSON_THROW_ON_ERROR));
    $sig = hash_hmac('sha256', $header . '.' . $body, $secret, true);
    return $header . '.' . $body . '.' . b64url_encode($sig);
}

/** @return array<string, mixed>|null */
function jwt_decode(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    $secret = jwt_secret();
    $expected = b64url_encode(hash_hmac('sha256', $parts[0] . '.' . $parts[1], $secret, true));
    if (!hash_equals($expected, $parts[2])) {
        return null;
    }
    $json = b64url_decode($parts[1]);
    try {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        return null;
    }
    if (!is_array($payload)) {
        return null;
    }
    if (($payload['exp'] ?? 0) < time()) {
        return null;
    }
    return $payload;
}

function bearer_token(): ?string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' || !is_string($h)) {
        $h = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    }
    if ($h === '' || !is_string($h)) {
        $h = getenv('HTTP_AUTHORIZATION') ?: '';
    }
    if (($h === '' || !is_string($h)) && function_exists('apache_request_headers')) {
        $rh = apache_request_headers();
        if (is_array($rh)) {
            foreach ($rh as $key => $val) {
                if (strtolower((string) $key) === 'authorization' && is_string($val)) {
                    $h = $val;
                    break;
                }
            }
        }
    }
    if (!is_string($h) || !str_starts_with($h, 'Bearer ')) {
        return null;
    }
    $t = trim(substr($h, 7));
    return $t !== '' ? $t : null;
}
