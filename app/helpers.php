<?php

declare(strict_types=1);

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function brl(float $n): string
{
    return number_format($n, 2, ',', '.');
}

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return is_array($f) ? $f : null;
}

function format_date_br(?string $iso): string
{
    if ($iso === null || $iso === '') {
        return '—';
    }
    $part = substr($iso, 0, 10);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $part, $m)) {
        return $iso;
    }

    return $m[3] . '/' . $m[2] . '/' . $m[1];
}

function parse_date_br(?string $display): ?string
{
    if ($display === null) {
        return null;
    }
    $display = trim($display);
    if ($display === '') {
        return '';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $display)) {
        return $display;
    }
    if (!preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $display, $m)) {
        return null;
    }
    $d = (int) $m[1];
    $mo = (int) $m[2];
    $y = (int) $m[3];
    if (!checkdate($mo, $d, $y)) {
        return null;
    }

    return sprintf('%04d-%02d-%02d', $y, $mo, $d);
}

function status_badge(string $status): string
{
    $labels = [
        'pending' => 'Pendente',
        'paid' => 'Paga',
        'overdue' => 'Atrasada',
        'cancelled' => 'Cancelada',
    ];
    $classes = [
        'pending' => 'badge-warn',
        'paid' => 'badge-ok',
        'overdue' => 'badge-err',
        'cancelled' => 'badge-muted',
    ];
    $label = $labels[$status] ?? $status;
    $class = $classes[$status] ?? 'badge-muted';

    return '<span class="badge ' . $class . '">' . e($label) . '</span>';
}

function assert_uuid(string $id): bool
{
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id);
}

/** @param array<string, mixed> $in */
function parse_product_from_request(array $in): array
{
    $errors = [];
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') {
        $errors[] = 'Nome do produto é obrigatório';
    }
    $inst = max(1, min(120, (int) ($in['installments_count'] ?? 1)));
    $recurring = !empty($in['is_recurring_monthly']);
    $price = (float) str_replace(',', '.', (string) ($in['price'] ?? '0'));
    $instAmt = isset($in['installment_amount']) ? (float) str_replace(',', '.', (string) $in['installment_amount']) : null;
    if ($recurring && $instAmt !== null && $instAmt > 0) {
        $price = round($instAmt * $inst, 2);
    }
    if (!$recurring && $price <= 0) {
        $errors[] = 'Preço inválido';
    }
    if ($recurring && $price <= 0) {
        $errors[] = 'Indique o valor da parcela';
    }

    return [
        'errors' => $errors,
        'data' => [
            'name' => $name,
            'description' => trim((string) ($in['description'] ?? '')) ?: null,
            'price' => round($price, 2),
            'installments_count' => $inst,
            'is_monthly' => !empty($in['is_monthly']) ? 1 : 0,
            'is_recurring_monthly' => $recurring ? 1 : 0,
            'is_active' => !empty($in['is_active']) ? 1 : 0,
        ],
    ];
}

