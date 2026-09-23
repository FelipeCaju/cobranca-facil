<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/subscriptions.php';

/**
 * Empresas (tenants) — painel master.
 *
 * @param list<string> $seg segmentos após "admin/companies/"
 */
function handle_admin_companies(PDO $pdo, string $method, array $seg): void
{
    $id = isset($seg[0]) && $seg[0] !== '' ? (string) $seg[0] : null;

    if ($method === 'GET' && $id === null) {
        $st = $pdo->query(
            'SELECT c.id, c.name, c.email, c.phone, c.cnpj, c.is_active, c.plan_id, c.plan_renews_at, c.created_at, c.updated_at,
              u.email AS owner_email,
              IFNULL(p.full_name, \'\') AS owner_full_name,
              IFNULL(pl.name, \'\') AS plan_name,
              pl.charges_limit' . cobx_plan_duration_select($pdo, 'pl') . admin_company_subscription_select($pdo) . ',
              (SELECT COUNT(*) FROM charges ch WHERE ch.company_id = c.id) AS charges_count
             FROM companies c
             INNER JOIN users u ON u.id = c.owner_id
             LEFT JOIN profiles p ON p.user_id = c.owner_id
             LEFT JOIN plans pl ON pl.id = c.plan_id
             ' . admin_company_subscription_join($pdo) . '
             ORDER BY c.created_at DESC'
        );
        $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
        $items = [];
        foreach ($rows as $r) {
            $items[] = admin_company_list_row($r);
        }
        json_response(200, ['items' => $items]);
    }

    if ($method === 'POST' && $id === null) {
        admin_company_create($pdo);
    }

    if ($id !== null) {
        admin_companies_assert_uuid($id);
    }

    if ($method === 'GET' && $id !== null) {
        $row = admin_company_fetch_row($pdo, $id);
        if (!$row) {
            json_response(404, ['error' => 'Empresa não encontrada']);
        }
        json_response(200, $row);
    }

    if ($method === 'PUT' && $id !== null) {
        $row = admin_company_fetch_raw($pdo, $id);
        if (!$row) {
            json_response(404, ['error' => 'Empresa não encontrada']);
        }
        $in = json_input();
        $name = array_key_exists('name', $in) ? trim((string) $in['name']) : (string) ($row['name'] ?? '');
        if ($name === '') {
            json_response(422, ['error' => 'Nome da empresa é obrigatório']);
        }
        $email = array_key_exists('email', $in) ? trim((string) $in['email']) : trim((string) ($row['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_response(422, ['error' => 'Email da empresa inválido']);
        }
        $phone = array_key_exists('phone', $in) ? trim((string) $in['phone']) : trim((string) ($row['phone'] ?? ''));
        $cnpj = array_key_exists('cnpj', $in) ? trim((string) $in['cnpj']) : trim((string) ($row['cnpj'] ?? ''));
        $isActive = array_key_exists('is_active', $in) ? (!empty($in['is_active']) ? 1 : 0) : ((int) ($row['is_active'] ?? 1) === 1 ? 1 : 0);

        $planId = array_key_exists('plan_id', $in) ? trim((string) $in['plan_id']) : (string) ($row['plan_id'] ?? '');
        $planId = $planId === '' ? null : $planId;
        if ($planId !== null) {
            admin_companies_assert_uuid($planId);
            $chk = $pdo->prepare('SELECT id FROM plans WHERE id = ? LIMIT 1');
            $chk->execute([$planId]);
            if (!$chk->fetch()) {
                json_response(422, ['error' => 'Plano inválido']);
            }
        }

        $renews = null;
        if (array_key_exists('plan_renews_at', $in)) {
            $raw = trim((string) $in['plan_renews_at']);
            if ($raw === '') {
                $renews = null;
            } else {
                $dt = DateTimeImmutable::createFromFormat('Y-m-d', $raw);
                if ($dt === false) {
                    json_response(422, ['error' => 'Data de renovação inválida (use AAAA-MM-DD)']);
                }
                $renews = $dt->format('Y-m-d');
            }
        } else {
            $pr = $row['plan_renews_at'] ?? null;
            $renews = $pr !== null && $pr !== '' ? (string) $pr : null;
        }

        $ownerId = (string) ($row['owner_id'] ?? '');
        $ownerEmail = array_key_exists('owner_email', $in) ? strtolower(trim((string) $in['owner_email'])) : strtolower(trim((string) ($row['owner_email'] ?? '')));
        if ($ownerEmail === '' || !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
            json_response(422, ['error' => 'Email do dono inválido']);
        }

        $newPass = array_key_exists('owner_new_password', $in) ? (string) $in['owner_new_password'] : '';
        $newPassTrim = trim($newPass);
        if ($newPassTrim !== '' && strlen($newPassTrim) < 6) {
            json_response(422, ['error' => 'Nova palavra-passe do dono: mínimo 6 caracteres']);
        }

        if ($ownerEmail !== strtolower(trim((string) ($row['owner_email'] ?? '')))) {
            $st = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
            $st->execute([$ownerEmail, $ownerId]);
            if ($st->fetch()) {
                json_response(409, ['error' => 'Este email do dono já está em uso por outro utilizador']);
            }
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare(
                'UPDATE companies SET name=?, email=?, phone=?, cnpj=?, is_active=?, plan_id=?, plan_renews_at=?, updated_at=NOW(3) WHERE id=?'
            )->execute([
                $name,
                $email !== '' ? $email : null,
                $phone !== '' ? $phone : null,
                $cnpj !== '' ? $cnpj : null,
                $isActive,
                $planId,
                $renews,
                $id,
            ]);
            $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$ownerEmail, $ownerId]);
            $pdo->prepare('UPDATE profiles SET email = ? WHERE user_id = ?')->execute([$ownerEmail, $ownerId]);
            if ($newPassTrim !== '') {
                $hash = password_hash($newPassTrim, PASSWORD_DEFAULT);
                if ($hash === false) {
                    throw new RuntimeException('password_hash');
                }
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $ownerId]);
            }
            cobx_subscription_sync_current(
                $pdo,
                $id,
                $planId,
                $renews,
                admin_company_subscription_storage_status($planId, $renews)
            );
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_response(500, ['error' => 'Não foi possível atualizar a empresa']);
        }

        $out = admin_company_fetch_row($pdo, $id);
        json_response(200, $out ?: ['ok' => true]);
    }

    if ($method === 'POST' && $id !== null && (($seg[1] ?? '') === 'subscription')) {
        admin_company_subscription_action($pdo, $id);
    }

    if ($method === 'DELETE' && $id !== null) {
        admin_company_delete($pdo, $id);
    }

    json_response(405, ['error' => 'Método não permitido']);
}

