<?php

declare(strict_types=1);

$user = app_require_login();
app_require_admin($user);
$pdo = app_pdo();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? 'save');
    if ($action === 'delete' && isset($_POST['id']) && assert_uuid((string) $_POST['id'])) {
        $pdo->prepare('DELETE FROM plans WHERE id = ?')->execute([$_POST['id']]);
        flash_set('ok', 'Plano removido.');
        app_redirect('/admin/plans');
    }
    $name = trim((string) ($_POST['name'] ?? ''));
    $price = (float) str_replace(',', '.', (string) ($_POST['price'] ?? '0'));
    $chargesLimit = max(0, (int) ($_POST['charges_limit'] ?? 0));
    $usersLimit = max(0, (int) ($_POST['users_limit'] ?? 0));
    $isActive = !empty($_POST['is_active']) ? 1 : 0;
    if ($name === '') {
        flash_set('error', 'Nome obrigatório');
        app_redirect('/admin/plans');
    }
    $editId = trim((string) ($_POST['id'] ?? ''));
    if ($editId !== '' && assert_uuid($editId)) {
        $pdo->prepare('UPDATE plans SET name=?, price=?, charges_limit=?, users_limit=?, is_active=? WHERE id=?')
            ->execute([$name, $price, $chargesLimit, $usersLimit, $isActive, $editId]);
        flash_set('ok', 'Plano atualizado.');
    } else {
        $pdo->prepare('INSERT INTO plans (id, name, price, charges_limit, users_limit, is_active) VALUES (?,?,?,?,?,?)')
            ->execute([uuid_v4(), $name, $price, $chargesLimit, $usersLimit, $isActive]);
        flash_set('ok', 'Plano criado.');
    }
    app_redirect('/admin/plans');
}

$st = $pdo->query('SELECT * FROM plans ORDER BY price ASC, name ASC');
$items = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];

$edit = null;
$editId = isset($_GET['edit']) ? trim((string) $_GET['edit']) : '';
if ($editId !== '' && assert_uuid($editId)) {
    $st = $pdo->prepare('SELECT * FROM plans WHERE id = ? LIMIT 1');
    $st->execute([$editId]);
    $edit = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

app_render('admin_plans', ['items' => $items, 'edit' => $edit], 'Planos');
