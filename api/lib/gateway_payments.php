<?php

declare(strict_types=1);

require_once __DIR__ . '/platform_urls.php';
require_once __DIR__ . '/payment_connector.php';

/**
 * @return array{ok: bool, created: int, failed: int, details: list<string>}
 */
function cobx_gateway_generate_charge_payments(PDO $pdo, string $companyId, string $chargeId): array
{
    cobx_gateway_ensure_schema($pdo);

    $st = $pdo->prepare(
        'SELECT ch.id, ch.description, ch.payment_gateway, ch.payment_account_id, ch.payment_method, ch.company_id,
                cl.id AS client_id, cl.name AS client_name, cl.email AS client_email, cl.phone AS client_phone,
                cl.document AS client_document, cl.external_id AS client_external_id
         FROM charges ch
         INNER JOIN clients cl ON cl.id = ch.client_id
         WHERE ch.id = ? AND ch.company_id = ? LIMIT 1'
    );
    $st->execute([$chargeId, $companyId]);
    $charge = $st->fetch(PDO::FETCH_ASSOC);
    if (!$charge) {
        return ['ok' => false, 'created' => 0, 'failed' => 1, 'details' => ['Cobranca nao encontrada.']];
    }

    $accountId = trim((string) ($charge['payment_account_id'] ?? ''));
    if ($accountId === '') {
        $accountId = cobx_gateway_default_account_id($pdo, $companyId) ?? '';
    }
    $account = $accountId !== '' ? cobx_gateway_account($pdo, $companyId, $accountId) : null;
    if ($account === null || empty($account['api_key'])) {
        return ['ok' => false, 'created' => 0, 'failed' => 1, 'details' => ['Selecione uma conta de recebimento ativa com credenciais configuradas.']];
    }
    $gateway = (string) $account['provider'];
    $method = (string) ($charge['payment_method'] ?? 'pix');

    $st = $pdo->prepare(
        "SELECT id, installment_number, amount, due_date
         FROM installments
         WHERE charge_id = ? AND company_id = ? AND status IN ('pending','overdue')
           AND (
             external_id IS NULL OR external_id = ''
             OR (? = 'mercadopago' AND IFNULL(pix_qrcode, '') = '' AND IFNULL(pix_copy_paste, '') = '')
           )
         ORDER BY installment_number ASC"
    );
    $st->execute([$chargeId, $companyId, $gateway]);
    $installments = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$installments) {
        return ['ok' => true, 'created' => 0, 'failed' => 0, 'details' => ['Parcelas ja possuem ID externo ou nao estao pendentes.']];
    }

    $created = 0;
    $failed = 0;
    $details = [];
    foreach ($installments as $installment) {
        $result = cobx_gateway_create_payment($pdo, $account, $charge, $installment, $method);
        if ($result['ok']) {
            $pdo->prepare(
                'UPDATE installments SET external_id = ?, payment_url = ?, boleto_digitable_line = ?, boleto_pdf_url = ?, pix_qrcode = ?, pix_copy_paste = ?, updated_at = NOW(3)
                 WHERE id = ? AND company_id = ?'
            )->execute([
                $result['external_id'] ?? null,
                $result['payment_url'] ?? null,
                $result['boleto_digitable_line'] ?? null,
                $result['boleto_pdf_url'] ?? null,
                $result['pix_qrcode'] ?? null,
                $result['pix_copy_paste'] ?? null,
                (string) $installment['id'],
                $companyId,
            ]);
            $created++;
        } else {
            $failed++;
        }
        $details[] = $result['detail'];
    }

    return ['ok' => $failed === 0, 'created' => $created, 'failed' => $failed, 'details' => $details];
}

