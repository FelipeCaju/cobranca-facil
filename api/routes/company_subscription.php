<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/subscriptions.php';

/**
 * @param list<string> $seg segmentos apos "company/subscription/"
 */
function company_subscription_dispatch(PDO $pdo, string $method, string $companyId, array $seg): void
{
    $action = $seg[0] ?? null;

    if ($method === 'GET' && $action === null) {
        company_subscription_status($pdo, $companyId);
    }

    if ($method === 'POST' && $action === 'checkout') {
        company_subscription_checkout($pdo, $companyId);
    }

    json_response(405, ['error' => 'Metodo nao permitido']);
}

function company_subscription_status(PDO $pdo, string $companyId): void
{
    $st = $pdo->prepare(
        'SELECT c.id, c.name, c.email, c.plan_id, c.plan_renews_at, c.created_at, c.is_active,
                p.name AS plan_name, p.price AS plan_price, p.charges_limit, p.users_limit' . cobx_plan_duration_select($pdo, 'p') . '
         FROM companies c
         LEFT JOIN plans p ON p.id = c.plan_id
         WHERE c.id = ?
         LIMIT 1'
    );
    $st->execute([$companyId]);
    $company = $st->fetch(PDO::FETCH_ASSOC);
    if (!$company) {
        json_response(404, ['error' => 'Empresa nao encontrada']);
    }

    $plansSt = $pdo->query(
        'SELECT id, name, price, charges_limit, users_limit' . cobx_plan_duration_select($pdo, '') . '
         FROM plans
         WHERE is_active = 1
         ORDER BY price ASC, name ASC'
    );
    $plans = $plansSt ? $plansSt->fetchAll(PDO::FETCH_ASSOC) : [];

    $state = company_subscription_state_from_row($company);
    $supportPhone = company_subscription_support_phone($pdo);

    json_response(200, [
        'company' => [
            'id' => (string) $company['id'],
            'name' => (string) $company['name'],
            'email' => (string) ($company['email'] ?? ''),
            'is_active' => (bool) $company['is_active'],
            'plan_id' => $company['plan_id'] !== null ? (string) $company['plan_id'] : null,
            'plan_name' => (string) ($company['plan_name'] ?? ''),
            'plan_price' => round((float) ($company['plan_price'] ?? 0), 2),
            'plan_renews_at' => $state['renews_at'],
            'charges_limit' => (int) ($company['charges_limit'] ?? 0),
            'users_limit' => (int) ($company['users_limit'] ?? 0),
            'duration_months' => cobx_plan_duration_months($company),
        ],
        'subscription' => [
            'status' => $state['status'],
            'days_remaining' => $state['days_remaining'],
            'is_blocked' => $state['is_blocked'],
            'message' => $state['message'],
        ],
        'plans' => array_map(static function (array $p): array {
            $price = round((float) ($p['price'] ?? 0), 2);
            return [
                'id' => (string) $p['id'],
                'name' => (string) $p['name'],
                'price' => $price,
                'price_label' => 'R$ ' . number_format($price, 2, ',', '.'),
                'charges_limit' => (int) ($p['charges_limit'] ?? 0),
                'users_limit' => (int) ($p['users_limit'] ?? 0),
                'duration_months' => cobx_plan_duration_months($p),
            ];
        }, $plans),
        'checkout_gateway' => 'mercadopago',
        'support' => [
            'whatsapp_phone' => $supportPhone,
        ],
    ]);
}

/**
 * @param array<string, mixed> $company
 * @return array{status: string, days_remaining: ?int, renews_at: ?string, is_blocked: bool, message: string}
 */
