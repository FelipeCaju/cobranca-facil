<?php

declare(strict_types=1);

require_once __DIR__ . '/bank_certificate_manager.php';
require_once __DIR__ . '/bank_http_client.php';
require_once __DIR__ . '/bank_idempotency.php';
require_once __DIR__ . '/oauth_token_manager.php';

/** Provider Banco Inter. Não contém API Banking (saldo, extrato ou pagamentos). */
final class CobxInterConnector implements CobxPaymentConnector, CobxConnectorCapabilities, CobxConnectionTestable, CobxContextualPaymentConnector, CobxContextualConnectionTestable
{
    private const PROD = 'https://cdpj.partners.bancointer.com.br';
    private const SANDBOX = 'https://cdpj-sandbox.partners.uatinter.co';

    public function provider(): string { return 'inter'; }
    public function paymentMethods(): array { return ['pix', 'boleto']; }
    public function capabilities(): array { return ['pix_immediate','pix_due','boleto_pix','webhook','cancel','fetch','pdf','reconciliation','sandbox','mtls']; }
    public function testConnection(array $account): array { return ['ok'=>false,'supported'=>true,'detail'=>'O teste do Inter requer contexto seguro da conta e certificado mTLS.']; }
    public function fetch(array $account, string $externalId): array { return ['ok'=>false,'detail'=>'A consulta Inter requer contexto persistente.']; }
    public function cancel(array $account, string $externalId): array { return ['ok'=>false,'detail'=>'O cancelamento Inter requer contexto persistente.']; }

    public function testConnectionWithContext(PDO $pdo, array $account): array
    {
        try {
            $this->withMtls($pdo, $account, function (array $mtls) use ($pdo, $account): void {
                $this->token($pdo, $account, 'cob.read', $mtls);
            });
            return ['ok'=>true,'supported'=>true,'detail'=>'Autenticação OAuth/mTLS do Banco Inter validada (scope cob.read).'];
        } catch (Throwable $e) {
            return ['ok'=>false,'supported'=>true,'detail'=>'Banco Inter: '.$e->getMessage()];
        }
    }

    public function create(PDO $pdo, array $account, array $charge, array $installment, string $method): array
    {
        if ($method === 'boleto') return $this->createBoleto($pdo, $account, $charge, $installment);
        if ($method === 'pix') return $this->createPix($pdo, $account, $charge, $installment);
        return ['ok'=>false,'detail'=>'Banco Inter: método não suportado.'];
    }

    private function createPix(PDO $pdo, array $account, array $charge, array $installment): array
    {
        $txid = cobx_bank_txid((string) $installment['id']);
        $due = (string) $installment['due_date'];
        $kind = $due > date('Y-m-d') ? 'cobv' : 'cob';
        $scope = $kind === 'cobv' ? 'cobv.write' : 'cob.write';
        $config = (array) ($account['provider_config'] ?? []);
        $pixKey = trim((string) ($config['pix_key'] ?? ''));
        if ($pixKey === '') return ['ok'=>false,'detail'=>'Banco Inter: configure a chave PIX recebedora.'];
        $document = preg_replace('/\D+/', '', (string) ($charge['client_document'] ?? '')) ?: '';
        if (!in_array(strlen($document), [11,14], true)) return ['ok'=>false,'detail'=>'Banco Inter: o pagador precisa de CPF ou CNPJ válido.'];
        $debtor = ['nome'=>(string)$charge['client_name'], strlen($document)===11?'cpf':'cnpj'=>$document];
        $payload = [
            'calendario' => $kind === 'cobv' ? ['dataDeVencimento'=>$due,'validadeAposVencimento'=>30] : ['expiracao'=>86400],
            'devedor' => $debtor,
            'valor' => ['original'=>number_format((float)$installment['amount'],2,'.','')],
            'chave' => $pixKey,
            'solicitacaoPagador' => mb_substr((string)$charge['description'],0,140),
            'infoAdicionais' => [['nome'=>'referencia','valor'=>cobx_bank_installment_reference((string)$installment['id'])]],
        ];
        return $this->withMtls($pdo, $account, function (array $mtls) use ($pdo,$account,$scope,$kind,$txid,$payload,$installment): array {
            $response=$this->request($pdo,$account,$scope,'PUT','/pix/v2/'.$kind.'/'.rawurlencode($txid),$mtls,$payload,cobx_bank_idempotency_key('inter',(string)$installment['id'],'pix-'.$kind));
            if(!$response->ok())return $this->failure('criar PIX',$response);
            $remote=$response->json()??[];
            return ['ok'=>true,'detail'=>'Banco Inter: PIX '.($kind==='cobv'?'com vencimento':'imediato').' criado.','external_id'=>$txid,'provider_reference'=>'inter:'.$kind.':'.cobx_bank_installment_reference((string)$installment['id']),'txid'=>$txid,'payment_url'=>(string)($remote['location']??''),'pix_copy_paste'=>(string)($remote['pixCopiaECola']??''),'pix_qrcode'=>''];
        });
    }

