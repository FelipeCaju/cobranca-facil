<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/admin_notifications.php';
require_once __DIR__ . '/../lib/charge_helpers.php';
require_once __DIR__ . '/../lib/charge_audit.php';
require_once __DIR__ . '/../lib/gateway_payments.php';

/**
 * Webhooks publicos (sem JWT) para gateways notificarem pagamentos.
 *
 * @param list<string> $seg segmentos apos "webhooks/"
 */
function handle_webhooks(PDO $pdo, string $method, array $seg): void
{
    $kind = $seg[0] ?? '';
    if ($kind === 'plans') {
        require_once __DIR__ . '/webhooks_plans.php';
        handle_webhooks_plans($pdo, $method, array_slice($seg, 1));
        return;
    }
    if ($kind !== 'payment') {
        json_response(404, ['error' => 'Webhook nao encontrado']);
    }

    $gateway = $seg[1] ?? '';
    $companyId = $seg[2] ?? '';
    if ($method !== 'POST') {
        json_response(405, ['error' => 'Metodo nao permitido']);
    }
    try{$connector=cobx_connector($gateway);}catch(Throwable){json_response(422,['error'=>'Gateway invalido']);}
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $companyId)) {
        json_response(422, ['error' => 'Empresa invalida']);
    }

    $st = $pdo->prepare('SELECT id FROM companies WHERE id = ? AND is_active = 1 LIMIT 1');
    $st->execute([$companyId]);
    if (!$st->fetch()) {
        json_response(404, ['error' => 'Empresa nao encontrada']);
    }

    $raw = file_get_contents('php://input');
    if ($raw === false) {
        $raw = '';
    }

    if (!$connector->verifyWebhook($pdo,$companyId,$raw,$_SERVER,$_GET)) {
        cobx_payment_webhook_audit($pdo, $companyId, $gateway, $raw, ['ok'=>false,'paid'=>false,'message'=>'Assinatura de webhook inválida.']);
        json_response(401, ['error' => 'Assinatura de webhook inválida']);
    }
    $eventKey = hash('sha256', $gateway . '|' . ($_SERVER['HTTP_X_REQUEST_ID'] ?? '') . '|' . $raw);
    $seen = $pdo->prepare('SELECT 1 FROM payment_webhook_events WHERE company_id=? AND provider=? AND event_key=? LIMIT 1');
    $seen->execute([$companyId, $gateway, $eventKey]);
    if ($seen->fetchColumn()) json_response(200, ['ok'=>true,'paid'=>false,'duplicate'=>true,'message'=>'Evento já processado.']);

    $result=['ok'=>true,'paid'=>false,'message'=>'Evento recebido sem baixa.'];
    foreach($connector->webhookEvents($pdo,$companyId,$raw,$_GET) as $event){
        if($event['status']!=='paid'){$result=['ok'=>true,'paid'=>false,'message'=>'Evento recebido com status '.$event['status'].'.'];continue;}
        $result=cobx_payment_webhook_mark_paid($pdo,$companyId,$connector->provider(),$event['external_id'],$event['reference'],$event['amount'],$event['paid_at']);
        if($result['paid'])break;
    }

    $result['event_key'] = $eventKey;
    cobx_payment_webhook_audit($pdo, $companyId, $gateway, $raw, $result);

    admin_notify_superadmin(
        $pdo,
        $result['paid'] ? 'payment-webhook-paid' : 'payment-webhook',
        $result['paid'] ? 'Pagamento confirmado automaticamente' : 'Webhook de pagamento recebido',
        [
            'Gateway: ' . $gateway,
            'Empresa ID: ' . $companyId,
            'Resultado: ' . $result['message'],
            'Charge ID: ' . (string) ($result['charge_id'] ?? ''),
            'Parcela ID: ' . (string) ($result['installment_id'] ?? ''),
        ]
    );

    json_response(200, $result);
}

