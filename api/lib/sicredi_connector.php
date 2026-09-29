<?php
declare(strict_types=1);

require_once __DIR__.'/bank_certificate_manager.php';
require_once __DIR__.'/bank_http_client.php';
require_once __DIR__.'/bank_idempotency.php';
require_once __DIR__.'/oauth_token_manager.php';

/** Sicredi Recebimentos: API Pix e API de Cobrança, com autenticações independentes. */
final class CobxSicrediConnector implements CobxPaymentConnector,CobxConnectorCapabilities,CobxConnectionTestable,CobxContextualPaymentConnector,CobxContextualConnectionTestable
{
    private const PIX_PROD='https://api-pix.sicredi.com.br';
    private const BILLING_PROD='https://api-parceiro.sicredi.com.br';
    private const BILLING_SANDBOX='https://api-parceiro.sicredi.com.br/sb';

    public function provider():string{return'sicredi';}
    public function paymentMethods():array{return['pix','boleto'];}
    public function capabilities():array{return['pix_immediate','pix_due','pix_received','boleto','boleto_pix_optional','webhook','cancel','fetch','reconciliation','sandbox_partial','mtls_pix'];}
    public function testConnection(array $account):array{return['ok'=>false,'supported'=>true,'detail'=>'O teste Sicredi exige contexto seguro; PIX também exige certificado mTLS.'];}
    public function fetch(array $account,string $externalId):array{return['ok'=>false,'detail'=>'A consulta Sicredi exige contexto persistente.'];}
    public function cancel(array $account,string $externalId):array{return['ok'=>false,'detail'=>'A baixa Sicredi exige contexto persistente.'];}

    public function testConnectionWithContext(PDO $pdo,array $account):array
    {
        try{$billing=$this->billingToken($pdo,$account);$pix=$this->withMtls($pdo,$account,fn(array $m):string=>$this->pixToken($pdo,$account,$m));return['ok'=>$billing!==''&&$pix!=='','supported'=>true,'detail'=>'Autenticações independentes de PIX e Cobrança Sicredi disponíveis.'];}
        catch(Throwable $e){return['ok'=>false,'supported'=>true,'detail'=>'Sicredi: '.$e->getMessage()];}
    }

    public function create(PDO $pdo,array $account,array $charge,array $installment,string $method):array
    {return$method==='boleto'?$this->createBoleto($pdo,$account,$charge,$installment):$this->createPix($pdo,$account,$charge,$installment);}

    private function createPix(PDO $pdo,array $account,array $charge,array $installment):array
    {
        $c=(array)($account['provider_config']??[]);$key=trim((string)($c['pix_key']??''));if($key==='')return['ok'=>false,'detail'=>'Sicredi: configure a chave PIX.'];
        $doc=preg_replace('/\D+/','',(string)($charge['client_document']??''))?:'';if(!in_array(strlen($doc),[11,14],true))return['ok'=>false,'detail'=>'Sicredi: pagador sem CPF/CNPJ válido.'];
        $txid=cobx_bank_txid((string)$installment['id']);$kind=(string)$installment['due_date']>date('Y-m-d')?'cobv':'cob';
        $payload=['calendario'=>$kind==='cobv'?['dataDeVencimento'=>(string)$installment['due_date'],'validadeAposVencimento'=>30]:['expiracao'=>86400],'devedor'=>[strlen($doc)===11?'cpf':'cnpj'=>$doc,'nome'=>(string)$charge['client_name']],'valor'=>['original'=>number_format((float)$installment['amount'],2,'.','')],'chave'=>$key,'solicitacaoPagador'=>mb_substr((string)$charge['description'],0,140),'infoAdicionais'=>[['nome'=>'referencia','valor'=>cobx_bank_installment_reference((string)$installment['id'])]]];
        try{return$this->withMtls($pdo,$account,function(array $m)use($pdo,$account,$payload,$txid,$kind,$installment):array{$r=$this->pixRequest($pdo,$account,'PUT','/'.$kind.'/'.rawurlencode($txid),$m,$payload,cobx_bank_idempotency_key('sicredi',(string)$installment['id'],'pix-'.$kind));if(!$r->ok())return$this->failure('criar PIX',$r);$j=$r->json()??[];return['ok'=>true,'detail'=>'Sicredi: PIX '.($kind==='cobv'?'com vencimento':'imediato').' criado.','external_id'=>$txid,'provider_reference'=>'sicredi:'.$kind.':'.cobx_bank_installment_reference((string)$installment['id']),'txid'=>$txid,'payment_url'=>(string)($j['location']??''),'pix_copy_paste'=>(string)($j['pixCopiaECola']??''),'pix_qrcode'=>''];});}catch(Throwable $e){return['ok'=>false,'detail'=>'Sicredi: '.$e->getMessage()];}
    }

