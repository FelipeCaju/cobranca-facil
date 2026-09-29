<?php
declare(strict_types=1);
require_once __DIR__.'/../../api/lib/payment_connector.php';
require_once __DIR__.'/../../api/lib/bank_provider_registry.php';

$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$connector=cobx_connector('sicoob');
$assert($connector instanceof CobxSicoobConnector,'Provider sicoob não registrado.');
$assert($connector instanceof CobxContextualPaymentConnector,'Sicoob não reutiliza o contrato contextual comum.');
$assert($connector->paymentMethods()===['pix','boleto'],'Métodos Sicoob incorretos.');
$definition=cobx_bank_provider_definition('sicoob');
$assert(!empty($definition['enabled'])&&!empty($definition['requires_certificate']),'Sicoob não habilitado com mTLS obrigatório.');

$boleto=$connector->normalize(['_cobx_resource'=>'boleto','resultado'=>['nossoNumero'=>'123','situacaoBoleto'=>'Liquidado','linhaDigitavel'=>'1','qrCode'=>'pix']]);
$assert($boleto['external_id']==='123'&&$boleto['status']==='paid'&&$boleto['provider_status']==='Liquidado','Normalização de boleto Sicoob incorreta.');
$pix=$connector->normalize(['_cobx_resource'=>'cob','txid'=>'tx1','status'=>'CONCLUIDA']);
$assert($pix['status']==='paid'&&$pix['txid']==='tx1','Normalização PIX Sicoob incorreta.');
$cancel=$connector->normalize(['txid'=>'tx2','status'=>'REMOVIDA_PELO_USUARIO_RECEBEDOR']);
$assert($cancel['status']==='cancelled','Cancelamento PIX Sicoob incorreto.');

$source=(string)file_get_contents(__DIR__.'/../../api/lib/sicoob_connector.php');
foreach(['https://api.sicoob.com.br/pix/api/v2','https://api.sicoob.com.br/cobranca-bancaria/v3','/boletos/segunda-via','/baixar','client_id']as$contract)$assert(str_contains($source,$contract),'Contrato oficial ausente: '.$contract);
foreach(['/conta-corrente/','pix-pagamentos','/spb/','investimentos','cobranca-bancaria-pagamentos']as$forbidden)$assert(!str_contains($source,$forbidden),'API fora do escopo encontrada: '.$forbidden);

echo "sicoob_connector_smoke: OK\n";