function cobx_payment_webhook_authentic(PDO $pdo, string $companyId, string $gateway, string $raw): bool
{
    $st=$pdo->prepare('SELECT webhook_secret FROM payment_accounts WHERE company_id=? AND provider=? AND is_active=1 AND webhook_secret IS NOT NULL');
    $st->execute([$companyId,$gateway]); $secrets=[];
    foreach($st->fetchAll(PDO::FETCH_COLUMN) as $stored){$s=trim((string)(cobx_secret_decrypt((string)$stored)??'')); if($s!=='')$secrets[]=$s;}
    if($secrets===[]) return false;
    if($gateway==='asaas'){
        $received=trim((string)($_SERVER['HTTP_ASAAS_ACCESS_TOKEN']??''));
        foreach($secrets as $secret) if(hash_equals($secret,$received)) return true;
        return false;
    }
    $signature=(string)($_SERVER['HTTP_X_SIGNATURE']??''); $requestId=(string)($_SERVER['HTTP_X_REQUEST_ID']??'');
    $parts=[]; foreach(explode(',',$signature) as $part){$pair=explode('=',$part,2); if(count($pair)===2)$parts[trim($pair[0])]=trim($pair[1]);}
    $ts=$parts['ts']??''; $v1=$parts['v1']??''; $payload=cobx_payment_decode_json($raw)??[];
    $dataId=(string)($_GET['data.id']??$_GET['data_id']??($payload['data']['id']??'')); $dataId=mb_strtolower($dataId);
    if($ts===''||$v1===''||$requestId===''||$dataId==='') return false;
    $manifest='id:'.$dataId.';request-id:'.$requestId.';ts:'.$ts.';';
    foreach($secrets as $secret) if(hash_equals(hash_hmac('sha256',$manifest,$secret),$v1)) return true;
    return false;
}

/** @return array{paid: bool, ok: bool, message: string, charge_id?: string, installment_id?: string} */
function cobx_payment_webhook_mercadopago(PDO $pdo, string $companyId, string $raw): array
{
    $paymentIds = cobx_payment_mp_extract_ids($raw);
    if ($paymentIds === []) {
        return ['ok' => true, 'paid' => false, 'message' => 'Mercado Pago: webhook sem ID de pagamento.'];
    }

    $tokens = cobx_payment_company_gateway_tokens($pdo, $companyId, 'mercadopago');
    if ($tokens === []) {
        return ['ok' => true, 'paid' => false, 'message' => 'Mercado Pago: access token da empresa nao configurado.'];
    }

    $last = ['ok' => true, 'paid' => false, 'message' => 'Mercado Pago: nenhum pagamento aprovado processado.'];
    foreach ($paymentIds as $paymentId) {
        // Cada conta Mercado Pago possui seu próprio token. Tentamos as contas ativas
        // até localizar o pagamento, sem depender da antiga chave única da empresa.
        foreach ($tokens as $token) {
            foreach (cobx_payment_mp_resolve_payment_ids($token, $paymentId) as $resolvedId) {
                $payment = cobx_payment_mp_fetch($token, $resolvedId);
                if ($payment === null) {
                    $last = ['ok' => true, 'paid' => false, 'message' => 'Mercado Pago: nao foi possivel consultar o pagamento ' . $resolvedId . '.'];
                    continue;
                }
                $status = strtolower((string) ($payment['status'] ?? ''));
                if (!in_array($status, ['approved', 'authorized'], true)) {
                    $last = ['ok' => true, 'paid' => false, 'message' => 'Mercado Pago: pagamento ' . $resolvedId . ' com status ' . $status . '.'];
                    continue;
                }
                $result = cobx_payment_webhook_mark_paid(
                    $pdo,
                    $companyId,
                    'mercadopago',
                    trim((string) ($payment['id'] ?? $resolvedId)),
                    cobx_payment_reference_from_payload($payment),
                    (float) ($payment['transaction_amount'] ?? $payment['amount'] ?? 0),
                    (string) ($payment['date_approved'] ?? $payment['money_release_date'] ?? ''),
                );
                if ($result['paid']) {
                    return $result;
                }
                $last = $result;
            }
        }
    }

    return $last;
}

