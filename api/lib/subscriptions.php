<?php

declare(strict_types=1);

function cobx_db_column_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $st = $pdo->prepare('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '` LIKE ?');
        $st->execute([$column]);
        $cache[$key] = (bool) $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

function cobx_db_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $st = $pdo->prepare('SHOW TABLES LIKE ?');
        $st->execute([$table]);
        $cache[$table] = (bool) $st->fetch(PDO::FETCH_NUM);
    } catch (Throwable) {
        $cache[$table] = false;
    }

    return $cache[$table];
}

function cobx_plan_duration_select(PDO $pdo, string $alias = 'p'): string
{
    if (!cobx_db_column_exists($pdo, 'plans', 'duration_months')) {
        return '';
    }
    $prefix = $alias !== '' ? $alias . '.' : '';
    return ', ' . $prefix . 'duration_months';
}

/** @param array<string, mixed> $row */
function cobx_plan_duration_months(array $row): int
{
    $raw = isset($row['duration_months']) && is_numeric($row['duration_months'])
        ? (int) $row['duration_months']
        : (int) ($row['charges_limit'] ?? 1);

    if ($raw > 60) {
        if ($raw <= 100) {
            return 1;
        }
        if ($raw <= 1000) {
            return 2;
        }
        return 12;
    }

    return max(1, min(60, $raw));
}

function cobx_plan_update_duration(PDO $pdo, string $planId, int $months): void
{
    $months = max(1, min(60, $months));
    if (cobx_db_column_exists($pdo, 'plans', 'duration_months')) {
        $st = $pdo->prepare('UPDATE plans SET duration_months = ?, updated_at = NOW(3) WHERE id = ?');
        $st->execute([$months, $planId]);
    }
}

function cobx_subscription_sync_current(PDO $pdo, string $companyId, ?string $planId, ?string $periodEnd, string $status): void
{
    if (!cobx_db_table_exists($pdo, 'subscriptions')) {
        return;
    }

    $st = $pdo->prepare('SELECT id FROM subscriptions WHERE company_id = ? LIMIT 1');
    $st->execute([$companyId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $start = (new DateTimeImmutable('today'))->format('Y-m-d');

    if ($row) {
        $pdo->prepare(
            'UPDATE subscriptions SET plan_id=?, status=?, current_period_start=?, current_period_end=?, updated_at=NOW(3) WHERE company_id=?'
        )->execute([$planId, $status, $start, $periodEnd, $companyId]);
        return;
    }

    $pdo->prepare(
        'INSERT INTO subscriptions (id, company_id, plan_id, status, current_period_start, current_period_end, trial_ends_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([uuid_v4(), $companyId, $planId, $status, $start, $periodEnd, $status === 'trialing' ? $periodEnd : null]);
}

/**
 * @param array<string, mixed> $payment
 */
function cobx_subscription_record_payment(PDO $pdo, string $companyId, ?string $planId, string $gateway, array $payment, string $status): void
{
    if (!cobx_db_table_exists($pdo, 'subscription_payments')) {
        return;
    }

    $subscriptionId = null;
    if (cobx_db_table_exists($pdo, 'subscriptions')) {
        $st = $pdo->prepare('SELECT id FROM subscriptions WHERE company_id = ? LIMIT 1');
        $st->execute([$companyId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $subscriptionId = $row ? (string) $row['id'] : null;
    }

    $externalId = trim((string) ($payment['id'] ?? ''));
    $externalReference = trim((string) ($payment['external_reference'] ?? ''));
    $amount = round((float) ($payment['transaction_amount'] ?? $payment['amount'] ?? 0), 2);
    $paidAt = null;
    $paidRaw = trim((string) ($payment['date_approved'] ?? $payment['money_release_date'] ?? ''));
    if ($paidRaw !== '') {
        try {
            $paidAt = (new DateTimeImmutable($paidRaw))->format('Y-m-d H:i:s.v');
        } catch (Throwable) {
            $paidAt = null;
        }
    }
    $raw = json_encode($payment, JSON_UNESCAPED_UNICODE);

    $pdo->prepare(
        'INSERT INTO subscription_payments (id, subscription_id, company_id, plan_id, gateway, external_id, external_reference, status, amount, paid_at, raw_payload)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE subscription_id=VALUES(subscription_id), plan_id=VALUES(plan_id), status=VALUES(status),
           amount=VALUES(amount), paid_at=VALUES(paid_at), raw_payload=VALUES(raw_payload), updated_at=NOW(3)'
    )->execute([
        uuid_v4(),
        $subscriptionId,
        $companyId,
        $planId,
        $gateway,
        $externalId !== '' ? $externalId : null,
        $externalReference !== '' ? $externalReference : null,
        $status,
        $amount,
        $paidAt,
        $raw !== false ? $raw : null,
    ]);
}

function cobx_subscription_payment_is_processed(PDO $pdo, string $gateway, string $externalId): bool
{
    if ($externalId === '' || !cobx_db_table_exists($pdo, 'subscription_payments')) {
        return false;
    }

    $st = $pdo->prepare(
        "SELECT id FROM subscription_payments WHERE gateway = ? AND external_id = ? AND status IN ('approved', 'authorized') LIMIT 1"
    );
    $st->execute([$gateway, $externalId]);

    return (bool) $st->fetch(PDO::FETCH_ASSOC);
}
