<?php
declare(strict_types=1);
require_once __DIR__.'/../../api/lib/payment_connector.php';
require_once __DIR__.'/../../api/lib/bank_provider_registry.php';

$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$connector=cobx_connector('bradesco');
$assert($connector instanceof CobxBradescoConnector,'Provider Bradesco não registrado.');
$assert($connector instanceof CobxContextualPaymentConnector,'Bradesco não reutiliza o contrato contextual comum.');
$assert($connector->paymentMethods()===['pix'],'Bradesco deve anunciar apenas o Pix oficialmente implementado.');
$definition=cobx_bank_provider_definition('bradesco');
$assert(!empty($definition['enabled'])&&!empty($definition['requires_certificate']),'Bradesco PIX não habilitado com certificado mTLS.');
$assert(in_array('pix_due_pending',$definition['capabilities'],true)&&in_array('boleto_pending',$definition['capabilities'],true),'Pendências Bradesco não declaradas.');
$paid=$connector->normalize(['status'=>'CONCLUIDA','txid'=>'tx1','pix'=>[['horario'=>'2026-09-29T12:00:00Z']]]);
$assert($paid['status']==='paid'&&$paid['payment_origin']==='PIX'&&$paid['txid']==='tx1','PIX Bradesco normalizado incorretamente.');
$cancelled=$connector->normalize(['status'=>'REMOVIDA_PELO_USUARIO_RECEBEDOR','txid'=>'tx2']);
$assert($cancelled['status']==='cancelled','Cancelamento Bradesco normalizado incorretamente.');
$source=(string)file_get_contents(__DIR__.'/../../api/lib/bradesco_connector.php');
foreach(['https://qrpix-h.bradesco.com.br','/auth/server/oauth/token','https://qrpix.bradesco.com.br',"'/cob/'",'REMOVIDA_PELO_USUARIO_RECEBEDOR','CobxOAuthTokenManager','CobxBankCertificateManager']as$contract)$assert(str_contains($source,$contract),'Contrato Bradesco ausente: '.$contract);
foreach(['/pagamentos/','open-finance','pix_saida','/saldo','/extrato']as$forbidden)$assert(!str_contains(mb_strtolower($source),$forbidden),'API fora do escopo encontrada: '.$forbidden);
echo "bradesco_connector_smoke: OK\n";
