<?php
declare(strict_types=1);

require_once __DIR__.'/caixa_sigcb_xml_adapter.php';

/** CAIXA Recebimentos: SIGCB boleto convencional/híbrido por SOAP/XML. */
final class CobxCaixaConnector implements CobxPaymentConnector,CobxConnectorCapabilities,CobxConnectionTestable,CobxContextualPaymentConnector,CobxContextualConnectionTestable
{
    public function provider():string{return'caixa';}
    public function paymentMethods():array{return['boleto'];}
    public function capabilities():array{return['boleto','boleto_pix_optional','fetch','cancel','pdf','reconciliation_query','soap_xml','sigcb','pix_api_pending','webhook_unavailable','sandbox_pending'];}
    public function testConnection(array $account):array{return['ok'=>false,'supported'=>false,'detail'=>'CAIXA SIGCB: não há teste não destrutivo documentado sem consultar um Nosso Número real.'];}
    public function testConnectionWithContext(PDO $pdo,array $account):array{return$this->testConnection($account);}
    public function fetch(array $account,string $externalId):array{return['ok'=>false,'detail'=>'CAIXA: a consulta exige contexto persistente.'];}
    public function cancel(array $account,string $externalId):array{return['ok'=>false,'detail'=>'CAIXA: a baixa exige contexto persistente.'];}

    public function create(PDO $pdo,array $account,array $charge,array $installment,string $method):array
    {
        if($method!=='boleto')return['ok'=>false,'detail'=>'CAIXA: PIX Cob/CobV avulso permanece PENDENTE DE DOCUMENTAÇÃO CAIXA.'];$hybrid=(string)($account['provider_config']['hybrid_boleto']??'0')==='1';
        try{$remote=(new CobxCaixaSigcbXmlAdapter())->include($account,$charge,$installment,$hybrid);if(empty($remote['ok']))return$remote;$our=(string)($remote['our_number']?:'');return['ok'=>true,'detail'=>'CAIXA: '.($hybrid?'boleto híbrido':'boleto').' emitido via SIGCB.','external_id'=>$our,'provider_reference'=>'caixa:sigcb:'.cobx_bank_installment_reference((string)$installment['id']),'txid'=>'','payment_url'=>(string)$remote['url'],'boleto_digitable_line'=>(string)$remote['digitable_line'],'boleto_pdf_url'=>(string)$remote['url'],'pix_copy_paste'=>(string)$remote['pix_copy_paste'],'pix_qrcode'=>(string)$remote['pix_qrcode_url'],'barcode'=>(string)$remote['barcode']];}
        catch(Throwable $e){return['ok'=>false,'detail'=>'CAIXA: '.$e->getMessage()];}
    }

    public function fetchWithContext(PDO $pdo,array $account,string $externalId):array{try{$remote=(new CobxCaixaSigcbXmlAdapter())->query($account,$externalId);return empty($remote['ok'])?$remote:['ok'=>true,'remote'=>$remote,'normalized'=>$this->normalize($remote)];}catch(Throwable $e){return['ok'=>false,'detail'=>'CAIXA: '.$e->getMessage()];}}
    public function cancelWithContext(PDO $pdo,array $account,string $externalId):array{try{return(new CobxCaixaSigcbXmlAdapter())->cancel($account,$externalId);}catch(Throwable $e){return['ok'=>false,'detail'=>'CAIXA: '.$e->getMessage()];}}
    public function normalize(array $remote):array{$original=(string)($remote['provider_status']??'');$upper=mb_strtoupper($original);$status=str_contains($upper,'LIQUIDADO')||str_contains($upper,'TITULO JA PAGO')?'paid':(str_contains($upper,'BAIXA')||str_contains($upper,'DEVOLUCAO')||str_contains($upper,'ESTORNO')?'cancelled':'pending');$origin=!empty($remote['pix_copy_paste'])?'PIX_OR_BOLETO_NAO_INFORMADO':'BOLETO';return['external_id'=>(string)($remote['our_number']??''),'status'=>$status,'provider_status'=>$original,'provider_event'=>(string)($remote['operation']??''),'payment_origin'=>$origin,'txid'=>null,'provider_reference'=>null,'paid_at'=>null,'receipt_url'=>$remote['url']??null,'boleto_digitable_line'=>$remote['digitable_line']??null,'boleto_pdf_url'=>$remote['url']??null,'pix_copy_paste'=>$remote['pix_copy_paste']??null,'barcode'=>$remote['barcode']??null,'resource_kind'=>'boleto'];}
    public function verifyWebhook(PDO $pdo,string $companyId,string $raw,array $server,array $query):bool{return false;}
    public function webhookEvents(PDO $pdo,string $companyId,string $raw,array $query):array{return[];}
}
