<?php

declare(strict_types=1);

/**
 * Catálogo público e centralizado dos provedores. Segredos nunca pertencem a esta metadata.
 * Bancos planejados permanecem desabilitados até que seus conectores sejam homologados.
 */
function cobx_bank_provider_definitions(): array
{
    $credential = static fn (string $key, string $label, bool $secret = true): array => [
        'key' => $key, 'label' => $label, 'type' => $secret ? 'password' : 'text',
        'secret' => $secret, 'required' => true, 'storage' => 'credentials',
    ];

    return [
        'asaas' => [
            'label' => 'Asaas', 'enabled' => true,
            'capabilities' => ['pix_immediate', 'boleto', 'webhook', 'cancel', 'pdf', 'sandbox'],
            'fields' => [
                $credential('api_key', 'API Key'),
                ['key' => 'webhook_secret', 'label' => 'Token de autenticação do webhook', 'type' => 'password', 'secret' => true, 'required' => false, 'storage' => 'credentials'],
            ],
        ],
        'mercadopago' => [
            'label' => 'Mercado Pago', 'enabled' => true,
            'capabilities' => ['pix_immediate', 'webhook', 'cancel', 'sandbox'],
            'fields' => [
                $credential('api_key', 'Access Token'),
                ['key' => 'public_key', 'label' => 'Public Key', 'type' => 'text', 'secret' => false, 'required' => false, 'storage' => 'public'],
                ['key' => 'webhook_secret', 'label' => 'Assinatura secreta do webhook', 'type' => 'password', 'secret' => true, 'required' => false, 'storage' => 'credentials'],
            ],
        ],
        'inter' => [
            'label' => 'Banco Inter', 'enabled' => true,
            'capabilities' => ['pix_immediate', 'pix_due', 'boleto_pix', 'webhook', 'cancel', 'fetch', 'pdf', 'reconciliation', 'sandbox', 'mtls'],
            'requires_certificate' => true,
            'fields' => [
                $credential('client_id', 'Client ID'),
                $credential('client_secret', 'Client Secret'),
                ['key' => 'pix_key', 'label' => 'Chave PIX recebedora', 'type' => 'text', 'secret' => false, 'required' => true, 'storage' => 'config'],
                ['key' => 'account_number', 'label' => 'Conta corrente (x-conta-corrente)', 'type' => 'text', 'secret' => false, 'required' => false, 'storage' => 'config'],
            ],
        ],
        'sicoob' => ['label' => 'Sicoob', 'enabled' => false, 'capabilities' => [], 'fields' => []],
        'sicredi' => ['label' => 'Sicredi', 'enabled' => false, 'capabilities' => [], 'fields' => []],
        'bb' => ['label' => 'Banco do Brasil', 'enabled' => false, 'capabilities' => [], 'fields' => []],
        'santander' => ['label' => 'Santander', 'enabled' => false, 'capabilities' => [], 'fields' => []],
        'itau' => ['label' => 'Itaú', 'enabled' => false, 'capabilities' => [], 'fields' => []],
        'caixa' => ['label' => 'Caixa', 'enabled' => false, 'capabilities' => [], 'fields' => []],
        'c6' => ['label' => 'C6 Bank', 'enabled' => false, 'capabilities' => [], 'fields' => []],
        'bradesco' => ['label' => 'Bradesco', 'enabled' => false, 'capabilities' => [], 'fields' => []],
    ];
}

function cobx_bank_provider_definition(string $provider, bool $requireEnabled = true): array
{
    $definition = cobx_bank_provider_definitions()[$provider] ?? null;
    if (!is_array($definition) || ($requireEnabled && empty($definition['enabled']))) {
        throw new InvalidArgumentException('Provedor não disponível.');
    }
    return ['id' => $provider] + $definition;
}

function cobx_bank_provider_public_catalog(): array
{
    $items = [];
    foreach (cobx_bank_provider_definitions() as $id => $definition) {
        $items[] = ['id' => $id] + $definition;
    }
    return $items;
}
