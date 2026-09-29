<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/gateway_payments.php';
require_once __DIR__ . '/../lib/mail.php';
require_once __DIR__ . '/../lib/bank_provider_registry.php';
require_once __DIR__ . '/../lib/bank_account_store.php';
require_once __DIR__ . '/../lib/bank_certificate_manager.php';

function company_payment_providers(string $method): void
{
    if ($method !== 'GET') json_response(405, ['error' => 'Método não permitido']);
    json_response(200, ['items' => cobx_bank_provider_public_catalog()]);
}

/** Contas de recebimento; uma conta padrão é obrigatória. */
function company_payment_settings(PDO $pdo, string $method, string $companyId, ?string $accountId = null, ?string $sub = null): void
{
    cobx_gateway_ensure_schema($pdo);
    if ($accountId !== null && $sub === 'test' && $method === 'POST') {
        company_assert_uuid($accountId);
        $account = cobx_gateway_account($pdo, $companyId, $accountId);
        if (!$account) json_response(404, ['error' => 'Conta de recebimento não encontrada']);
        $connector = cobx_connector((string) $account['provider']);
        if (!$connector instanceof CobxConnectionTestable) json_response(422, ['error' => 'Este conector não oferece teste de conexão.']);
        json_response(200, $connector->testConnection($account));
    }
    if ($accountId !== null && $sub === 'certificate') {
        company_assert_uuid($accountId);
        $q=$pdo->prepare('SELECT id FROM payment_accounts WHERE id=? AND company_id=?');$q->execute([$accountId,$companyId]);
        if (!$q->fetchColumn()) json_response(404,['error'=>'Conta de recebimento não encontrada']);
        $manager = new CobxBankCertificateManager();
        if ($method === 'GET') json_response(200, ['certificate' => $manager->metadata($pdo, $accountId)]);
        if ($method === 'DELETE') { $pdo->prepare('DELETE FROM payment_account_certificates WHERE payment_account_id=?')->execute([$accountId]); json_response(200,['ok'=>true]); }
        if ($method === 'PUT') {
            $in=json_input();$format=(string)($in['format']??'');$certificate=(string)($in['certificate']??'');
            if ($format===''||$certificate==='') json_response(422,['error'=>'Formato e certificado são obrigatórios.']);
            try{$meta=$manager->store($pdo,$accountId,$format,$certificate,isset($in['private_key'])?(string)$in['private_key']:null,isset($in['password'])?(string)$in['password']:null);}
            catch(Throwable $e){json_response(422,['error'=>$e->getMessage()]);}
            json_response(200,['ok'=>true,'certificate'=>$meta]);
        }
        json_response(405,['error'=>'Método não permitido']);
    }
    if ($method === 'GET' && $accountId === null) {
        $st = $pdo->prepare('SELECT * FROM payment_accounts WHERE company_id=? ORDER BY is_default DESC, name');
        $st->execute([$companyId]);
        $certificateManager = new CobxBankCertificateManager();
        $items = array_map(static function (array $r) use ($pdo, $certificateManager): array {
            $account = cobx_bank_account_hydrate($r);
            $apiKey = (string) ($account['api_key'] ?? '');
            $credentials = (array) ($account['credentials'] ?? []);
            return [
            'id'=>$r['id'], 'name'=>$r['name'], 'provider'=>$r['provider'], 'public_key'=>$r['public_key'] ?? '',
            'environment'=>$r['environment'], 'is_default'=>(bool)$r['is_default'], 'is_active'=>(bool)$r['is_active'],
            'api_key_set'=>trim($apiKey) !== '', 'api_key_masked'=>cobx_mask_secret($apiKey),
            'webhook_secret_set'=>trim((string)($credentials['webhook_secret']??$account['webhook_secret']??'')) !== '',
            'credential_presence'=>cobx_bank_credential_presence($credentials),
            'provider_config'=>$account['provider_config'],
            'certificate'=>$certificateManager->metadata($pdo,(string)$r['id']),
            'payment_methods'=>cobx_connector_capabilities((string)$r['provider'])['payment_methods'],
            'capabilities'=>cobx_connector_capabilities((string)$r['provider'])['capabilities'],
            ];
        }, $st->fetchAll(PDO::FETCH_ASSOC));
        $default = $items[0] ?? null;
        json_response(200, ['items'=>$items,
            // Campos legados para clientes web ainda não atualizados.
            'payment_gateway'=>$default['provider'] ?? '', 'gateway_api_key_set'=>$default['api_key_set'] ?? false,
            'gateway_api_key_masked'=>$default['api_key_masked'] ?? '', 'gateway_public_key'=>$default['public_key'] ?? '',
            'gateway_environment'=>$default['environment'] ?? 'sandbox']);
    }
    if ($method === 'POST' && $accountId === null) {
        $in=json_input(); $d=company_payment_account_input($in); $id=uuid_v4();
        $q=$pdo->prepare('SELECT COUNT(*) FROM payment_accounts WHERE company_id=?'); $q->execute([$companyId]);
        $default=!empty($in['is_default']) || (int)$q->fetchColumn()===0;
        if ($default) $pdo->prepare('UPDATE payment_accounts SET is_default=0 WHERE company_id=?')->execute([$companyId]);
        $pdo->prepare('INSERT INTO payment_accounts (id,company_id,name,provider,api_key,public_key,webhook_secret,credentials_encrypted,provider_config,environment,is_default,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id,$companyId,$d['name'],$d['provider'],cobx_secret_encrypt($d['api_key']),$d['public_key'],cobx_secret_encrypt($d['webhook_secret']),cobx_bank_credentials_encrypt($d['credentials']),cobx_bank_provider_config_encode($d['provider_config']),$d['environment'],$default?1:0,$d['is_active']]);
        json_response(201,['id'=>$id]);
    }
    if ($method === 'PUT' && $accountId === null) {
        $q=$pdo->prepare('SELECT * FROM payment_accounts WHERE company_id=? ORDER BY is_default DESC, created_at ASC LIMIT 1'); $q->execute([$companyId]); $old=$q->fetch(PDO::FETCH_ASSOC);
        if (!$old) json_response(422,['error'=>'Cadastre uma conta de recebimento antes de editar.']);
        $in=json_input(); $provider=(string)($in['payment_gateway'] ?? '');
        if (!in_array($provider,['asaas','mercadopago'],true)) json_response(422,['error'=>'Provedor inválido']);
        $plainKey=trim((string)($in['gateway_api_key'] ?? ''));$hydrated=cobx_bank_account_hydrate($old);$credentials=(array)$hydrated['credentials'];
        if($plainKey===''){$key=(string)$old['api_key'];}else{$key=(string)cobx_secret_encrypt($plainKey);$credentials['api_key']=$plainKey;}
        $env=(string)($in['gateway_environment'] ?? 'sandbox'); if (!in_array($env,['sandbox','production'],true)) $env='sandbox';
        $pdo->prepare('UPDATE payment_accounts SET provider=?,api_key=?,public_key=?,credentials_encrypted=?,token_cache_encrypted=NULL,token_expires_at=NULL,environment=?,updated_at=NOW(3) WHERE id=? AND company_id=?')
            ->execute([$provider,$key,trim((string)($in['gateway_public_key'] ?? '')),cobx_bank_credentials_encrypt($credentials),$env,$old['id'],$companyId]);
        json_response(200,['ok'=>true]);
    }
    if ($method === 'PUT' && $accountId !== null) {
        company_assert_uuid($accountId); $in=json_input(); $d=company_payment_account_input($in,true);
        $q=$pdo->prepare('SELECT * FROM payment_accounts WHERE id=? AND company_id=?'); $q->execute([$accountId,$companyId]); $old=$q->fetch(PDO::FETCH_ASSOC);
        if (!$old) json_response(404,['error'=>'Conta de recebimento não encontrada']);
        if ($d['is_default']) $pdo->prepare('UPDATE payment_accounts SET is_default=0 WHERE company_id=?')->execute([$companyId]);
        $oldHydrated=cobx_bank_account_hydrate($old);$credentials=array_merge((array)$oldHydrated['credentials'],$d['credentials']);
        $pdo->prepare('UPDATE payment_accounts SET name=?,provider=?,api_key=?,public_key=?,webhook_secret=?,credentials_encrypted=?,provider_config=?,token_cache_encrypted=NULL,token_expires_at=NULL,environment=?,is_default=?,is_active=?,updated_at=NOW(3) WHERE id=? AND company_id=?')
            ->execute([$d['name'],$d['provider'],$d['api_key'] !== null ? cobx_secret_encrypt($d['api_key']) : $old['api_key'],$d['public_key'],$d['webhook_secret'] !== null ? cobx_secret_encrypt($d['webhook_secret']) : $old['webhook_secret'],cobx_bank_credentials_encrypt($credentials),cobx_bank_provider_config_encode($d['provider_config']),$d['environment'],$d['is_default']?1:0,$d['is_active'],$accountId,$companyId]);
        json_response(200,['ok'=>true]);
    }
    if ($method === 'DELETE' && $accountId !== null) {
        company_assert_uuid($accountId); $q=$pdo->prepare('SELECT is_default FROM payment_accounts WHERE id=? AND company_id=?'); $q->execute([$accountId,$companyId]); $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) json_response(404,['error'=>'Conta de recebimento não encontrada']);
        if (!empty($row['is_default'])) json_response(422,['error'=>'Defina outra conta como padrão antes de excluir esta.']);
        $pdo->prepare('DELETE FROM payment_accounts WHERE id=? AND company_id=?')->execute([$accountId,$companyId]); json_response(200,['ok'=>true]);
    }
    json_response(405,['error'=>'Método não permitido']);
}

