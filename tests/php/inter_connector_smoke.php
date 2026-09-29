<?php
declare(strict_types=1);

require_once __DIR__ . '/../../api/lib/payment_connector.php';
require_once __DIR__ . '/../../api/lib/bank_provider_registry.php';

$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$connector=cobx_connector('inter');
$assert($connector instanceof CobxInterConnector,'Provider inter não registrado.');
$assert($connector instanceof CobxContextualPaymentConnector,'Provider inter não usa o contrato contextual comum.');
$assert($connector->paymentMethods()===['pix','boleto'],'Métodos Inter incorretos.');
$definition=cobx_bank_provider_definition('inter');
$assert($definition['enabled']===true,'Inter não habilitado no catálogo.');
$assert(!empty($definition['requires_certificate']),'Inter não exige certificado no catálogo.');

$paid=$connector->normalize(['_cobx_resource'=>'boleto','cobranca'=>['codigoSolicitacao'=>'req-1','situacao'=>'RECEBIDO','origemRecebimento'=>'PIX','seuNumero'=>'ref-1'],'pix'=>['txid'=>'tx-1']]);
$assert($paid['status']==='paid'&&$paid['payment_origin']==='PIX'&&$paid['txid']==='tx-1','Conciliação híbrida PIX incorreta.');
$boleto=$connector->normalize(['_cobx_resource'=>'boleto','cobranca'=>['codigoSolicitacao'=>'req-2','situacao'=>'RECEBIDO','origemRecebimento'=>'BOLETO']]);
$assert($boleto['payment_origin']==='BOLETO','Origem BOLETO não preservada.');
$cancelled=$connector->normalize(['txid'=>'tx-2','status'=>'REMOVIDA_PELO_USUARIO_RECEBEDOR']);
$assert($cancelled['status']==='cancelled'&&$cancelled['provider_status']==='REMOVIDA_PELO_USUARIO_RECEBEDOR','Status original Pix não preservado.');

$source=file_get_contents(__DIR__.'/../../api/lib/inter_connector.php');
foreach(['/oauth/v2/token','/pix/v2/','/cobranca/v3/cobrancas','cob.read','cobv.read','boleto-cobranca.read'] as $contract)$assert(str_contains((string)$source,$contract),'Contrato oficial ausente: '.$contract);
$assert(!str_contains((string)$source,'/banking/'),'Provider Inter incluiu API Banking fora do escopo.');

echo "inter_connector_smoke: OK\n";