    private function createBoleto(PDO $pdo, array $account, array $charge, array $installment): array
    {
        $doc=preg_replace('/\D+/','',(string)($charge['client_document']??''))?:'';
        if(!in_array(strlen($doc),[11,14],true))return ['ok'=>false,'detail'=>'Banco Inter: o pagador precisa de CPF ou CNPJ válido.'];
        foreach(['client_address_street','client_address_city','client_address_state','client_address_postal_code'] as $field) if(trim((string)($charge[$field]??''))==='') return ['ok'=>false,'detail'=>'Banco Inter: complete endereço, cidade, UF e CEP do pagador para emitir boleto.'];
        $phone=preg_replace('/\D+/','',(string)($charge['client_phone']??''))?:'';
        $payer=array_filter([
            'cpfCnpj'=>$doc,'tipoPessoa'=>strlen($doc)===11?'FISICA':'JURIDICA','nome'=>(string)$charge['client_name'],
            'endereco'=>(string)$charge['client_address_street'],'numero'=>(string)($charge['client_address_number']??''),'complemento'=>(string)($charge['client_address_complement']??''),
            'bairro'=>(string)($charge['client_address_neighborhood']??''),'cidade'=>(string)$charge['client_address_city'],'uf'=>strtoupper((string)$charge['client_address_state']),
            'cep'=>preg_replace('/\D+/','',(string)$charge['client_address_postal_code']),'email'=>(string)($charge['client_email']??''),
            'ddd'=>strlen($phone)>=10?substr($phone,0,2):null,'telefone'=>strlen($phone)>=10?substr($phone,2):null,
        ],static fn($v)=>$v!==''&&$v!==null);
        $reference='I'.substr(hash('sha256',(string)$installment['id']),0,14);
        $payload=['seuNumero'=>$reference,'valorNominal'=>round((float)$installment['amount'],2),'dataVencimento'=>(string)$installment['due_date'],'numDiasAgenda'=>0,'pagador'=>$payer];
        return $this->withMtls($pdo,$account,function(array $mtls)use($pdo,$account,$payload,$installment,$reference):array{
            $response=$this->request($pdo,$account,'boleto-cobranca.write','POST','/cobranca/v3/cobrancas',$mtls,$payload,cobx_bank_idempotency_key('inter',(string)$installment['id'],'boleto'));
            if(!$response->ok())return $this->failure('emitir boleto com PIX',$response);
            $issued=$response->json()??[];$code=trim((string)($issued['codigoSolicitacao']??''));
            if($code==='')return ['ok'=>false,'detail'=>'Banco Inter: emissão aceita sem codigoSolicitacao.'];
            $details=$this->request($pdo,$account,'boleto-cobranca.read','GET','/cobranca/v3/cobrancas/'.rawurlencode($code),$mtls)->json()??[];
            $slip=is_array($details['boleto']??null)?$details['boleto']:[];$pix=is_array($details['pix']??null)?$details['pix']:[];
            $pdf=$this->request($pdo,$account,'boleto-cobranca.read','GET','/cobranca/v3/cobrancas/'.rawurlencode($code).'/pdf',$mtls);
            $pdfJson=$pdf->ok()?($pdf->json()??[]):[];$pdf64=(string)($pdfJson['pdf']??'');
            return ['ok'=>true,'detail'=>'Banco Inter: boleto com PIX solicitado.','external_id'=>$code,'provider_reference'=>'inter:boleto:'.cobx_bank_installment_reference((string)$installment['id']),'txid'=>(string)($pix['txid']??''),'payment_url'=>'','boleto_digitable_line'=>(string)($slip['linhaDigitavel']??''),'boleto_pdf_url'=>$pdf64!==''?'data:application/pdf;base64,'.$pdf64:'','pix_copy_paste'=>(string)($pix['pixCopiaECola']??''),'pix_qrcode'=>''];
        });
    }