function company_subscription_state_from_row(array $company): array
{
    $renews = $company['plan_renews_at'] ?? null;
    $hasPlan = !empty($company['plan_id']);
    $isActive = (int) ($company['is_active'] ?? 1) === 1;

    if (!$hasPlan) {
        return [
            'status' => 'no_plan',
            'days_remaining' => null,
            'renews_at' => null,
            'is_blocked' => true,
            'message' => 'Escolha um plano para liberar o acesso ao sistema.',
        ];
    }

    if (!$isActive) {
        return [
            'status' => 'inactive',
            'days_remaining' => null,
            'renews_at' => $renews !== null && $renews !== '' ? (string) $renews : null,
            'is_blocked' => true,
            'message' => 'A conta da empresa esta inativa. Fale com o suporte para regularizar.',
        ];
    }

    if (($renews === null || $renews === '') && !empty($company['plan_id'])) {
        $created = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', (string) ($company['created_at'] ?? ''))
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr((string) ($company['created_at'] ?? ''), 0, 19));
        if ($created instanceof DateTimeImmutable) {
            $renews = $created->modify('+3 days')->format('Y-m-d');
        }
    }

    $daysRemaining = null;
    $status = 'active';
    $isBlocked = false;
    $message = 'Sua assinatura esta ativa.';
    if ($renews !== null && $renews !== '') {
        $today = new DateTimeImmutable('today');
        $renewDate = DateTimeImmutable::createFromFormat('Y-m-d', (string) $renews);
        if ($renewDate instanceof DateTimeImmutable) {
            $diffDays = (int) $today->diff($renewDate)->format('%r%a');
            if ($renewDate < $today) {
                $daysRemaining = 0;
                $status = 'overdue';
                $isBlocked = true;
                $message = 'Sua assinatura venceu. Renove o plano para liberar o acesso ao sistema.';
            } elseif ($diffDays === 0) {
                $daysRemaining = 0;
                $status = 'renew_today';
                $message = 'Sua assinatura vence hoje. Renove para evitar bloqueio.';
            } elseif ($diffDays <= 7) {
                $daysRemaining = $diffDays;
                $status = 'renew_soon';
                $message = 'Sua assinatura esta perto da renovacao.';
            } else {
                $daysRemaining = $diffDays;
            }
        }
    }

    return [
        'status' => $status,
        'days_remaining' => $daysRemaining,
        'renews_at' => $renews !== null && $renews !== '' ? (string) $renews : null,
        'is_blocked' => $isBlocked,
        'message' => $message,
    ];
}

function company_subscription_is_access_blocked(PDO $pdo, string $companyId): bool
{
    $st = $pdo->prepare('SELECT id, plan_id, plan_renews_at, created_at, is_active FROM companies WHERE id = ? LIMIT 1');
    $st->execute([$companyId]);
    $company = $st->fetch(PDO::FETCH_ASSOC);
    if (!$company) {
        return true;
    }

    $state = company_subscription_state_from_row($company);
    return (bool) $state['is_blocked'];
}

function company_subscription_support_phone(PDO $pdo): string
{
    $st = $pdo->query('SELECT notification_phone FROM master_settings WHERE id = 1 LIMIT 1');
    $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
    $phone = $row ? preg_replace('/\D+/', '', (string) ($row['notification_phone'] ?? '')) : '';

    return is_string($phone) ? $phone : '';
}

