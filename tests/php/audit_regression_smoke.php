<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$webhooks = (string) file_get_contents($root . '/api/routes/webhooks.php');
$queue = (string) file_get_contents($root . '/api/lib/integration_queue.php');
$idempotency = (string) file_get_contents($root . '/api/lib/bank_idempotency.php');
$migration = (string) file_get_contents($root . '/database/migrations/20260924_connectors_audit_receipts.sql');
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) $failures[] = $message;
};

$check(str_contains($webhooks, 'provider_event=COALESCE(?,provider_event)'), 'Webhook não preserva provider_event.');
$check(str_contains($webhooks, 'cobx_secret_encrypt($raw)'), 'Payload bruto do webhook não está criptografado em repouso.');
$check(str_contains($webhooks, 'cobx_secret_decrypt'), 'Worker não descriptografa payload de webhook.');
$check(!str_contains($webhooks, '$r = $rows[0];'), 'Conciliação ainda baixa a primeira parcela quando a referência é ambígua.');
$check(str_contains($queue, "status='processing' AND locked_at<DATE_SUB"), 'Fila não recupera jobs abandonados.');
$check(str_contains($idempotency, "in_array(\$method, ['PUT', 'PATCH', 'DELETE']"), 'POST ainda pode ser repetido com idempotência genérica.');
$check(str_contains($migration, 'ADD COLUMN IF NOT EXISTS') && str_contains($migration, 'CREATE TABLE IF NOT EXISTS'), 'Migration redundante não está protegida.');

echo json_encode(['ok' => $failures === [], 'failures' => $failures], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($failures === [] ? 0 : 1);