function create_charge_for_product(PDO $pdo, string $companyId, string $clientId, string $productId, string $gateway, string $firstDue): ?string
{
    if ($gateway !== 'mercadopago' && $gateway !== 'asaas') {
        return 'Gateway inválido';
    }
    if (!assert_uuid($clientId) || !assert_uuid($productId)) {
        return 'Dados inválidos';
    }
    $st = $pdo->prepare('SELECT id FROM clients WHERE id = ? AND company_id = ? LIMIT 1');
    $st->execute([$clientId, $companyId]);
    if (!$st->fetch()) {
        return 'Cliente não encontrado';
    }
    $st = $pdo->prepare('SELECT * FROM products WHERE id = ? AND company_id = ? AND is_active = 1 LIMIT 1');
    $st->execute([$productId, $companyId]);
    $product = $st->fetch(PDO::FETCH_ASSOC);
    if (!$product) {
        return 'Produto não encontrado';
    }

    $total = (float) $product['price'];
    $n = max(1, (int) $product['installments_count']);
    $desc = (string) $product['name'];
    $isMonthly = (int) $product['is_monthly'] === 1;

    if ($firstDue === '') {
        $firstDue = (new DateTimeImmutable('today'))->format('Y-m-d');
    }
    $firstDt = DateTimeImmutable::createFromFormat('Y-m-d', $firstDue) ?: new DateTimeImmutable('today');

    $chargeId = uuid_v4();
    try {
        $pdo->beginTransaction();
        $pdo->prepare(
            'INSERT INTO charges (id, company_id, client_id, product_id, description, total_amount, installments_count, payment_gateway, status)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([$chargeId, $companyId, $clientId, $productId, $desc, $total, $n, $gateway, 'pending']);

        $cents = (int) round($total * 100);
        $base = intdiv($cents, $n);
        $rem = $cents - $base * $n;
        for ($i = 1; $i <= $n; $i++) {
            $instCents = $base + ($i === $n ? $rem : 0);
            $amt = round($instCents / 100, 2);
            $due = $firstDt;
            if ($i > 1) {
                $due = $isMonthly ? $firstDt->modify('+' . ($i - 1) . ' months') : $firstDt->modify('+' . ($i - 1) . ' weeks');
            }
            $iid = uuid_v4();
            $pdo->prepare(
                'INSERT INTO installments (id, charge_id, company_id, installment_number, amount, due_date, status)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([$iid, $chargeId, $companyId, $i, $amt, $due->format('Y-m-d'), 'pending']);
        }
        $pdo->commit();
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return 'Não foi possível criar a cobrança';
    }

    return null;
}

function update_charge_for_product(
    PDO $pdo,
    string $companyId,
    string $chargeId,
    string $clientId,
    string $productId,
    string $gateway,
    string $firstDue,
): ?string {
    require_once __DIR__ . '/../api/lib/charge_helpers.php';

    if (!assert_uuid($chargeId)) {
        return 'Cobrança inválida';
    }
    $st = $pdo->prepare('SELECT * FROM charges WHERE id = ? AND company_id = ? LIMIT 1');
    $st->execute([$chargeId, $companyId]);
    $charge = $st->fetch(PDO::FETCH_ASSOC);
    if (!$charge) {
        return 'Cobrança não encontrada';
    }
    if ($gateway !== 'mercadopago' && $gateway !== 'asaas') {
        return 'Gateway inválido';
    }

    $paidCount = cobx_charge_count_paid_installments($pdo, $chargeId);
    $productChanged = $productId !== (string) ($charge['product_id'] ?? '');
    $firstDueNorm = $firstDue;
    if ($firstDueNorm === '') {
        $firstDueNorm = cobx_charge_first_due_from_installments($pdo, $chargeId);
    }
    $firstDueChanged = $firstDue !== '' && $firstDue !== cobx_charge_first_due_from_installments($pdo, $chargeId);

    if ($paidCount > 0 && ($productChanged || $firstDueChanged)) {
        return 'Com parcelas já pagas só pode alterar cliente e gateway.';
    }

    $st = $pdo->prepare('SELECT id FROM clients WHERE id = ? AND company_id = ? LIMIT 1');
    $st->execute([$clientId, $companyId]);
    if (!$st->fetch()) {
        return 'Cliente não encontrado';
    }
    $st = $pdo->prepare('SELECT * FROM products WHERE id = ? AND company_id = ? AND is_active = 1 LIMIT 1');
    $st->execute([$productId, $companyId]);
    $product = $st->fetch(PDO::FETCH_ASSOC);
    if (!$product) {
        return 'Produto não encontrado';
    }

    $total = (float) $product['price'];
    $n = max(1, (int) $product['installments_count']);
    $desc = (string) $product['name'];

    try {
        $pdo->beginTransaction();
        if ($paidCount === 0 && ($productChanged || $firstDueChanged)) {
            $pdo->prepare('DELETE FROM installments WHERE charge_id = ? AND company_id = ?')->execute([$chargeId, $companyId]);
            $fd = $firstDue !== '' ? $firstDue : (new DateTimeImmutable('today'))->format('Y-m-d');
            $firstDt = DateTimeImmutable::createFromFormat('Y-m-d', $fd) ?: new DateTimeImmutable('today');
            cobx_charge_insert_installments($pdo, $chargeId, $companyId, $product, $firstDt);
        }
        $pdo->prepare(
            'UPDATE charges SET client_id=?, product_id=?, description=?, total_amount=?, installments_count=?, payment_gateway=?, updated_at=NOW(3)
             WHERE id=? AND company_id=?'
        )->execute([$clientId, $productId, $desc, $total, $n, $gateway, $chargeId, $companyId]);
        cobx_charge_refresh_status($pdo, $chargeId, $companyId);
        $pdo->commit();
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return 'Não foi possível atualizar a cobrança';
    }

    return null;
}

function delete_charge(PDO $pdo, string $companyId, string $chargeId): ?string
{
    if (!assert_uuid($chargeId)) {
        return 'Cobrança inválida';
    }
    $st = $pdo->prepare('DELETE FROM charges WHERE id = ? AND company_id = ?');
    $st->execute([$chargeId, $companyId]);
    if ($st->rowCount() === 0) {
        return 'Cobrança não encontrada';
    }

    return null;
}

function mark_installment_paid(PDO $pdo, string $companyId, string $installmentId): ?string
{
    if (!assert_uuid($installmentId)) {
        return 'Parcela inválida';
    }
    require_once __DIR__ . '/../api/lib/gateway_notify.php';
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            'SELECT i.*, ch.payment_gateway, ch.status AS charge_status, ch.client_id
             FROM installments i
             INNER JOIN charges ch ON ch.id = i.charge_id
             WHERE i.id = ? AND i.company_id = ? LIMIT 1 FOR UPDATE'
        );
        $st->execute([$installmentId, $companyId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->rollBack();

            return 'Parcela não encontrada';
        }
        if (!in_array($row['status'], ['pending', 'overdue'], true)) {
            $pdo->rollBack();

            return 'Só é possível dar baixa em parcelas pendentes ou em atraso';
        }
        $chargeId = (string) $row['charge_id'];
        $pdo->prepare(
            'UPDATE installments SET status = \'paid\', paid_at = NOW(3), updated_at = NOW(3) WHERE id = ? AND company_id = ?'
        )->execute([$installmentId, $companyId]);

        $st = $pdo->prepare('SELECT COUNT(*) AS c FROM installments WHERE charge_id = ? AND status <> \'paid\'');
        $st->execute([$chargeId]);
        $pending = (int) ($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
        $newChargeStatus = $pending === 0 ? 'paid' : 'pending';
        $pdo->prepare('UPDATE charges SET status = ?, updated_at = NOW(3) WHERE id = ? AND company_id = ?')
            ->execute([$newChargeStatus, $chargeId, $companyId]);

        $st = $pdo->prepare('SELECT * FROM charges WHERE id = ? AND company_id = ? LIMIT 1');
        $st->execute([$chargeId, $companyId]);
        $charge = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $pdo->commit();
        cobx_notify_gateway_manual_payment($pdo, $companyId, $row, $charge);
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return 'Não foi possível atualizar a parcela';
    }

    return null;
}
