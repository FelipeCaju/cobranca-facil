<?php

declare(strict_types=1);

function cobx_bank_installment_reference(string $installmentId): string
{
    return 'installment:' . strtolower(trim($installmentId));
}

function cobx_bank_idempotency_key(string $provider, string $installmentId, string $operation = 'create'): string
{
    return 'cobx-' . substr(hash('sha256', strtolower($provider) . '|' . $operation . '|' . strtolower($installmentId)), 0, 48);
}

/** txid compatível com o limite comum de 35 caracteres, sem afirmar regra de um banco específico. */
function cobx_bank_txid(string $installmentId): string
{
    return 'cobx' . substr(hash('sha256', strtolower(trim($installmentId))), 0, 31);
}

function cobx_bank_operation_retryable(string $method, bool $hasIdempotency): bool
{
    $method = strtoupper($method);
    return in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) || $hasIdempotency;
}
