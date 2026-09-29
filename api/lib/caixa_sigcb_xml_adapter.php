<?php
declare(strict_types=1);

require_once __DIR__.'/bank_http_client.php';

/** Isola todo o contrato SOAP/XML do SIGCB; o restante do sistema recebe somente arrays. */
final class CobxCaixaSigcbXmlAdapter
{
    private const MAINTENANCE_URL='https://barramento.caixa.gov.br/sibar/ManutencaoCobrancaBancaria/Boleto/Externo';
    private const QUERY_URL='https://barramento.caixa.gov.br/sibar/ConsultaCobrancaBancaria/Boleto';

    public function include(array $account,array $charge,array $installment,bool $hybrid):array
    {
        $config=(array)$account['provider_config'];$beneficiary=$this->required($config,'beneficiary_code');$beneficiaryDocument=$this->required($config,'beneficiary_document');
        $ourNumber=$this->ourNumber($config,(string)$installment['id']);$due=(string)$installment['due_date'];$amount=number_format((float)$installment['amount'],2,'.','');
        $auth=$this->authentication($beneficiary,$ourNumber,$due,$amount,$beneficiaryDocument);$document=preg_replace('/\D+/','',(string)($charge['client_document']??''))?:'';
        if(!in_array(strlen($document),[11,14],true))throw new InvalidArgumentException('CAIXA: pagador sem CPF/CNPJ válido.');
        $title=function(XMLWriter $xml)use($config,$charge,$installment,$hybrid,$ourNumber,$due,$amount,$document):void{$xml->startElement('TITULO');$this->element($xml,'NOSSO_NUMERO',$ourNumber);if($hybrid)$this->element($xml,'TIPO','HIBRIDO');$this->element($xml,'NUMERO_DOCUMENTO',substr(preg_replace('/[^A-Za-z0-9]/','',(string)$installment['id'])?:'COBRANCA',0,11));$this->element($xml,'DATA_VENCIMENTO',$due);$this->element($xml,'VALOR',$amount);$this->element($xml,'TIPO_ESPECIE',(string)($config['document_species']??'99'));$this->element($xml,'FLAG_ACEITE',(string)($config['accept_flag']??'N'));$this->element($xml,'DATA_EMISSAO',date('Y-m-d'));$xml->startElement('JUROS_MORA');$this->element($xml,'TIPO','ISENTO');$this->element($xml,'VALOR','0.00');$xml->endElement();$this->element($xml,'VALOR_ABATIMENTO','0.00');$xml->startElement('POS_VENCIMENTO');$this->element($xml,'ACAO',(string)($config['after_due_action']??'DEVOLVER'));$this->element($xml,'NUMERO_DIAS',(string)($config['after_due_days']??30));$xml->endElement();$this->element($xml,'CODIGO_MOEDA','9');$xml->startElement('PAGADOR');$this->element($xml,strlen($document)===11?'CPF':'CNPJ',$document);$this->element($xml,strlen($document)===11?'NOME':'RAZAO_SOCIAL',mb_substr((string)$charge['client_name'],0,40));$xml->startElement('ENDERECO');$this->element($xml,'LOGRADOURO',mb_substr((string)($charge['client_address_street']??''),0,40));$this->element($xml,'BAIRRO',mb_substr((string)($charge['client_address_neighborhood']??''),0,15));$this->element($xml,'CIDADE',mb_substr((string)($charge['client_address_city']??''),0,15));$this->element($xml,'UF',strtoupper(substr((string)($charge['client_address_state']??''),0,2)));$this->element($xml,'CEP',preg_replace('/\D+/','',(string)($charge['client_address_postal_code']??''))?:'');$xml->endElement();$xml->endElement();$xml->startElement('FICHA_COMPENSACAO');$xml->startElement('MENSAGENS');$this->element($xml,'MENSAGEM',mb_substr((string)$charge['description'],0,40));$xml->endElement();$xml->endElement();if($hybrid){$xml->startElement('PAGAMENTO');$this->element($xml,'QUANTIDADE_PERMITIDA','1');$this->element($xml,'TIPO','NAO_ACEITA_VALOR_DIVERGENTE');$xml->endElement();}$xml->endElement();};
        $xml=$this->envelope('maintenance',$hybrid?'3.2':'3.0',$auth,'INCLUI_BOLETO',(string)($config['agency']??''),function(XMLWriter $xml)use($beneficiary,$title):void{$xml->startElement('INCLUI_BOLETO');$this->element($xml,'CODIGO_BENEFICIARIO',$beneficiary);$title($xml);$xml->endElement();});
        return$this->send(self::MAINTENANCE_URL,$xml,'INCLUI_BOLETO');
    }

