<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/request.php';
require_once __DIR__ . '/../lib/charge_helpers.php';
require_once __DIR__ . '/../lib/gateway_notify.php';
require_once __DIR__ . '/../lib/gateway_payments.php';
require_once __DIR__ . '/../lib/admin_notifications.php';
require_once __DIR__ . '/../lib/charge_audit.php';
require_once __DIR__ . '/company_mail.php';
require_once __DIR__ . '/company_whatsapp.php';
require_once __DIR__ . '/company_messages.php';
require_once __DIR__ . '/cron_reminders.php';
require_once __DIR__ . '/company_payment.php';
require_once __DIR__ . '/company_imports.php';
require_once __DIR__ . '/company_profile.php';
require_once __DIR__ . '/company_subscription.php';
require_once __DIR__ . '/public_charge.php';

/**
 * @param list<string> $seg segmentos após "company/"
 */
function handle_company(PDO $pdo, string $method, array $seg): void
{
    $ctx = require_auth_context($pdo);
    $resource = $seg[0] ?? '';
    $id = $seg[1] ?? null;
    $sub = $seg[2] ?? null;

    if (in_array('admin', $ctx['roles'], true) && $ctx['company_id'] === null) {
        if ($resource === 'overview' && $method === 'GET' && $id === null) {
            json_response(200, [
                'stats' => [
                    'clients_total' => 0,
                    'clients_change_pct' => null,
                    'charges_active' => 0,
                    'charges_active_change_pct' => null,
                    'revenue_month' => 0.0,
                    'revenue_change_pct' => null,
                    'delinquency_pct' => null,
                    'delinquency_change_pp' => null,
                ],
                'recent_charges' => [],
            ]);
        }
        json_response(403, ['error' => 'Área da empresa: inicie sessão com um utilizador dono de empresa.']);
    }
    $companyId = require_company_id($ctx);
    cobx_gateway_ensure_schema($pdo);

    if ($resource !== 'subscription' && company_subscription_is_access_blocked($pdo, $companyId)) {
        json_response(402, ['error' => 'Assinatura vencida. Renove o plano para liberar o acesso ao sistema.']);
    }

    if ($resource === 'client-categories') {
        company_client_categories($pdo, $method, $companyId, $id);
    } elseif ($resource === 'clients') {
        company_clients($pdo, $method, $companyId, $id, $sub);
    } elseif ($resource === 'products') {
        company_products($pdo, $method, $companyId, $id);
    } elseif ($resource === 'charges') {
        company_charges($pdo, $method, $companyId, $id, $sub);
    } elseif ($resource === 'installments') {
        company_installments($pdo, $method, $companyId, $id, $sub);
    } elseif ($resource === 'overview') {
        if ($method !== 'GET' || $id !== null) {
            json_response(405, ['error' => 'Método não permitido']);
        }
        company_overview($pdo, $companyId);
    } elseif ($resource === 'mail-settings') {
        $sub = $seg[1] ?? null;
        if ($sub === 'test' && $method === 'POST') {
            company_mail_settings_test($pdo, $companyId);
        } else {
            company_mail_settings($pdo, $method, $companyId);
        }
    } elseif ($resource === 'whatsapp-connection') {
        company_whatsapp_connection_dispatch($pdo, $method, $companyId, $seg);
    } elseif ($resource === 'message-settings') {
        if ($id !== null) {
            json_response(404, ['error' => 'Recurso não encontrado']);
        }
        company_message_settings($pdo, $method, $companyId);
    } elseif ($resource === 'payment-settings') {
        company_payment_settings($pdo, $method, $companyId, $id);
    } elseif ($resource === 'imports') {
        company_imports($pdo, $method, $companyId, $id);
    } elseif ($resource === 'profile') {
        if ($id !== null) {
            json_response(404, ['error' => 'Recurso não encontrado']);
        }
        company_profile($pdo, $method, $companyId);
    } elseif ($resource === 'subscription') {
        company_subscription_dispatch($pdo, $method, $companyId, array_slice($seg, 1));
    } else {
        json_response(404, ['error' => 'Recurso não encontrado']);
    }
}