/** @return array{paid: bool, ok: bool, message: string, charge_id?: string, installment_id?: string} */
function cobx_payment_webhook_asaas(PDO $pdo, string $companyId, string $raw): array
{
    $payload = cobx_payment_decode_json($raw);
    if ($payload === null) {
        return ['ok' => true, 'paid' => false, 'message' => 'Asaas: payload invalido ou vazio.'];
    }

    $event = strtoupper((string) ($payload['event'] ?? ''));
    $payment = isset($payload['payment']) && is_array($payload['payment']) ? $payload['payment'] : $payload;
    $status = strtoupper((string) ($payment['status'] ?? ''));
    $paidEvents = ['PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED'];
    $paidStatuses = ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'];
    if ($event !== '' && !in_array($event, $paidEvents, true) && !in_array($status, $paidStatuses, true)) {
        return ['ok' => true, 'paid' => false, 'message' => 'Asaas: evento sem baixa (' . $event . '/' . $status . ').'];
    }

    return cobx_payment_webhook_mark_paid(
        $pdo,
        $companyId,
        'asaas',
        trim((string) ($payment['id'] ?? '')),
        trim((string) ($payment['externalReference'] ?? $payment['external_reference'] ?? '')),
        (float) ($payment['value'] ?? $payment['netValue'] ?? 0),
        (string) ($payment['paymentDate'] ?? $payment['clientPaymentDate'] ?? $payment['confirmedDate'] ?? ''),
    );
}

/** @return array{paid: bool, ok: bool, message: string, charge_id?: string, installment_id?: string} */
function cobx_payment_webhook_mark_paid(
    PDO $pdo,
    string $companyId,
    string $gateway,
    string $externalId,
    string $reference,
    float $amount,
    string $paidAtRaw
): array {
    $targets = cobx_payment_find_targets($pdo, $companyId, $gateway, $externalId, $reference, $amount);
    if ($targets === []) {
        return ['ok' => true, 'paid' => false, 'message' => 'Nenhuma parcela pendente encontrada para ID/referencia recebidos.'];
    }

    $paidAt = cobx_payment_paid_at_sql($paidAtRaw);
    try {
        $pdo->beginTransaction();
        foreach ($targets as $target) {
            $pdo->prepare(
                "UPDATE installments SET status = 'paid', paid_at = ?, external_id = COALESCE(NULLIF(external_id, ''), ?), updated_at = NOW(3)
                 WHERE id = ? AND company_id = ? AND status IN ('pending','overdue')"
            )->execute([$paidAt, $externalId !== '' ? $externalId : null, $target['installment_id'], $companyId]);
        }

        $chargeIds = array_values(array_unique(array_map(static fn (array $r): string => $r['charge_id'], $targets)));
        foreach ($chargeIds as $chargeId) {
            cobx_charge_refresh_status($pdo, $chargeId, $companyId);
            cobx_charge_audit($pdo,$companyId,$chargeId,'payment_webhook',null,['provider'=>$gateway,'external_id'=>$externalId],['installments'=>array_column($targets,'installment_id')]);
            if ($externalId !== '') {
                $pdo->prepare("UPDATE charges SET external_id = COALESCE(NULLIF(external_id, ''), ?), updated_at = NOW(3) WHERE id = ? AND company_id = ?")
                    ->execute([$externalId, $chargeId, $companyId]);
            }
        }
        $pdo->commit();
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => true, 'paid' => false, 'message' => 'Falha ao atualizar parcela a partir do webhook.'];
    }

    return [
        'ok' => true,
        'paid' => true,
        'message' => count($targets) === 1 ? 'Parcela baixada automaticamente.' : count($targets) . ' parcelas baixadas automaticamente.',
        'charge_id' => $targets[0]['charge_id'],
        'installment_id' => $targets[0]['installment_id'],
    ];
}

/**
 * @return list<array{installment_id: string, charge_id: string}>
 */