    public function query(array $account,string $ourNumber):array
    {
        $config=(array)$account['provider_config'];$beneficiary=$this->required($config,'beneficiary_code');$auth=$this->authentication($beneficiary,$ourNumber,null,null,$this->required($config,'beneficiary_document'));
        $xml=$this->envelope('query','5.2',$auth,'CONSULTA_BOLETO',(string)($config['agency']??''),function(XMLWriter $xml)use($beneficiary,$ourNumber):void{$xml->startElement('CONSULTA_BOLETO');$this->element($xml,'CODIGO_BENEFICIARIO',$beneficiary);$this->element($xml,'NOSSO_NUMERO',$ourNumber);$xml->endElement();});
        return$this->send(self::QUERY_URL,$xml,'CONSULTA_BOLETO');
    }

    public function cancel(array $account,string $ourNumber):array
    {
        $config=(array)$account['provider_config'];$beneficiary=$this->required($config,'beneficiary_code');$auth=$this->authentication($beneficiary,$ourNumber,null,null,$this->required($config,'beneficiary_document'));
        $xml=$this->envelope('maintenance','3.0',$auth,'BAIXA_BOLETO',(string)($config['agency']??''),function(XMLWriter $xml)use($beneficiary,$ourNumber):void{$xml->startElement('BAIXA_BOLETO');$this->element($xml,'CODIGO_BENEFICIARIO',$beneficiary);$this->element($xml,'NOSSO_NUMERO',$ourNumber);$xml->endElement();});
        return$this->send(self::MAINTENANCE_URL,$xml,'BAIXA_BOLETO');
    }

    public function authentication(string $beneficiary,string $ourNumber,?string $due,?string $amount,string $beneficiaryDocument):string
    {
        $beneficiary=str_pad(substr(preg_replace('/\D+/','',$beneficiary)?:'',-7),7,'0',STR_PAD_LEFT);$ourNumber=str_pad(substr(preg_replace('/\D+/','',$ourNumber)?:'',-17),17,'0',STR_PAD_LEFT);$date=$due?DateTimeImmutable::createFromFormat('!Y-m-d',$due):false;$datePart=$date?$date->format('dmY'):'00000000';$valuePart=$amount!==null?str_pad(substr(preg_replace('/\D+/','',$amount)?:'',-15),15,'0',STR_PAD_LEFT):str_repeat('0',15);$document=str_pad(substr(preg_replace('/\D+/','',$beneficiaryDocument)?:'',-14),14,'0',STR_PAD_LEFT);$bytes=mb_convert_encoding($beneficiary.$ourNumber.$datePart.$valuePart.$document,'ISO-8859-1','UTF-8');return base64_encode(hash('sha256',$bytes,true));
    }

