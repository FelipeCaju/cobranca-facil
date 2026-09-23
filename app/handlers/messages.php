<?php

declare(strict_types=1);

$user = app_require_login();
$companyId = app_require_company($user);
$pdo = app_pdo();

require_once __DIR__ . '/../../api/routes/company_messages.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        cobx_message_settings_ensure_schema($pdo);
        $start = cobx_parse_hhmm_time((string) ($_POST['billing_reminder_start_time'] ?? '08:00'));
        $gap = max(5, min(3600, (int) ($_POST['billing_reminder_gap_seconds'] ?? 60)));
        $sendQr = !empty($_POST['send_qrcode']) ? 1 : 0;
        $sendCp = !empty($_POST['send_copy_paste_key']) ? 1 : 0;
        $pdo->prepare(
            'UPDATE companies SET send_qrcode=?, send_copy_paste_key=?, billing_reminder_start_time=?, billing_reminder_gap_seconds=?, updated_at=NOW(3) WHERE id=?'
        )->execute([$sendQr, $sendCp, $start, $gap, $companyId]);
        flash_set('ok', 'Configurações de mensagens guardadas.');
    } catch (Throwable $e) {
        flash_set('error', 'Erro ao guardar: ' . $e->getMessage());
    }
    app_redirect('/dashboard/messages');
}

cobx_message_settings_ensure_schema($pdo);
cobx_message_templates_ensure($pdo, $companyId);

$st = $pdo->prepare(
    'SELECT send_qrcode, send_copy_paste_key, billing_reminder_start_time, billing_reminder_gap_seconds FROM companies WHERE id = ? LIMIT 1'
);
$st->execute([$companyId]);
$co = $st->fetch(PDO::FETCH_ASSOC) ?: [];

require_once __DIR__ . '/../../api/lib/message_vars.php';
$vars = cobx_message_placeholder_catalog();

app_render('messages', [
    'co' => $co,
    'vars' => $vars,
    'startTime' => cobx_time_hhmm((string) ($co['billing_reminder_start_time'] ?? '08:00:00')),
], 'Mensagens');
