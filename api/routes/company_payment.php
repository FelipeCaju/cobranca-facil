<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/gateway_payments.php';
require_once __DIR__ . '/../lib/mail.php';

/** Contas de recebimento; uma conta padrão é obrigatória. */
function company_payment_settings(PDO $pdo, string $method, string $companyId, ?string $accountId = null): void
{
    cobx_gateway_ensure_schema($pdo);
    if ($method === 'GET' && $accountId === null) {
        $st = $pdo->prepare('SELECT * FROM payment_accounts WHERE company_id=? ORDER BY is_default DESC, name');
        $st->execute([$companyId]);
        $items = array_map(static function (array $r): array {
            $apiKey = (string) (cobx_secret_decrypt($r['api_key'] ?? null) ?? '');
            return [
            'id'=>$r['id'], 'name'=>$r['name'], 'provider'=>$r['provider'], 'public_key'=>$r['public_key'] ?? '',
            'environment'=>$r['environment'], 'is_default'=>(bool)$r['is_default'], 'is_active'=>(bool)$r['is_active'],
            'api_key_set'=>trim($apiKey) !== '', 'api_key_masked'=>cobx_mask_secret($apiKey),
            'webhook_secret_set'=>trim((string)(cobx_secret_decrypt($r['webhook_secret'] ?? null) ?? '')) !== '',
            'payment_methods'=>$r['provider'] === 'asaas' ? ['pix','boleto'] : ['pix'],
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
        $pdo->prepare('INSERT INTO payment_accounts (id,company_id,name,provider,api_key,public_key,webhook_secret,environment,is_default,is_active) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id,$companyId,$d['name'],$d['provider'],cobx_secret_encrypt($d['api_key']),$d['public_key'],cobx_secret_encrypt($d['webhook_secret']),$d['environment'],$default?1:0,$d['is_active']]);
        json_response(201,['id'=>$id]);
    }
    if ($method === 'PUT' && $accountId === null) {
        $q=$pdo->prepare('SELECT id, api_key FROM payment_accounts WHERE company_id=? ORDER BY is_default DESC, created_at ASC LIMIT 1'); $q->execute([$companyId]); $old=$q->fetch(PDO::FETCH_ASSOC);
        if (!$old) json_response(422,['error'=>'Cadastre uma conta de recebimento antes de editar.']);
        $in=json_input(); $provider=(string)($in['payment_gateway'] ?? '');
        if (!in_array($provider,['asaas','mercadopago'],true)) json_response(422,['error'=>'Provedor inválido']);
        $key=trim((string)($in['gateway_api_key'] ?? '')); if ($key==='') $key=(string)$old['api_key']; else $key=(string)cobx_secret_encrypt($key);
        $env=(string)($in['gateway_environment'] ?? 'sandbox'); if (!in_array($env,['sandbox','production'],true)) $env='sandbox';
        $pdo->prepare('UPDATE payment_accounts SET provider=?,api_key=?,public_key=?,environment=?,updated_at=NOW(3) WHERE id=? AND company_id=?')
            ->execute([$provider,$key,trim((string)($in['gateway_public_key'] ?? '')),$env,$old['id'],$companyId]);
        json_response(200,['ok'=>true]);
    }
    if ($method === 'PUT' && $accountId !== null) {
        company_assert_uuid($accountId); $in=json_input(); $d=company_payment_account_input($in,true);
        $q=$pdo->prepare('SELECT * FROM payment_accounts WHERE id=? AND company_id=?'); $q->execute([$accountId,$companyId]); $old=$q->fetch(PDO::FETCH_ASSOC);
        if (!$old) json_response(404,['error'=>'Conta de recebimento não encontrada']);
        if ($d['is_default']) $pdo->prepare('UPDATE payment_accounts SET is_default=0 WHERE company_id=?')->execute([$companyId]);
        $pdo->prepare('UPDATE payment_accounts SET name=?,provider=?,api_key=?,public_key=?,webhook_secret=?,environment=?,is_default=?,is_active=?,updated_at=NOW(3) WHERE id=? AND company_id=?')
            ->execute([$d['name'],$d['provider'],$d['api_key'] !== null ? cobx_secret_encrypt($d['api_key']) : $old['api_key'],$d['public_key'],$d['webhook_secret'] !== null ? cobx_secret_encrypt($d['webhook_secret']) : $old['webhook_secret'],$d['environment'],$d['is_default']?1:0,$d['is_active'],$accountId,$companyId]);
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

/** @return array{name:string,provider:string,api_key:?string,webhook_secret:?string,public_key:string,environment:string,is_default:bool,is_active:int} */
function company_payment_account_input(array $in, bool $updating=false): array
{
    $provider=trim((string)($in['provider'] ?? ''));
    if (!in_array($provider,['asaas','mercadopago'],true)) json_response(422,['error'=>'Provedor inválido']);
    $name=trim((string)($in['name'] ?? ucfirst($provider))); if ($name==='') json_response(422,['error'=>'Nome da conta é obrigatório']);
    $env=trim((string)($in['environment'] ?? 'sandbox')); if (!in_array($env,['sandbox','production'],true)) $env='sandbox';
    $hasKey=array_key_exists('api_key',$in); $key=$hasKey ? trim((string)$in['api_key']) : null;
    $webhookSecret=array_key_exists('webhook_secret',$in) ? trim((string)$in['webhook_secret']) : null;
    if (!$updating && ($key===null || $key==='')) json_response(422,['error'=>'Credencial da API é obrigatória']);
    $isDefault = !empty($in['is_default']);
    $isActive = array_key_exists('is_active',$in) ? (!empty($in['is_active']) ? 1 : 0) : 1;
    if ($isDefault && !$isActive) json_response(422, ['error'=>'A conta padrão precisa estar ativa']);
    return ['name'=>$name,'provider'=>$provider,'api_key'=>$key,'webhook_secret'=>$webhookSecret,'public_key'=>trim((string)($in['public_key'] ?? '')),'environment'=>$env,'is_default'=>$isDefault,'is_active'=>$isActive];
}