    private function createBoleto(PDO $pdo,array $account,array $charge,array $installment):array
    {
        $c=(array)($account['provider_config']??[]);foreach(['cooperativa','posto','codigo_beneficiario','especie_documento']as$k)if(trim((string)($c[$k]??''))==='')return['ok'=>false,'detail'=>'Sicredi: configuração ausente: '.$k.'.'];
        $doc=preg_replace('/\D+/','',(string)($charge['client_document']??''))?:'';if(!in_array(strlen($doc),[11,14],true))return['ok'=>false,'detail'=>'Sicredi: pagador sem CPF/CNPJ válido.'];
        $payload=['codigoBeneficiario'=>(string)$c['codigo_beneficiario'],'dataVencimento'=>(string)$installment['due_date'],'especieDocumento'=>(string)$c['especie_documento'],'tipoCobranca'=>(string)($c['boleto_hibrido']??'0')==='1'?'HIBRIDO':'NORMAL','seuNumero'=>substr(preg_replace('/\D/','',(string)$installment['id'])?:hash('crc32b',(string)$installment['id']),0,10),'idTituloEmpresa'=>cobx_bank_installment_reference((string)$installment['id']),'valor'=>round((float)$installment['amount'],2),'pagador'=>['tipoPessoa'=>strlen($doc)===11?'PESSOA_FISICA':'PESSOA_JURIDICA','documento'=>$doc,'nome'=>(string)$charge['client_name'],'endereco'=>(string)($charge['client_address_street']??''),'cidade'=>(string)($charge['client_address_city']??''),'uf'=>(string)($charge['client_address_state']??''),'cep'=>preg_replace('/\D+/','',(string)($charge['client_address_postal_code']??''))]];
        try{$r=$this->billingRequest($pdo,$account,'POST','/cobranca/boleto/v1/boletos',$payload,cobx_bank_idempotency_key('sicredi',(string)$installment['id'],'boleto'));if(!$r->ok())return$this->failure('emitir boleto',$r);$j=$r->json()??[];$our=(string)($j['nossoNumero']??'');if($our==='')return['ok'=>false,'detail'=>'Sicredi: emissão aceita sem nosso número.'];return['ok'=>true,'detail'=>'Sicredi: boleto '.(($payload['tipoCobranca']==='HIBRIDO')?'híbrido':'normal').' emitido.','external_id'=>$our,'provider_reference'=>'sicredi:boleto:'.cobx_bank_installment_reference((string)$installment['id']),'txid'=>(string)($j['txid']??''),'payment_url'=>(string)($j['linkBoleto']??''),'boleto_digitable_line'=>(string)($j['linhaDigitavel']??''),'boleto_pdf_url'=>(string)($j['linkBoleto']??''),'pix_copy_paste'=>(string)($j['qrCode']??$j['pixCopiaECola']??''),'pix_qrcode'=>''];}catch(Throwable $e){return['ok'=>false,'detail'=>'Sicredi: '.$e->getMessage()];}
    }

    public function fetchWithContext(PDO $pdo,array $account,string $externalId):array
    {
        try{$kind=$this->resourceKind($pdo,$account,$externalId);if($kind==='boleto'){$c=(array)$account['provider_config'];$path='/cobranca/boleto/'.(($account['environment']??'sandbox')==='production'?'v2':'v1').'/boletos?'.http_build_query(['codigoBeneficiario'=>$c['codigo_beneficiario'],'nossoNumero'=>$externalId]);$r=$this->billingRequest($pdo,$account,'GET',$path);}else{$r=$this->withMtls($pdo,$account,fn(array $m):CobxBankHttpResponse=>$this->pixRequest($pdo,$account,'GET','/'.$kind.'/'.rawurlencode($externalId),$m));}if(!$r->ok())return$this->failure('consultar recebimento',$r);$remote=$r->json()??[];$remote['_cobx_resource']=$kind;return['ok'=>true,'remote'=>$remote,'normalized'=>$this->normalize($remote)];}catch(Throwable $e){return['ok'=>false,'detail'=>'Sicredi: '.$e->getMessage()];}
    }

    public function cancelWithContext(PDO $pdo,array $account,string $externalId):array
    {
        try{$kind=$this->resourceKind($pdo,$account,$externalId);if($kind==='boleto')$r=$this->billingRequest($pdo,$account,'PATCH','/cobranca/boleto/v1/boletos/'.rawurlencode($externalId).'/baixa',[],cobx_bank_idempotency_key('sicredi',$externalId,'baixa'));else$r=$this->withMtls($pdo,$account,fn(array $m):CobxBankHttpResponse=>$this->pixRequest($pdo,$account,'PATCH','/'.$kind.'/'.rawurlencode($externalId),$m,['status'=>'REMOVIDA_PELO_USUARIO_RECEBEDOR'],cobx_bank_idempotency_key('sicredi',$externalId,'cancel-'.$kind)));return$r->ok()?['ok'=>true,'detail'=>'Sicredi: baixa/cancelamento solicitado.']:$this->failure('baixar/cancelar',$r);}catch(Throwable $e){return['ok'=>false,'detail'=>'Sicredi: '.$e->getMessage()];}
    }

