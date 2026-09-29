<?php
declare(strict_types=1);
require_once __DIR__.'/../../api/lib/payment_connector.php';
require_once __DIR__.'/../../api/lib/bank_provider_registry.php';

$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$connector=cobx_connector('caixa');
$assert($connector instanceof CobxCaixaConnector,'Provider CAIXA não registrado.');
$assert($connector instanceof CobxContextualPaymentConnector,'CAIXA não reutiliza o contrato contextual comum.');
$assert($connector->paymentMethods()===['boleto'],'CAIXA deve anunciar apenas boleto SIGCB implementado.');
$definition=cobx_bank_provider_definition('caixa');
$assert(!empty($definition['enabled']),'CAIXA SIGCB não habilitado.');
$assert(in_array('soap_xml',$definition['capabilities'],true)&&in_array('pix_api_pending',$definition['capabilities'],true),'Transporte ou pendências CAIXA não declarados.');

$adapter=new CobxCaixaSigcbXmlAdapter();
$hash=$adapter->authentication('0200400','14000000000012345','2016-02-05','150.05','123456789124');
$raw='0200400'.'14000000000012345'.'05022016'.'000000000015005'.'00123456789124';
$assert(hash_equals(base64_encode(hash('sha256',$raw,true)),$hash),'Hash SIGCB SHA-256/Base64 incorreto.');
$parse=new ReflectionMethod($adapter,'parse');$parse->setAccessible(true);
$parsed=$parse->invoke($adapter,'<Envelope><COD_RETORNO>00</COD_RETORNO><DADOS><CONTROLE_NEGOCIAL><COD_RETORNO>0</COD_RETORNO><MENSAGENS><RETORNO>(0) OPERACAO EFETUADA</RETORNO></MENSAGENS></CONTROLE_NEGOCIAL><INCLUI_BOLETO><CODIGO_BARRAS>104123</CODIGO_BARRAS><LINHA_DIGITAVEL>104999</LINHA_DIGITAVEL><NOSSO_NUMERO>14001</NOSSO_NUMERO><URL>https://caixa.example/boleto</URL><QRCODE>000201</QRCODE><URL_QRCODE>https://caixa.example/qrcode</URL_QRCODE></INCLUI_BOLETO></DADOS></Envelope>','INCLUI_BOLETO');
$assert(!empty($parsed['ok'])&&$parsed['our_number']==='14001'&&$parsed['barcode']==='104123'&&$parsed['digitable_line']==='104999'&&$parsed['pix_copy_paste']==='000201','Parsing de resposta SIGCB incompleto.');
$paid=$connector->normalize(['our_number'=>'1','provider_status'=>'SITUACAO DO TITULO = LIQUIDADO','operation'=>'CONSULTA_BOLETO']);
$assert($paid['status']==='paid'&&$paid['payment_origin']==='BOLETO','Liquidação SIGCB normalizada incorretamente.');
$hybrid=$connector->normalize(['our_number'=>'2','provider_status'=>'SITUACAO DO TITULO = EM ABERTO','pix_copy_paste'=>'000201']);
$assert($hybrid['payment_origin']==='PIX_OR_BOLETO_NAO_INFORMADO'&&$hybrid['pix_copy_paste']==='000201','Boleto híbrido CAIXA normalizado incorretamente.');

$adapterSource=(string)file_get_contents(__DIR__.'/../../api/lib/caixa_sigcb_xml_adapter.php');
foreach(['ConsultaCobrancaBancaria/Boleto','ManutencaoCobrancaBancaria/Boleto/Externo','INCLUI_BOLETO','CONSULTA_BOLETO','BAIXA_BOLETO','SGCBS02P','HIBRIDO','QRCODE','URL_QRCODE','XMLWriter','raw_body']as$contract)$assert(str_contains($adapterSource,$contract),'Contrato SIGCB ausente: '.$contract);
foreach(['oauth','cnab','pixautomatico','openfinance']as$forbidden)$assert(!str_contains(mb_strtolower($adapterSource),$forbidden),'Implementação indevida encontrada: '.$forbidden);
echo "caixa_connector_smoke: OK\n";
