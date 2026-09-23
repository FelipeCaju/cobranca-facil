<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/subscriptions.php';

function handle_public_plans(PDO $pdo, string $method): void
{
    if ($method !== 'GET') {
        json_response(405, ['error' => 'Método não permitido']);
    }

    $st = $pdo->query(
        'SELECT id, name, price, charges_limit, users_limit' . cobx_plan_duration_select($pdo, '') . '
         FROM plans
         WHERE is_active = 1
         ORDER BY price ASC, name ASC'
    );
    $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    $items = [];
    foreach ($rows as $r) {
        $price = round((float) ($r['price'] ?? 0), 2);
        $items[] = [
            'id' => (string) $r['id'],
            'name' => (string) $r['name'],
            'price' => $price,
            'price_label' => 'R$ ' . number_format($price, 2, ',', '.'),
            'charges_limit' => (int) ($r['charges_limit'] ?? 0),
            'users_limit' => (int) ($r['users_limit'] ?? 0),
            'duration_months' => cobx_plan_duration_months($r),
        ];
    }

    json_response(200, ['items' => $items]);
}