    public function normalize(array $remote):array{$kind=(string)($remote['_cobx_resource']??'cob');$r=is_array($remote['items'][0]??null)?$remote['items'][0]:$remote;$original=(string)($r['status']??$r['situacao']??$r['statusTitulo']??'');$u=mb_strtoupper($original);$status=match($u){'CONCLUIDA','LIQUIDADO','LIQUIDADA','PAGO','PAGA'=>'paid','BAIXADO','BAIXADA','CANCELADO','CANCELADA','REMOVIDA_PELO_USUARIO_RECEBEDOR','REMOVIDA_PELO_PSP'=>'cancelled','VENCIDO','VENCIDA'=>'overdue',default=>'pending'};return['external_id'=>(string)($r['nossoNumero']??$r['txid']??''),'status'=>$status,'provider_status'=>$original,'provider_event'=>$r['tipoEvento']??null,'payment_origin'=>$r['formaLiquidacao']??($kind==='boleto'?'BOLETO':'PIX'),'txid'=>$r['txid']??null,'provider_reference'=>$r['idTituloEmpresa']??$r['seuNumero']??null,'paid_at'=>$r['horario']??$r['dataLiquidacao']??null,'receipt_url'=>$r['linkBoleto']??null,'boleto_digitable_line'=>$r['linhaDigitavel']??null,'boleto_pdf_url'=>$r['linkBoleto']??null,'pix_copy_paste'=>$r['qrCode']??$r['pixCopiaECola']??null,'resource_kind'=>$kind];}

    public function verifyWebhook(PDO $pdo,string $companyId,string $raw,array $server,array $query):bool
    {
        if(strtoupper((string)($server['SSL_CLIENT_VERIFY']??''))==='SUCCESS')return true;
        $received=trim((string)($server['HTTP_AUTHORIZATION']??''));$q=$pdo->prepare("SELECT * FROM payment_accounts WHERE company_id=? AND provider='sicredi' AND is_active=1");$q->execute([$companyId]);foreach($q->fetchAll(PDO::FETCH_ASSOC)as$row){$a=cobx_bank_account_hydrate($row);$secret=trim((string)($a['credentials']['webhook_token']??''));if($secret!==''&&(hash_equals($secret,$received)||hash_equals('Bearer '.$secret,$received)))return true;}return false;
    }

    public function webhookEvents(PDO $pdo,string $companyId,string $raw,array $query):array
    {
        $body=json_decode($raw,true);if(!is_array($body))return[];$items=isset($body['pix'])&&is_array($body['pix'])?$body['pix']:(array_is_list($body)?$body:[$body]);$out=[];
        foreach($items as$item){if(!is_array($item))continue;$our=(string)($item['nossoNumero']??'');if($our!==''){$q=$pdo->prepare("SELECT * FROM payment_accounts WHERE company_id=? AND provider='sicredi' AND is_active=1");$q->execute([$companyId]);foreach($q->fetchAll(PDO::FETCH_ASSOC)as$row){$f=$this->fetchWithContext($pdo,cobx_bank_account_hydrate($row),$our);if(!empty($f['ok'])){$n=$f['normalized'];$out[]=['external_id'=>$our,'reference'=>(string)($n['provider_reference']??''),'amount'=>(float)($item['valorLiquidacao']??$item['valor']??0),'paid_at'=>(string)($n['paid_at']??''),'status'=>(string)$n['status'],'provider_status'=>$n['provider_status']??null,'payment_origin'=>$n['payment_origin']??'BOLETO','txid'=>$n['txid']??null];break;}}continue;}$status=(string)($item['status']??'CONCLUIDA');$out[]=['external_id'=>(string)($item['txid']??''),'reference'=>'','amount'=>(float)($item['valor']??0),'paid_at'=>(string)($item['horario']??''),'status'=>mb_strtoupper($status)==='CONCLUIDA'?'paid':'pending','provider_status'=>$status,'payment_origin'=>'PIX','txid'=>$item['txid']??null];}return$out;
    }

