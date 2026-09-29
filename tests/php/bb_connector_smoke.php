<?php
declare(strict_types=1);
require_once __DIR__.'/../../api/lib/payment_connector.php';
require_once __DIR__.'/../../api/lib/bank_provider_registry.php';

$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$connector=cobx_connector('bb');
$assert($connector instanceof CobxBbConnector,'Estrutura do provider BB não registrada.');
$assert($connector->paymentMethods()===[],'BB não pode anunciar operações sem a OpenAPI específica.');
$definition=cobx_bank_provider_definition('bb',false);
$assert(empty($definition['enabled']),'BB deve permanecer desabilitado enquanto faltar documentação autenticada.');
$assert(($definition['status']??'')==='PENDENTE DE DOCUMENTAÇÃO BB','Status documental BB ausente.');
$keys=array_column($definition['fields'],'key');
foreach(['developer_application_key','client_id','client_secret']as$key)$assert(in_array($key,$keys,true),'Credencial BB ausente: '.$key);
$source=(string)file_get_contents(__DIR__.'/../../api/lib/bb_connector.php');
$assert(str_contains($source,'SSL_CLIENT_VERIFY')&&str_contains($source,"environment==='production'"),'mTLS produtivo do webhook BB não protegido.');
$assert(!str_contains($source,'HTTP_X_BB_'),'Header BB não documentado não pode ser inventado.');
$assert(!preg_match('~https?://[^\'\"]+/(cobranca|cobrancas|pix)~i',$source),'Endpoint BB especulativo encontrado.');
echo "bb_connector_smoke: OK\n";
