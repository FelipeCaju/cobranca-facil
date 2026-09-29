<?php

declare(strict_types=1);

require_once __DIR__ . '/secrets.php';
require_once __DIR__ . '/bank_account_store.php';
require_once __DIR__ . '/bank_http_client.php';

final class CobxOAuthTokenManager
{
    public function get(PDO $pdo, array $account, array $strategy, ?array $mtls = null): string
    {
        $cached = $this->cached($account);
        if ($cached !== null) return $cached;
        $tokenUrl = trim((string) ($strategy['token_url'] ?? ''));
        if ($tokenUrl === '') throw new InvalidArgumentException('Token URL não configurada pelo conector.');
        $credentials = (array) ($account['credentials'] ?? []);
        $form = (array) ($strategy['parameters'] ?? []);
        $form['grant_type'] = (string) ($strategy['grant_type'] ?? 'client_credentials');
        if (!empty($strategy['scopes'])) $form['scope'] = is_array($strategy['scopes']) ? implode(' ', $strategy['scopes']) : (string) $strategy['scopes'];
        $options = ['form' => $form, 'timeout' => 30, 'mtls' => $mtls ?? []];
        $auth = (string) ($strategy['client_auth'] ?? 'basic');
        if ($auth === 'basic') $options['basic'] = ['user' => $credentials['client_id'] ?? '', 'password' => $credentials['client_secret'] ?? ''];
        elseif ($auth === 'body') { $options['form']['client_id'] = $credentials['client_id'] ?? ''; $options['form']['client_secret'] = $credentials['client_secret'] ?? ''; }
        foreach ((array) ($strategy['headers'] ?? []) as $key => $value) $options['headers'][$key] = $value;

        $response = (new CobxBankHttpClient())->request('POST', $tokenUrl, $options);
        $json = $response->json();
        if (!$response->ok() || !is_array($json) || empty($json['access_token'])) throw new RuntimeException('Não foi possível obter token OAuth.');
        $expiresIn = max(1, (int) ($json['expires_in'] ?? 300));
        $expiresAt = (new DateTimeImmutable())->modify('+' . $expiresIn . ' seconds');
        $cache = cobx_secret_encrypt(cobx_bank_json_encode(['access_token' => (string) $json['access_token'], 'token_type' => $json['token_type'] ?? 'Bearer']));
        $pdo->prepare('UPDATE payment_accounts SET token_cache_encrypted=?,token_expires_at=?,updated_at=NOW(3) WHERE id=? AND company_id=?')
            ->execute([$cache, $expiresAt->format('Y-m-d H:i:s.v'), $account['id'], $account['company_id']]);
        return (string) $json['access_token'];
    }

    private function cached(array $account): ?string
    {
        $expires = trim((string) ($account['token_expires_at'] ?? ''));
        $stored = $account['token_cache_encrypted'] ?? null;
        if ($expires === '' || empty($stored)) return null;
        try { if (new DateTimeImmutable($expires) <= new DateTimeImmutable('+30 seconds')) return null; }
        catch (Throwable) { return null; }
        $plain = cobx_secret_decrypt((string) $stored);
        $cache = is_string($plain) ? json_decode($plain, true) : null;
        return is_array($cache) && !empty($cache['access_token']) ? (string) $cache['access_token'] : null;
    }

    public function invalidate(PDO $pdo, string $accountId, string $companyId): void
    {
        $pdo->prepare('UPDATE payment_accounts SET token_cache_encrypted=NULL,token_expires_at=NULL WHERE id=? AND company_id=?')->execute([$accountId, $companyId]);
    }
}