    private function pixToken(PDO $pdo,array $a,array $mtls):string{$copy=$a;$copy['credentials']['client_id']=$a['credentials']['pix_client_id']??'';$copy['credentials']['client_secret']=$a['credentials']['pix_client_secret']??'';$scopes=preg_split('/[\s,+]+/',trim((string)($a['provider_config']['pix_scopes']??'')),-1,PREG_SPLIT_NO_EMPTY)?:[];if($scopes===[])throw new RuntimeException('scopes PIX não configurados.');$tokenUrl=$this->pixUrls($a)['token'];return(new CobxOAuthTokenManager())->get($pdo,$copy,['token_url'=>$tokenUrl,'grant_type'=>'client_credentials','client_auth'=>'basic','scopes'=>$scopes],$mtls);}
    private function billingToken(PDO $pdo,array $a):string{$c=(array)$a['provider_config'];$cr=(array)$a['credentials'];$api=trim((string)($cr['billing_api_key']??''));$code=trim((string)($cr['billing_access_code']??''));if($api===''||$code==='')throw new RuntimeException('x-api-key ou Código de Acesso da Cobrança ausente.');$base=($a['environment']??'sandbox')==='production'?self::BILLING_PROD:self::BILLING_SANDBOX;$copy=$a;$copy['credentials']['client_id']='';$copy['credentials']['client_secret']='';return(new CobxOAuthTokenManager())->get($pdo,$copy,['token_url'=>$base.'/auth/openapi/token','grant_type'=>'password','client_auth'=>'none','parameters'=>['username'=>(string)($c['codigo_beneficiario']??'').(string)($c['cooperativa']??''),'password'=>$code,'scope'=>'cobranca'],'headers'=>['x-api-key'=>$api,'context'=>'COBRANCA']]);}
    private function pixRequest(PDO $pdo,array $a,string $method,string $path,array $mtls,?array $json=null,?string $idempotency=null):CobxBankHttpResponse{$urls=$this->pixUrls($a);$o=['bearer'=>$this->pixToken($pdo,$a,$mtls),'mtls'=>$mtls,'retry_attempts'=>2];if($json!==null)$o['json']=$json;if($idempotency)$o['idempotency_key']=$idempotency;return(new CobxBankHttpClient())->request($method,$urls['base'].$path,$o);}
    private function pixUrls(array $a):array{if(($a['environment']??'sandbox')==='production')return['base'=>self::PIX_PROD.'/api/v2','token'=>self::PIX_PROD.'/oauth/token'];$c=(array)($a['provider_config']??[]);$base=rtrim(trim((string)($c['pix_sandbox_base_url']??'')),'/');$token=trim((string)($c['pix_sandbox_token_url']??''));if($base===''||$token==='')throw new RuntimeException('URLs oficiais de homologação PIX não configuradas; o sistema não usará produção como sandbox.');return['base'=>$base,'token'=>$token];}
    private function billingRequest(PDO $pdo,array $a,string $method,string $path,?array $json=null,?string $idempotency=null):CobxBankHttpResponse{$c=(array)$a['provider_config'];$cr=(array)$a['credentials'];$base=($a['environment']??'sandbox')==='production'?self::BILLING_PROD:self::BILLING_SANDBOX;$o=['bearer'=>$this->billingToken($pdo,$a),'headers'=>['x-api-key'=>(string)($cr['billing_api_key']??''),'cooperativa'=>(string)($c['cooperativa']??''),'posto'=>(string)($c['posto']??''),'codigoBeneficiario'=>(string)($c['codigo_beneficiario']??'')],'retry_attempts'=>2];if($json!==null)$o['json']=$json;if($idempotency)$o['idempotency_key']=$idempotency;return(new CobxBankHttpClient())->request($method,$base.$path,$o);}
    private function resourceKind(PDO $pdo,array $a,string $id):string{$q=$pdo->prepare('SELECT provider_reference FROM installments i JOIN charges ch ON ch.id=i.charge_id WHERE ch.payment_account_id=? AND i.external_id=? LIMIT 1');$q->execute([$a['id'],$id]);$ref=(string)($q->fetchColumn()?:'');if(str_starts_with($ref,'sicredi:boleto:'))return'boleto';if(str_starts_with($ref,'sicredi:cobv:'))return'cobv';return'cob';}
    private function withMtls(PDO $pdo,array $a,callable $cb):mixed{$m=new CobxBankCertificateManager();$material=$m->material($pdo,(string)$a['id']);if(!$material||empty($material['certificate_pem'])||empty($material['private_key_pem']))throw new RuntimeException('certificado e chave mTLS do PIX não configurados.');$files=$m->temporaryFiles($material);try{return$cb($files);}finally{$m->cleanup($files);}}
    private function failure(string $op,CobxBankHttpResponse $r):array{$j=$r->json();$msg=is_array($j)?(string)($j['detail']??$j['message']??$j['title']??''):'';return['ok'=>false,'detail'=>'Sicredi: falha ao '.$op.' (HTTP '.$r->status.')'.($msg!==''?' - '.$msg:'').'.'];}
}
