<?php
declare(strict_types=1);

/**
 * C6 Bank: estrutura segura preparada. Operações permanecem bloqueadas até o
 * titular obter, no portal autenticado, a especificação e as credenciais de teste.
 */
final class CobxC6Connector implements CobxPaymentConnector,CobxConnectorCapabilities,CobxConnectionTestable
{
    private const PENDING='PENDENTE DE DOCUMENTAÇÃO C6: faltam base URLs e endpoints de PIX/boleto, método de autenticação, scopes, requisitos de certificado, contrato de webhook e schemas de requisição/resposta disponibilizados após cadastro/homologação.';

    public function provider():string{return'c6';}
    public function paymentMethods():array{return[];}
    public function capabilities():array{return['pix_product_confirmed','boleto_product_confirmed','boleto_pix_product_confirmed','sandbox_process_confirmed','documentation_blocked'];}
    public function testConnection(array $account):array{return['ok'=>false,'supported'=>false,'detail'=>self::PENDING];}
    public function create(PDO $pdo,array $account,array $charge,array $installment,string $method):array{return['ok'=>false,'detail'=>self::PENDING];}
    public function fetch(array $account,string $externalId):array{return['ok'=>false,'detail'=>self::PENDING];}
    public function cancel(array $account,string $externalId):array{return['ok'=>false,'detail'=>self::PENDING];}
    public function normalize(array $remote):array{return['external_id'=>'','status'=>'pending','provider_status'=>null,'provider_event'=>null,'payment_origin'=>null,'txid'=>null,'provider_reference'=>null,'paid_at'=>null,'receipt_url'=>null];}
    public function verifyWebhook(PDO $pdo,string $companyId,string $raw,array $server,array $query):bool{return false;}
    public function webhookEvents(PDO $pdo,string $companyId,string $raw,array $query):array{return[];}
}