function cobx_payment_find_targets(PDO $pdo, string $companyId, string $gateway, string $externalId, string $reference, float $amount): array
{
    $ids = cobx_payment_reference_ids($reference);
    foreach ($ids['installment_ids'] as $installmentId) {
        $row = cobx_payment_pending_installment_by_id($pdo, $companyId, $gateway, $installmentId);
        if ($row !== null) {
            return [$row];
        }
    }
    foreach ($ids['charge_ids'] as $chargeId) {
        $rows = cobx_payment_pending_installments_by_charge($pdo, $companyId, $gateway, $chargeId, $amount);
        if ($rows !== []) {
            return $rows;
        }
    }

    if ($externalId !== '') {
        $row = cobx_payment_pending_installment_by_external_id($pdo, $companyId, $gateway, $externalId);
        if ($row !== null) {
            return [$row];
        }
        $chargeId = cobx_payment_charge_id_by_external_id($pdo, $companyId, $gateway, $externalId);
        if ($chargeId !== null) {
            return cobx_payment_pending_installments_by_charge($pdo, $companyId, $gateway, $chargeId, $amount);
        }
    }

    return [];
}

/** @return array{installment_ids: list<string>, charge_ids: list<string>} */
function cobx_payment_reference_ids(string $reference): array
{
    $out = ['installment_ids' => [], 'charge_ids' => []];
    if ($reference === '') {
        return $out;
    }
    $uuid = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
    if (preg_match_all('/(?:installment|parcela)[_:|-](' . $uuid . ')/i', $reference, $m)) {
        $out['installment_ids'] = array_values(array_unique($m[1]));
    }
    if (preg_match_all('/(?:charge|cobranca|cobranca)[_:|-](' . $uuid . ')/i', $reference, $m)) {
        $out['charge_ids'] = array_values(array_unique($m[1]));
    }
    if (preg_match_all('/' . $uuid . '/i', $reference, $m)) {
        foreach (array_values(array_unique($m[0])) as $id) {
            $out['installment_ids'][] = $id;
            $out['charge_ids'][] = $id;
        }
    }
    $out['installment_ids'] = array_values(array_unique($out['installment_ids']));
    $out['charge_ids'] = array_values(array_unique($out['charge_ids']));

    return $out;
}

