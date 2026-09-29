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
        'sicoob' => [
            'label' => 'Sicoob', 'enabled' => true,
            'capabilities' => ['pix_immediate','pix_due','boleto','boleto_pix_optional','webhook','cancel','fetch','pdf','reconciliation','sandbox','mtls'],
            'requires_certificate' => true,
            'fields' => [
                $credential('client_id', 'Client ID de produção'),
                $credential('client_secret', 'Client Secret de produção'),
                ['key'=>'sandbox_access_token','label'=>'Access Token do Sandbox','type'=>'password','secret'=>true,'required'=>false,'storage'=>'credentials'],
                ['key'=>'pix_client_id','label'=>'Client ID específico de PIX (opcional)','type'=>'text','secret'=>false,'required'=>false,'storage'=>'credentials'],
                ['key'=>'pix_client_secret','label'=>'Client Secret específico de PIX (opcional)','type'=>'password','secret'=>true,'required'=>false,'storage'=>'credentials'],
                ['key'=>'billing_client_id','label'=>'Client ID específico de Cobrança (opcional)','type'=>'text','secret'=>false,'required'=>false,'storage'=>'credentials'],
                ['key'=>'billing_client_secret','label'=>'Client Secret específico de Cobrança (opcional)','type'=>'password','secret'=>true,'required'=>false,'storage'=>'credentials'],
                ['key'=>'pix_key','label'=>'Chave PIX recebedora','type'=>'text','secret'=>false,'required'=>true,'storage'=>'config'],
                ['key'=>'pix_scopes','label'=>'Scopes PIX liberados no aplicativo','type'=>'text','secret'=>false,'required'=>false,'storage'=>'config'],
                ['key'=>'billing_scopes','label'=>'Scopes de Cobrança liberados no aplicativo','type'=>'text','secret'=>false,'required'=>false,'storage'=>'config'],
                ['key'=>'numero_cliente','label'=>'Número do cliente','type'=>'text','secret'=>false,'required'=>true,'storage'=>'config'],
                ['key'=>'codigo_modalidade','label'=>'Código da modalidade','type'=>'text','secret'=>false,'required'=>true,'storage'=>'config'],
                ['key'=>'numero_conta_corrente','label'=>'Número da conta corrente','type'=>'text','secret'=>false,'required'=>true,'storage'=>'config'],
                ['key'=>'numero_contrato_cobranca','label'=>'Número do contrato de cobrança','type'=>'text','secret'=>false,'required'=>false,'storage'=>'config'],
                ['key'=>'codigo_especie_documento','label'=>'Código da espécie do documento','type'=>'text','secret'=>false,'required'=>true,'storage'=>'config'],
                ['key'=>'identificacao_emissao_boleto','label'=>'Identificação de emissão do boleto','type'=>'text','secret'=>false,'required'=>true,'storage'=>'config'],
                ['key'=>'identificacao_distribuicao_boleto','label'=>'Identificação de distribuição do boleto','type'=>'text','secret'=>false,'required'=>true,'storage'=>'config'],
                ['key'=>'boleto_hibrido','label'=>'Boleto híbrido contratado? (1=sim, 0=não)','type'=>'text','secret'=>false,'required'=>true,'storage'=>'config'],
            ],
        ],
        'sicredi' => [
            'label' => 'Sicredi', 'enabled' => true,
            'capabilities' => ['pix_immediate','pix_due','pix_received','boleto','boleto_pix_optional','webhook','cancel','fetch','reconciliation','sandbox_partial','mtls_pix'],
            'requires_certificate' => true,
            'fields' => [
                ['key'=>'pix_client_id','label'=>'PIX Client ID','type'=>'text','secret'=>false,'required'=>true,'storage'=>'credentials'],
                ['key'=>'pix_client_secret','label'=>'PIX Client Secret','type'=>'password','secret'=>true,'required'=>true,'storage'=>'credentials'],
                ['key'=>'billing_api_key','label'=>'Cobrança x-api-key','type'=>'password','secret'=>true,'required'=>true,'storage'=>'credentials'],
                ['key'=>'billing_access_code','label'=>'Código de Acesso da Cobrança','type'=>'password','secret'=>true,'required'=>true,'storage'=>'credentials'],
                ['key'=>'webhook_token','label'=>'Token do webhook de Cobrança','type'=>'password','secret'=>true,'required'=>false,'storage'=>'credentials'],
                ['key'=>'pix_key','label'=>'Chave PIX recebedora','type'=>'text','secret'=>false,'required'=>true,'storage'=>'config'],
                ['key'=>'pix_scopes','label'=>'Scopes PIX','type'=>'text','secret'=>false,'required'=>true,'storage'=>'config'],
                ['key'=>'pix_sandbox_base_url','label'=>'URL oficial de homologação PIX','type'=>'text','secret'=>false,'required'=>false,'storage'=>'config'],
                ['key'=>'pix_sandbox_token_url','label'=>'URL oficial do token PIX de homologação','type'=>'text','secret'=>false,'required'=>false,'storage'=>'config'],
                ['key'=>'cooperativa','label'=>'Cooperativa (4 dígitos)','type'=>'text','secret'=>false,'required'=>true,'storage'=>'config'],
                ['key'=>'posto','label'=>'Posto (2 dígitos)','type'=>'text','secret'=>false,'required'=>true,'storage'=>'config'],
                ['key'=>'codigo_beneficiario','label'=>'Código do beneficiário (5 dígitos)','type'=>'text','secret'=>false,'required'=>true,'storage'=>'config'],
                ['key'=>'especie_documento','label'=>'Espécie do documento','type'=>'text','secret'=>false,'required'=>true,'storage'=>'config'],
                ['key'=>'boleto_hibrido','label'=>'Boleto híbrido contratado? (1=sim, 0=não)','type'=>'text','secret'=>false,'required'=>true,'storage'=>'config'],
            ],
        ],
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
