<?php

declare(strict_types=1);

$user = app_require_login();
$companyId = app_require_company($user);
$pdo = app_pdo();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'mark_paid' && isset($_POST['installment_id'])) {
        $err = mark_installment_paid($pdo, $companyId, (string) $_POST['installment_id']);
        flash_set($err === null ? 'ok' : 'error', $err ?? 'Parcela marcada como paga.');
        app_redirect('/dashboard/clients?view=' . urlencode((string) ($_POST['client_id'] ?? '')));
    }
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        flash_set('error', 'Nome é obrigatório');
        app_redirect('/dashboard/clients');
    }
    $cat = trim((string) ($_POST['category_id'] ?? '')) ?: null;
    if ($cat !== null && !assert_uuid($cat)) {
        $cat = null;
    }
    $fields = [
        $cat, $name,
        trim((string) ($_POST['email'] ?? '')) ?: null,
        trim((string) ($_POST['phone'] ?? '')) ?: null,
        trim((string) ($_POST['document'] ?? '')) ?: null,
        trim((string) ($_POST['address_city'] ?? '')) ?: null,
        trim((string) ($_POST['address_state'] ?? '')) ?: null,
    ];
    $editId = trim((string) ($_POST['id'] ?? ''));
    if ($editId !== '' && assert_uuid($editId)) {
        $pdo->prepare(
            'UPDATE clients SET category_id=?, name=?, email=?, phone=?, document=?, address_city=?, address_state=?
             WHERE id=? AND company_id=?'
        )->execute(array_merge($fields, [$editId, $companyId]));
        flash_set('ok', 'Cliente atualizado.');
    } else {
        $cid = uuid_v4();
        $pdo->prepare(
            'INSERT INTO clients (id, company_id, category_id, name, email, phone, document, address_city, address_state)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute(array_merge([$cid, $companyId], $fields));
        flash_set('ok', 'Cliente criado.');
    }
    app_redirect('/dashboard/clients');
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = 150;
$offset = ($page - 1) * $limit;
$q = trim((string) ($_GET['q'] ?? ''));

$where = 'FROM clients c LEFT JOIN client_categories cc ON cc.id = c.category_id WHERE c.company_id = ?';
$params = [$companyId];
if ($q !== '') {
    $like = '%' . $q . '%';
    $where .= ' AND (c.name LIKE ? OR IFNULL(c.email,\'\') LIKE ?)';
    $params[] = $like;
    $params[] = $like;
}

$st = $pdo->prepare('SELECT COUNT(*) AS c ' . $where);
$st->execute($params);
$total = (int) ($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
$totalPages = max(1, (int) ceil($total / $limit));

$sql = 'SELECT c.*, cc.name AS category_name,
  (SELECT COUNT(*) FROM charges ch WHERE ch.client_id = c.id AND ch.status IN (\'pending\',\'overdue\')) AS active_charges
  ' . $where . ' ORDER BY c.name LIMIT ' . $limit . ' OFFSET ' . $offset;
$st = $pdo->prepare($sql);
$st->execute($params);
$items = $st->fetchAll(PDO::FETCH_ASSOC);

$st = $pdo->prepare('SELECT id, name FROM client_categories WHERE company_id = ? ORDER BY name');
$st->execute([$companyId]);
$categories = $st->fetchAll(PDO::FETCH_ASSOC);

$viewId = isset($_GET['view']) ? trim((string) $_GET['view']) : '';
$viewClient = null;
$billingInstallments = [];
if ($viewId !== '' && assert_uuid($viewId)) {
    $st = $pdo->prepare('SELECT * FROM clients WHERE id = ? AND company_id = ? LIMIT 1');
    $st->execute([$viewId, $companyId]);
    $viewClient = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($viewClient) {
        $st = $pdo->prepare(
            'SELECT i.id, i.installment_number, i.amount, i.due_date, i.status, ch.description AS charge_description, ch.installments_count AS charge_installments_count
             FROM installments i INNER JOIN charges ch ON ch.id = i.charge_id
             WHERE i.company_id = ? AND ch.client_id = ? AND i.status IN (\'pending\',\'overdue\')
             ORDER BY i.due_date ASC'
        );
        $st->execute([$companyId, $viewId]);
        $billingInstallments = $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

$edit = null;
$editId = isset($_GET['edit']) ? trim((string) $_GET['edit']) : '';
if ($editId !== '' && assert_uuid($editId)) {
    $st = $pdo->prepare('SELECT * FROM clients WHERE id = ? AND company_id = ? LIMIT 1');
    $st->execute([$editId, $companyId]);
    $edit = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

app_render('clients', [
    'items' => $items,
    'categories' => $categories,
    'edit' => $edit,
    'viewClient' => $viewClient,
    'billingInstallments' => $billingInstallments,
    'page' => $page,
    'totalPages' => $totalPages,
    'total' => $total,
    'q' => $q,
], 'Clientes');
