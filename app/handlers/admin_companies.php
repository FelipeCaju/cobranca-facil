<?php

declare(strict_types=1);

$user = app_require_login();
app_require_admin($user);
$pdo = app_pdo();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['id'])) {
    $id = (string) $_POST['id'];
    if (!assert_uuid($id)) {
        flash_set('error', 'ID inválido');
        app_redirect('/admin/companies');
    }
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        flash_set('error', 'Nome obrigatório');
        app_redirect('/admin/companies?edit=' . urlencode($id));
    }
    $planId = trim((string) ($_POST['plan_id'] ?? '')) ?: null;
    $renews = parse_date_br((string) ($_POST['plan_renews_at'] ?? ''));
    if ($renews === null && ($_POST['plan_renews_at'] ?? '') !== '') {
        flash_set('error', 'Data de renovação inválida');
        app_redirect('/admin/companies?edit=' . urlencode($id));
    }
    $isActive = !empty($_POST['is_active']) ? 1 : 0;
    $pdo->prepare(
        'UPDATE companies SET name=?, email=?, phone=?, is_active=?, plan_id=?, plan_renews_at=?, updated_at=NOW(3) WHERE id=?'
    )->execute([
        $name,
        trim((string) ($_POST['email'] ?? '')) ?: null,
        trim((string) ($_POST['phone'] ?? '')) ?: null,
        $isActive,
        $planId,
        $renews !== '' ? $renews : null,
        $id,
    ]);
    flash_set('ok', 'Empresa atualizada.');
    app_redirect('/admin/companies');
}

$st = $pdo->query(
    'SELECT c.id, c.name, c.email, c.is_active, c.plan_renews_at, IFNULL(pl.name,\'\') AS plan_name, u.email AS owner_email
     FROM companies c
     INNER JOIN users u ON u.id = c.owner_id
     LEFT JOIN plans pl ON pl.id = c.plan_id
     ORDER BY c.name'
);
$items = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];

$st = $pdo->query('SELECT id, name FROM plans WHERE is_active = 1 ORDER BY name');
$plans = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];

$edit = null;
$editId = isset($_GET['edit']) ? trim((string) $_GET['edit']) : '';
if ($editId !== '' && assert_uuid($editId)) {
    $st = $pdo->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1');
    $st->execute([$editId]);
    $edit = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

app_render('admin_companies', ['items' => $items, 'plans' => $plans, 'edit' => $edit], 'Empresas');
