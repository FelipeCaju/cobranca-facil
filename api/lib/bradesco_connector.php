<?php
declare(strict_types=1);

require_once __DIR__.'/bank_certificate_manager.php';
require_once __DIR__.'/bank_http_client.php';
require_once __DIR__.'/bank_idempotency.php';
require_once __DIR__.'/oauth_token_manager.php';

/** Bradesco Recebimentos: somente API Pix oficialmente documentada publicamente. */
final class CobxBradescoConnector implements CobxPaymentConnector,CobxConnectorCapabilities,CobxConnectionTestable,CobxContextualPaymentConnector,CobxContextualConnectionTestable
{
    private const PIX_SANDBOX='https://qrpix-h.bradesco.com.br';
    private const TOKEN_SANDBOX='https://qrpix-h.bradesco.com.br/auth/server/oauth/token';
    private const PIX_PRODUCTION='https://qrpix.bradesco.com.br';

    public function provider():string{return'bradesco';}
    public function paymentMethods():array{return['pix'];}
    public function capabilities():array{return['pix_immediate','pix_received','webhook','cancel','fetch','reconciliation','sandbox','mtls','pix_due_pending','boleto_pending','boleto_pix_pending'];}
    public function testConnection(array $account):array{return['ok'=>false,'supported'=>true,'detail'=>'Bradesco: o teste OAuth/mTLS exige o contexto persistente da conta.'];}
    public function fetch(array $account,string $externalId):array{return['ok'=>false,'detail'=>'Bradesco: a consulta exige o contexto persistente da conta.'];}
    public function cancel(array $account,string $externalId):array{return['ok'=>false,'detail'=>'Bradesco: o cancelamento exige o contexto persistente da conta.'];}

    public function testConnectionWithContext(PDO $pdo,array $account):array
    {
        try{return $this->withMtls($pdo,$account,function(array $mtls)use($pdo,$account):array{$token=$this->token($pdo,$account,$mtls);return['ok'=>$token!=='','supported'=>true,'detail'=>'Bradesco: OAuth2 Client Credentials com mTLS autenticado.'];});}
        catch(Throwable $e){return['ok'=>false,'supported'=>true,'detail'=>'Bradesco: '.$e->getMessage()];}
    }

    public function create(PDO $pdo,array $account,array $charge,array $installment,string $method):array
    {
        if($method!=='pix')return['ok'=>false,'detail'=>'Bradesco: boleto e boleto com PIX estão PENDENTES DE DOCUMENTAÇÃO BRADESCO.'];
        if((string)($installment['due_date']??'')>date('Y-m-d'))return['ok'=>false,'detail'=>'Bradesco: PIX com vencimento (CobV) não consta na especificação oficial pública revisada e permanece pendente.'];
        $config=(array)($account['provider_config']??[]);$key=trim((string)($config['pix_key']??''));
        if($key==='')return['ok'=>false,'detail'=>'Bradesco: configure a chave PIX recebedora.'];
        $document=preg_replace('/\D+/','',(string)($charge['client_document']??''))?:'';
        if(!in_array(strlen($document),[11,14],true))return['ok'=>false,'detail'=>'Bradesco: pagador sem CPF/CNPJ válido.'];
        $txid=cobx_bank_txid((string)$installment['id']);
        $payload=['calendario'=>['expiracao'=>(int)($config['pix_expiration_seconds']??86400)],'devedor'=>[strlen($document)===11?'cpf':'cnpj'=>$document,'nome'=>(string)$charge['client_name']],'valor'=>['original'=>number_format((float)$installment['amount'],2,'.','')],'chave'=>$key,'solicitacaoPagador'=>mb_substr((string)$charge['description'],0,140)];
        try{$response=$this->request($pdo,$account,'PUT','/cob/'.rawurlencode($txid),$payload,cobx_bank_idempotency_key('bradesco',(string)$installment['id'],'pix-cob'));if(!$response->ok())return$this->failure('criar PIX',$response);$remote=$response->json()??[];return['ok'=>true,'detail'=>'Bradesco: PIX imediato criado.','external_id'=>$txid,'provider_reference'=>'bradesco:cob:'.cobx_bank_installment_reference((string)$installment['id']),'txid'=>$txid,'payment_url'=>(string)($remote['location']??''),'pix_copy_paste'=>(string)($remote['pixCopiaECola']??$remote['pix_copia_e_cola']??''),'pix_qrcode'=>(string)($remote['imagemQrcode']??'')];}
        catch(Throwable $e){return['ok'=>false,'detail'=>'Bradesco: '.$e->getMessage()];}
    }

    public function fetchWithContext(PDO $pdo,array $account,string $externalId):array
    {
        try{$response=$this->request($pdo,$account,'GET','/cob/'.rawurlencode($externalId));if(!$response->ok())return$this->failure('consultar PIX',$response);$remote=$response->json()??[];return['ok'=>true,'remote'=>$remote,'normalized'=>$this->normalize($remote)];}
        catch(Throwable $e){return['ok'=>false,'detail'=>'Bradesco: '.$e->getMessage()];}
    }

    public function cancelWithContext(PDO $pdo,array $account,string $externalId):array
    {
        try{$response=$this->request($pdo,$account,'PATCH','/cob/'.rawurlencode($externalId),['status'=>'REMOVIDA_PELO_USUARIO_RECEBEDOR'],cobx_bank_idempotency_key('bradesco',$externalId,'cancel-cob'));return$response->ok()?['ok'=>true,'detail'=>'Bradesco: cobrança PIX removida pelo recebedor.']:$this->failure('cancelar PIX',$response);}
        catch(Throwable $e){return['ok'=>false,'detail'=>'Bradesco: '.$e->getMessage()];}
    }