function cobx_gateway_ensure_schema(PDO $pdo): void
{
    if (function_exists('cobx_charge_db_column_exists')) {
        if (!cobx_charge_db_column_exists($pdo, 'installments', 'payment_url')) {
            $pdo->exec('ALTER TABLE installments ADD COLUMN payment_url TEXT NULL AFTER external_id');
        }
        if (!cobx_charge_db_column_exists($pdo, 'installments', 'pix_qrcode')) {
            $pdo->exec('ALTER TABLE installments ADD COLUMN pix_qrcode TEXT NULL AFTER payment_url');
        }
        if (!cobx_charge_db_column_exists($pdo, 'installments', 'boleto_digitable_line')) {
            $pdo->exec('ALTER TABLE installments ADD COLUMN boleto_digitable_line VARCHAR(255) NULL AFTER payment_url');
        }
        if (!cobx_charge_db_column_exists($pdo, 'installments', 'boleto_pdf_url')) {
            $pdo->exec('ALTER TABLE installments ADD COLUMN boleto_pdf_url TEXT NULL AFTER boleto_digitable_line');
        }
        if (!cobx_charge_db_column_exists($pdo, 'installments', 'pix_copy_paste')) {
            $pdo->exec('ALTER TABLE installments ADD COLUMN pix_copy_paste TEXT NULL AFTER pix_qrcode');
        }
        if (!cobx_charge_db_column_exists($pdo, 'clients', 'external_id')) {
            $pdo->exec('ALTER TABLE clients ADD COLUMN external_id VARCHAR(255) NULL AFTER document');
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_accounts (
        id CHAR(36) NOT NULL PRIMARY KEY, company_id CHAR(36) NOT NULL, name VARCHAR(120) NOT NULL,
        provider ENUM('mercadopago','asaas') NOT NULL, api_key TEXT NULL, public_key TEXT NULL, webhook_secret TEXT NULL,
        environment ENUM('sandbox','production') NOT NULL DEFAULT 'sandbox', is_default TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
        KEY idx_payment_accounts_company (company_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (function_exists('cobx_charge_db_column_exists')) {
        if (!cobx_charge_db_column_exists($pdo, 'charges', 'payment_account_id')) {
            $pdo->exec('ALTER TABLE charges ADD COLUMN payment_account_id CHAR(36) NULL AFTER payment_gateway');
        }
        if (!cobx_charge_db_column_exists($pdo, 'charges', 'payment_method')) {
            $pdo->exec("ALTER TABLE charges ADD COLUMN payment_method ENUM('pix','boleto') NOT NULL DEFAULT 'pix' AFTER payment_account_id");
        }
    }
    cobx_gateway_migrate_legacy_accounts($pdo);
}

function cobx_gateway_migrate_legacy_accounts(PDO $pdo): void
{
    $rows = $pdo->query("SELECT id, payment_gateway, gateway_api_key, gateway_public_key, gateway_environment FROM companies WHERE payment_gateway IS NOT NULL AND payment_gateway <> ''")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $check = $pdo->prepare('SELECT id FROM payment_accounts WHERE company_id = ? LIMIT 1');
        $check->execute([$row['id']]);
        if ($check->fetch()) continue;
        $pdo->prepare('INSERT INTO payment_accounts (id, company_id, name, provider, api_key, public_key, environment, is_default) VALUES (?,?,?,?,?,?,?,1)')
            ->execute([uuid_v4(), $row['id'], ucfirst((string) $row['payment_gateway']), $row['payment_gateway'], $row['gateway_api_key'], $row['gateway_public_key'], $row['gateway_environment'] ?: 'sandbox']);
    }
}

function cobx_gateway_default_account_id(PDO $pdo, string $companyId): ?string
{
    $st = $pdo->prepare('SELECT id FROM payment_accounts WHERE company_id = ? AND is_active = 1 ORDER BY is_default DESC, created_at ASC LIMIT 1');
    $st->execute([$companyId]); $row = $st->fetch(PDO::FETCH_ASSOC); return $row ? (string) $row['id'] : null;
}

/** @return array<string,mixed>|null */
function cobx_gateway_account(PDO $pdo, string $companyId, string $accountId): ?array
{
    $st = $pdo->prepare('SELECT * FROM payment_accounts WHERE id = ? AND company_id = ? AND is_active = 1 LIMIT 1');
    $st->execute([$accountId, $companyId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($row !== null) $row['api_key'] = cobx_secret_decrypt($row['api_key'] ?? null);
    return $row;
}

/** @return array{ok: bool, detail: string, external_id?: string, payment_url?: string, pix_qrcode?: string, pix_copy_paste?: string} */
function cobx_gateway_create_payment(PDO $pdo, array $account, array $charge, array $installment, string $method): array
{
    return cobx_connector((string)$account['provider'])->create($pdo,$account,$charge,$installment,$method);
}

/**
 * Contrato mínimo de um conector. Novos provedores devem declarar métodos e
 * implementar create, fetch e cancel através deste ponto central.
 * @return array{provider:string, payment_methods:list<string>, supports_fetch:bool, supports_cancel:bool}
 */
function cobx_connector_capabilities(string $provider): array
{
    try{$c=cobx_connector($provider);return ['provider'=>$c->provider(),'payment_methods'=>$c->paymentMethods(),'supports_fetch'=>true,'supports_cancel'=>true];}catch(Throwable){return ['provider'=>$provider,'payment_methods'=>[],'supports_fetch'=>false,'supports_cancel'=>false];}
}

/** @return array{ok:bool, detail:string} */
function cobx_connector_cancel_payment(array $account, string $externalId): array
{
    $externalId = trim($externalId);
    if ($externalId === '') return ['ok' => true, 'detail' => 'Parcela sem cobrança remota.'];
    $provider = (string) ($account['provider'] ?? ''); $token=trim((string)($account['api_key']??''));
    if ($token === '') return ['ok' => false, 'detail' => 'A conta de recebimento não possui credencial para cancelar a cobrança.'];
    try{return cobx_connector($provider)->cancel($account,$externalId);}catch(Throwable $e){return ['ok'=>false,'detail'=>$e->getMessage()];}
}
function cobx_connector_fetch_payment(array $account,string $externalId):array{return cobx_connector((string)$account['provider'])->fetch($account,$externalId);}

/**
 * @param array<string, mixed> $company
 * @param array<string, mixed> $charge
 * @param array<string, mixed> $installment
 * @return array{ok: bool, detail: string, external_id?: string, payment_url?: string, pix_qrcode?: string, pix_copy_paste?: string}
 */
function cobx_gateway_create_asaas_payment(PDO $pdo, array $company, array $charge, array $installment, string $method = 'pix'): array
{
    $token = trim((string) ($company['gateway_api_key'] ?? ''));
    $base = cobx_gateway_asaas_base((string) ($company['gateway_environment'] ?? 'sandbox'));
    $customerId = cobx_gateway_asaas_customer_id($pdo, $base, $token, $charge);
    if ($customerId === null) {
        return ['ok' => false, 'detail' => 'Asaas: nao foi possivel criar/localizar o cliente. Verifique nome e CPF/CNPJ.'];
    }

    $reference = 'installment:' . (string) $installment['id'];
    $payload = [
        'customer' => $customerId,
        'billingType' => $method === 'boleto' ? 'BOLETO' : 'PIX',
        'value' => round((float) $installment['amount'], 2),
        'dueDate' => (string) $installment['due_date'],
        'description' => (string) $charge['description'] . ' - parcela ' . (string) $installment['installment_number'],
        'externalReference' => $reference,
    ];
    $payment = cobx_gateway_http_json('POST', $base . '/v3/payments', $token, $payload, 'asaas');
    if ($payment === null || empty($payment['id'])) {
        return ['ok' => false, 'detail' => 'Asaas: falha ao criar cobranca PIX.'];
    }

    $paymentId = (string) $payment['id'];
    $qr = $method === 'pix' ? cobx_gateway_http_json('GET', $base . '/v3/payments/' . rawurlencode($paymentId) . '/pixQrCode', $token, null, 'asaas') : null;

    return [
        'ok' => true,
        'detail' => 'Asaas: cobranca ' . ($method === 'boleto' ? 'boleto' : 'PIX') . ' criada para parcela #' . (string) $installment['installment_number'] . '.',
        'external_id' => $paymentId,
        'payment_url' => (string) ($payment['invoiceUrl'] ?? $payment['bankSlipUrl'] ?? ''),
        'boleto_digitable_line' => $method === 'boleto' ? (string) ($payment['identificationField'] ?? '') : '',
        'boleto_pdf_url' => $method === 'boleto' ? (string) ($payment['bankSlipUrl'] ?? $payment['invoiceUrl'] ?? '') : '',
        'pix_qrcode' => is_array($qr) ? (string) ($qr['encodedImage'] ?? '') : '',
        'pix_copy_paste' => is_array($qr) ? (string) ($qr['payload'] ?? '') : '',
    ];
}

/**
 * @param array<string, mixed> $company
 * @param array<string, mixed> $charge
 * @param array<string, mixed> $installment
 * @return array{ok: bool, detail: string, external_id?: string, payment_url?: string, pix_qrcode?: string, pix_copy_paste?: string}
 */
function cobx_gateway_create_mercadopago_pix_payment(array $company, array $charge, array $installment): array
{
    $token = trim((string) ($company['gateway_api_key'] ?? ''));
    $email = trim((string) ($charge['client_email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'detail' => 'Mercado Pago: cliente sem email valido para gerar PIX.'];
    }

    $reference = 'installment:' . (string) $installment['id'];
    $appUrl = rtrim((string) env('APP_URL', ''), '/');
    $notificationUrl = $appUrl !== '' ? $appUrl . '/api/webhooks/payment/mercadopago/' . (string) $charge['company_id'] : null;
    $payer = [
        'email' => $email,
    ];
    $nameParts = preg_split('/\s+/', trim((string) ($charge['client_name'] ?? ''))) ?: [];
    if ($nameParts !== []) {
        $payer['first_name'] = (string) array_shift($nameParts);
        if ($nameParts !== []) {
            $payer['last_name'] = implode(' ', $nameParts);
        }
    }
    $document = preg_replace('/\D+/', '', (string) ($charge['client_document'] ?? ''));
    if (is_string($document) && in_array(strlen($document), [11, 14], true)) {
        $payer['identification'] = [
            'type' => strlen($document) === 11 ? 'CPF' : 'CNPJ',
            'number' => $document,
        ];
    }

    $payload = [
        'transaction_amount' => round((float) $installment['amount'], 2),
        'description' => (string) $charge['description'] . ' - parcela ' . (string) $installment['installment_number'],
        'payment_method_id' => 'pix',
        'external_reference' => $reference,
        'metadata' => [
            'installment_id' => (string) $installment['id'],
            'charge_id' => (string) $charge['id'],
            'external_reference' => $reference,
        ],
        'payer' => $payer,
    ];
    if ($notificationUrl !== null) {
        $payload['notification_url'] = $notificationUrl;
    }

    $payment = cobx_gateway_http_json(
        'POST',
        'https://api.mercadopago.com/v1/payments',
        $token,
        $payload,
        'mercadopago',
        ['X-Idempotency-Key: cobx-' . (string) $installment['id']]
    );
    if ($payment === null || empty($payment['id'])) {
        return ['ok' => false, 'detail' => 'Mercado Pago: falha ao criar cobranca PIX.'];
    }

    $transaction = isset($payment['point_of_interaction']['transaction_data']) && is_array($payment['point_of_interaction']['transaction_data'])
        ? $payment['point_of_interaction']['transaction_data']
        : [];

    return [
        'ok' => true,
        'detail' => 'Mercado Pago: cobranca PIX criada para parcela #' . (string) $installment['installment_number'] . '.',
        'external_id' => (string) $payment['id'],
        'payment_url' => (string) ($transaction['ticket_url'] ?? $transaction['external_resource_url'] ?? ''),
        'pix_qrcode' => (string) ($transaction['qr_code_base64'] ?? ''),
        'pix_copy_paste' => (string) ($transaction['qr_code'] ?? ''),
    ];
}

/**
 * @param array<string, mixed> $charge
 */
function cobx_gateway_asaas_customer_id(PDO $pdo, string $base, string $token, array $charge): ?string
{
    $current = trim((string) ($charge['client_external_id'] ?? ''));
    if (str_starts_with($current, 'cus_')) {
        return $current;
    }

    $document = preg_replace('/\D+/', '', (string) ($charge['client_document'] ?? ''));
    if (!is_string($document) || $document === '') {
        return null;
    }

    $payload = [
        'name' => (string) $charge['client_name'],
        'cpfCnpj' => $document,
        'email' => (string) ($charge['client_email'] ?? ''),
        'mobilePhone' => preg_replace('/\D+/', '', (string) ($charge['client_phone'] ?? '')),
        'externalReference' => (string) $charge['client_id'],
        'notificationDisabled' => false,
    ];
    $customer = cobx_gateway_http_json('POST', $base . '/v3/customers', $token, array_filter($payload, static fn ($v): bool => $v !== '' && $v !== null), 'asaas');
    if ($customer === null || empty($customer['id'])) {
        return null;
    }

    $customerId = (string) $customer['id'];
    $pdo->prepare('UPDATE clients SET external_id = ?, updated_at = NOW(3) WHERE id = ? AND company_id = ?')
        ->execute([$customerId, (string) $charge['client_id'], (string) $charge['company_id']]);

    return $customerId;
}

function cobx_gateway_asaas_base(string $environment): string
{
    return strtolower(trim($environment)) === 'production' ? 'https://api.asaas.com' : 'https://api-sandbox.asaas.com';
}

/**
 * @param array<string, mixed>|null $payload
 * @return array<string, mixed>|null
 */
function cobx_gateway_http_json(string $method, string $url, string $token, ?array $payload, string $gateway, array $extraHeaders = []): ?array
{
    $headers = ['Accept: application/json'];
    if ($gateway === 'asaas') {
        $headers[] = 'access_token: ' . $token;
        $headers[] = 'User-Agent: Cobx/1.0';
    } else {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $body = null;
    if ($payload !== null) {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $headers[] = 'Content-Type: application/json';
    }
    foreach ($extraHeaders as $header) {
        if (is_string($header) && trim($header) !== '') {
            $headers[] = $header;
        }
    }

    if (!function_exists('curl_init')) {
        $ctx = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $body ?? '',
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        return is_string($raw) && $raw !== '' ? cobx_gateway_decode_json($raw) : null;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (!is_string($raw) || $raw === '' || $status < 200 || $status >= 300) {
        return null;
    }

    return cobx_gateway_decode_json($raw);
}

function cobx_gateway_http_status(string $method, string $url, string $token, ?array $payload, string $gateway): int
{
    $headers = ['Accept: application/json'];
    $headers[] = $gateway === 'asaas' ? 'access_token: ' . $token : 'Authorization: Bearer ' . $token;
    $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    if (!function_exists('curl_init')) return 0;
    $ch = curl_init($url);
    if ($ch === false) return 0;
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 30]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_exec($ch); $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return $status;
}

/** @return array<string, mixed>|null */
function cobx_gateway_decode_json(string $raw): ?array
{
    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }

    return is_array($data) ? $data : null;
}