    public function fetchWithContext(PDO $pdo, array $account, string $externalId): array
    {
        try{return $this->withMtls($pdo,$account,function(array $mtls)use($pdo,$account,$externalId):array{
            $kind=$this->resourceKind($pdo,$account,$externalId);
            [$scope,$path]=$this->resource($kind,$externalId);
            $response=$this->request($pdo,$account,$scope,'GET',$path,$mtls);
            if(!$response->ok())return $this->failure('consultar cobrança',$response);
            $remote=$response->json()??[];$remote['_cobx_resource']=$kind;$normalized=$this->normalize($remote);
            if($kind==='boleto'){$slip=is_array($remote['boleto']??null)?$remote['boleto']:[];$pix=is_array($remote['pix']??null)?$remote['pix']:[];$pdf=$this->request($pdo,$account,'boleto-cobranca.read','GET','/cobranca/v3/cobrancas/'.rawurlencode($externalId).'/pdf',$mtls);$pdfJson=$pdf->ok()?($pdf->json()??[]):[];$pdf64=(string)($pdfJson['pdf']??'');$normalized['boleto_digitable_line']=$slip['linhaDigitavel']??null;$normalized['boleto_pdf_url']=$pdf64!==''?'data:application/pdf;base64,'.$pdf64:null;$normalized['pix_copy_paste']=$pix['pixCopiaECola']??null;}
            return ['ok'=>true,'remote'=>$remote,'normalized'=>$normalized];
        });}catch(Throwable $e){return ['ok'=>false,'detail'=>'Banco Inter: '.$e->getMessage()];}
    }

    public function cancelWithContext(PDO $pdo, array $account, string $externalId): array
    {
        try{return $this->withMtls($pdo,$account,function(array $mtls)use($pdo,$account,$externalId):array{
            $kind=$this->resourceKind($pdo,$account,$externalId);
            if($kind==='boleto')$response=$this->request($pdo,$account,'boleto-cobranca.write','POST','/cobranca/v3/cobrancas/'.rawurlencode($externalId).'/cancelar',$mtls,['motivoCancelamento'=>'SOLICITACAO_DO_BENEFICIARIO'],cobx_bank_idempotency_key('inter',$externalId,'cancel-boleto'));
            else $response=$this->request($pdo,$account,$kind==='cobv'?'cobv.write':'cob.write','PATCH','/pix/v2/'.$kind.'/'.rawurlencode($externalId),$mtls,['status'=>'REMOVIDA_PELO_USUARIO_RECEBEDOR'],cobx_bank_idempotency_key('inter',$externalId,'cancel-'.$kind));
            return $response->ok()?['ok'=>true,'detail'=>'Banco Inter: cobrança cancelada.']:$this->failure('cancelar cobrança',$response);
        });}catch(Throwable $e){return ['ok'=>false,'detail'=>'Banco Inter: '.$e->getMessage()];}
    }

    public function normalize(array $remote): array
    {
        $kind=(string)($remote['_cobx_resource']??'cob');$billing=is_array($remote['cobranca']??null)?$remote['cobranca']:$remote;$pix=is_array($remote['pix']??null)?$remote['pix']:[];
        $original=strtoupper((string)($billing['situacao']??$billing['status']??''));
        $status=match($original){'CONCLUIDA','RECEBIDO','MARCADO_RECEBIDO'=>'paid','REMOVIDA_PELO_USUARIO_RECEBEDOR','REMOVIDA_PELO_PSP','CANCELADO','EXPIRADO'=>'cancelled','ATIVA','A_RECEBER','EM_PROCESSAMENTO'=>'pending','ATRASADO'=>'overdue',default=>'pending'};
        return ['external_id'=>(string)($billing['codigoSolicitacao']??$billing['txid']??''),'status'=>$status,'provider_status'=>$original,'provider_event'=>$billing['motivoCancelamento']??null,'payment_origin'=>$billing['origemRecebimento']??null,'txid'=>$pix['txid']??$billing['txid']??null,'provider_reference'=>$billing['seuNumero']??null,'paid_at'=>$billing['dataSituacao']??$billing['dataHoraSituacao']??null,'receipt_url'=>null,'resource_kind'=>$kind];
    }