function admin_company_create(PDO $pdo): void
{
    $in = json_input();
    $companyName = trim((string) ($in['name'] ?? ''));
    $companyEmail = trim((string) ($in['email'] ?? ''));
    $phone = trim((string) ($in['phone'] ?? ''));
    $cnpj = trim((string) ($in['cnpj'] ?? ''));
    $ownerEmail = strtolower(trim((string) ($in['owner_email'] ?? '')));
    $ownerName = trim((string) ($in['owner_full_name'] ?? ''));
    $password = trim((string) ($in['owner_password'] ?? ''));
    $isActive = array_key_exists('is_active', $in) ? (!empty($in['is_active']) ? 1 : 0) : 1;

    if ($companyName === '') {
        json_response(422, ['error' => 'Nome da empresa é obrigatório']);
    }
    if ($companyEmail !== '' && !filter_var($companyEmail, FILTER_VALIDATE_EMAIL)) {
        json_response(422, ['error' => 'Email da empresa inválido']);
    }
    if ($ownerEmail === '' || !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
        json_response(422, ['error' => 'Email do dono inválido']);
    }
    if (strlen($password) < 6) {
        json_response(422, ['error' => 'Senha do dono: mínimo 6 caracteres']);
    }

    $st = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $st->execute([$ownerEmail]);
    if ($st->fetch()) {
        json_response(409, ['error' => 'Este email do dono já está em uso por outro utilizador']);
    }

    $planId = isset($in['plan_id']) ? trim((string) $in['plan_id']) : '';
    $planId = $planId === '' ? null : $planId;
    if ($planId !== null) {
        admin_companies_assert_uuid($planId);
        $chk = $pdo->prepare('SELECT id FROM plans WHERE id = ? LIMIT 1');
        $chk->execute([$planId]);
        if (!$chk->fetch()) {
            json_response(422, ['error' => 'Plano inválido']);
        }
    }

    $renews = null;
    if (array_key_exists('plan_renews_at', $in) && trim((string) $in['plan_renews_at']) !== '') {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', trim((string) $in['plan_renews_at']));
        if ($dt === false) {
            json_response(422, ['error' => 'Data de renovação inválida (use AAAA-MM-DD)']);
        }
        $renews = $dt->format('Y-m-d');
    } elseif ($planId !== null) {
        $renews = (new DateTimeImmutable('today'))->modify('+3 days')->format('Y-m-d');
    }

    $userId = uuid_v4();
    $companyId = uuid_v4();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($hash === false) {
        json_response(500, ['error' => 'Erro ao processar senha']);
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO users (id, email, password_hash) VALUES (?, ?, ?)')
            ->execute([$userId, $ownerEmail, $hash]);
        $pdo->prepare('INSERT INTO profiles (id, user_id, full_name, email) VALUES (?, ?, ?, ?)')
            ->execute([uuid_v4(), $userId, $ownerName !== '' ? $ownerName : null, $ownerEmail]);
        $pdo->prepare('INSERT INTO user_roles (id, user_id, role) VALUES (?, ?, ?)')
            ->execute([uuid_v4(), $userId, 'company_owner']);
        $pdo->prepare(
            'INSERT INTO companies (id, owner_id, plan_id, plan_renews_at, name, email, phone, cnpj, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $companyId,
            $userId,
            $planId,
            $renews,
            $companyName,
            $companyEmail !== '' ? $companyEmail : $ownerEmail,
            $phone !== '' ? $phone : null,
            $cnpj !== '' ? $cnpj : null,
            $isActive,
        ]);
        cobx_subscription_sync_current(
            $pdo,
            $companyId,
            $planId,
            $renews,
            admin_company_subscription_storage_status($planId, $renews)
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(500, ['error' => 'Não foi possível criar a empresa']);
    }

    $out = admin_company_fetch_row($pdo, $companyId);
    json_response(201, $out ?: ['ok' => true, 'id' => $companyId]);
}

