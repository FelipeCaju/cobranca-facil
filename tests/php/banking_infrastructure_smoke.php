<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/common.php';
require_once __DIR__ . '/../../api/db.php';
require_once __DIR__ . '/../../api/lib/bank_account_store.php';
require_once __DIR__ . '/../../api/lib/bank_provider_registry.php';
require_once __DIR__ . '/../../api/lib/bank_idempotency.php';
require_once __DIR__ . '/../../api/lib/bank_http_client.php';
require_once __DIR__ . '/../../api/lib/bank_certificate_manager.php';
require_once __DIR__ . '/../../api/lib/payment_connector.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$catalog = cobx_bank_provider_definitions();
$assert(!empty($catalog['asaas']['enabled']) && !empty($catalog['mercadopago']['enabled']), 'Conectores existentes não estão habilitados.');
$assert(!empty($catalog['inter']['enabled']), 'Provider Inter deveria estar habilitado após a implementação local.');
$assert(!empty($catalog['sicoob']['enabled']), 'Provider Sicoob deveria estar habilitado após a implementação local.');
$assert(in_array('pix_immediate', $catalog['sicoob']['capabilities'] ?? [], true), 'Provider Sicoob deveria aceitar PIX.');
$assert(in_array('boleto', $catalog['sicoob']['capabilities'] ?? [], true), 'Provider Sicoob deveria aceitar boleto.');
$assert(!empty($catalog['sicoob']['requires_certificate']), 'Provider Sicoob deveria exigir certificado mTLS.');
$assert(!empty($catalog['sicredi']['enabled']), 'Provider Sicredi deveria estar habilitado após a implementação local.');
$assert(in_array('sandbox_partial', $catalog['sicredi']['capabilities'] ?? [], true), 'Sicredi deve declarar sandbox apenas parcial.');
$assert(in_array('mtls_pix', $catalog['sicredi']['capabilities'] ?? [], true), 'Sicredi deve limitar a indicação de mTLS ao PIX.');
$assert(!empty($catalog['santander']['enabled']), 'Provider Santander deveria estar habilitado após a implementação local.');
$assert(in_array('workspace', $catalog['santander']['capabilities'] ?? [], true), 'Santander deve declarar suporte a Workspace.');
$assert(in_array('boleto_pix', $catalog['santander']['capabilities'] ?? [], true), 'Santander deve declarar Boleto SX/BolePix.');
$assert(!empty($catalog['itau']['enabled']), 'Provider Itaú deveria estar habilitado após a implementação local.');
$assert(in_array('sandbox_simplified', $catalog['itau']['capabilities'] ?? [], true), 'Itaú deve separar o sandbox simplificado da produção.');
$assert(in_array('dynamic_certificate', $catalog['itau']['capabilities'] ?? [], true), 'Itaú deve declarar certificado dinâmico.');
$assert(empty($catalog['bb']['enabled']), 'BB deve permanecer desabilitado sem a especificação autenticada da API contratada.');
$assert(($catalog['bb']['status'] ?? '') === 'PENDENTE DE DOCUMENTAÇÃO BB', 'BB deve expor o bloqueio documental.');
$assert(!empty($catalog['bradesco']['enabled']), 'Bradesco PIX deveria estar habilitado após a implementação local.');
$assert(in_array('pix_immediate', $catalog['bradesco']['capabilities'] ?? [], true), 'Bradesco deve aceitar PIX imediato.');
$assert(in_array('boleto_pending', $catalog['bradesco']['capabilities'] ?? [], true), 'Bradesco não deve anunciar boleto sem documentação técnica oficial.');
$assert(cobx_connector('asaas')->paymentMethods() === ['pix', 'boleto'], 'Capacidades Asaas foram alteradas.');
$assert(cobx_connector('mercadopago')->paymentMethods() === ['pix'], 'Capacidades Mercado Pago foram alteradas.');
$assert(cobx_connector('asaas')->normalize(['id'=>'pay_1','status'=>'CONFIRMED'])['status']==='paid', 'Normalização Asaas incompatível.');
$assert(cobx_connector('mercadopago')->normalize(['id'=>'1','status'=>'approved'])['status']==='paid', 'Normalização Mercado Pago incompatível.');

$secret = ['client_id' => 'teste', 'client_secret' => 'segredo', 'webhook_secret' => 'webhook'];
$encrypted = cobx_bank_credentials_encrypt($secret);
$assert(is_string($encrypted) && str_starts_with($encrypted, COBX_SECRET_PREFIX), 'Cofre não criptografou o bundle.');
$assert(cobx_bank_credentials_decrypt($encrypted) === $secret, 'Cofre não recuperou o bundle.');
$assert(strlen(cobx_bank_txid('00000000-0000-4000-8000-000000000000')) === 35, 'txid determinístico inválido.');
$assert(cobx_bank_idempotency_key('inter', 'abc') === cobx_bank_idempotency_key('inter', 'abc'), 'Idempotência não determinística.');
$assert(!cobx_bank_operation_retryable('POST', false) && cobx_bank_operation_retryable('POST', true), 'Política de retry insegura.');
$http = (new CobxBankHttpClient())->request('GET', 'http://example.invalid');
$assert(!$http->ok() && str_contains((string) $http->error, 'HTTPS'), 'Cliente HTTP aceitou conexão bancária sem TLS.');
$certificateRejected = false;
try { (new CobxBankCertificateManager())->inspect('pem', 'certificado-inválido'); }
catch (InvalidArgumentException) { $certificateRejected = true; }
$assert($certificateRejected, 'Certificate Manager aceitou certificado inválido.');

$pdo = db();
$schema = (string) env('DB_DATABASE');
foreach ([['payment_accounts','credentials_encrypted'],['payment_accounts','token_cache_encrypted'],['installments','provider_status'],['installments','txid'],['payment_account_certificates','fingerprint']] as [$table,$column]) {
    $s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');
    $s->execute([$schema,$table,$column]);
    $assert((int)$s->fetchColumn()===1,"Coluna ausente: {$table}.{$column}");
}

echo json_encode(['ok'=>$failures===[],'failures'=>$failures,'providers'=>count($catalog)],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),PHP_EOL;
exit($failures===[]?0:1);
