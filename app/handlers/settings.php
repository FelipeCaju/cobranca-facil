<?php

declare(strict_types=1);

$user = app_require_login();
$companyId = app_require_company($user);
$pdo = app_pdo();
$tab = (string) ($_GET['tab'] ?? 'payment');
if (!in_array($tab, ['payment', 'email'], true)) {
    $tab = 'payment';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $formTab = (string) ($_POST['tab'] ?? 'payment');

    if ($formTab === 'email') {
        $st = $pdo->prepare(
            'SELECT smtp_password FROM companies WHERE id = ? LIMIT 1'
        );
        $st->execute([$companyId]);
        $cur = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $useCustom = !empty($_POST['smtp_use_custom']) ? 1 : 0;
        $host = trim((string) ($_POST['smtp_host'] ?? ''));
        $port = max(1, min(65535, (int) ($_POST['smtp_port'] ?? 587)));
        $enc = in_array($_POST['smtp_encryption'] ?? '', ['tls', 'ssl', 'none'], true)
            ? (string) $_POST['smtp_encryption'] : 'tls';
        $smtpUser = trim((string) ($_POST['smtp_username'] ?? ''));
        $fromEmail = trim((string) ($_POST['smtp_from_email'] ?? ''));
        $fromName = trim((string) ($_POST['smtp_from_name'] ?? ''));
        $pw = (string) ($cur['smtp_password'] ?? '');
        $newPw = trim((string) ($_POST['smtp_password'] ?? ''));
        if ($newPw !== '') {
            $pw = $newPw;
        }
        if ($useCustom && ($host === '' || $fromEmail === '' || $pw === '')) {
            flash_set('error', 'SMTP próprio: servidor, email remetente e palavra-passe são obrigatórios.');
            app_redirect('/dashboard/settings?tab=email');
        }
        $pdo->prepare(
            'UPDATE companies SET smtp_use_custom=?, smtp_host=?, smtp_port=?, smtp_encryption=?, smtp_username=?, smtp_password=?, smtp_from_email=?, smtp_from_name=?, updated_at=NOW(3) WHERE id=?'
        )->execute([
            $useCustom, $host ?: null, $port, $enc, $smtpUser ?: null, $pw ?: null,
            $fromEmail ?: null, $fromName ?: null, $companyId,
        ]);
        flash_set('ok', 'Configurações de email guardadas.');
        app_redirect('/dashboard/settings?tab=email');
    }

    $gateway = trim((string) ($_POST['payment_gateway'] ?? ''));
    if ($gateway !== '' && $gateway !== 'mercadopago' && $gateway !== 'asaas') {
        flash_set('error', 'Gateway inválido');
        app_redirect('/dashboard/settings?tab=payment');
    }
    $apiKey = trim((string) ($_POST['gateway_api_key'] ?? ''));
    $publicKey = trim((string) ($_POST['gateway_public_key'] ?? ''));
    $env = trim((string) ($_POST['gateway_environment'] ?? 'sandbox'));
    if ($env !== 'production') {
        $env = 'sandbox';
    }
    if ($gateway === '') {
        $apiKey = '';
        $publicKey = '';
    }
    $pdo->prepare(
        'UPDATE companies SET payment_gateway=?, gateway_api_key=?, gateway_public_key=?, gateway_environment=?, updated_at=NOW(3) WHERE id=?'
    )->execute([
        $gateway !== '' ? $gateway : null,
        $apiKey !== '' ? $apiKey : null,
        $publicKey !== '' ? $publicKey : null,
        $env,
        $companyId,
    ]);
    flash_set('ok', 'Configurações de pagamento guardadas.');
    app_redirect('/dashboard/settings?tab=payment');
}

$st = $pdo->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1');
$st->execute([$companyId]);
$company = $st->fetch(PDO::FETCH_ASSOC) ?: [];

$base = rtrim((string) env('APP_URL', 'http://localhost/cobx'), '/');
$webhookMp = $base . '/api/webhooks/mercadopago?company_id=' . urlencode($companyId);
$webhookAsaas = $base . '/api/webhooks/asaas?company_id=' . urlencode($companyId);

app_render('settings', [
    'tab' => $tab,
    'company' => $company,
    'webhookMp' => $webhookMp,
    'webhookAsaas' => $webhookAsaas,
], 'Configurações');
