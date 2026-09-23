<?php

declare(strict_types=1);

/** Métricas globais da plataforma (super admin). */
function admin_platform_overview(PDO $pdo): void
{
    $st = $pdo->query('SELECT COUNT(*) AS c FROM companies');
    $companiesTotal = (int) ($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

    $st = $pdo->query('SELECT COUNT(*) AS c FROM companies WHERE is_active = 1');
    $companiesActive = (int) ($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

    $st = $pdo->query(
        'SELECT COUNT(DISTINCT company_id) AS c FROM charges'
    );
    $companiesWithCharges = (int) ($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

    $st = $pdo->query("SELECT COUNT(*) AS c FROM user_roles WHERE role = 'company_owner'");
    $ownersTotal = (int) ($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

    $st = $pdo->query('SELECT COUNT(*) AS c FROM plans WHERE is_active = 1');
    $plansActive = (int) ($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

    $st = $pdo->query('SELECT COUNT(*) AS c FROM clients');
    $endClientsTotal = (int) ($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

    $st = $pdo->query("SELECT COUNT(*) AS c FROM charges WHERE status IN ('pending','overdue')");
    $chargesActive = (int) ($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

    $st = $pdo->query('SELECT COUNT(*) AS c FROM charges');
    $chargesTotal = (int) ($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

    $st = $pdo->query(
        "SELECT COALESCE(SUM(amount), 0) AS s FROM installments
         WHERE status = 'paid' AND paid_at IS NOT NULL
         AND paid_at >= DATE_FORMAT(NOW(3), '%Y-%m-01 00:00:00.000')
         AND paid_at < DATE_FORMAT(DATE_ADD(NOW(3), INTERVAL 1 MONTH), '%Y-%m-01 00:00:00.000')"
    );
    $revenueMonth = (float) ($st->fetch(PDO::FETCH_ASSOC)['s'] ?? 0);

    $st = $pdo->query(
        "SELECT COALESCE(SUM(amount), 0) AS s FROM installments
         WHERE status = 'paid' AND paid_at IS NOT NULL
         AND paid_at >= DATE_FORMAT(DATE_SUB(NOW(3), INTERVAL 1 MONTH), '%Y-%m-01 00:00:00.000')
         AND paid_at < DATE_FORMAT(NOW(3), '%Y-%m-01 00:00:00.000')"
    );
    $revenuePrev = (float) ($st->fetch(PDO::FETCH_ASSOC)['s'] ?? 0);
    $revenueChangePct = null;
    if ($revenuePrev > 0.0001) {
        $revenueChangePct = round(100 * ($revenueMonth - $revenuePrev) / $revenuePrev, 1);
    } elseif ($revenueMonth > 0) {
        $revenueChangePct = 100.0;
    }

    $st = $pdo->query(
        'SELECT COUNT(*) AS c FROM companies WHERE created_at >= DATE_SUB(NOW(3), INTERVAL 30 DAY)'
    );
    $companiesNew30 = (int) ($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

    $st = $pdo->query(
        'SELECT COUNT(*) AS c FROM companies
         WHERE created_at >= DATE_SUB(NOW(3), INTERVAL 60 DAY)
         AND created_at < DATE_SUB(NOW(3), INTERVAL 30 DAY)'
    );
    $companiesNewPrev30 = (int) ($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
    $companiesChangePct = null;
    if ($companiesNewPrev30 > 0) {
        $companiesChangePct = round(100 * ($companiesNew30 - $companiesNewPrev30) / $companiesNewPrev30, 1);
    } elseif ($companiesNew30 > 0) {
        $companiesChangePct = 100.0;
    }

    $st = $pdo->query(
        'SELECT c.id, c.name, c.email, c.is_active, c.created_at,
          IFNULL(pl.name, \'\') AS plan_name,
          u.email AS owner_email,
          (SELECT COUNT(*) FROM charges ch WHERE ch.company_id = c.id) AS charges_count
         FROM companies c
         INNER JOIN users u ON u.id = c.owner_id
         LEFT JOIN plans pl ON pl.id = c.plan_id
         ORDER BY c.created_at DESC
         LIMIT 10'
    );
    $recent = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];

    json_response(200, [
        'stats' => [
            'companies_total' => $companiesTotal,
            'companies_active' => $companiesActive,
            'companies_with_charges' => $companiesWithCharges,
            'companies_change_pct' => $companiesChangePct,
            'owners_total' => $ownersTotal,
            'plans_active' => $plansActive,
            'end_clients_total' => $endClientsTotal,
            'charges_active' => $chargesActive,
            'charges_total' => $chargesTotal,
            'revenue_month' => $revenueMonth,
            'revenue_change_pct' => $revenueChangePct,
        ],
        'recent_companies' => array_map(static function (array $r): array {
            return [
                'id' => (string) $r['id'],
                'name' => (string) $r['name'],
                'email' => (string) ($r['email'] ?? ''),
                'owner_email' => (string) ($r['owner_email'] ?? ''),
                'plan_name' => (string) ($r['plan_name'] ?? ''),
                'is_active' => (int) ($r['is_active'] ?? 0) === 1,
                'charges_count' => (int) ($r['charges_count'] ?? 0),
                'created_at' => (string) ($r['created_at'] ?? ''),
            ];
        }, $recent),
    ]);
}
