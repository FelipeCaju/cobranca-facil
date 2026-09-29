<?php
declare(strict_types=1);
require_once __DIR__.'/../../api/lib/payment_connector.php';
require_once __DIR__.'/../../api/lib/bank_provider_registry.php';

$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$connector=cobx_connector('c6');
$assert($connector instanceof CobxC6Connector,'Estrutura do provider C6 não registrada.');
$assert($connector->paymentMethods()===[],'C6 não pode anunciar operações sem a documentação autenticada.');
$definition=cobx_bank_provider_definition('c6',false);
$assert(empty($definition['enabled']),'C6 deve permanecer desabilitado enquanto faltar documentação autenticada.');
$assert(($definition['status']??'')==='PENDENTE DE DOCUMENTAÇÃO C6','Status documental C6 ausente.');
$assert(in_array('pix_product_confirmed',$definition['capabilities'],true)&&in_array('boleto_product_confirmed',$definition['capabilities'],true),'Produtos públicos C6 não registrados.');
$assert($definition['fields']===[],'Credenciais C6 não confirmadas não podem ser solicitadas como contrato definitivo.');
$source=(string)file_get_contents(__DIR__.'/../../api/lib/c6_connector.php');
foreach(['base URLs e endpoints','método de autenticação','scopes','requisitos de certificado','contrato de webhook','schemas de requisição/resposta']as$missing)$assert(str_contains($source,$missing),'Pendência C6 não identificada: '.$missing);
$assert(!preg_match('~https?://~i',$source),'Endpoint C6 especulativo encontrado.');
$assert(!str_contains(mb_strtolower($source),'client_credentials'),'OAuth C6 não confirmado foi inventado.');
$assert(!$connector->verifyWebhook(new PDO('sqlite::memory:'),'company','{}',[],[]),'Webhook C6 não documentado foi aceito.');
echo "c6_connector_smoke: OK\n";
