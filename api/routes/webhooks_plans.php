<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/admin_notifications.php';
require_once __DIR__ . '/../lib/subscriptions.php';

/**
 * Webhooks de assinatura dos planos Cobx (credenciais master / Mercado Pago da plataforma).
 *
 * @param list<string> $seg segmentos após "webhooks/plans/"
 */
function handle_webhooks_plans(PDO $pdo, string $method, array $seg): void
{
    $gateway = $seg[0] ?? '';
    if ($gateway !== 'mercadopago') {
        json_response(404, ['error' => 'Gateway não encontrado']);
    }
    if ($method !== 'POST') {
        json_response(405, ['error' => 'Método não permitido']);
    }

    $raw = file_get_contents('php://input');
    if ($raw === false) {
        $raw = '';
    }

    $token = cobx_master_mercadopago_token($pdo);
    if ($token === null) {
        json_response(503, ['error' => 'Mercado Pago master não configurado']);
    }

    $paymentIds = cobx_mp_extract_payment_ids($raw);
    if ($paymentIds === []) {
        error_log('[cobx webhook plans] mercadopago: nenhum ID de pagamento no payload');
        json_response(200, ['ok' => true, 'message' => 'Recebido (sem ID de pagamento para processar).']);
    }

    $activated = [];
    $skipped = [];
    foreach ($paymentIds as $paymentId) {
        foreach (cobx_mp_resolve_payment_ids($token, $paymentId) as $resolvedPaymentId) {
            $result = cobx_mp_activate_plan_from_payment($pdo, $token, $resolvedPaymentId);
            if ($result['activated']) {
                $activated[] = $result;
            } else {
                $skipped[] = $result;
            }
        }
    }

    if ($activated !== []) {
        $first = $activated[0];
        admin_notify_superadmin(
            $pdo,
            'payment-webhook',
            'Assinatura de plano confirmada (Mercado Pago)',
            [
                'Empresa: ' . ($first['company_name'] ?? $first['company_id'] ?? ''),
                'Plano: ' . ($first['plan_name'] ?? $first['plan_id'] ?? ''),
                'Pagamento MP: ' . ($first['payment_id'] ?? ''),
                'Renova em: ' . ($first['plan_renews_at'] ?? ''),
            ]
        );
    }

    json_response(200, [
        'ok' => true,
        'activated' => count($activated),
        'skipped' => count($skipped),
        'details' => ['activated' => $activated, 'skipped' => $skipped],
    ]);
}

/**
 * @return list<string>
 */
function cobx_mp_resolve_payment_ids(string $accessToken, string $id): array
{
    $payments = cobx_mp_fetch_merchant_order_payment_ids($accessToken, $id);
    if ($payments !== []) {
        return $payments;
    }

    return [$id];
}

function cobx_master_mercadopago_token(PDO $pdo): ?string
{
    $st = $pdo->query('SELECT mercadopago_access_token FROM master_settings WHERE id = 1 LIMIT 1');
    $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
    $tok = $row ? trim((string) (cobx_secret_decrypt($row['mercadopago_access_token'] ?? null) ?? '')) : '';

    return $tok !== '' ? $tok : null;
}

/**
 * @return list<string>
 */
function cobx_mp_extract_payment_ids(string $raw): array
{
    $ids = [];

    $topic = isset($_GET['topic']) ? trim((string) $_GET['topic']) : '';
    $id = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
    if ($id !== '' && ($topic === 'payment' || $topic === 'merchant_order' || $topic === '')) {
        $ids[] = $id;
    }

    if ($raw !== '') {
        try {
            $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $json = null;
        }
        if (is_array($json)) {
            $type = strtolower((string) ($json['type'] ?? $json['action'] ?? ''));
            $dataId = '';
            if (isset($json['data']) && is_array($json['data'])) {
                $dataId = trim((string) ($json['data']['id'] ?? ''));
            }
            if ($dataId === '' && isset($json['id'])) {
                $dataId = trim((string) $json['id']);
            }
            if ($dataId !== '' && (str_contains($type, 'payment') || $type === '')) {
                $ids[] = $dataId;
            }
        }
    }

    $unique = [];
    foreach ($ids as $pid) {
        if ($pid !== '' && !in_array($pid, $unique, true)) {
            $unique[] = $pid;
        }
    }

    return $unique;
}

/**
 * @return array{activated: bool, payment_id?: string, company_id?: string, plan_id?: string, company_name?: string, plan_name?: string, plan_renews_at?: string, reason?: string}
 */