function company_overview(PDO $pdo, string $companyId): void
{
    $st = $pdo->prepare('SELECT COUNT(*) AS c FROM clients WHERE company_id = ?');
    $st->execute([$companyId]);
    $clientsTotal = (int) ($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

    $st = $pdo->prepare(
        'SELECT COUNT(*) AS c FROM clients WHERE company_id = ? AND created_at >= DATE_SUB(NOW(3), INTERVAL 30 DAY)'
    );
    $st->execute([$companyId]);
    $clientsNew30 = (int) ($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

    $st = $pdo->prepare(
        'SELECT COUNT(*) AS c FROM clients WHERE company_id = ? AND created_at >= DATE_SUB(NOW(3), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(3), INTERVAL 30 DAY)'
    );
    $st->execute([$companyId]);
    $clientsNewPrev30 = (int) ($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
    $clientsChangePct = null;
    if ($clientsNewPrev30 > 0) {
        $clientsChangePct = round(100 * ($clientsNew30 - $clientsNewPrev30) / $clientsNewPrev30, 1);
    } elseif ($clientsNew30 > 0) {
        $clientsChangePct = 100.0;
    }

    $st = $pdo->prepare(
        "SELECT COUNT(*) AS c FROM charges WHERE company_id = ? AND status IN ('pending','overdue')"
    );
    $st->execute([$companyId]);
    $chargesActive = (int) ($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

    $st = $pdo->prepare(
        "SELECT COUNT(*) AS c FROM charges WHERE company_id = ? AND status IN ('pending','overdue')
         AND created_at >= DATE_SUB(NOW(3), INTERVAL 30 DAY)"
    );
    $st->execute([$companyId]);
    $chargesActiveNew30 = (int) ($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

    $st = $pdo->prepare(
        "SELECT COUNT(*) AS c FROM charges WHERE company_id = ? AND status IN ('pending','overdue')
         AND created_at >= DATE_SUB(NOW(3), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(3), INTERVAL 30 DAY)"
    );
    $st->execute([$companyId]);
    $chargesActivePrev30 = (int) ($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
    $chargesChangePct = null;
    if ($chargesActivePrev30 > 0) {
        $chargesChangePct = round(100 * ($chargesActiveNew30 - $chargesActivePrev30) / $chargesActivePrev30, 1);
    } elseif ($chargesActiveNew30 > 0) {
        $chargesChangePct = 100.0;
    }

    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(amount), 0) AS s FROM installments
         WHERE company_id = ? AND status = 'paid' AND paid_at IS NOT NULL
         AND paid_at >= DATE_FORMAT(NOW(3), '%Y-%m-01 00:00:00.000')
         AND paid_at < DATE_FORMAT(DATE_ADD(NOW(3), INTERVAL 1 MONTH), '%Y-%m-01 00:00:00.000')"
    );
    $st->execute([$companyId]);
    $revMonth = (float) ($st->fetch(PDO::FETCH_ASSOC)['s'] ?? 0);

    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(amount), 0) AS s FROM installments
         WHERE company_id = ? AND status = 'paid' AND paid_at IS NOT NULL
         AND paid_at >= DATE_FORMAT(DATE_SUB(NOW(3), INTERVAL 1 MONTH), '%Y-%m-01 00:00:00.000')
         AND paid_at < DATE_FORMAT(NOW(3), '%Y-%m-01 00:00:00.000')"
    );
    $st->execute([$companyId]);
    $revPrev = (float) ($st->fetch(PDO::FETCH_ASSOC)['s'] ?? 0);
    $revChangePct = null;
    if ($revPrev > 0.0001) {
        $revChangePct = round(100 * ($revMonth - $revPrev) / $revPrev, 1);
    } elseif ($revMonth > 0) {
        $revChangePct = 100.0;
    }

    $st = $pdo->prepare(
        "SELECT
           COALESCE(SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END), 0) AS overdue_cnt,
           COALESCE(SUM(CASE WHEN status IN ('pending','overdue','paid') THEN 1 ELSE 0 END), 0) AS open_cnt
         FROM installments WHERE company_id = ?"
    );
    $st->execute([$companyId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $openCnt = (int) ($row['open_cnt'] ?? 0);
    $overdueCnt = (int) ($row['overdue_cnt'] ?? 0);
    $delinqPct = $openCnt > 0 ? round(100 * $overdueCnt / $openCnt, 1) : null;

    $st = $pdo->prepare(
        "SELECT
           COALESCE(SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END), 0) AS overdue_cnt,
           COALESCE(SUM(CASE WHEN status IN ('pending','overdue','paid') THEN 1 ELSE 0 END), 0) AS open_cnt
         FROM installments
         WHERE company_id = ?
         AND due_date < DATE_FORMAT(NOW(3), '%Y-%m-01')"
    );
    $st->execute([$companyId]);
    $rowP = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $openPrev = (int) ($rowP['open_cnt'] ?? 0);
    $overduePrev = (int) ($rowP['overdue_cnt'] ?? 0);
    $delinqPrevPct = $openPrev > 0 ? round(100 * $overduePrev / $openPrev, 1) : null;
    $delinqChangePp = null;
    if ($delinqPct !== null && $delinqPrevPct !== null) {
        $delinqChangePp = round($delinqPct - $delinqPrevPct, 1);
    }

    $st = $pdo->prepare(
        "SELECT ch.id, ch.total_amount, ch.status, ch.created_at, cl.name AS client_name,
          (SELECT MIN(i.due_date) FROM installments i WHERE i.charge_id = ch.id AND i.status IN ('pending','overdue')) AS next_due
         FROM charges ch
         INNER JOIN clients cl ON cl.id = ch.client_id
         WHERE ch.company_id = ?
         ORDER BY ch.created_at DESC
         LIMIT 8"
    );
    $st->execute([$companyId]);
    $recent = $st->fetchAll(PDO::FETCH_ASSOC);

    json_response(200, [
        'stats' => [
            'clients_total' => $clientsTotal,
            'clients_change_pct' => $clientsChangePct,
            'charges_active' => $chargesActive,
            'charges_active_change_pct' => $chargesChangePct,
            'revenue_month' => round($revMonth, 2),
            'revenue_change_pct' => $revChangePct,
            'delinquency_pct' => $delinqPct,
            'delinquency_change_pp' => $delinqChangePp,
        ],
        'recent_charges' => $recent,
    ]);
}

function company_client_categories(PDO $pdo, string $method, string $companyId, ?string $id): void
{
    if ($method === 'GET' && $id === null) {
        $st = $pdo->prepare('SELECT id, name, created_at FROM client_categories WHERE company_id = ? ORDER BY name');
        $st->execute([$companyId]);
        json_response(200, ['items' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if ($method === 'POST' && $id === null) {
        $in = json_input();
        $name = isset($in['name']) ? trim((string) $in['name']) : '';
        if ($name === '') {
            json_response(422, ['error' => 'Nome da categoria é obrigatório']);
        }
        $cid = uuid_v4();
        $pdo->prepare('INSERT INTO client_categories (id, company_id, name) VALUES (?, ?, ?)')->execute([$cid, $companyId, $name]);
        json_response(201, ['id' => $cid, 'name' => $name]);
    }
    if ($method === 'PUT' && $id !== null) {
        company_assert_uuid($id);
        $in = json_input();
        $name = isset($in['name']) ? trim((string) $in['name']) : '';
        if ($name === '') {
            json_response(422, ['error' => 'Nome inválido']);
        }
        $st = $pdo->prepare('UPDATE client_categories SET name = ? WHERE id = ? AND company_id = ?');
        $st->execute([$name, $id, $companyId]);
        if ($st->rowCount() === 0) {
            json_response(404, ['error' => 'Categoria não encontrada']);
        }
        json_response(200, ['ok' => true]);
    }
    if ($method === 'DELETE' && $id !== null) {
        company_assert_uuid($id);
        $st = $pdo->prepare('DELETE FROM client_categories WHERE id = ? AND company_id = ?');
        $st->execute([$id, $companyId]);
        json_response(200, ['ok' => true]);
    }
    json_response(405, ['error' => 'Método não permitido']);
}

function company_clients(PDO $pdo, string $method, string $companyId, ?string $id, ?string $sub): void
{
    if ($method === 'GET' && $id !== null && $sub === 'billing') {
        company_assert_uuid($id);
        $st = $pdo->prepare('SELECT id FROM clients WHERE id = ? AND company_id = ? LIMIT 1');
        $st->execute([$id, $companyId]);
        if (!$st->fetch()) {
            json_response(404, ['error' => 'Cliente não encontrado']);
        }
        $st = $pdo->prepare(
            'SELECT ch.*, pr.name AS product_name
             FROM charges ch
             LEFT JOIN products pr ON pr.id = ch.product_id
             WHERE ch.company_id = ? AND ch.client_id = ? AND ch.status IN (\'pending\',\'overdue\')
             ORDER BY ch.created_at DESC'
        );
        $st->execute([$companyId, $id]);
        $charges = $st->fetchAll(PDO::FETCH_ASSOC);

        $st = $pdo->prepare(
            'SELECT i.id, i.charge_id, i.installment_number, i.amount, i.due_date, i.status, i.paid_at, i.external_id,
                    ch.description AS charge_description, ch.payment_gateway, ch.installments_count AS charge_installments_count
             FROM installments i
             INNER JOIN charges ch ON ch.id = i.charge_id
             WHERE i.company_id = ? AND ch.client_id = ? AND i.status IN (\'pending\',\'overdue\')
             ORDER BY i.due_date ASC, i.installment_number ASC'
        );
        $st->execute([$companyId, $id]);
        $installments = $st->fetchAll(PDO::FETCH_ASSOC);

        json_response(200, ['charges' => $charges, 'installments' => $installments]);
    }

    if ($method === 'GET' && $id === null) {
        $q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
        $cat = isset($_GET['category_id']) ? trim((string) $_GET['category_id']) : '';
        $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? min(15, max(1, (int) $_GET['limit'])) : 15;
        $offset = ($page - 1) * $limit;

        $like = $q === '' ? null : '%' . $q . '%';
        $where = 'FROM clients c
          LEFT JOIN client_categories cc ON cc.id = c.category_id
          WHERE c.company_id = ?';
        $params = [$companyId];
        if ($like !== null) {
            $where .= ' AND (c.name LIKE ? OR IFNULL(c.email,\'\') LIKE ? OR IFNULL(c.document,\'\') LIKE ? OR IFNULL(c.address_city,\'\') LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        if ($cat !== '') {
            $where .= ' AND c.category_id = ?';
            $params[] = $cat;
        }

        $st = $pdo->prepare('SELECT COUNT(*) AS c ' . $where);
        $st->execute($params);
        $total = (int) ($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

        $sql = 'SELECT c.*, cc.name AS category_name,
          (SELECT COUNT(*) FROM charges ch WHERE ch.client_id = c.id AND ch.status IN (\'pending\',\'overdue\')) AS active_charges
          ' . $where . ' ORDER BY c.name LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $items = $st->fetchAll(PDO::FETCH_ASSOC);
        $totalPages = $limit > 0 ? (int) ceil($total / $limit) : 0;
        json_response(200, [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => $totalPages,
        ]);
    }
    if ($method === 'GET' && $id !== null && ($sub ?? '') === '') {
        company_assert_uuid($id);
        $st = $pdo->prepare(
            'SELECT c.*, cc.name AS category_name,
              (SELECT COUNT(*) FROM charges ch WHERE ch.client_id = c.id AND ch.status IN (\'pending\',\'overdue\')) AS active_charges
             FROM clients c
             LEFT JOIN client_categories cc ON cc.id = c.category_id
             WHERE c.id = ? AND c.company_id = ? LIMIT 1'
        );
        $st->execute([$id, $companyId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            json_response(404, ['error' => 'Cliente não encontrado']);
        }
        json_response(200, $row);
    }
    if ($method === 'POST' && $id === null) {
        $row = company_parse_client_body($pdo, $companyId, json_input(), true);
        $cid = uuid_v4();
        $pdo->prepare(
            'INSERT INTO clients (id, company_id, category_id, name, email, phone, document,
              address_street, address_number, address_complement, address_neighborhood, address_city, address_state, address_postal_code, address_country)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $cid, $companyId, $row['category_id'], $row['name'], $row['email'], $row['phone'], $row['document'],
            $row['address_street'], $row['address_number'], $row['address_complement'], $row['address_neighborhood'],
            $row['address_city'], $row['address_state'], $row['address_postal_code'], $row['address_country'],
        ]);
        json_response(201, ['id' => $cid]);
    }
    if ($method === 'PUT' && $id !== null) {
        company_assert_uuid($id);
        $row = company_parse_client_body($pdo, $companyId, json_input(), true);
        $st = $pdo->prepare(
            'UPDATE clients SET category_id=?, name=?, email=?, phone=?, document=?,
             address_street=?, address_number=?, address_complement=?, address_neighborhood=?, address_city=?, address_state=?, address_postal_code=?, address_country=?
             WHERE id=? AND company_id=?'
        );
        $st->execute([
            $row['category_id'], $row['name'], $row['email'], $row['phone'], $row['document'],
            $row['address_street'], $row['address_number'], $row['address_complement'], $row['address_neighborhood'],
            $row['address_city'], $row['address_state'], $row['address_postal_code'], $row['address_country'],
            $id, $companyId,
        ]);
        if ($st->rowCount() === 0) {
            json_response(404, ['error' => 'Cliente não encontrado']);
        }
        json_response(200, ['ok' => true]);
    }
    json_response(405, ['error' => 'Método não permitido']);
}

/** @return array<string, mixed> */
function company_parse_client_body(PDO $pdo, string $companyId, array $in, bool $requireName): array
{
    $name = isset($in['name']) ? trim((string) $in['name']) : '';
    if ($requireName && $name === '') {
        json_response(422, ['error' => 'Nome é obrigatório']);
    }
    $cat = isset($in['category_id']) && $in['category_id'] !== '' && $in['category_id'] !== null ? (string) $in['category_id'] : null;
    if ($cat !== null) {
        $st = $pdo->prepare('SELECT 1 FROM client_categories WHERE id = ? AND company_id = ? LIMIT 1');
        $st->execute([$cat, $companyId]);
        if (!$st->fetch()) {
            json_response(422, ['error' => 'Categoria inválida']);
        }
    }
    return [
        'category_id' => $cat,
        'name' => $name,
        'email' => isset($in['email']) ? (trim((string) $in['email']) ?: null) : null,
        'phone' => isset($in['phone']) ? (trim((string) $in['phone']) ?: null) : null,
        'document' => isset($in['document']) ? (trim((string) $in['document']) ?: null) : null,
        'address_street' => isset($in['address_street']) ? (trim((string) $in['address_street']) ?: null) : null,
        'address_number' => isset($in['address_number']) ? (trim((string) $in['address_number']) ?: null) : null,
        'address_complement' => isset($in['address_complement']) ? (trim((string) $in['address_complement']) ?: null) : null,
        'address_neighborhood' => isset($in['address_neighborhood']) ? (trim((string) $in['address_neighborhood']) ?: null) : null,
        'address_city' => isset($in['address_city']) ? (trim((string) $in['address_city']) ?: null) : null,
        'address_state' => isset($in['address_state']) ? (trim((string) $in['address_state']) ?: null) : null,
        'address_postal_code' => isset($in['address_postal_code']) ? (trim((string) $in['address_postal_code']) ?: null) : null,
        'address_country' => isset($in['address_country']) ? (trim((string) $in['address_country']) ?: null) : null,
    ];
}

function company_products(PDO $pdo, string $method, string $companyId, ?string $id): void
{
    company_products_ensure_interest_schema($pdo);

    if ($method === 'GET' && $id === null) {
        $st = $pdo->prepare(
            'SELECT id, name, description, price, installments_count, is_monthly, is_recurring_monthly, is_active,
                    has_daily_interest, daily_interest_percent, created_at
             FROM products WHERE company_id = ? ORDER BY name'
        );
        $st->execute([$companyId]);
        json_response(200, ['items' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if ($method === 'GET' && $id !== null) {
        company_assert_uuid($id);
        $st = $pdo->prepare('SELECT * FROM products WHERE id = ? AND company_id = ? LIMIT 1');
        $st->execute([$id, $companyId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            json_response(404, ['error' => 'Produto não encontrado']);
        }
        json_response(200, $row);
    }
    if ($method === 'POST' && $id === null) {
        $r = company_parse_product(json_input(), true);
        $pid = uuid_v4();
        $pdo->prepare(
            'INSERT INTO products (id, company_id, name, description, price, installments_count, is_monthly, is_recurring_monthly, is_active, has_daily_interest, daily_interest_percent)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $pid, $companyId, $r['name'], $r['description'], $r['price'], $r['installments_count'],
            $r['is_monthly'], $r['is_recurring_monthly'], $r['is_active'], $r['has_daily_interest'], $r['daily_interest_percent'],
        ]);
        json_response(201, ['id' => $pid]);
    }
    if ($method === 'PUT' && $id !== null) {
        company_assert_uuid($id);
        $r = company_parse_product(json_input(), true);
        $st = $pdo->prepare(
            'UPDATE products SET name=?, description=?, price=?, installments_count=?, is_monthly=?, is_recurring_monthly=?, is_active=?,
                 has_daily_interest=?, daily_interest_percent=?
             WHERE id=? AND company_id=?'
        );
        $st->execute([
            $r['name'], $r['description'], $r['price'], $r['installments_count'], $r['is_monthly'], $r['is_recurring_monthly'], $r['is_active'],
            $r['has_daily_interest'], $r['daily_interest_percent'], $id, $companyId,
        ]);
        if ($st->rowCount() === 0) {
            json_response(404, ['error' => 'Produto não encontrado']);
        }
        json_response(200, ['ok' => true]);
    }
    if ($method === 'DELETE' && $id !== null) {
        company_assert_uuid($id);
        $pdo->prepare('DELETE FROM products WHERE id = ? AND company_id = ?')->execute([$id, $companyId]);
        json_response(200, ['ok' => true]);
    }
    json_response(405, ['error' => 'Método não permitido']);
}

function company_products_ensure_interest_schema(PDO $pdo): void
{
    if (!cobx_charge_db_column_exists($pdo, 'products', 'has_daily_interest')) {
        $pdo->exec('ALTER TABLE products ADD COLUMN has_daily_interest TINYINT(1) NOT NULL DEFAULT 0 AFTER is_recurring_monthly');
    }
    if (!cobx_charge_db_column_exists($pdo, 'products', 'daily_interest_percent')) {
        $pdo->exec('ALTER TABLE products ADD COLUMN daily_interest_percent DECIMAL(8,4) NOT NULL DEFAULT 0 AFTER has_daily_interest');
    }
}

/** @return array<string, mixed> */
function company_parse_product(array $in, bool $requireName): array
{
    $name = isset($in['name']) ? trim((string) $in['name']) : '';
    if ($requireName && $name === '') {
        json_response(422, ['error' => 'Nome do produto é obrigatório']);
    }
    $inst = isset($in['installments_count']) ? (int) $in['installments_count'] : 1;
    if ($inst < 1) {
        $inst = 1;
    }
    if ($inst > 120) {
        $inst = 120;
    }
    $recurring = !empty($in['is_recurring_monthly']);
    $price = isset($in['price']) ? (float) $in['price'] : 0;
    $instAmt = isset($in['installment_amount']) ? (float) $in['installment_amount'] : null;
    if ($recurring && $instAmt !== null && $instAmt > 0) {
        $price = round($instAmt * $inst, 2);
    }
    if ($price < 0) {
        json_response(422, ['error' => 'Preço inválido']);
    }
    if (!$recurring && $price <= 0) {
        json_response(422, ['error' => 'Preço inválido']);
    }
    if ($recurring && $price <= 0) {
        json_response(422, ['error' => 'Indique o valor da parcela (ou total) para produto recorrente']);
    }
    return [
        'name' => $name,
        'description' => isset($in['description']) ? (trim((string) $in['description']) ?: null) : null,
        'price' => round($price, 2),
        'installments_count' => $inst,
        'is_monthly' => !empty($in['is_monthly']) ? 1 : 0,
        'is_recurring_monthly' => $recurring ? 1 : 0,
        'has_daily_interest' => !empty($in['has_daily_interest']) ? 1 : 0,
        'daily_interest_percent' => !empty($in['has_daily_interest'])
            ? round(max(0, min(100, (float) ($in['daily_interest_percent'] ?? 0))), 4)
            : 0,
        'is_active' => array_key_exists('is_active', $in) ? (!empty($in['is_active']) ? 1 : 0) : 1,
    ];
}

function company_charges(PDO $pdo, string $method, string $companyId, ?string $id, ?string $sub=null): void
{
    if($method==='GET'&&$id!==null&&$sub==='audit'){
        company_assert_uuid($id);$st=$pdo->prepare('SELECT id,action,before_data,after_data,metadata,created_at FROM charge_audit_log WHERE charge_id=? AND company_id=? ORDER BY created_at DESC');$st->execute([$id,$companyId]);json_response(200,['items'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if ($method === 'GET' && $id === null) {
        $clientFilter = isset($_GET['client_id']) ? trim((string) $_GET['client_id']) : '';
        $activeOnly = isset($_GET['active_only']) && (string) $_GET['active_only'] === '1';
        $q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
        $statusFilter = isset($_GET['status']) ? trim((string) $_GET['status']) : '';
        $gatewayFilter = isset($_GET['gateway']) ? trim((string) $_GET['gateway']) : '';
        if ($clientFilter !== '') {
            company_assert_uuid($clientFilter);
        }
        $allowedStatus = ['pending', 'paid', 'overdue', 'cancelled'];
        if ($statusFilter !== '' && !in_array($statusFilter, $allowedStatus, true)) {
            $statusFilter = '';
        }
        if ($gatewayFilter !== '' && $gatewayFilter !== 'mercadopago' && $gatewayFilter !== 'asaas') {
            $gatewayFilter = '';
        }
        $sql = 'SELECT ch.*, cl.name AS client_name, pr.name AS product_name, pa.name AS payment_account_name
             FROM charges ch
             INNER JOIN clients cl ON cl.id = ch.client_id
             LEFT JOIN products pr ON pr.id = ch.product_id
             LEFT JOIN payment_accounts pa ON pa.id = ch.payment_account_id
             WHERE ch.company_id = ?';
        $params = [$companyId];
        if ($clientFilter !== '') {
            $sql .= ' AND ch.client_id = ?';
            $params[] = $clientFilter;
        }
        if ($activeOnly) {
            $sql .= " AND ch.status IN ('pending','overdue')";
        }
        if ($statusFilter !== '') {
            $sql .= ' AND ch.status = ?';
            $params[] = $statusFilter;
        }
        if ($gatewayFilter !== '') {
            $sql .= ' AND ch.payment_gateway = ?';
            $params[] = $gatewayFilter;
        }
        if ($q !== '') {
            $like = '%' . $q . '%';
            $sql .= ' AND (cl.name LIKE ? OR IFNULL(pr.name,\'\') LIKE ? OR ch.description LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $sql .= ' ORDER BY ch.created_at DESC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        json_response(200, ['items' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if ($method === 'POST' && $id === null) {
        $in = json_input();
        $clientId = isset($in['client_id']) ? (string) $in['client_id'] : '';
        $productId = isset($in['product_id']) ? (string) $in['product_id'] : '';
        $accountId = isset($in['payment_account_id']) ? (string) $in['payment_account_id'] : '';
        $method = isset($in['payment_method']) ? (string) $in['payment_method'] : 'pix';
        $firstDue = isset($in['first_due_date']) ? trim((string) $in['first_due_date']) : '';
        if ($clientId === '' || $productId === '' || $accountId === '' || !in_array($method, ['pix','boleto'], true)) {
            json_response(422, ['error' => 'client_id, product_id, payment_account_id e payment_method (pix|boleto) são obrigatórios']);
        }
        company_assert_uuid($clientId);
        company_assert_uuid($productId);
        company_assert_uuid($accountId);
        $accountSt=$pdo->prepare('SELECT provider FROM payment_accounts WHERE id=? AND company_id=? AND is_active=1 LIMIT 1'); $accountSt->execute([$accountId,$companyId]); $account=$accountSt->fetch(PDO::FETCH_ASSOC);
        if (!$account) json_response(422,['error'=>'Conta de recebimento inválida ou inativa']);
        if ($method === 'boleto' && $account['provider'] !== 'asaas') json_response(422,['error'=>'Boleto está disponível atualmente apenas para contas Asaas']);
        $gateway=(string)$account['provider'];

        $st = $pdo->prepare('SELECT id FROM clients WHERE id = ? AND company_id = ? LIMIT 1');
        $st->execute([$clientId, $companyId]);
        if (!$st->fetch()) {
            json_response(404, ['error' => 'Cliente não encontrado']);
        }
        $st = $pdo->prepare('SELECT * FROM products WHERE id = ? AND company_id = ? AND is_active = 1 LIMIT 1');
        $st->execute([$productId, $companyId]);
        $product = $st->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            json_response(404, ['error' => 'Produto não encontrado ou inativo']);
        }

        $total = (float) $product['price'];
        $n = (int) $product['installments_count'];
        if ($n < 1) {
            $n = 1;
        }
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
                'INSERT INTO charges (id, company_id, client_id, product_id, description, total_amount, installments_count, payment_gateway, payment_account_id, payment_method, status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([$chargeId, $companyId, $clientId, $productId, $desc, $total, $n, $gateway, $accountId, $method, 'pending']);

            cobx_charge_insert_installments($pdo, $chargeId, $companyId, $product, $firstDt);
            cobx_charge_sync_total_from_installments($pdo, $chargeId, $companyId);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_response(500, ['error' => 'Não foi possível criar a cobrança']);
        }
        $jobId=cobx_queue_enqueue($pdo,$companyId,'generate_charge',['charge_id'=>$chargeId]);
        cobx_charge_audit($pdo,$companyId,$chargeId,'created',null,['client_id'=>$clientId,'product_id'=>$productId,'payment_account_id'=>$accountId,'payment_method'=>$method],['job_id'=>$jobId]);
        json_response(201, ['id' => $chargeId, 'payment_generation' => ['queued'=>true,'job_id'=>$jobId]]);
    }
    if ($method === 'GET' && $id !== null) {
        company_assert_uuid($id);
        $st = $pdo->prepare(
            'SELECT ch.*, cl.name AS client_name, pr.name AS product_name
             FROM charges ch
             INNER JOIN clients cl ON cl.id = ch.client_id
             LEFT JOIN products pr ON pr.id = ch.product_id
             WHERE ch.id = ? AND ch.company_id = ? LIMIT 1'
        );
        $st->execute([$id, $companyId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            json_response(404, ['error' => 'Cobrança não encontrada']);
        }
        $row['paid_installments_count'] = cobx_charge_count_paid_installments($pdo, $id);
        $st = $pdo->prepare(
            'SELECT due_date FROM installments WHERE charge_id = ? ORDER BY installment_number ASC LIMIT 1'
        );
        $st->execute([$id]);
        $first = $st->fetch(PDO::FETCH_ASSOC);
        $row['first_due_date'] = $first['due_date'] ?? null;
        $st = $pdo->prepare(
            'SELECT id, installment_number, amount, due_date, status, paid_at, external_id, payment_url, boleto_digitable_line, boleto_pdf_url, pix_qrcode, pix_copy_paste
             FROM installments WHERE charge_id = ? AND company_id = ?
             ORDER BY installment_number ASC'
        );
        $st->execute([$id, $companyId]);
        $row['installments'] = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($row['installments'] as &$installment) {
            $installment['payer_portal_url'] = cobx_public_charge_link((string) $installment['id']);
        }
        unset($installment);
        json_response(200, $row);
    }
    if ($method === 'PUT' && $id !== null) {
        company_assert_uuid($id);
        company_charge_update($pdo, $companyId, $id, json_input());
    }
    if ($method === 'DELETE' && $id !== null) {
        company_assert_uuid($id);
        $st = $pdo->prepare('SELECT * FROM charges WHERE id = ? AND company_id = ? LIMIT 1');
        $st->execute([$id, $companyId]);
        $charge = $st->fetch(PDO::FETCH_ASSOC);
        if (!$charge) {
            json_response(404, ['error' => 'Cobrança não encontrada']);
        }
        $pdo->prepare("UPDATE charges SET status='cancelled',updated_at=NOW(3) WHERE id=? AND company_id=?")->execute([$id,$companyId]);
        $jobId=cobx_queue_enqueue($pdo,$companyId,'cancel_charge',['charge_id'=>$id]);
        cobx_charge_audit($pdo,$companyId,$id,'cancellation_queued',$charge,['status'=>'cancelled'],['job_id'=>$jobId]);
        json_response(202, ['ok'=>true,'queued'=>true,'job_id'=>$jobId]);
    }
    json_response(405, ['error' => 'Método não permitido']);
}

/** @param array<string, mixed> $in */
function company_charge_update(PDO $pdo, string $companyId, string $chargeId, array $in): void
{
    $st = $pdo->prepare('SELECT * FROM charges WHERE id = ? AND company_id = ? LIMIT 1');
    $st->execute([$chargeId, $companyId]);
    $charge = $st->fetch(PDO::FETCH_ASSOC);
    if (!$charge) {
        json_response(404, ['error' => 'Cobrança não encontrada']);
    }

    $clientId = isset($in['client_id']) ? (string) $in['client_id'] : (string) $charge['client_id'];
    $productId = isset($in['product_id']) ? (string) $in['product_id'] : (string) ($charge['product_id'] ?? '');
    $accountId = isset($in['payment_account_id']) ? (string) $in['payment_account_id'] : (string) ($charge['payment_account_id'] ?? '');
    $method = isset($in['payment_method']) ? (string) $in['payment_method'] : (string) ($charge['payment_method'] ?? 'pix');
    $firstDue = isset($in['first_due_date']) ? trim((string) $in['first_due_date']) : '';

    if ($clientId === '' || $productId === '' || $accountId === '' || !in_array($method, ['pix','boleto'],true)) {
        json_response(422, ['error' => 'client_id, product_id, payment_account_id e payment_method são obrigatórios']);
    }
    company_assert_uuid($clientId);
    company_assert_uuid($productId);
    company_assert_uuid($accountId);
    $accountSt=$pdo->prepare('SELECT provider FROM payment_accounts WHERE id=? AND company_id=? AND is_active=1'); $accountSt->execute([$accountId,$companyId]); $account=$accountSt->fetch(PDO::FETCH_ASSOC);
    if (!$account || ($method === 'boleto' && $account['provider'] !== 'asaas')) json_response(422,['error'=>'Conta ou método de pagamento inválido']);
    $gateway=(string)$account['provider'];

    $paidCount = cobx_charge_count_paid_installments($pdo, $chargeId);
    $productChanged = $productId !== (string) ($charge['product_id'] ?? '');
    $firstDueChanged = $firstDue !== '' && $firstDue !== cobx_charge_first_due_from_installments($pdo, $chargeId);

    if ($paidCount > 0 && ($productChanged || $firstDueChanged)) {
        json_response(422, ['error' => 'Com parcelas já pagas só pode alterar cliente e gateway.']);
    }

    $st = $pdo->prepare('SELECT id FROM clients WHERE id = ? AND company_id = ? LIMIT 1');
    $st->execute([$clientId, $companyId]);
    if (!$st->fetch()) {
        json_response(404, ['error' => 'Cliente não encontrado']);
    }
    $st = $pdo->prepare('SELECT * FROM products WHERE id = ? AND company_id = ? AND is_active = 1 LIMIT 1');
    $st->execute([$productId, $companyId]);
    $product = $st->fetch(PDO::FETCH_ASSOC);
    if (!$product) {
        json_response(404, ['error' => 'Produto não encontrado ou inativo']);
    }

    $total = (float) $product['price'];
    $n = max(1, (int) $product['installments_count']);
    $desc = (string) $product['name'];

    try {
        $pdo->beginTransaction();

        if ($paidCount === 0 && ($productChanged || $firstDueChanged)) {
            $pdo->prepare('DELETE FROM installments WHERE charge_id = ? AND company_id = ?')->execute([$chargeId, $companyId]);
            if ($firstDue === '') {
                $firstDue = (new DateTimeImmutable('today'))->format('Y-m-d');
            }
            $firstDt = DateTimeImmutable::createFromFormat('Y-m-d', $firstDue) ?: new DateTimeImmutable('today');
            cobx_charge_insert_installments($pdo, $chargeId, $companyId, $product, $firstDt);
            cobx_charge_sync_total_from_installments($pdo, $chargeId, $companyId);
            $total = cobx_charge_total_from_installments($pdo, $chargeId, $companyId);
        }

        $pdo->prepare(
            'UPDATE charges SET client_id=?, product_id=?, description=?, total_amount=?, installments_count=?, payment_gateway=?, payment_account_id=?, payment_method=?, updated_at=NOW(3)
             WHERE id=? AND company_id=?'
        )->execute([$clientId, $productId, $desc, $total, $n, $gateway, $accountId, $method, $chargeId, $companyId]);

        cobx_charge_refresh_status($pdo, $chargeId, $companyId);
        $pdo->commit();
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(500, ['error' => 'Não foi possível atualizar a cobrança']);
    }

    cobx_charge_audit($pdo,$companyId,$chargeId,'updated',$charge,['client_id'=>$clientId,'product_id'=>$productId,'payment_account_id'=>$accountId,'payment_method'=>$method]);
    json_response(200, ['ok' => true]);
}

function company_installments(PDO $pdo, string $method, string $companyId, ?string $id, ?string $sub): void
{
    if ($method === 'POST' && $id !== null && $sub === 'generate-payment') {
        company_assert_uuid($id);
        $st = $pdo->prepare('SELECT charge_id FROM installments WHERE id = ? AND company_id = ? LIMIT 1');
        $st->execute([$id, $companyId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            json_response(404, ['error' => 'Parcela não encontrada']);
        }
        $jobId=cobx_queue_enqueue($pdo,$companyId,'generate_charge',['charge_id'=>(string)$row['charge_id']]);
        json_response(202, ['ok'=>true,'queued'=>true,'job_id'=>$jobId]);
    }

    if ($method === 'POST' && $id !== null && $sub === 'mark-paid') {
        company_assert_uuid($id);
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare(
                'SELECT i.*, ch.payment_gateway, ch.status AS charge_status, ch.client_id
                 FROM installments i
                 INNER JOIN charges ch ON ch.id = i.charge_id
                 WHERE i.id = ? AND i.company_id = ? LIMIT 1 FOR UPDATE'
            );
            $st->execute([$id, $companyId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $pdo->rollBack();
                json_response(404, ['error' => 'Parcela não encontrada']);
            }
            if (!in_array($row['status'], ['pending', 'overdue'], true)) {
                $pdo->rollBack();
                json_response(422, ['error' => 'Só é possível dar baixa em parcelas pendentes ou em atraso']);
            }
            $chargeId = (string) $row['charge_id'];
            $pdo->prepare(
                'UPDATE installments SET status = \'paid\', paid_at = NOW(3), updated_at = NOW(3) WHERE id = ? AND company_id = ?'
            )->execute([$id, $companyId]);

            $st = $pdo->prepare(
                'SELECT COUNT(*) AS c FROM installments WHERE charge_id = ? AND status <> \'paid\''
            );
            $st->execute([$chargeId]);
            $pending = (int) ($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
            $newChargeStatus = $pending === 0 ? 'paid' : 'pending';
            $pdo->prepare('UPDATE charges SET status = ?, updated_at = NOW(3) WHERE id = ? AND company_id = ?')
                ->execute([$newChargeStatus, $chargeId, $companyId]);

            $st = $pdo->prepare('SELECT * FROM charges WHERE id = ? AND company_id = ? LIMIT 1');
            $st->execute([$chargeId, $companyId]);
            $charge = $st->fetch(PDO::FETCH_ASSOC) ?: [];

            $pdo->commit();
            cobx_charge_audit($pdo,$companyId,$chargeId,'installment_paid_manual',$charge,array_merge($charge,['status'=>$newChargeStatus]),['installment_id'=>$id]);
        } catch (Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            json_response(500, ['error' => 'Não foi possível atualizar a parcela']);
        }

        $notify = cobx_notify_gateway_manual_payment($pdo, $companyId, $row, $charge);
        admin_notify_superadmin(
            $pdo,
            'payment-manual',
            'Pagamento confirmado manualmente',
            [
                'Empresa ID: ' . $companyId,
                'Charge ID: ' . (string) ($row['charge_id'] ?? ''),
                'Parcela: #' . (string) ($row['installment_number'] ?? ''),
                'Valor: R$ ' . number_format((float) ($row['amount'] ?? 0), 2, ',', '.'),
            ]
        );
        json_response(200, [
            'ok' => true,
            'gateway_notified' => $notify['notified'],
            'gateway_detail' => $notify['detail'],
        ]);
    }

    if ($method === 'POST' && $id !== null && $sub === 'send-now') {
        company_assert_uuid($id);
        $result = cobx_reminder_send_installment_now($pdo, $companyId, $id);
        if (!$result['ok']) {
            json_response(422, [
                'error' => $result['details'][0] ?? 'Não foi possível enviar a cobrança agora.',
                'result' => $result,
            ]);
        }
        json_response(200, $result);
    }

    if ($method !== 'GET' || $id !== null) {
        json_response(405, ['error' => 'Método não permitido']);
    }

    $q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
    $status = isset($_GET['status']) ? trim((string) $_GET['status']) : '';
    $dueFrom = isset($_GET['due_from']) ? trim((string) $_GET['due_from']) : '';
    $dueTo = isset($_GET['due_to']) ? trim((string) $_GET['due_to']) : '';
    $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min(15, max(1, (int) $_GET['limit'])) : 15;
    $offset = ($page - 1) * $limit;

    $allowedStatus = ['pending', 'paid', 'overdue', 'cancelled'];
    if ($status !== '' && !in_array($status, $allowedStatus, true)) {
        json_response(422, ['error' => 'Filtro de status inválido']);
    }

    $from = 'FROM installments i
             INNER JOIN charges ch ON ch.id = i.charge_id
             INNER JOIN clients cl ON cl.id = ch.client_id
             WHERE i.company_id = ?';
    $params = [$companyId];

    if ($q !== '') {
        $like = '%' . $q . '%';
        $from .= ' AND (cl.name LIKE ? OR ch.description LIKE ?)';
        $params[] = $like;
        $params[] = $like;
    }
    if ($status !== '') {
        $from .= ' AND i.status = ?';
        $params[] = $status;
    }
    if ($dueFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueFrom)) {
        $from .= ' AND i.due_date >= ?';
        $params[] = $dueFrom;
    }
    if ($dueTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueTo)) {
        $from .= ' AND i.due_date <= ?';
        $params[] = $dueTo;
    }

    $st = $pdo->prepare('SELECT COUNT(*) AS c ' . $from);
    $st->execute($params);
    $total = (int) ($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

    $sql = 'SELECT i.id, i.charge_id, i.installment_number, i.amount, i.due_date, i.status, i.paid_at, i.created_at,
             i.external_id, i.payment_url, i.pix_qrcode, i.pix_copy_paste,
             ch.description AS charge_description, ch.installments_count AS charge_installments_count,
             cl.name AS client_name
             ' . $from . ' ORDER BY i.due_date DESC, i.installment_number ASC
             LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $items = $st->fetchAll(PDO::FETCH_ASSOC);
    $totalPages = $limit > 0 ? (int) ceil($total / $limit) : 0;
    json_response(200, [
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'total_pages' => $totalPages,
    ]);
}

function company_assert_uuid(string $id): void
{
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
        json_response(422, ['error' => 'ID inválido']);
    }
}
