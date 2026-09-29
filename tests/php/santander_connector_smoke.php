<?php
declare(strict_types=1);
require_once __DIR__.'/../../api/lib/payment_connector.php';
require_once __DIR__.'/../../api/lib/bank_provider_registry.php';

$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$connector=cobx_connector('santander');
$assert($connector instanceof CobxSantanderConnector,'Provider Santander não registrado.');
$assert($connector instanceof CobxContextualPaymentConnector,'Santander não reutiliza o contrato contextual comum.');
$assert($connector->paymentMethods()===['pix','boleto'],'Métodos Santander incorretos.');
$definition=cobx_bank_provider_definition('santander');
$assert(!empty($definition['enabled'])&&!empty($definition['requires_certificate']),'Santander não habilitado com mTLS.');
$fieldKeys=array_column($definition['fields'],'key');
$assert(in_array('workspace_id',$fieldKeys,true)&&in_array('covenant',$fieldKeys,true),'Workspace/convênio ausentes do provider_config.');

$paid=$connector->normalize(['_cobx_resource'=>'boleto','bankNumber'=>'123','function'=>'PAGAMENTO','paymentType'=>'PIX','txId'=>'tx1']);
$assert($paid['status']==='paid'&&$paid['payment_origin']==='PIX'&&$paid['txid']==='tx1','Pagamento BolePix normalizado incorretamente.');
$refund=$connector->normalize(['_cobx_resource'=>'boleto','bankNumber'=>'123','function'=>'ESTORNO','paymentType'=>'SANTANDER']);
$assert($refund['status']==='cancelled'&&$refund['payment_origin']==='BOLETO','Estorno Santander normalizado incorretamente.');

$source=(string)file_get_contents(__DIR__.'/../../api/lib/santander_connector.php');
foreach(['https://trust-sandbox.api.santander.com.br','https://trust-open.api.santander.com.br','https://trust-pix-h.santander.com.br','https://trust-pix.santander.com.br','/collection_bill_management/v2/workspaces/','X-Application-Key','PAGAMENTO','ESTORNO']as$contract)$assert(str_contains($source,$contract),'Contrato oficial ausente: '.$contract);
foreach(['/dda/','pix-transfer','open-finance','tax_payment']as$forbidden)$assert(!str_contains($source,$forbidden),'API fora do escopo encontrada: '.$forbidden);
echo "santander_connector_smoke: OK\n";
