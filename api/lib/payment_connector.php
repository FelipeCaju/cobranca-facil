<?php
declare(strict_types=1);

interface CobxPaymentConnector
{
    public function provider(): string;
    /** @return list<string> */ public function paymentMethods(): array;
    public function create(PDO $pdo, array $account, array $charge, array $installment, string $method): array;
    public function fetch(array $account, string $externalId): array;
    public function cancel(array $account, string $externalId): array;
    /** Normaliza um retorno remoto para o domínio local. */ public function normalize(array $remote): array;
    public function verifyWebhook(PDO $pdo, string $companyId, string $raw, array $server, array $query): bool;
    /** @return list<array{external_id:string,reference:string,amount:float,paid_at:string,status:string}> */
    public function webhookEvents(PDO $pdo, string $companyId, string $raw, array $query): array;
}

final class CobxAsaasConnector implements CobxPaymentConnector
{
    public function provider():string{return 'asaas';} public function paymentMethods():array{return ['pix','boleto'];}
    public function create(PDO $pdo,array $a,array $c,array $i,string $m):array{$a['gateway_api_key']=$a['api_key']??'';$a['gateway_environment']=$a['environment']??'sandbox';return cobx_gateway_create_asaas_payment($pdo,$a,$c,$i,$m);}
    public function fetch(array $a,string $id):array{$r=cobx_gateway_http_json('GET',cobx_gateway_asaas_base((string)($a['environment']??'sandbox')).'/v3/payments/'.rawurlencode($id),(string)$a['api_key'],null,'asaas');return $r?['ok'=>true,'remote'=>$r,'normalized'=>$this->normalize($r)]:['ok'=>false,'detail'=>'Asaas: falha na consulta.'];}
    public function cancel(array $a,string $id):array{$s=cobx_gateway_http_status('DELETE',cobx_gateway_asaas_base((string)($a['environment']??'sandbox')).'/v3/payments/'.rawurlencode($id),(string)$a['api_key'],null,'asaas');return $s>=200&&$s<300?['ok'=>true,'detail'=>'Cobrança remota cancelada.']:['ok'=>false,'detail'=>'Asaas recusou o cancelamento (HTTP '.$s.').'];}
    public function normalize(array $r):array{return ['external_id'=>(string)($r['id']??''),'status'=>match(strtoupper((string)($r['status']??''))){'RECEIVED','CONFIRMED','RECEIVED_IN_CASH'=>'paid','OVERDUE'=>'overdue','REFUNDED','DELETED'=>'cancelled',default=>'pending'},'paid_at'=>$r['paymentDate']??$r['confirmedDate']??null,'receipt_url'=>$r['transactionReceiptUrl']??null];}
    public function verifyWebhook(PDO $pdo,string $companyId,string $raw,array $server,array $query):bool{foreach(cobx_connector_webhook_secrets($pdo,$companyId,$this->provider()) as $secret)if(hash_equals($secret,trim((string)($server['HTTP_ASAAS_ACCESS_TOKEN']??''))))return true;return false;}
    public function webhookEvents(PDO $pdo,string $companyId,string $raw,array $query):array{$p=json_decode($raw,true);if(!is_array($p))return[];$event=strtoupper((string)($p['event']??''));$pay=is_array($p['payment']??null)?$p['payment']:$p;$n=$this->normalize($pay);$paid=in_array($event,['PAYMENT_RECEIVED','PAYMENT_CONFIRMED'],true)||$n['status']==='paid';return[['external_id'=>(string)($pay['id']??''),'reference'=>(string)($pay['externalReference']??$pay['external_reference']??''),'amount'=>(float)($pay['value']??$pay['netValue']??0),'paid_at'=>(string)($n['paid_at']??''),'status'=>$paid?'paid':$n['status']]];}
}
final class CobxMercadoPagoConnector implements CobxPaymentConnector
{
    public function provider():string{return 'mercadopago';} public function paymentMethods():array{return ['pix'];}
    public function create(PDO $pdo,array $a,array $c,array $i,string $m):array{return $m==='pix'?cobx_gateway_create_mercadopago_pix_payment(['gateway_api_key'=>$a['api_key']??''],$c,$i):['ok'=>false,'detail'=>'Mercado Pago não suporta boleto neste conector.'];}
    public function fetch(array $a,string $id):array{$r=cobx_gateway_http_json('GET','https://api.mercadopago.com/v1/payments/'.rawurlencode($id),(string)$a['api_key'],null,'mercadopago');return $r?['ok'=>true,'remote'=>$r,'normalized'=>$this->normalize($r)]:['ok'=>false,'detail'=>'Mercado Pago: falha na consulta.'];}
    public function cancel(array $a,string $id):array{$s=cobx_gateway_http_status('PUT','https://api.mercadopago.com/v1/payments/'.rawurlencode($id),(string)$a['api_key'],['status'=>'cancelled'],'mercadopago');return $s>=200&&$s<300?['ok'=>true,'detail'=>'Cobrança remota cancelada.']:['ok'=>false,'detail'=>'Mercado Pago recusou o cancelamento (HTTP '.$s.').'];}
    public function normalize(array $r):array{return ['external_id'=>(string)($r['id']??''),'status'=>match(strtolower((string)($r['status']??''))){'approved','authorized'=>'paid','cancelled','refunded','charged_back'=>'cancelled',default=>'pending'},'paid_at'=>$r['date_approved']??null,'receipt_url'=>$r['transaction_details']['external_resource_url']??null];}
    public function verifyWebhook(PDO $pdo,string $companyId,string $raw,array $server,array $query):bool{$sig=(string)($server['HTTP_X_SIGNATURE']??'');$request=(string)($server['HTTP_X_REQUEST_ID']??'');$parts=[];foreach(explode(',',$sig)as$part){$kv=explode('=',$part,2);if(count($kv)===2)$parts[trim($kv[0])]=trim($kv[1]);}$body=json_decode($raw,true);$dataId=mb_strtolower((string)($query['data.id']??$query['data_id']??($body['data']['id']??'')));if(empty($parts['ts'])||empty($parts['v1'])||$request===''||$dataId==='')return false;$manifest='id:'.$dataId.';request-id:'.$request.';ts:'.$parts['ts'].';';foreach(cobx_connector_webhook_secrets($pdo,$companyId,$this->provider())as$secret)if(hash_equals(hash_hmac('sha256',$manifest,$secret),$parts['v1']))return true;return false;}
    public function webhookEvents(PDO $pdo,string $companyId,string $raw,array $query):array{$body=json_decode($raw,true);if(!is_array($body))return[];$ids=[];$id=(string)($query['data.id']??$query['data_id']??($body['data']['id']??$body['id']??''));if($id!=='')$ids[]=$id;$tokens=cobx_connector_account_tokens($pdo,$companyId,$this->provider());foreach($tokens as $token)foreach(array_unique($ids)as$pid){$a=['provider'=>'mercadopago','api_key'=>$token];$f=$this->fetch($a,$pid);if(!$f['ok'])continue;$r=$f['remote'];$n=$f['normalized'];return[['external_id'=>(string)($r['id']??$pid),'reference'=>(string)($r['external_reference']??''),'amount'=>(float)($r['transaction_amount']??0),'paid_at'=>(string)($n['paid_at']??''),'status'=>$n['status']]];}return[];}
}
function cobx_connector(string $provider): CobxPaymentConnector{return match($provider){'asaas'=>new CobxAsaasConnector(),'mercadopago'=>new CobxMercadoPagoConnector(),default=>throw new InvalidArgumentException('Conector não implementado: '.$provider)};}
function cobx_connector_webhook_secrets(PDO $pdo,string $companyId,string $provider):array{$q=$pdo->prepare('SELECT webhook_secret FROM payment_accounts WHERE company_id=? AND provider=? AND is_active=1');$q->execute([$companyId,$provider]);$out=[];foreach($q->fetchAll(PDO::FETCH_COLUMN)as$v){$s=trim((string)(cobx_secret_decrypt((string)$v)??''));if($s!=='')$out[]=$s;}return$out;}
function cobx_connector_account_tokens(PDO $pdo,string $companyId,string $provider):array{$q=$pdo->prepare('SELECT api_key FROM payment_accounts WHERE company_id=? AND provider=? AND is_active=1');$q->execute([$companyId,$provider]);$out=[];foreach($q->fetchAll(PDO::FETCH_COLUMN)as$v){$s=trim((string)(cobx_secret_decrypt((string)$v)??''));if($s!=='')$out[]=$s;}return$out;}
