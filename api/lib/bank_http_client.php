<?php

declare(strict_types=1);

require_once __DIR__ . '/bank_idempotency.php';

final class CobxBankHttpResponse
{
    public function __construct(public int $status, public string $body, public array $headers = [], public ?string $error = null) {}
    public function ok(): bool { return $this->error === null && $this->status >= 200 && $this->status < 300; }
    public function json(): ?array
    {
        try { $value = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR); }
        catch (JsonException) { return null; }
        return is_array($value) ? $value : null;
    }
}

final class CobxBankHttpClient
{
    /**
     * Opções: headers, json, form, bearer, basic[user,password], timeout,
     * raw_body, mtls[cert_path,key_path,key_password], idempotency_key e retry_attempts.
     */
    public function request(string $method, string $url, array $options = []): CobxBankHttpResponse
    {
        if (!str_starts_with(strtolower($url), 'https://') && empty($options['allow_http_for_tests'])) {
            return new CobxBankHttpResponse(0, '', [], 'Integrações bancárias exigem HTTPS.');
        }
        if (!function_exists('curl_init')) return new CobxBankHttpResponse(0, '', [], 'Extensão cURL indisponível.');

        $method = strtoupper($method);
        $headers = ['Accept: application/json'];
        foreach (($options['headers'] ?? []) as $name => $value) {
            if (is_int($name)) $headers[] = (string) $value;
            else $headers[] = $name . ': ' . (string) $value;
        }
        if (!empty($options['bearer'])) $headers[] = 'Authorization: Bearer ' . (string) $options['bearer'];
        if (!empty($options['idempotency_key'])) $headers[] = 'Idempotency-Key: ' . (string) $options['idempotency_key'];

        $body = null;
        if (array_key_exists('json', $options)) {
            $body = json_encode($options['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
        } elseif (array_key_exists('form', $options)) {
            $body = http_build_query((array) $options['form']);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        } elseif (array_key_exists('raw_body', $options)) {
            $body = (string) $options['raw_body'];
        }

        $attempts = max(1, min(3, (int) ($options['retry_attempts'] ?? 1)));
        $safeRetry = cobx_bank_operation_retryable($method, !empty($options['idempotency_key']));
        if (!$safeRetry) $attempts = 1;
        $last = new CobxBankHttpResponse(0, '', [], 'Falha de comunicação bancária.');

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $ch = curl_init($url);
            if ($ch === false) return $last;
            $responseHeaders = [];
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => max(5, min(120, (int) ($options['timeout'] ?? 30))),
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                    return strlen($line);
                },
            ]);
            if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            if (!empty($options['basic']) && is_array($options['basic'])) {
                curl_setopt($ch, CURLOPT_USERPWD, (string) ($options['basic']['user'] ?? '') . ':' . (string) ($options['basic']['password'] ?? ''));
            }
            $mtls = is_array($options['mtls'] ?? null) ? $options['mtls'] : [];
            if (!empty($mtls['cert_path'])) curl_setopt($ch, CURLOPT_SSLCERT, (string) $mtls['cert_path']);
            if (!empty($mtls['key_path'])) curl_setopt($ch, CURLOPT_SSLKEY, (string) $mtls['key_path']);
            if (!empty($mtls['key_password'])) curl_setopt($ch, CURLOPT_KEYPASSWD, (string) $mtls['key_password']);
            if (!empty($mtls['ca_path'])) curl_setopt($ch, CURLOPT_CAINFO, (string) $mtls['ca_path']);

            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_errno($ch) ? 'Falha de comunicação bancária (cURL ' . curl_errno($ch) . ').' : null;
            curl_close($ch);
            $last = new CobxBankHttpResponse($status, is_string($raw) ? $raw : '', $responseHeaders, $error);
            if ($last->ok() || !$safeRetry || ($status > 0 && $status < 500 && $status !== 429)) break;
            if ($attempt < $attempts) usleep(150000 * $attempt);
        }
        return $last;
    }
}