function company_subscription_checkout(PDO $pdo, string $companyId): void
{
    $in = json_input();
    $planId = trim((string) ($in['plan_id'] ?? ''));
    if (!cobx_subscription_is_uuid($planId)) {
        json_response(422, ['error' => 'Plano invalido']);
    }

    $token = company_subscription_master_mp_token($pdo);
    if ($token === null) {
        json_response(503, ['error' => 'Mercado Pago master nao configurado. Configure as credenciais em Config. master.']);
    }

    $st = $pdo->prepare(
        'SELECT c.id, c.name, c.email, u.email AS owner_email
         FROM companies c
         LEFT JOIN users u ON u.id = c.owner_id
         WHERE c.id = ? AND c.is_active = 1
         LIMIT 1'
    );
    $st->execute([$companyId]);
    $company = $st->fetch(PDO::FETCH_ASSOC);
    if (!$company) {
        json_response(404, ['error' => 'Empresa nao encontrada ou inativa']);
    }

    $st = $pdo->prepare(
        'SELECT id, name, price, charges_limit, users_limit' . cobx_plan_duration_select($pdo, '') . '
         FROM plans
         WHERE id = ? AND is_active = 1
         LIMIT 1'
    );
    $st->execute([$planId]);
    $plan = $st->fetch(PDO::FETCH_ASSOC);
    if (!$plan) {
        json_response(404, ['error' => 'Plano nao encontrado ou inativo']);
    }

    $price = round((float) ($plan['price'] ?? 0), 2);
    if ($price <= 0) {
        json_response(422, ['error' => 'Plano sem valor de assinatura configurado']);
    }

    $preference = company_subscription_mp_create_preference($token, $company, $plan, $price);
    if ($preference === null) {
        json_response(502, ['error' => 'Nao foi possivel gerar o link de pagamento no Mercado Pago']);
    }

    json_response(200, [
        'checkout_url' => (string) ($preference['init_point'] ?? $preference['sandbox_init_point'] ?? ''),
        'preference_id' => (string) ($preference['id'] ?? ''),
        'external_reference' => 'cobx:' . $companyId . ':' . $planId,
    ]);
}

function company_subscription_master_mp_token(PDO $pdo): ?string
{
    $st = $pdo->query('SELECT mercadopago_access_token FROM master_settings WHERE id = 1 LIMIT 1');
    $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
    $token = $row ? trim((string) (cobx_secret_decrypt($row['mercadopago_access_token'] ?? null) ?? '')) : '';

    return $token !== '' ? $token : null;
}

/**
 * @param array<string, mixed> $company
 * @param array<string, mixed> $plan
 * @return array<string, mixed>|null
 */
function company_subscription_mp_create_preference(string $accessToken, array $company, array $plan, float $price): ?array
{
    $companyId = (string) $company['id'];
    $planId = (string) $plan['id'];
    $appUrl = rtrim((string) env('APP_URL', ''), '/');
    $dashboardUrl = $appUrl !== '' ? $appUrl . '/dashboard/subscription' : null;
    $notificationUrl = $appUrl !== '' ? $appUrl . '/api/webhooks/plans/mercadopago' : null;

    $months = cobx_plan_duration_months($plan);
    $payload = [
        'items' => [[
            'id' => $planId,
            'title' => 'Assinatura Cobx - ' . (string) $plan['name'],
            'description' => 'Assinatura de ' . $months . ' ' . ($months === 1 ? 'mes' : 'meses') . ' da empresa ' . (string) $company['name'],
            'quantity' => 1,
            'currency_id' => 'BRL',
            'unit_price' => $price,
        ]],
        'external_reference' => 'cobx:' . $companyId . ':' . $planId,
        'metadata' => [
            'company_id' => $companyId,
            'plan_id' => $planId,
        ],
        'payer' => [
            'email' => (string) (($company['email'] ?? '') ?: ($company['owner_email'] ?? '')),
            'name' => (string) ($company['name'] ?? ''),
        ],
    ];

    if ($dashboardUrl !== null) {
        $payload['auto_return'] = 'approved';
        $payload['back_urls'] = [
            'success' => $dashboardUrl,
            'pending' => $dashboardUrl,
            'failure' => $dashboardUrl,
        ];
    }
    if ($notificationUrl !== null) {
        $payload['notification_url'] = $notificationUrl;
    }

    $raw = company_subscription_http_json(
        'https://api.mercadopago.com/checkout/preferences',
        $accessToken,
        $payload
    );
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

/**
 * @param array<string, mixed> $payload
 */
function company_subscription_http_json(string $url, string $accessToken, array $payload): ?string
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (!function_exists('curl_init')) {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Authorization: Bearer {$accessToken}\r\nContent-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $json,
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
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
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

function cobx_subscription_is_uuid(string $id): bool
{
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id);
}