    public function normalize(array $remote):array
    {
        $original=(string)($remote['status']??'');$upper=mb_strtoupper($original);$pix=is_array($remote['pix']??null)?($remote['pix'][0]??[]):$remote;
        return['external_id'=>(string)($remote['txid']??$pix['txid']??''),'status'=>match($upper){'CONCLUIDA'=>'paid','REMOVIDA_PELO_USUARIO_RECEBEDOR','REMOVIDA_PELO_PSP'=>'cancelled',default=>'pending'},'provider_status'=>$original,'provider_event'=>$remote['tipo_evento']??null,'payment_origin'=>'PIX','txid'=>$remote['txid']??$pix['txid']??null,'provider_reference'=>$remote['referencia']??null,'paid_at'=>$pix['horario']??null,'receipt_url'=>$pix['urlComprovante']??null,'pix_copy_paste'=>$remote['pixCopiaECola']??$remote['pix_copia_e_cola']??null,'resource_kind'=>'cob'];
    }

    public function verifyWebhook(PDO $pdo,string $companyId,string $raw,array $server,array $query):bool
    {
        $body=json_decode($raw,true);if(!is_array($body)||!isset($body['pix'])||!is_array($body['pix']))return false;
        if(!cobx_connector_company_has_account($pdo,$companyId,$this->provider()))return false;if(strtoupper((string)($server['SSL_CLIENT_VERIFY']??''))==='SUCCESS')return true;
        $received=trim((string)($server['HTTP_AUTHORIZATION']??''));foreach(cobx_connector_webhook_secrets($pdo,$companyId,$this->provider())as$secret)if(hash_equals($secret,$received)||hash_equals('Bearer '.$secret,$received))return true;return false;
    }

    public function webhookEvents(PDO $pdo,string $companyId,string $raw,array $query):array
    {
        $body=json_decode($raw,true);if(!is_array($body))return[];$accounts=$pdo->prepare("SELECT * FROM payment_accounts WHERE company_id=? AND provider='bradesco' AND is_active=1");$accounts->execute([$companyId]);$rows=$accounts->fetchAll(PDO::FETCH_ASSOC);$out=[];
        foreach((array)($body['pix']??[])as$item){if(!is_array($item))continue;$txid=(string)($item['txid']??'');if($txid==='')continue;foreach($rows as$row){$f=$this->fetchWithContext($pdo,cobx_bank_account_hydrate($row),$txid);if(empty($f['ok']))continue;$n=$f['normalized'];$out[]=['external_id'=>$txid,'reference'=>(string)($n['provider_reference']??''),'amount'=>(float)($item['valor']??0),'paid_at'=>(string)($item['horario']??$n['paid_at']??''),'status'=>(string)$n['status'],'provider_status'=>$n['provider_status']??null,'provider_event'=>'PIX_RECEBIDO','payment_origin'=>'PIX','txid'=>$txid,'end_to_end_id'=>$item['endToEndId']??null];break;}}
        return$out;
    }

    private function request(PDO $pdo,array $account,string $method,string $path,?array $json=null,?string $key=null):CobxBankHttpResponse
    {
        return$this->withMtls($pdo,$account,function(array $mtls)use($pdo,$account,$method,$path,$json,$key):CobxBankHttpResponse{$options=['bearer'=>$this->token($pdo,$account,$mtls),'mtls'=>$mtls,'retry_attempts'=>2];if($json!==null)$options['json']=$json;if($key)$options['idempotency_key']=$key;return(new CobxBankHttpClient())->request($method,$this->baseUrl($account).$path,$options);});
    }

    private function token(PDO $pdo,array $account,array $mtls):string
    {
        $config=(array)($account['provider_config']??[]);$scope=trim((string)($config['pix_scopes']??''));$strategy=['token_url'=>$this->tokenUrl($account),'grant_type'=>'client_credentials','client_auth'=>'basic'];if($scope!=='')$strategy['scopes']=$scope;return(new CobxOAuthTokenManager())->get($pdo,$account,$strategy,$mtls);
    }
    private function baseUrl(array $account):string{return($account['environment']??'sandbox')==='sandbox'?self::PIX_SANDBOX:self::PIX_PRODUCTION;}
    private function tokenUrl(array $account):string{if(($account['environment']??'sandbox')==='sandbox')return self::TOKEN_SANDBOX;$url=rtrim(trim((string)($account['provider_config']['production_token_url']??'')),'/');if($url===''||!str_starts_with($url,'https://'))throw new RuntimeException('URL oficial do token de produção não configurada; use a URL entregue no onboarding Bradesco.');return$url;}
    private function withMtls(PDO $pdo,array $account,callable $callback):mixed{$manager=new CobxBankCertificateManager();$material=$manager->material($pdo,(string)$account['id']);if(!$material||empty($material['certificate_pem'])||empty($material['private_key_pem']))throw new RuntimeException('certificado mTLS não configurado.');$files=$manager->temporaryFiles($material);try{return$callback($files);}finally{$manager->cleanup($files);}}
    private function failure(string $operation,CobxBankHttpResponse $response):array{$json=$response->json();$message=is_array($json)?(string)($json['mensagem']??$json['message']??$json['detail']??''):'';return['ok'=>false,'detail'=>'Bradesco: falha ao '.$operation.' (HTTP '.$response->status.')'.($message!==''?' - '.$message:'').'.'];}
}
