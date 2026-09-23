<?php

declare(strict_types=1);

function cobx_charge_db_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $st->execute([$table, $column]);
        return (int) $st->fetchColumn() > 0;
    } catch (Throwable) {
        return false;
    }
}

function cobx_charge_count_paid_installments(PDO $pdo, string $chargeId): int
{
    $st = $pdo->prepare("SELECT COUNT(*) AS c FROM installments WHERE charge_id = ? AND status = 'paid'");
    $st->execute([$chargeId]);

    return (int) ($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
}

/** @param array<string, mixed> $product */
function cobx_charge_insert_installments(
    PDO $pdo,
    string $chargeId,
    string $companyId,
    array $product,
    DateTimeImmutable $firstDt,
): void {
    $total = (float) $product['price'];
    $n = max(1, (int) $product['installments_count']);
    $isMonthly = (int) ($product['is_monthly'] ?? 0) === 1;
    $hasDailyInterest = (int) ($product['has_daily_interest'] ?? 0) === 1;
    $dailyInterestPercent = max(0.0, (float) ($product['daily_interest_percent'] ?? 0));

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
        if ($hasDailyInterest && $dailyInterestPercent > 0) {
            $today = new DateTimeImmutable('today');
            if ($due < $today) {
                $daysLate = (int) $due->diff($today)->format('%a');
                $amt = round($amt * (1 + ($dailyInterestPercent / 100) * $daysLate), 2);
            }
        }
        $iid = uuid_v4();
        $pdo->prepare(
            'INSERT INTO installments (id, charge_id, company_id, installment_number, amount, due_date, status)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([$iid, $chargeId, $companyId, $i, $amt, $due->format('Y-m-d'), 'pending']);
    }
}

function cobx_charge_refresh_status(PDO $pdo, string $chargeId, string $companyId): void
{
    $st = $pdo->prepare("SELECT COUNT(*) AS c FROM installments WHERE charge_id = ? AND status <> 'paid'");
    $st->execute([$chargeId]);
    $pending = (int) ($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
    $st = $pdo->prepare("SELECT COUNT(*) AS c FROM installments WHERE charge_id = ? AND status = 'overdue'");
    $st->execute([$chargeId]);
    $overdue = (int) ($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

    if ($pending === 0) {
        $status = 'paid';
    } elseif ($overdue > 0) {
        $status = 'overdue';
    } else {
        $status = 'pending';
    }
    $pdo->prepare('UPDATE charges SET status = ?, updated_at = NOW(3) WHERE id = ? AND company_id = ?')
        ->execute([$status, $chargeId, $companyId]);
}

function cobx_charge_total_from_installments(PDO $pdo, string $chargeId, string $companyId): float
{
    $st = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) AS total FROM installments WHERE charge_id = ? AND company_id = ?');
    $st->execute([$chargeId, $companyId]);

    return round((float) ($st->fetch(PDO::FETCH_ASSOC)['total'] ?? 0), 2);
}

function cobx_charge_sync_total_from_installments(PDO $pdo, string $chargeId, string $companyId): void
{
    $pdo->prepare('UPDATE charges SET total_amount = ?, updated_at = NOW(3) WHERE id = ? AND company_id = ?')
        ->execute([cobx_charge_total_from_installments($pdo, $chargeId, $companyId), $chargeId, $companyId]);
}

function cobx_charge_first_due_from_installments(PDO $pdo, string $chargeId): string
{
    $st = $pdo->prepare(
        'SELECT due_date FROM installments WHERE charge_id = ? ORDER BY installment_number ASC LIMIT 1'
    );
    $st->execute([$chargeId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['due_date'])) {
        return (string) $row['due_date'];
    }

    return (new DateTimeImmutable('today'))->format('Y-m-d');
}
