<?php

declare(strict_types=1);

/** URL pública do webhook Mercado Pago para assinaturas dos planos da plataforma. */
function cobx_mercadopago_plans_webhook_url(): ?string
{
    $b = rtrim((string) env('APP_URL', ''), '/');

    return $b !== '' ? $b . '/api/webhooks/plans/mercadopago' : null;
}
