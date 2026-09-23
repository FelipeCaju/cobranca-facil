<?php

declare(strict_types=1);

/**
 * @param array<string, string|null> $row client + company + charge + installment context
 */
function cobx_message_replace_placeholders(string $text, array $row): string
{
    $fullAddr = cobx_format_client_address($row);
    $firstName = cobx_first_name((string) ($row['client_name'] ?? ''));
    $valor = isset($row['installment_amount']) ? cobx_money_br((float) $row['installment_amount']) : '';
    $valorCobranca = isset($row['charge_total']) ? cobx_money_br((float) $row['charge_total']) : '';
    $due = isset($row['due_date']) ? cobx_format_date_br((string) $row['due_date']) : '';

    $map = [
        '{nome_cliente}' => (string) ($row['client_name'] ?? ''),
        '{primeiro_nome}' => $firstName,
        '{email_cliente}' => (string) ($row['client_email'] ?? ''),
        '{telefone_cliente}' => (string) ($row['client_phone'] ?? ''),
        '{cpf_cnpj_cliente}' => (string) ($row['client_document'] ?? ''),
        '{documento_cliente}' => (string) ($row['client_document'] ?? ''),
        '{endereco_completo}' => $fullAddr,
        '{logradouro}' => (string) ($row['address_street'] ?? ''),
        '{numero}' => (string) ($row['address_number'] ?? ''),
        '{complemento}' => (string) ($row['address_complement'] ?? ''),
        '{bairro}' => (string) ($row['address_neighborhood'] ?? ''),
        '{cidade}' => (string) ($row['address_city'] ?? ''),
        '{uf}' => (string) ($row['address_state'] ?? ''),
        '{cep}' => (string) ($row['address_postal_code'] ?? ''),
        '{pais}' => (string) ($row['address_country'] ?? ''),
        '{valor}' => $valor,
        '{valor_parcela}' => $valor,
        '{valor_cobranca}' => $valorCobranca,
        '{data_vencimento}' => $due,
        '{descricao}' => (string) ($row['charge_description'] ?? ''),
        '{descricao_cobranca}' => (string) ($row['charge_description'] ?? ''),
        '{nome_empresa}' => (string) ($row['company_name'] ?? ''),
        '{cnpj_empresa}' => (string) ($row['company_cnpj'] ?? ''),
        '{parcela_atual}' => isset($row['installment_number']) ? (string) (int) $row['installment_number'] : '',
        '{total_parcelas}' => isset($row['charge_installments_count']) ? (string) (int) $row['charge_installments_count'] : '',
        '{id_cobranca}' => (string) ($row['charge_id'] ?? ''),
        '{link_pagamento}' => (string) ($row['payment_link'] ?? ''),
    ];

    return strtr($text, $map);
}

function cobx_first_name(string $full): string
{
    $t = trim($full);
    if ($t === '') {
        return '';
    }
    $parts = preg_split('/\s+/u', $t);

    return is_array($parts) && isset($parts[0]) ? $parts[0] : $t;
}

/** @param array<string, string|null> $row */
function cobx_format_client_address(array $row): string
{
    $parts = array_filter([
        trim((string) ($row['address_street'] ?? '')),
        trim((string) ($row['address_number'] ?? '')),
        trim((string) ($row['address_complement'] ?? '')),
        trim((string) ($row['address_neighborhood'] ?? '')),
        trim((string) ($row['address_city'] ?? '')),
        trim((string) ($row['address_state'] ?? '')),
        trim((string) ($row['address_postal_code'] ?? '')),
    ], static fn ($v) => $v !== '');

    return implode(', ', $parts);
}

function cobx_money_br(float $n): string
{
    return number_format($n, 2, ',', '.');
}

function cobx_format_date_br(string $ymd): string
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $ymd);

    return $dt ? $dt->format('d/m/Y') : $ymd;
}

/** @return list<array{key: string, label: string}> */
function cobx_message_placeholder_catalog(): array
{
    return [
        ['key' => '{nome_cliente}', 'label' => 'Nome completo do cliente'],
        ['key' => '{primeiro_nome}', 'label' => 'Primeiro nome do cliente'],
        ['key' => '{email_cliente}', 'label' => 'Email do cliente'],
        ['key' => '{telefone_cliente}', 'label' => 'Telefone / WhatsApp do cliente'],
        ['key' => '{cpf_cnpj_cliente}', 'label' => 'CPF ou CNPJ do cliente (documento)'],
        ['key' => '{documento_cliente}', 'label' => 'Igual a CPF/CNPJ (alias)'],
        ['key' => '{endereco_completo}', 'label' => 'Endereço completo em uma linha'],
        ['key' => '{logradouro}', 'label' => 'Rua / logradouro'],
        ['key' => '{numero}', 'label' => 'Número do endereço'],
        ['key' => '{complemento}', 'label' => 'Complemento'],
        ['key' => '{bairro}', 'label' => 'Bairro'],
        ['key' => '{cidade}', 'label' => 'Cidade'],
        ['key' => '{uf}', 'label' => 'Estado (UF)'],
        ['key' => '{cep}', 'label' => 'CEP'],
        ['key' => '{pais}', 'label' => 'País'],
        ['key' => '{valor}', 'label' => 'Valor da parcela (formato brasileiro)'],
        ['key' => '{valor_parcela}', 'label' => 'Alias de {valor}'],
        ['key' => '{valor_cobranca}', 'label' => 'Valor total da cobrança'],
        ['key' => '{data_vencimento}', 'label' => 'Data de vencimento da parcela (dd/mm/aaaa)'],
        ['key' => '{descricao}', 'label' => 'Descrição / título da cobrança'],
        ['key' => '{descricao_cobranca}', 'label' => 'Alias da descrição da cobrança'],
        ['key' => '{nome_empresa}', 'label' => 'Nome da empresa (sua marca)'],
        ['key' => '{cnpj_empresa}', 'label' => 'CNPJ da empresa'],
        ['key' => '{parcela_atual}', 'label' => 'Número da parcela atual (ex.: 2)'],
        ['key' => '{total_parcelas}', 'label' => 'Total de parcelas da cobrança'],
        ['key' => '{id_cobranca}', 'label' => 'Identificador interno da cobrança'],
        ['key' => '{link_pagamento}', 'label' => 'Link de pagamento (quando existir integração)'],
    ];
}