function admin_company_delete(PDO $pdo, string $companyId): void
{
    $row = admin_company_fetch_raw($pdo, $companyId);
    if (!$row) {
        json_response(404, ['error' => 'Empresa não encontrada']);
    }
    $ownerId = (string) ($row['owner_id'] ?? '');
    if ($ownerId === '') {
        json_response(500, ['error' => 'Dono da empresa não encontrado']);
    }

    $st = $pdo->prepare(
        "SELECT 1 FROM user_roles WHERE user_id = ? AND role = 'admin' LIMIT 1"
    );
    $st->execute([$ownerId]);
    if ($st->fetch()) {
        json_response(403, ['error' => 'Não é permitido eliminar contas de administrador da plataforma.']);
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(500, ['error' => 'Não foi possível eliminar a empresa e os dados associados']);
    }

    json_response(200, ['ok' => true, 'deleted_company_id' => $companyId]);
}

function admin_companies_assert_uuid(string $id): void
{
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
        json_response(422, ['error' => 'ID inválido']);
    }
}

function admin_company_subscription_select(PDO $pdo): string
{
    if (!cobx_db_table_exists($pdo, 'subscriptions')) {
        return ',
              NULL AS subscription_status,
              NULL AS subscription_period_end,
              NULL AS subscription_trial_ends_at';
    }

    return ',
              s.status AS subscription_status,
              s.current_period_end AS subscription_period_end,
              s.trial_ends_at AS subscription_trial_ends_at';
}

function admin_company_subscription_join(PDO $pdo): string
{
    if (!cobx_db_table_exists($pdo, 'subscriptions')) {
        return '';
    }

    return 'LEFT JOIN subscriptions s ON s.company_id = c.id';
}