/** @return array{name:string,provider:string,api_key:?string,webhook_secret:?string,public_key:string,credentials:array,provider_config:array,environment:string,is_default:bool,is_active:int} */
function company_payment_account_input(array $in, bool $updating=false): array
{
    $provider=trim((string)($in['provider'] ?? ''));
    try{$definition=cobx_bank_provider_definition($provider,true);}catch(Throwable){json_response(422,['error'=>'Provedor inválido ou ainda não habilitado']);}
    $name=trim((string)($in['name'] ?? ucfirst($provider))); if ($name==='') json_response(422,['error'=>'Nome da conta é obrigatório']);
    $env=trim((string)($in['environment'] ?? 'sandbox')); if (!in_array($env,['sandbox','production'],true)) $env='sandbox';
    $hasKey=array_key_exists('api_key',$in); $key=$hasKey ? trim((string)$in['api_key']) : null;
    $webhookSecret=array_key_exists('webhook_secret',$in) ? trim((string)$in['webhook_secret']) : null;
    $isDefault = !empty($in['is_default']);
    $isActive = array_key_exists('is_active',$in) ? (!empty($in['is_active']) ? 1 : 0) : 1;
    if ($isDefault && !$isActive) json_response(422, ['error'=>'A conta padrão precisa estar ativa']);
    $credentials=is_array($in['credentials']??null)?$in['credentials']:[];
    if ($key!==null && $key!=='') $credentials['api_key']=$key;
    if ($webhookSecret!==null && $webhookSecret!=='') $credentials['webhook_secret']=$webhookSecret;
    $providerConfig=is_array($in['provider_config']??null)?$in['provider_config']:[];
    if (!$updating) foreach ((array)($definition['fields']??[]) as $field) {
        if (empty($field['required'])) continue;
        $fieldKey=(string)($field['key']??'');$storage=(string)($field['storage']??'credentials');
        $value=$storage==='config'?($providerConfig[$fieldKey]??''):($storage==='public'?($in[$fieldKey]??''):($credentials[$fieldKey]??''));
        if (trim((string)$value)==='') json_response(422,['error'=>'Campo obrigatório ausente: '.(string)($field['label']??$fieldKey)]);
    }
    return ['name'=>$name,'provider'=>$provider,'api_key'=>$key,'webhook_secret'=>$webhookSecret,'public_key'=>trim((string)($in['public_key'] ?? '')),'credentials'=>$credentials,'provider_config'=>$providerConfig,'environment'=>$env,'is_default'=>$isDefault,'is_active'=>$isActive];
}