    public function verifyWebhook(PDO $pdo, string $companyId, string $raw, array $server, array $query): bool
    {
        if(strtoupper((string)($server['SSL_CLIENT_VERIFY']??''))!=='SUCCESS')return false;
        $received=trim((string)($server['HTTP_X_CONTA_CORRENTE']??''));
        $q=$pdo->prepare("SELECT * FROM payment_accounts WHERE company_id=? AND provider='inter' AND is_active=1");$q->execute([$companyId]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC)as$row){$account=cobx_bank_account_hydrate($row);$expected=trim((string)($account['provider_config']['account_number']??''));if($received===''||$expected===''||hash_equals($expected,$received))return true;}
        return false;
    }

    public function webhookEvents(PDO $pdo, string $companyId, string $raw, array $query): array
    {
        $body=json_decode($raw,true);if(!is_array($body))return[];$items=[];
        if(isset($body['pix'])&&is_array($body['pix']))$items=$body['pix'];elseif(isset($body['payload'])&&is_array($body['payload']))$items=$body['payload'];elseif(array_is_list($body))$items=$body;else $items=[$body];$out=[];
        foreach($items as $item){if(!is_array($item))continue;$billing=is_array($item['cobranca']??null)?$item['cobranca']:$item;$original=strtoupper((string)($billing['situacao']??$billing['status']??($billing['devolucoes']??[]?'DEVOLVIDO':'CONCLUIDA')));$paid=in_array($original,['CONCLUIDA','RECEBIDO','MARCADO_RECEBIDO'],true);$out[]=['external_id'=>(string)($billing['codigoSolicitacao']??$billing['txid']??''),'reference'=>(string)($billing['seuNumero']??''),'amount'=>(float)($billing['valorTotalRecebido']??$billing['valor']??0),'paid_at'=>(string)($billing['dataHoraSituacao']??$billing['horario']??''),'status'=>$paid?'paid':'pending','provider_status'=>$original,'payment_origin'=>$billing['origemRecebimento']??'PIX','txid'=>$billing['txid']??null];}
        return $out;
    }

    private function resourceKind(PDO $pdo,array $account,string $externalId):string{$q=$pdo->prepare('SELECT provider_reference FROM installments i JOIN charges ch ON ch.id=i.charge_id WHERE ch.payment_account_id=? AND i.external_id=? LIMIT 1');$q->execute([$account['id'],$externalId]);$ref=(string)($q->fetchColumn()?:'');if(str_starts_with($ref,'inter:boleto:'))return'boleto';if(str_starts_with($ref,'inter:cobv:'))return'cobv';return'cob';}
    private function resource(string $kind,string $id):array{return $kind==='boleto'?['boleto-cobranca.read','/cobranca/v3/cobrancas/'.rawurlencode($id)]:[$kind==='cobv'?'cobv.read':'cob.read','/pix/v2/'.$kind.'/'.rawurlencode($id)];}
    private function base(array $account):string{return strtolower((string)($account['environment']??'sandbox'))==='production'?self::PROD:self::SANDBOX;}
    private function token(PDO $pdo,array $account,string $scope,array $mtls):string{return(new CobxOAuthTokenManager())->get($pdo,$account,['token_url'=>$this->base($account).'/oauth/v2/token','grant_type'=>'client_credentials','client_auth'=>'body','scopes'=>[$scope]],$mtls);}
    private function request(PDO $pdo,array $account,string $scope,string $method,string $path,array $mtls,?array $json=null,?string $idempotency=null):CobxBankHttpResponse{$options=['bearer'=>$this->token($pdo,$account,$scope,$mtls),'mtls'=>$mtls,'retry_attempts'=>2];$number=trim((string)($account['provider_config']['account_number']??''));if($number!=='')$options['headers']['x-conta-corrente']=$number;if($json!==null)$options['json']=$json;if($idempotency!==null)$options['idempotency_key']=$idempotency;return(new CobxBankHttpClient())->request($method,$this->base($account).$path,$options);}
    private function withMtls(PDO $pdo,array $account,callable $callback):mixed{$manager=new CobxBankCertificateManager();$material=$manager->material($pdo,(string)$account['id']);if(!$material||empty($material['certificate_pem'])||empty($material['private_key_pem']))throw new RuntimeException('certificado e chave privada mTLS não configurados.');$files=$manager->temporaryFiles($material);try{return $callback($files);}finally{$manager->cleanup($files);}}
    private function failure(string $operation,CobxBankHttpResponse $response):array{$json=$response->json();$message=is_array($json)?(string)($json['detail']??$json['title']??$json['message']??''):'';return['ok'=>false,'detail'=>'Banco Inter: falha ao '.$operation.' (HTTP '.$response->status.')'.($message!==''?' - '.$message:'').'.'];}
}
