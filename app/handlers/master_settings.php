<?php

declare(strict_types=1);

$user = app_require_login();
app_require_admin($user);
$pdo = app_pdo();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $st = $pdo->prepare('SELECT * FROM master_settings WHERE id = 1 LIMIT 1');
    $st->execute();
    $cur = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $mpk = trim((string) ($_POST['mercadopago_public_key'] ?? ''));
    $mat = trim((string) ($_POST['mercadopago_access_token'] ?? ''));
    if ($mat === '' && !empty($cur['mercadopago_access_token'])) {
        $mat = (string) $cur['mercadopago_access_token'];
    }
    $cron = trim((string) ($_POST['cron_secret'] ?? ''));
    if ($cron === '' && !empty($cur['cron_secret'])) {
        $cron = (string) $cur['cron_secret'];
    }
    if ($cur) {
        $pdo->prepare(
            'UPDATE master_settings SET mercadopago_public_key=?, mercadopago_access_token=?, cron_secret=?, updated_at=NOW(3) WHERE id=1'
        )->execute([$mpk, $mat, $cron]);
    } else {
        $pdo->prepare(
            'INSERT INTO master_settings (id, mercadopago_public_key, mercadopago_access_token, cron_secret) VALUES (1,?,?,?)'
        )->execute([$mpk, $mat, $cron]);
    }
    flash_set('ok', 'Configurações master guardadas.');
    app_redirect('/dashboard/master-settings');
}

$st = $pdo->query('SELECT * FROM master_settings WHERE id = 1 LIMIT 1');
$row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
$cronUrl = rtrim((string) env('APP_URL', ''), '/') . '/api/cron/run';

app_render('master_settings', ['row' => $row ?: [], 'cronUrl' => $cronUrl], 'Config. master');