function cobx_mp_activate_plan_from_payment(PDO $pdo, string $accessToken, string $paymentId): array
{
    $payment = cobx_mp_fetch_payment($accessToken, $paymentId);
    if ($payment === null) {
        return ['activated' => false, 'payment_id' => $paymentId, 'reason' => 'Não foi possível obter o pagamento no Mercado Pago'];
    }

    $status = strtolower((string) ($payment['status'] ?? ''));
    if (!in_array($status, ['approved', 'authorized'], true)) {
        return ['activated' => false, 'payment_id' => $paymentId, 'reason' => 'Pagamento com status: ' . $status];
    }

    $externalId = trim((string) ($payment['id'] ?? $paymentId));
    if (cobx_subscription_payment_is_processed($pdo, 'mercadopago', $externalId)) {
        return ['activated' => false, 'payment_id' => $paymentId, 'reason' => 'Pagamento ja processado anteriormente'];
    }

    $refs = cobx_mp_parse_plan_reference($payment);
    if ($refs === null) {
        return [
            'activated' => false,
            'payment_id' => $paymentId,
            'reason' => 'Referência inválida (use external_reference cobx:{company_id}:{plan_id} ou metadata company_id + plan_id)',
        ];
    }

    [$companyId, $planId] = $refs;

    $chk = $pdo->prepare(
        'SELECT c.id, c.name, c.plan_renews_at, p.name AS plan_name, p.charges_limit' . cobx_plan_duration_select($pdo, 'p') . '
         FROM companies c
         INNER JOIN plans p ON p.id = ?
         WHERE c.id = ?
         LIMIT 1'
    );
    $chk->execute([$planId, $companyId]);
    $row = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['activated' => false, 'payment_id' => $paymentId, 'company_id' => $companyId, 'plan_id' => $planId, 'reason' => 'Empresa ou plano não encontrado'];
    }

    $months = cobx_plan_duration_months($row);
    $renews = cobx_subscription_next_renewal_date($row['plan_renews_at'] ?? null, $months);

    try {
        $pdo->beginTransaction();
        if (cobx_subscription_payment_is_processed($pdo, 'mercadopago', $externalId)) {
            $pdo->rollBack();
            return ['activated' => false, 'payment_id' => $paymentId, 'reason' => 'Pagamento ja processado anteriormente'];
        }

        $pdo->prepare(
            'UPDATE companies SET plan_id = ?, plan_renews_at = ?, is_active = 1, updated_at = NOW(3) WHERE id = ?'
        )->execute([$planId, $renews, $companyId]);
        cobx_subscription_sync_current($pdo, $companyId, $planId, $renews, 'active');
        cobx_subscription_record_payment($pdo, $companyId, $planId, 'mercadopago', $payment, $status);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['activated' => false, 'payment_id' => $paymentId, 'company_id' => $companyId, 'plan_id' => $planId, 'reason' => 'Falha ao atualizar assinatura'];
    }

    return [
        'activated' => true,
        'payment_id' => $paymentId,
        'company_id' => $companyId,
        'plan_id' => $planId,
        'company_name' => (string) ($row['name'] ?? ''),
        'plan_name' => (string) ($row['plan_name'] ?? ''),
        'plan_renews_at' => $renews,
    ];
}

function cobx_subscription_next_renewal_date(mixed $currentRenewal, int $months): string
{
    $today = new DateTimeImmutable('today');
    $base = $today;
    if ($currentRenewal !== null && $currentRenewal !== '') {
        try {
            $current = new DateTimeImmutable((string) $currentRenewal);
            if ($current > $today) {
                $base = $current;
            }
        } catch (Throwable) {
            $base = $today;
        }
    }

    return $base->modify('+' . max(1, $months) . ' months')->format('Y-m-d');
}

/**
 * @return array<string, mixed>|null
 */
function cobx_mp_fetch_payment(string $accessToken, string $paymentId): ?array
{
    $url = 'https://api.mercadopago.com/v1/payments/' . rawurlencode($paymentId);
    $raw = cobx_mp_http_get($accessToken, $url);
    if ($raw === null) {
        return null;
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }

    return is_array($decoded) ? $decoded : null;
}

function cobx_mp_http_get(string $accessToken, string $url): ?string
{
    if (!function_exists('curl_init')) {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Authorization: Bearer {$accessToken}\r\nAccept: application/json\r\n",
                'timeout' => 25,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            return null;
        }
    } else {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 25,
        ]);
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($status < 200 || $status >= 300) {
            return null;
        }
    }

    return $raw !== '' ? $raw : null;
}

/**
 * @return list<string>
 */
function cobx_mp_fetch_merchant_order_payment_ids(string $accessToken, string $merchantOrderId): array
{
    $url = 'https://api.mercadopago.com/merchant_orders/' . rawurlencode($merchantOrderId);
    $raw = cobx_mp_http_get($accessToken, $url);
    if ($raw === null) {
        return [];
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return [];
    }
    if (!is_array($decoded) || empty($decoded['payments']) || !is_array($decoded['payments'])) {
        return [];
    }

    $ids = [];
    foreach ($decoded['payments'] as $payment) {
        if (!is_array($payment)) {
            continue;
        }
        $id = trim((string) ($payment['id'] ?? ''));
        if ($id !== '' && !in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }

    return $ids;
}

/**
 * @param array<string, mixed> $payment
 * @return array{0: string, 1: string}|null [company_id, plan_id]
 */
function cobx_mp_parse_plan_reference(array $payment): ?array
{
    $meta = $payment['metadata'] ?? null;
    if (is_array($meta)) {
        $cid = trim((string) ($meta['company_id'] ?? ''));
        $pid = trim((string) ($meta['plan_id'] ?? ''));
        if (cobx_is_uuid($cid) && cobx_is_uuid($pid)) {
            return [$cid, $pid];
        }
    }

    $ext = trim((string) ($payment['external_reference'] ?? ''));
    if ($ext === '') {
        return null;
    }

    if (preg_match('/^cobx:([0-9a-f-]{36}):([0-9a-f-]{36})$/i', $ext, $m)) {
        return [$m[1], $m[2]];
    }

    if (preg_match('/^([0-9a-f-]{36})\|([0-9a-f-]{36})$/i', $ext, $m)) {
        return [$m[1], $m[2]];
    }

    return null;
}

function cobx_is_uuid(string $id): bool
{
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id);
}
