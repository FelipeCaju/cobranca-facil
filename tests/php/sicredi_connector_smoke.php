<?php
declare(strict_types=1);
require_once __DIR__.'/../../api/lib/payment_connector.php';
require_once __DIR__.'/../../api/lib/bank_provider_registry.php';

$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$connector=cobx_connector('sicredi');
$assert($connector instanceof CobxSicrediConnector,'Provider Sicredi não registrado.');
$assert($connector instanceof CobxContextualPaymentConnector,'Sicredi não reutiliza o contrato contextual comum.');
$assert($connector->paymentMethods()===['pix','boleto'],'Métodos Sicredi incorretos.');
$definition=cobx_bank_provider_definition('sicredi');
$assert(!empty($definition['enabled'])&&!empty($definition['requires_certificate']),'Sicredi não habilitado com certificado PIX.');

$pix=$connector->normalize(['_cobx_resource'=>'cob','txid'=>'tx1','status'=>'CONCLUIDA']);
$assert($pix['status']==='paid'&&$pix['txid']==='tx1'&&$pix['payment_origin']==='PIX','Normalização PIX Sicredi incorreta.');
$boleto=$connector->normalize(['_cobx_resource'=>'boleto','items'=>[['nossoNumero'=>'123','statusTitulo'=>'LIQUIDADO','linhaDigitavel'=>'1','formaLiquidacao'=>'PIX']]]);
$assert($boleto['external_id']==='123'&&$boleto['status']==='paid'&&$boleto['payment_origin']==='PIX','Normalização boleto Sicredi incorreta.');

$source=(string)file_get_contents(__DIR__.'/../../api/lib/sicredi_connector.php');
foreach(['https://api-pix.sicredi.com.br','/api/v2','grant_type\'=>\'password','context\'=>\'COBRANCA','/cobranca/boleto/v1/boletos',"'v2':'v1'",'webhook_token','pix_sandbox_base_url']as$contract)$assert(str_contains($source,$contract),'Contrato oficial ausente: '.$contract);
$assert(!str_contains($source,"BILLING_PROD.'/oauth/token"),'Cobrança não pode usar o OAuth do PIX.');
$assert(str_contains($source,"'mtls'=>\$mtls")&&substr_count($source,"billingRequest(")>2,'Separação mTLS PIX/Cobrança ausente.');
echo "sicredi_connector_smoke: OK\n";
