<?php

declare(strict_types=1);

$user = app_require_login();
$companyId = app_require_company($user);
$pdo = app_pdo();

/** @return array<string, string> */
function charges_filter_params(): array
{
    $f = [];
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q !== '') {
        $f['q'] = $q;
    }
    $status = trim((string) ($_GET['status'] ?? ''));
    if (in_array($status, ['pending', 'paid', 'overdue', 'cancelled'], true)) {
        $f['status'] = $status;
    }
    $clientId = trim((string) ($_GET['client_id'] ?? ''));
    if ($clientId !== '' && assert_uuid($clientId)) {
        $f['client_id'] = $clientId;
    }
    $gateway = trim((string) ($_GET['gateway'] ?? ''));
    if ($gateway === 'mercadopago' || $gateway === 'asaas') {
        $f['gateway'] = $gateway;
    }

    return $f;
}

function charges_filter_query(array $extra = []): string
{
    $qs = http_build_query(array_merge(charges_filter_params(), $extra));

    return $qs !== '' ? '?' . $qs : '';
}

function charges_redirect_edit(string $chargeId, array $extra = []): void
{
    app_redirect('/dashboard/charges' . charges_filter_query(array_merge(['edit' => $chargeId], $extra)));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? 'create');
    $editChargeId = trim((string) ($_POST['charge_id'] ?? $_POST['id'] ?? ''));

    if ($action === 'mark_paid' && isset($_POST['installment_id'])) {
        $err = mark_installment_paid($pdo, $companyId, (string) $_POST['installment_id']);
        flash_set($err === null ? 'ok' : 'error', $err ?? 'Parcela marcada como paga.');
        if ($editChargeId !== '' && assert_uuid($editChargeId)) {
            charges_redirect_edit($editChargeId);
        }
        app_redirect('/dashboard/charges' . charges_filter_query());
    }

    if ($action === 'delete' && isset($_POST['id'])) {
        $err = delete_charge($pdo, $companyId, (string) $_POST['id']);
        flash_set($err === null ? 'ok' : 'error', $err ?? 'Cobrança excluída.');
        app_redirect('/dashboard/charges' . charges_filter_query());
    }

    if ($action === 'update' && isset($_POST['id'])) {
        $firstDue = parse_date_br((string) ($_POST['first_due_date'] ?? ''));
        if ($firstDue === null && ($_POST['first_due_date'] ?? '') !== '') {
            flash_set('error', 'Data de vencimento inválida (use dd/mm/aaaa).');
            charges_redirect_edit((string) $_POST['id']);
        }
        $err = update_charge_for_product(
            $pdo,
            $companyId,
            (string) $_POST['id'],
            (string) ($_POST['client_id'] ?? ''),
            (string) ($_POST['product_id'] ?? ''),
            (string) ($_POST['payment_gateway'] ?? ''),
            is_string($firstDue) ? $firstDue : '',
        );
        flash_set($err === null ? 'ok' : 'error', $err ?? 'Cobrança atualizada.');
        charges_redirect_edit((string) $_POST['id']);
    }

    $firstDue = parse_date_br((string) ($_POST['first_due_date'] ?? '')) ?? '';
    if ($firstDue === null) {
        flash_set('error', 'Data de vencimento inválida (use dd/mm/aaaa).');
        app_redirect('/dashboard/charges' . charges_filter_query());
    }
    $err = create_charge_for_product(
        $pdo,
        $companyId,
        (string) ($_POST['client_id'] ?? ''),
        (string) ($_POST['product_id'] ?? ''),
        (string) ($_POST['payment_gateway'] ?? ''),
        $firstDue,
    );
    flash_set($err === null ? 'ok' : 'error', $err ?? 'Cobrança criada com parcelas.');
    app_redirect('/dashboard/charges' . charges_filter_query());
}

$filters = charges_filter_params();
$sql = 'SELECT ch.*, cl.name AS client_name, pr.name AS product_name
     FROM charges ch INNER JOIN clients cl ON cl.id = ch.client_id
     LEFT JOIN products pr ON pr.id = ch.product_id
     WHERE ch.company_id = ?';
$params = [$companyId];
if (isset($filters['client_id'])) {
    $sql .= ' AND ch.client_id = ?';
    $params[] = $filters['client_id'];
}
if (isset($filters['status'])) {
    $sql .= ' AND ch.status = ?';
    $params[] = $filters['status'];
}
if (isset($filters['gateway'])) {
    $sql .= ' AND ch.payment_gateway = ?';
    $params[] = $filters['gateway'];
}
if (isset($filters['q'])) {
    $like = '%' . $filters['q'] . '%';
    $sql .= ' AND (cl.name LIKE ? OR IFNULL(pr.name,\'\') LIKE ? OR ch.description LIKE ?)';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
$sql .= ' ORDER BY ch.created_at DESC';
$st = $pdo->prepare($sql);
$st->execute($params);
$charges = $st->fetchAll(PDO::FETCH_ASSOC);

$st = $pdo->prepare('SELECT id, name FROM clients WHERE company_id = ? ORDER BY name');
$st->execute([$companyId]);
$clients = $st->fetchAll(PDO::FETCH_ASSOC);

$st = $pdo->prepare('SELECT id, name, price, installments_count FROM products WHERE company_id = ? AND is_active = 1 ORDER BY name');
$st->execute([$companyId]);
$products = $st->fetchAll(PDO::FETCH_ASSOC);

$edit = null;
$editPaidCount = 0;
$editFirstDue = '';
$editInstallments = [];
$editClientName = '';
$editId = isset($_GET['edit']) ? trim((string) $_GET['edit']) : '';
if ($editId !== '' && assert_uuid($editId)) {
    $st = $pdo->prepare(
        'SELECT ch.*, cl.name AS client_name, pr.name AS product_name
         FROM charges ch
         INNER JOIN clients cl ON cl.id = ch.client_id
         LEFT JOIN products pr ON pr.id = ch.product_id
         WHERE ch.id = ? AND ch.company_id = ? LIMIT 1'
    );
    $st->execute([$editId, $companyId]);
    $edit = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($edit) {
        require_once __DIR__ . '/../../api/lib/charge_helpers.php';
        $editPaidCount = cobx_charge_count_paid_installments($pdo, $editId);
        $editFirstDue = format_date_br(cobx_charge_first_due_from_installments($pdo, $editId));
        $editClientName = (string) ($edit['client_name'] ?? '');
        $st = $pdo->prepare(
            'SELECT id, installment_number, amount, due_date, status, paid_at
             FROM installments WHERE charge_id = ? AND company_id = ?
             ORDER BY installment_number ASC'
        );
        $st->execute([$editId, $companyId]);
        $editInstallments = $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

app_render('charges', [
    'charges' => $charges,
    'clients' => $clients,
    'products' => $products,
    'edit' => $edit,
    'editPaidCount' => $editPaidCount,
    'editFirstDue' => $editFirstDue,
    'editInstallments' => $editInstallments,
    'editClientName' => $editClientName,
    'filters' => $filters,
    'filterQuery' => charges_filter_query(),
], $edit ? 'Editar cobrança' : 'Cobranças');
