<?php

declare(strict_types=1);

/**
 * Notifica o gateway (best-effort) quando uma parcela é dada como paga manualmente.
 *
 * @param array<string, mixed> $installment Linha de installments (amount, external_id, …)
 * @param array<string, mixed> $charge Linha de charges (payment_gateway, …)
 * @return array{notified: bool, detail: string}
 */
function cobx_notify_gateway_manual_payment(PDO $pdo, string $companyId, array $installment, array $charge): array
{
    $gateway = (string) ($charge['payment_gateway'] ?? '');
    $extId = isset($installment['external_id']) ? trim((string) $installment['external_id']) : '';
    if ($extId === '') {
        return ['notified' => false, 'detail' => 'Parcela sem ID externo no gateway; nada a notificar.'];
    }

    $st = $pdo->prepare(
        'SELECT gateway_api_key, gateway_environment FROM companies WHERE id = ? LIMIT 1'
    );
    $st->execute([$companyId]);
    $co = $st->fetch(PDO::FETCH_ASSOC);
    if (!$co) {
        return ['notified' => false, 'detail' => 'Empresa não encontrada.'];
    }
    $apiKey = isset($co['gateway_api_key']) ? trim((string) $co['gateway_api_key']) : '';
    if ($apiKey === '') {
        return ['notified' => false, 'detail' => 'Chave de API do gateway não configurada.'];
    }

    $env = isset($co['gateway_environment']) ? strtolower(trim((string) $co['gateway_environment'])) : 'sandbox';
    $isProd = $env === 'production';

    if ($gateway === 'asaas') {
        $base = $isProd ? 'https://api.asaas.com' : 'https://sandbox.asaas.com';
        $url = $base . '/api/v3/payments/' . rawurlencode($extId) . '/receiveInCash';
        $body = json_encode([
            'paymentDate' => (new DateTimeImmutable('today'))->format('Y-m-d'),
            'value' => (float) $installment['amount'],
            'notifyCustomer' => true,
        ], JSON_THROW_ON_ERROR);
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\naccess_token: {$apiKey}\r\n",
                'content' => $body,
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('#\b(\d{3})\b#', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }
        if ($code >= 200 && $code < 300) {
            return ['notified' => true, 'detail' => 'Asaas confirmou recebimento em dinheiro.'];
        }
        $snippet = is_string($resp) ? substr($resp, 0, 200) : '';
        return ['notified' => false, 'detail' => 'Asaas respondeu HTTP ' . $code . ($snippet !== '' ? ': ' . $snippet : '')];
    }

    if ($gateway === 'mercadopago') {
        return [
            'notified' => false,
            'detail' => 'Mercado Pago: confirmação manual via API ainda não implementada (use o painel ou integre o endpoint de pagamentos).',
        ];
    }

    return ['notified' => false, 'detail' => 'Gateway desconhecido.'];
}