/** @return array{installment_id: string, charge_id: string}|null */
function cobx_payment_pending_installment_by_id(PDO $pdo, string $companyId, string $gateway, string $installmentId): ?array
{
    $st = $pdo->prepare(
        "SELECT i.id AS installment_id, i.charge_id
         FROM installments i INNER JOIN charges ch ON ch.id = i.charge_id
         WHERE i.id = ? AND i.company_id = ? AND ch.payment_gateway = ? AND i.status IN ('pending','overdue') LIMIT 1"
    );
    $st->execute([$installmentId, $companyId, $gateway]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ? ['installment_id' => (string) $row['installment_id'], 'charge_id' => (string) $row['charge_id']] : null;
}

/** @return array{installment_id: string, charge_id: string}|null */
function cobx_payment_pending_installment_by_external_id(PDO $pdo, string $companyId, string $gateway, string $externalId): ?array
{
    $st = $pdo->prepare(
        "SELECT i.id AS installment_id, i.charge_id
         FROM installments i INNER JOIN charges ch ON ch.id = i.charge_id
         WHERE i.company_id = ? AND ch.payment_gateway = ? AND i.external_id = ? AND i.status IN ('pending','overdue') LIMIT 1"
    );
    $st->execute([$companyId, $gateway, $externalId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ? ['installment_id' => (string) $row['installment_id'], 'charge_id' => (string) $row['charge_id']] : null;
}

function cobx_payment_charge_id_by_external_id(PDO $pdo, string $companyId, string $gateway, string $externalId): ?string
{
    $st = $pdo->prepare('SELECT id FROM charges WHERE company_id = ? AND payment_gateway = ? AND external_id = ? LIMIT 1');
    $st->execute([$companyId, $gateway, $externalId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ? (string) $row['id'] : null;
}

/** @return list<array{installment_id: string, charge_id: string}> */
function cobx_payment_pending_installments_by_charge(PDO $pdo, string $companyId, string $gateway, string $chargeId, float $amount): array
{
    $st = $pdo->prepare(
        "SELECT i.id AS installment_id, i.charge_id, i.amount
         FROM installments i INNER JOIN charges ch ON ch.id = i.charge_id
         WHERE i.charge_id = ? AND i.company_id = ? AND ch.payment_gateway = ? AND i.status IN ('pending','overdue')
         ORDER BY i.installment_number ASC"
    );
    $st->execute([$chargeId, $companyId, $gateway]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return [];
    }

    $pendingTotal = array_sum(array_map(static fn (array $r): float => (float) $r['amount'], $rows));
    if ($amount > 0 && $amount + 0.01 >= $pendingTotal) {
        return array_map(static fn (array $r): array => ['installment_id' => (string) $r['installment_id'], 'charge_id' => (string) $r['charge_id']], $rows);
    }
    if ($amount > 0) {
        $matches = array_values(array_filter($rows, static fn (array $r): bool => abs(((float) $r['amount']) - $amount) < 0.01));
        if (count($matches) === 1) {
            $r = $matches[0];
            return [['installment_id' => (string) $r['installment_id'], 'charge_id' => (string) $r['charge_id']]];
        }
    }

    $r = $rows[0];
    return [['installment_id' => (string) $r['installment_id'], 'charge_id' => (string) $r['charge_id']]];
}

/** @return list<string> */
function cobx_payment_company_gateway_tokens(PDO $pdo, string $companyId, string $gateway): array
{
    $tokens = [];
    $st = $pdo->prepare('SELECT api_key FROM payment_accounts WHERE company_id = ? AND provider = ? AND is_active = 1 ORDER BY is_default DESC, created_at ASC');
    try {
        $st->execute([$companyId, $gateway]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $token = trim((string) (cobx_secret_decrypt($row['api_key'] ?? null) ?? ''));
            if ($token !== '') $tokens[] = $token;
        }
    } catch (Throwable) {
        // Instalações antigas ainda usam a configuração única abaixo.
    }

    $st = $pdo->prepare('SELECT payment_gateway, gateway_api_key FROM companies WHERE id = ? LIMIT 1');
    $st->execute([$companyId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row && (string) ($row['payment_gateway'] ?? '') === $gateway) {
        $legacy = trim((string) (cobx_secret_decrypt($row['gateway_api_key'] ?? null) ?? ''));
        if ($legacy !== '') $tokens[] = $legacy;
    }
    return array_values(array_unique($tokens));
}

/** @param array{paid: bool, ok: bool, message: string, charge_id?: string, installment_id?: string} $result */
function cobx_payment_webhook_audit(PDO $pdo, string $companyId, string $gateway, string $raw, array $result): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS payment_webhook_events (
            id CHAR(36) NOT NULL PRIMARY KEY, company_id CHAR(36) NOT NULL, provider VARCHAR(32) NOT NULL,
            event_type VARCHAR(120) NULL, external_id VARCHAR(255) NULL, event_key CHAR(64) NULL, payload LONGTEXT NOT NULL,
            processed TINYINT(1) NOT NULL DEFAULT 0, result_message TEXT NULL,
            created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            KEY idx_payment_webhook_events_company_created (company_id, created_at),
            KEY idx_payment_webhook_events_external (provider, external_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $payload = cobx_payment_decode_json($raw) ?? [];
        $payment = isset($payload['payment']) && is_array($payload['payment']) ? $payload['payment'] : $payload;
        $event = trim((string) ($payload['event'] ?? $payload['type'] ?? $payload['action'] ?? ''));
        $externalId = trim((string) ($payment['id'] ?? ''));
        $pdo->prepare('INSERT INTO payment_webhook_events (id, company_id, provider, event_type, external_id, event_key, payload, processed, result_message) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([uuid_v4(), $companyId, $gateway, $event !== '' ? $event : null, $externalId !== '' ? $externalId : null, $result['event_key'] ?? null, $raw, $result['paid'] ? 1 : 0, $result['message']]);
    } catch (Throwable) {
        // Auditoria não pode impedir a confirmação do pagamento.
    }
}

/** @return array<string, mixed>|null */
function cobx_payment_decode_json(string $raw): ?array
{
    if ($raw === '') {
        return null;
    }
    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }

    return is_array($data) ? $data : null;
}

function cobx_payment_reference_from_payload(array $payload): string
{
    $meta = $payload['metadata'] ?? null;
    if (is_array($meta)) {
        foreach (['installment_id', 'charge_id', 'external_reference'] as $key) {
            $value = trim((string) ($meta[$key] ?? ''));
            if ($value !== '') {
                return $key . ':' . $value;
            }
        }
    }

    return trim((string) ($payload['external_reference'] ?? ''));
}

function cobx_payment_paid_at_sql(string $raw): string
{
    if (trim($raw) !== '') {
        try {
            return (new DateTimeImmutable($raw))->format('Y-m-d H:i:s.v');
        } catch (Throwable) {
            // Uses current timestamp below.
        }
    }

    return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s.v');
}

/** @return list<string> */
function cobx_payment_mp_extract_ids(string $raw): array
{
    $ids = [];
    $topic = isset($_GET['topic']) ? trim((string) $_GET['topic']) : '';
    $id = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
    if ($id !== '' && ($topic === 'payment' || $topic === 'merchant_order' || $topic === '')) {
        $ids[] = $id;
    }

    $payload = cobx_payment_decode_json($raw);
    if ($payload !== null) {
        $type = strtolower((string) ($payload['type'] ?? $payload['action'] ?? ''));
        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];
        $dataId = trim((string) ($data['id'] ?? $payload['id'] ?? ''));
        if ($dataId !== '' && (str_contains($type, 'payment') || str_contains($type, 'merchant_order') || $type === '')) {
            $ids[] = $dataId;
        }
    }

    return array_values(array_unique(array_filter($ids, static fn (string $v): bool => $v !== '')));
}

/** @return list<string> */
function cobx_payment_mp_resolve_payment_ids(string $accessToken, string $id): array
{
    $payments = cobx_payment_mp_fetch_merchant_order_payment_ids($accessToken, $id);

    return $payments !== [] ? $payments : [$id];
}

/** @return array<string, mixed>|null */
function cobx_payment_mp_fetch(string $accessToken, string $paymentId): ?array
{
    $raw = cobx_payment_http_get($accessToken, 'https://api.mercadopago.com/v1/payments/' . rawurlencode($paymentId));

    return $raw !== null ? cobx_payment_decode_json($raw) : null;
}

/** @return list<string> */
function cobx_payment_mp_fetch_merchant_order_payment_ids(string $accessToken, string $merchantOrderId): array
{
    $raw = cobx_payment_http_get($accessToken, 'https://api.mercadopago.com/merchant_orders/' . rawurlencode($merchantOrderId));
    $data = $raw !== null ? cobx_payment_decode_json($raw) : null;
    if ($data === null || empty($data['payments']) || !is_array($data['payments'])) {
        return [];
    }

    $ids = [];
    foreach ($data['payments'] as $payment) {
        if (is_array($payment) && trim((string) ($payment['id'] ?? '')) !== '') {
            $ids[] = trim((string) $payment['id']);
        }
    }

    return array_values(array_unique($ids));
}

function cobx_payment_http_get(string $token, string $url): ?string
{
    if (!function_exists('curl_init')) {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Authorization: Bearer {$token}\r\nAccept: application/json\r\n",
                'timeout' => 25,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);

        return $raw === false ? null : $raw;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 25,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (!is_string($raw) || $raw === '' || $status < 200 || $status >= 300) {
        return null;
    }

    return $raw;
}