    private function envelope(string $kind,string $version,string $auth,string $operation,string $agency,callable $data):string
    {
        $maintenance=$kind==='maintenance';$prefix=$maintenance?'ext':'con';$namespace=$maintenance?'http://caixa.gov.br/sibar/manutencao_cobranca_bancaria/boleto/externo':'http://caixa.gov.br/sibar/consulta_cobranca_bancaria/boleto';$xml=new XMLWriter();$xml->openMemory();$xml->startDocument('1.0','UTF-8');$xml->startElementNs('soapenv','Envelope','http://schemas.xmlsoap.org/soap/envelope/');$xml->writeAttribute('xmlns:'.$prefix,$namespace);$xml->writeAttribute('xmlns:sib','http://caixa.gov.br/sibar');$xml->startElementNs('soapenv','Header',null);$xml->endElement();$xml->startElementNs('soapenv','Body',null);$xml->startElementNs($prefix,'SERVICO_ENTRADA',null);$xml->startElementNs('sib','HEADER',null);$this->element($xml,'VERSAO',$version);$this->element($xml,'AUTENTICACAO',$auth);$this->element($xml,'USUARIO_SERVICO','SGCBS02P');$this->element($xml,'OPERACAO',$operation);$this->element($xml,'SISTEMA_ORIGEM','SIGCB');if($agency!=='')$this->element($xml,'UNIDADE',str_pad(substr(preg_replace('/\D+/','',$agency)?:'',-4),4,'0',STR_PAD_LEFT));$this->element($xml,'DATA_HORA',date('YmdHis'));$xml->endElement();$xml->startElement('DADOS');$data($xml);$xml->endElement();$xml->endElement();$xml->endElement();$xml->endElement();$xml->endDocument();return$xml->outputMemory();
    }

    private function send(string $url,string $xml,string $operation):array
    {
        $response=(new CobxBankHttpClient())->request('POST',$url,['raw_body'=>$xml,'headers'=>['Accept'=>'text/xml','Content-Type'=>'text/xml; charset=utf-8'],'idempotency_key'=>hash('sha256',$operation.'|'.$xml),'retry_attempts'=>2]);if(!$response->ok())return['ok'=>false,'detail'=>'CAIXA SIGCB: falha HTTP '.$response->status.'.','http_status'=>$response->status];return$this->parse($response->body,$operation);
    }

    private function parse(string $xml,string $operation):array
    {
        $previous=libxml_use_internal_errors(true);try{$document=new DOMDocument();if(!$document->loadXML($xml,LIBXML_NONET|LIBXML_NOBLANKS))return['ok'=>false,'detail'=>'CAIXA SIGCB: resposta XML inválida.'];$xpath=new DOMXPath($document);$value=static function(string $name)use($xpath):string{$node=$xpath->query('//*[local-name()="'.$name.'"]')->item(0);return trim((string)($node?->textContent??''));};$businessCodes=[];foreach($xpath->query('//*[local-name()="CONTROLE_NEGOCIAL"]/*[local-name()="COD_RETORNO"]')as$node)$businessCodes[]=trim($node->textContent);$ok=($value('COD_RETORNO')===''||in_array($value('COD_RETORNO'),['0','00'],true))&&($businessCodes===[]||count(array_filter($businessCodes,static fn(string $code):bool=>!in_array($code,['0','00'],true)))===0);$message=$value('RETORNO')?:$value('MSG_RETORNO');$result=['ok'=>$ok,'detail'=>$ok?'CAIXA SIGCB: operação efetuada.':'CAIXA SIGCB: '.($message?:'operação recusada.'),'provider_status'=>$message,'operation'=>$operation,'our_number'=>$value('NOSSO_NUMERO'),'barcode'=>$value('CODIGO_BARRAS'),'digitable_line'=>$value('LINHA_DIGITAVEL'),'url'=>$value('URL'),'pix_copy_paste'=>$value('QRCODE'),'pix_qrcode_url'=>$value('URL_QRCODE'),'raw_xml'=>$xml];return$result;}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
    }

    private function ourNumber(array $config,string $installmentId):string{if(($config['our_number_mode']??'caixa')==='caixa')return'0';$digits=preg_replace('/\D+/','',$installmentId)?:sprintf('%015u',crc32($installmentId));return'14'.str_pad(substr($digits,-15),15,'0',STR_PAD_LEFT);}
    private function required(array $config,string $key):string{$value=trim((string)($config[$key]??''));if($value==='')throw new InvalidArgumentException('CAIXA: configuração ausente: '.$key.'.');return$value;}
    private function element(XMLWriter $xml,string $name,string $value):void{$xml->writeElement($name,$value);}
}
