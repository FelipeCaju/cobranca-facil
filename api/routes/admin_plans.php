<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/subscriptions.php';

const COBX_UNLIMITED_CHARGES = 999999999;

/**
 * @param list<string> $seg segmentos após "admin/plans/"
 */
function handle_admin_plans(PDO $pdo, string $method, array $seg): void
{
    $id = isset($seg[0]) && $seg[0] !== '' ? (string) $seg[0] : null;

    if ($method === 'GET' && $id === null) {
        $st = $pdo->query(
            'SELECT p.id, p.name, p.price, p.charges_limit, p.users_limit' . cobx_plan_duration_select($pdo, 'p') . ', p.is_active, p.created_at, p.updated_at,
              (SELECT COUNT(*) FROM companies c WHERE c.plan_id = p.id) AS companies_count
             FROM plans p
             ORDER BY p.price ASC, p.name ASC'
        );
        $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
        $items = [];
        foreach ($rows as $r) {
            $items[] = admin_plans_row($r);
        }
        json_response(200, ['items' => $items]);
    }

    if ($method === 'POST' && $id === null) {
        $in = json_input();
        foreach (['name', 'price'] as $req) {
            if (!array_key_exists($req, $in)) {
                json_response(422, ['error' => 'Campos obrigatorios: name, price, duration_months']);
            }
        }
        if (!array_key_exists('duration_months', $in) && !array_key_exists('charges_limit', $in)) {
            json_response(422, ['error' => 'Campo obrigatorio: duration_months']);
        }
        $parsed = admin_plans_parse_input($in, true);
        $newId = uuid_v4();
        $pdo->prepare(
            'INSERT INTO plans (id, name, price, charges_limit, users_limit, is_active) VALUES (?,?,?,?,?,?)'
        )->execute([$newId, $parsed['name'], $parsed['price'], $parsed['charges_limit'], $parsed['users_limit'], $parsed['is_active']]);
        cobx_plan_update_duration($pdo, $newId, $parsed['duration_months']);
        json_response(201, admin_plans_fetch_one($pdo, $newId));
    }

    if ($id !== null) {
        admin_plans_assert_uuid($id);
    }

    if ($method === 'PUT' && $id !== null) {
        $st = $pdo->prepare('SELECT * FROM plans WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $cur = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cur) {
            json_response(404, ['error' => 'Plano não encontrado']);
        }
        $in = json_input();
        $parsed = admin_plans_parse_input($in, false, $cur);
        $pdo->prepare(
            'UPDATE plans SET name=?, price=?, charges_limit=?, users_limit=?, is_active=? WHERE id=?'
        )->execute([
            $parsed['name'],
            $parsed['price'],
            $parsed['charges_limit'],
            $parsed['users_limit'],
            $parsed['is_active'],
            $id,
        ]);
        cobx_plan_update_duration($pdo, $id, $parsed['duration_months']);
        json_response(200, admin_plans_fetch_one($pdo, $id));
    }

    if ($method === 'DELETE' && $id !== null) {
        $st = $pdo->prepare('SELECT id FROM plans WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        if (!$st->fetch()) {
            json_response(404, ['error' => 'Plano não encontrado']);
        }
        $pdo->prepare('DELETE FROM plans WHERE id = ?')->execute([$id]);
        json_response(200, ['ok' => true]);
    }

    json_response(405, ['error' => 'Método não permitido']);
}

function admin_plans_assert_uuid(string $id): void
{
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
        json_response(422, ['error' => 'ID inválido']);
    }
}

/** @param array<string, mixed> $r */
function admin_plans_row(array $r): array
{
    return [
        'id' => (string) $r['id'],
        'name' => (string) $r['name'],
        'price' => round((float) ($r['price'] ?? 0), 2),
        'charges_limit' => (int) ($r['charges_limit'] ?? 0),
        'users_limit' => (int) ($r['users_limit'] ?? 0),
        'duration_months' => cobx_plan_duration_months($r),
        'is_active' => (int) ($r['is_active'] ?? 0) === 1 ? 1 : 0,
        'companies_count' => (int) ($r['companies_count'] ?? 0),
        'created_at' => $r['created_at'] ?? null,
        'updated_at' => $r['updated_at'] ?? null,
    ];
}

function admin_plans_fetch_one(PDO $pdo, string $id): array
{
    $st = $pdo->prepare(
        'SELECT p.id, p.name, p.price, p.charges_limit, p.users_limit' . cobx_plan_duration_select($pdo, 'p') . ', p.is_active, p.created_at, p.updated_at,
          (SELECT COUNT(*) FROM companies c WHERE c.plan_id = p.id) AS companies_count
         FROM plans p WHERE p.id = ? LIMIT 1'
    );
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        return [];
    }
    return admin_plans_row($r);
}

/**
 * @param array<string, mixed> $in
 * @param array<string, mixed>|null $cur registo atual (merge em PUT)
 * @return array{name: string, price: float, duration_months: int, charges_limit: int, users_limit: int, is_active: int}
 */
function admin_plans_parse_input(array $in, bool $requireAll, ?array $cur = null): array
{
    $name = array_key_exists('name', $in) ? trim((string) $in['name']) : '';
    if ($name === '' && !$requireAll && $cur !== null) {
        $name = trim((string) ($cur['name'] ?? ''));
    }
    if ($requireAll && trim((string) ($name ?? '')) === '') {
        json_response(422, ['error' => 'Nome do plano é obrigatório']);
    }
    if ($name === '') {
        json_response(422, ['error' => 'Nome inválido']);
    }

    $priceRaw = array_key_exists('price', $in) ? $in['price'] : ($cur['price'] ?? 0);
    $price = is_numeric($priceRaw) ? (float) $priceRaw : -1;
    if ($price < 0 || $price > 99999999.99) {
        json_response(422, ['error' => 'Preço inválido']);
    }

    $monthsRaw = array_key_exists('duration_months', $in)
        ? $in['duration_months']
        : (array_key_exists('charges_limit', $in) ? $in['charges_limit'] : ($cur['duration_months'] ?? $cur['charges_limit'] ?? 0));
    $months = is_numeric($monthsRaw) ? (int) $monthsRaw : -1;
    if ($months < 1 || $months > 60) {
        json_response(422, ['error' => 'Quantidade de meses inválida (1 a 60)']);
    }

    $active = array_key_exists('is_active', $in) ? !empty($in['is_active']) : (($cur['is_active'] ?? 1) == 1);
    $isActive = $active ? 1 : 0;

    return [
        'name' => $name,
        'price' => round($price, 2),
        'duration_months' => $months,
        'charges_limit' => COBX_UNLIMITED_CHARGES,
        'users_limit' => 1,
        'is_active' => $isActive,
    ];
}
