<?php

declare(strict_types=1);

$user = app_require_login();
$companyId = app_require_company($user);
$pdo = app_pdo();

$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;
$q = trim((string) ($_GET['q'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$dueFrom = parse_date_br((string) ($_GET['due_from'] ?? ''));
$dueTo = parse_date_br((string) ($_GET['due_to'] ?? ''));
if ($dueFrom === null && ($_GET['due_from'] ?? '') !== '') {
    flash_set('error', 'Data inicial inválida.');
}
if ($dueTo === null && ($_GET['due_to'] ?? '') !== '') {
    flash_set('error', 'Data final inválida.');
}

$from = 'FROM installments i INNER JOIN charges ch ON ch.id = i.charge_id INNER JOIN clients cl ON cl.id = ch.client_id WHERE i.company_id = ?';
$params = [$companyId];
if ($q !== '') {
    $like = '%' . $q . '%';
    $from .= ' AND (cl.name LIKE ? OR ch.description LIKE ?)';
    $params[] = $like;
    $params[] = $like;
}
if ($status !== '' && in_array($status, ['pending', 'paid', 'overdue', 'cancelled'], true)) {
    $from .= ' AND i.status = ?';
    $params[] = $status;
}
if (is_string($dueFrom) && $dueFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueFrom)) {
    $from .= ' AND i.due_date >= ?';
    $params[] = $dueFrom;
}
if (is_string($dueTo) && $dueTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueTo)) {
    $from .= ' AND i.due_date <= ?';
    $params[] = $dueTo;
}

$st = $pdo->prepare('SELECT COUNT(*) AS c ' . $from);
$st->execute($params);
$total = (int) ($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
$totalPages = max(1, (int) ceil($total / $limit));

$sql = 'SELECT i.id, i.installment_number, i.amount, i.due_date, i.status, ch.description AS charge_description, ch.installments_count AS charge_installments_count, cl.name AS client_name
  ' . $from . ' ORDER BY i.due_date DESC LIMIT ' . $limit . ' OFFSET ' . $offset;
$st = $pdo->prepare($sql);
$st->execute($params);
$items = $st->fetchAll(PDO::FETCH_ASSOC);

app_render('installments', [
    'items' => $items,
    'page' => $page,
    'totalPages' => $totalPages,
    'total' => $total,
    'q' => $q,
    'status' => $status,
    'due_from' => $_GET['due_from'] ?? '',
    'due_to' => $_GET['due_to'] ?? '',
], 'Parcelas');