function admin_company_subscription_storage_status(?string $planId, ?string $renews): string
{
    if ($planId === null || $planId === '') {
        return 'cancelled';
    }
    if ($renews === null || $renews === '') {
        return 'trialing';
    }

    try {
        $today = new DateTimeImmutable('today');
        $renewDate = new DateTimeImmutable($renews);
        if ($renewDate < $today) {
            return 'past_due';
        }
    } catch (Throwable $e) {
        return 'active';
    }

    return 'active';
}

/** @param array<string, mixed> $r */
function admin_company_list_row(array $r): array
{
    $charges = (int) ($r['charges_count'] ?? 0);
    $pr = $r['plan_renews_at'] ?? null;
    if (($pr === null || $pr === '') && !empty($r['plan_id'])) {
        $created = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', (string) ($r['created_at'] ?? ''))
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr((string) ($r['created_at'] ?? ''), 0, 19));
        if ($created instanceof DateTimeImmutable) {
            $pr = $created->modify('+3 days')->format('Y-m-d');
        }
    }
    $subscription = admin_company_subscription_state($r);

    return [
        'id' => (string) $r['id'],
        'name' => (string) $r['name'],
        'email' => $r['email'] !== null ? (string) $r['email'] : '',
        'phone' => $r['phone'] !== null ? (string) $r['phone'] : '',
        'cnpj' => $r['cnpj'] !== null ? (string) $r['cnpj'] : '',
        'is_active' => (int) ($r['is_active'] ?? 0) === 1,
        'plan_id' => $r['plan_id'] !== null ? (string) $r['plan_id'] : null,
        'plan_name' => (string) ($r['plan_name'] ?? ''),
        'plan_duration_months' => cobx_plan_duration_months($r),
        'plan_renews_at' => $pr !== null && $pr !== '' ? (string) $pr : null,
        'subscription_status' => $subscription['status'],
        'subscription_label' => $subscription['label'],
        'subscription_days_remaining' => $subscription['days_remaining'],
        'subscription_is_blocked' => $subscription['is_blocked'],
        'owner_email' => (string) ($r['owner_email'] ?? ''),
        'owner_full_name' => (string) ($r['owner_full_name'] ?? ''),
        'charges_count' => $charges,
        'has_used_system' => $charges > 0,
        'created_at' => $r['created_at'] ?? null,
        'updated_at' => $r['updated_at'] ?? null,
    ];
}

/** @return array<string, mixed>|null */
function admin_company_fetch_raw(PDO $pdo, string $id): ?array
{
    $st = $pdo->prepare(
        'SELECT c.*, u.email AS owner_email FROM companies c INNER JOIN users u ON u.id = c.owner_id WHERE c.id = ? LIMIT 1'
    );
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);

    return $r ?: null;
}

