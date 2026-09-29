<?php
declare(strict_types=1);
require_once __DIR__.'/../../api/lib/payment_connector.php';
require_once __DIR__.'/../../api/lib/bank_provider_registry.php';

$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$connector=cobx_connector('itau');
$assert($connector instanceof CobxItauConnector,'Provider Itaú não registrado.');
$assert($connector instanceof CobxContextualPaymentConnector,'Itaú não reutiliza o contrato contextual comum.');
$assert($connector->paymentMethods()===['pix','boleto'],'Métodos Itaú incorretos.');
$definition=cobx_bank_provider_definition('itau');
$assert(!empty($definition['enabled'])&&!empty($definition['requires_certificate']),'Itaú não habilitado com certificado produtivo.');
$assert(in_array('sandbox_simplified',$definition['capabilities'],true)&&in_array('dynamic_certificate',$definition['capabilities'],true),'Ambientes/certificado Itaú não declarados.');

$pix=$connector->normalize(['_cobx_resource'=>'cob','txid'=>'tx1','status'=>'CONCLUIDA']);
$assert($pix['status']==='paid'&&$pix['payment_origin']==='PIX','PIX Itaú normalizado incorretamente.');
$boleto=$connector->normalize(['_cobx_resource'=>'boleto','dados_individuais_boleto'=>[['numero_nosso_numero'=>'123','situacao_geral_boleto'=>'LIQUIDADO','tipo_pagamento'=>'PIX','pix_copia_e_cola'=>'abc']]]);
$assert($boleto['status']==='paid'&&$boleto['payment_origin']==='PIX'&&$boleto['external_id']==='123','Bolecode Itaú normalizado incorretamente.');

$source=(string)file_get_contents(__DIR__.'/../../api/lib/itau_connector.php');
foreach(['https://sts.itau.com.br/api/oauth/token','https://secure.api.itau/pix_recebimentos/v2','sandbox_access_token','production_billing_base_url','valid_until',"?'cobv':'cob'",'/boletos']as$contract)$assert(str_contains($source,$contract),'Contrato Itaú ausente: '.$contract);
foreach(['/pagamentos/','pix_saida','/dda/','transferencia']as$forbidden)$assert(!str_contains($source,$forbidden),'API de saída fora do escopo encontrada: '.$forbidden);
echo "itau_connector_smoke: OK\n";
