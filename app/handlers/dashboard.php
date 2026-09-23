<?php

declare(strict_types=1);

$user = app_require_login();
$pdo = app_pdo();

if (app_platform_only($user)) {
    app_render('dashboard_platform', ['user' => $user], 'Consola da plataforma');
    exit;
}

$companyId = app_require_company($user);

$st = $pdo->prepare('SELECT COUNT(*) AS c FROM clients WHERE company_id = ?');
$st->execute([$companyId]);
$clientsTotal = (int) ($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

$st = $pdo->prepare("SELECT COUNT(*) AS c FROM charges WHERE company_id = ? AND status IN ('pending','overdue')");
$st->execute([$companyId]);
$chargesActive = (int) ($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

$st = $pdo->prepare(
    "SELECT COALESCE(SUM(amount), 0) AS s FROM installments
     WHERE company_id = ? AND status = 'paid' AND paid_at >= DATE_FORMAT(NOW(3), '%Y-%m-01')"
);
$st->execute([$companyId]);
$revenueMonth = (float) ($st->fetch(PDO::FETCH_ASSOC)['s'] ?? 0);

$st = $pdo->prepare(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN status IN ('pending','overdue') THEN 1 ELSE 0 END) AS open_cnt
     FROM installments WHERE company_id = ?"
);
$st->execute([$companyId]);
$del = $st->fetch(PDO::FETCH_ASSOC);
$totalInst = (int) ($del['total'] ?? 0);
$openInst = (int) ($del['open_cnt'] ?? 0);
$delinqPct = $totalInst > 0 ? round(100 * $openInst / $totalInst, 1) : null;

$st = $pdo->prepare(
    "SELECT ch.id, ch.total_amount, ch.status, ch.created_at, cl.name AS client_name
     FROM charges ch INNER JOIN clients cl ON cl.id = ch.client_id
     WHERE ch.company_id = ? ORDER BY ch.created_at DESC LIMIT 8"
);
$st->execute([$companyId]);
$recent = $st->fetchAll(PDO::FETCH_ASSOC);

app_render('dashboard_overview', [
    'clientsTotal' => $clientsTotal,
    'chargesActive' => $chargesActive,
    'revenueMonth' => $revenueMonth,
    'delinqPct' => $delinqPct,
    'recent' => $recent,
], 'Visão geral');
