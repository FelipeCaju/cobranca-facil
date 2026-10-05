<?php

declare(strict_types=1);

require_once __DIR__ . '/secrets.php';
require_once __DIR__ . '/bank_account_store.php';
require_once __DIR__ . '/bank_http_client.php';

final class CobxOAuthTokenManager
{
    public function get(PDO $pdo, array $account, array $strategy, ?array $mtls = null): string
    {
        $cacheKey = $this->cacheKey($strategy, $account);
        $fresh = $pdo->prepare('SELECT token_cache_encrypted,token_expires_at FROM payment_accounts WHERE id=? AND company_id=? LIMIT 1');
        $fresh->execute([$account['id'], $account['company_id']]);
        $account = array_merge($account, $fresh->fetch(PDO::FETCH_ASSOC) ?: []);
        $cached = $this->cached($account, $cacheKey);
        if ($cached !== null) return $cached;
        $lockName='cobx_oauth_'.substr(hash('sha256',(string)$account['company_id'].'|'.(string)$account['id'].'|'.$cacheKey),0,48);
        $lock=$pdo->prepare('SELECT GET_LOCK(?,10)');$lock->execute([$lockName]);
        if((int)$lock->fetchColumn()!==1)throw new RuntimeException('Não foi possível obter trava de renovação OAuth.');
        try{
        $fresh->execute([$account['id'], $account['company_id']]);$account=array_merge($account,$fresh->fetch(PDO::FETCH_ASSOC)?:[]);
        $cached=$this->cached($account,$cacheKey);if($cached!==null)return$cached;
        $tokenUrl = trim((string) ($strategy['token_url'] ?? ''));
        if ($tokenUrl === '') throw new InvalidArgumentException('Token URL não configurada pelo conector.');
        $credentials = (array) ($account['credentials'] ?? []);
        $form = (array) ($strategy['parameters'] ?? []);
        $form['grant_type'] = (string) ($strategy['grant_type'] ?? 'client_credentials');
        if (!empty($strategy['scopes'])) $form['scope'] = is_array($strategy['scopes']) ? implode(' ', $strategy['scopes']) : (string) $strategy['scopes'];
        $options = ['form' => $form, 'timeout' => 30, 'mtls' => $mtls ?? []];
        if(!empty($strategy['allow_http_for_tests']))$options['allow_http_for_tests']=true;
        $auth = (string) ($strategy['client_auth'] ?? 'basic');
        if ($auth === 'basic') $options['basic'] = ['user' => $credentials['client_id'] ?? '', 'password' => $credentials['client_secret'] ?? ''];
        elseif ($auth === 'body') { $options['form']['client_id'] = $credentials['client_id'] ?? ''; $options['form']['client_secret'] = $credentials['client_secret'] ?? ''; }
        foreach ((array) ($strategy['headers'] ?? []) as $key => $value) $options['headers'][$key] = $value;

        $response = (new CobxBankHttpClient())->request('POST', $tokenUrl, $options);
        $json = $response->json();
        if (!$response->ok() || !is_array($json) || empty($json['access_token'])) throw new RuntimeException('Não foi possível obter token OAuth.');
        $expiresIn = max(1, (int) ($json['expires_in'] ?? 300));
        $expiresAt = (new DateTimeImmutable())->modify('+' . $expiresIn . ' seconds');
        $cacheData = $this->cacheData($account);
        $cacheData['tokens'][$cacheKey] = ['access_token'=>(string)$json['access_token'],'token_type'=>$json['token_type']??'Bearer','expires_at'=>$expiresAt->format(DATE_ATOM)];
        $cache = cobx_secret_encrypt(cobx_bank_json_encode($cacheData));
        $pdo->prepare('UPDATE payment_accounts SET token_cache_encrypted=?,token_expires_at=?,updated_at=NOW(3) WHERE id=? AND company_id=?')
            ->execute([$cache, $expiresAt->format('Y-m-d H:i:s.v'), $account['id'], $account['company_id']]);
        return (string) $json['access_token'];
        }finally{$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lockName]);}
    }

    private function cached(array $account, string $cacheKey): ?string
    {
        $cache=$this->cacheData($account);$item=$cache['tokens'][$cacheKey]??null;
        if(!is_array($item)||empty($item['access_token'])||empty($item['expires_at']))return null;
        try{if(new DateTimeImmutable((string)$item['expires_at'])<=new DateTimeImmutable('+30 seconds'))return null;}catch(Throwable){return null;}
        return (string)$item['access_token'];
    }

    private function cacheKey(array $strategy, array $account): string
    {
        $scopes=$strategy['scopes']??[];if(!is_array($scopes))$scopes=preg_split('/\s+/',trim((string)$scopes))?:[];sort($scopes);
        return hash('sha256',(string)($strategy['token_url']??'').'|'.implode(' ',$scopes).'|'.(string)($strategy['client_auth']??'basic').'|'.(string)($account['credentials']['client_id']??''));
    }

    private function cacheData(array $account): array
    {
        $stored=$account['token_cache_encrypted']??null;if(empty($stored))return['tokens'=>[]];
        try{$plain=cobx_secret_decrypt((string)$stored);$data=is_string($plain)?json_decode($plain,true):null;}catch(Throwable){$data=null;}
        if(is_array($data)&&isset($data['tokens'])&&is_array($data['tokens']))return$data;
        return['tokens'=>[]];
    }

    public function invalidate(PDO $pdo, string $accountId, string $companyId): void
    {
        $pdo->prepare('UPDATE payment_accounts SET token_cache_encrypted=NULL,token_expires_at=NULL WHERE id=? AND company_id=?')->execute([$accountId, $companyId]);
    }
}
