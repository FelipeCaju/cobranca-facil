<?php

declare(strict_types=1);

$user = app_require_login();
$companyId = app_require_company($user);
$pdo = app_pdo();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'delete' && isset($_POST['id']) && assert_uuid((string) $_POST['id'])) {
        $pdo->prepare('DELETE FROM products WHERE id = ? AND company_id = ?')->execute([$_POST['id'], $companyId]);
        flash_set('ok', 'Produto removido.');
        app_redirect('/dashboard/products');
    }
    $parsed = parse_product_from_request($_POST);
    if ($parsed['errors'] !== []) {
        flash_set('error', implode(' ', $parsed['errors']));
        app_redirect('/dashboard/products?edit=' . urlencode((string) ($_POST['id'] ?? '')));
    }
    $d = $parsed['data'];
    $editId = trim((string) ($_POST['id'] ?? ''));
    if ($editId !== '' && assert_uuid($editId)) {
        $pdo->prepare(
            'UPDATE products SET name=?, description=?, price=?, installments_count=?, is_monthly=?, is_recurring_monthly=?, is_active=?
             WHERE id=? AND company_id=?'
        )->execute([
            $d['name'], $d['description'], $d['price'], $d['installments_count'],
            $d['is_monthly'], $d['is_recurring_monthly'], $d['is_active'], $editId, $companyId,
        ]);
        flash_set('ok', 'Produto atualizado.');
    } else {
        $pid = uuid_v4();
        $pdo->prepare(
            'INSERT INTO products (id, company_id, name, description, price, installments_count, is_monthly, is_recurring_monthly, is_active)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            $pid, $companyId, $d['name'], $d['description'], $d['price'], $d['installments_count'],
            $d['is_monthly'], $d['is_recurring_monthly'], $d['is_active'],
        ]);
        flash_set('ok', 'Produto criado.');
    }
    app_redirect('/dashboard/products');
}

$st = $pdo->prepare(
    'SELECT id, name, description, price, installments_count, is_monthly, is_recurring_monthly, is_active
     FROM products WHERE company_id = ? ORDER BY name'
);
$st->execute([$companyId]);
$items = $st->fetchAll(PDO::FETCH_ASSOC);

$edit = null;
$editId = isset($_GET['edit']) ? trim((string) $_GET['edit']) : '';
if ($editId !== '' && assert_uuid($editId)) {
    $st = $pdo->prepare('SELECT * FROM products WHERE id = ? AND company_id = ? LIMIT 1');
    $st->execute([$editId, $companyId]);
    $edit = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

app_render('products', ['items' => $items, 'edit' => $edit], $edit ? 'Editar produto' : 'Produtos');