/** @return array<string, mixed>|null */
function admin_company_fetch_row(PDO $pdo, string $id): ?array
{
    $st = $pdo->prepare(
        'SELECT c.id, c.name, c.email, c.phone, c.cnpj, c.is_active, c.plan_id, c.plan_renews_at, c.created_at, c.updated_at,
          u.email AS owner_email,
          IFNULL(p.full_name, \'\') AS owner_full_name,
          IFNULL(pl.name, \'\') AS plan_name,
          pl.charges_limit' . cobx_plan_duration_select($pdo, 'pl') . admin_company_subscription_select($pdo) . ',
          (SELECT COUNT(*) FROM charges ch WHERE ch.company_id = c.id) AS charges_count
         FROM companies c
         INNER JOIN users u ON u.id = c.owner_id
         LEFT JOIN profiles p ON p.user_id = c.owner_id
         LEFT JOIN plans pl ON pl.id = c.plan_id
         ' . admin_company_subscription_join($pdo) . '
         WHERE c.id = ? LIMIT 1'
    );
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);

    return $r ? admin_company_list_row($r) : null;
}

/** @param array<string, mixed> $r */
function admin_company_subscription_state(array $r): array
{
    $planId = $r['plan_id'] ?? null;
    $renews = $r['plan_renews_at'] ?? null;
    $isActive = (int) ($r['is_active'] ?? 0) === 1;

    if ($planId === null || $planId === '') {
        return [
            'status' => 'no_plan',
            'label' => 'Sem plano',
            'days_remaining' => null,
            'is_blocked' => true,
        ];
    }

    if (!$isActive) {
        return [
            'status' => 'inactive',
            'label' => 'Inativa',
            'days_remaining' => null,
            'is_blocked' => true,
        ];
    }

    if ($renews === null || $renews === '') {
        $created = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', (string) ($r['created_at'] ?? ''))
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr((string) ($r['created_at'] ?? ''), 0, 19));
        if ($created instanceof DateTimeImmutable) {
            $renews = $created->modify('+3 days')->format('Y-m-d');
        } else {
            return [
                'status' => 'trialing',
                'label' => 'Teste',
                'days_remaining' => null,
                'is_blocked' => false,
            ];
        }
    }

    try {
        $today = new DateTimeImmutable('today');
        $renewDate = new DateTimeImmutable((string) $renews);
        $days = (int) $today->diff($renewDate)->format('%r%a');
    } catch (Throwable $e) {
        $days = null;
    }

    if ($days !== null && $days < 0) {
        return [
            'status' => 'past_due',
            'label' => 'Vencida',
            'days_remaining' => 0,
            'is_blocked' => true,
        ];
    }

    if ($days !== null && $days <= 3) {
        return [
            'status' => 'renewing_soon',
            'label' => 'Renovar em breve',
            'days_remaining' => $days,
            'is_blocked' => false,
        ];
    }

    return [
        'status' => 'active',
        'label' => 'Ativa',
        'days_remaining' => $days,
        'is_blocked' => false,
    ];
}

function admin_company_subscription_action(PDO $pdo, string $companyId): void
{
    $row = admin_company_fetch_raw($pdo, $companyId);
    if (!$row) {
        json_response(404, ['error' => 'Empresa não encontrada']);
    }

    $in = json_input();
    $action = trim((string) ($in['action'] ?? ''));
    if (!in_array($action, ['renew', 'trial', 'block'], true)) {
        json_response(422, ['error' => 'Ação inválida']);
    }

    $planId = $row['plan_id'] !== null && $row['plan_id'] !== '' ? (string) $row['plan_id'] : null;
    if (($action === 'renew' || $action === 'trial') && $planId === null) {
        json_response(422, ['error' => 'Defina um plano antes de liberar acesso']);
    }

    $renews = null;
    $status = 'past_due';
    if ($action === 'renew') {
        $months = 1;
        if ($planId !== null) {
            $st = $pdo->prepare(
                'SELECT id, charges_limit' . cobx_plan_duration_select($pdo, '') . ' FROM plans WHERE id = ? LIMIT 1'
            );
            $st->execute([$planId]);
            $plan = $st->fetch(PDO::FETCH_ASSOC);
            if (!$plan) {
                json_response(422, ['error' => 'Plano inválido']);
            }
            $months = cobx_plan_duration_months($plan);
        }

        $today = new DateTimeImmutable('today');
        $current = null;
        if (!empty($row['plan_renews_at'])) {
            try {
                $current = new DateTimeImmutable((string) $row['plan_renews_at']);
            } catch (Throwable $e) {
                $current = null;
            }
        }
        $base = $current !== null && $current > $today ? $current : $today;
        $renews = $base->modify('+' . $months . ' months')->format('Y-m-d');
        $status = 'active';
    } elseif ($action === 'trial') {
        $renews = (new DateTimeImmutable('today'))->modify('+3 days')->format('Y-m-d');
        $status = 'trialing';
    } else {
        $renews = (new DateTimeImmutable('today'))->modify('-1 day')->format('Y-m-d');
        $status = 'past_due';
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE companies SET plan_renews_at = ?, is_active = 1, updated_at = NOW(3) WHERE id = ?')
            ->execute([$renews, $companyId]);
        cobx_subscription_sync_current($pdo, $companyId, $planId, $renews, $status);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(500, ['error' => 'Não foi possível atualizar a assinatura']);
    }

    $out = admin_company_fetch_row($pdo, $companyId);
    json_response(200, $out ?: ['ok' => true]);
}
