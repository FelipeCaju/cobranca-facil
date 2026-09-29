<?php
declare(strict_types=1);

/**
 * Banco do Brasil: estrutura segura preparada, operações bloqueadas até acesso à
 * OpenAPI específica de Cobrança/PIX contratada no Portal Developers BB.
 */
final class CobxBbConnector implements CobxPaymentConnector,CobxConnectorCapabilities,CobxConnectionTestable
{
    private const PENDING='PENDENTE DE DOCUMENTAÇÃO BB: a especificação autenticada da API BB contratada é necessária para confirmar OAuth, endpoints, scopes e payloads.';

    public function provider():string{return'bb';}
    public function paymentMethods():array{return[];}
    public function capabilities():array{return['credentials_ready','webhook_mtls_production','webhook_manual_sandbox','documentation_blocked'];}
    public function testConnection(array $account):array{return['ok'=>false,'supported'=>false,'detail'=>self::PENDING];}
    public function create(PDO $pdo,array $account,array $charge,array $installment,string $method):array{return['ok'=>false,'detail'=>self::PENDING];}
    public function fetch(array $account,string $externalId):array{return['ok'=>false,'detail'=>self::PENDING];}
    public function cancel(array $account,string $externalId):array{return['ok'=>false,'detail'=>self::PENDING];}
    public function normalize(array $remote):array{return['external_id'=>'','status'=>'pending','provider_status'=>null,'provider_event'=>null,'payment_origin'=>null,'txid'=>null,'provider_reference'=>null,'paid_at'=>null,'receipt_url'=>null];}

    public function verifyWebhook(PDO $pdo,string $companyId,string $raw,array $server,array $query):bool
    {
        $body=json_decode($raw,true);if(!is_array($body))return false;
        $q=$pdo->prepare("SELECT environment FROM payment_accounts WHERE company_id=? AND provider='bb' AND is_active=1");$q->execute([$companyId]);
        foreach($q->fetchAll(PDO::FETCH_COLUMN)as$environment){
            if($environment==='production'&&strtoupper((string)($server['SSL_CLIENT_VERIFY']??''))==='SUCCESS')return true;
            if($environment==='sandbox')return true; // Simulação manual oficial do Portal, sem mTLS.
        }
        return false;
    }

    /** Payload não é interpretado sem a especificação da API; evita baixa financeira especulativa. */
    public function webhookEvents(PDO $pdo,string $companyId,string $raw,array $query):array{return[];}
}
