<?php

declare(strict_types=1);

require_once __DIR__ . '/../api/routes/auth.php';

/** @return array<string, mixed>|null */
function app_user(): ?array
{
    $uid = $_SESSION['user_id'] ?? null;
    if (!is_string($uid) || $uid === '') {
        return null;
    }
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = auth_user_payload(app_pdo(), $uid);

    return $cache;
}

function app_login(string $email, string $password): ?string
{
    $email = strtolower(trim($email));
    if ($email === '' || $password === '') {
        return 'Email e senha são obrigatórios';
    }
    $st = app_pdo()->prepare('SELECT id, email, password_hash FROM users WHERE email = ? LIMIT 1');
    $st->execute([$email]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u || !password_verify($password, (string) $u['password_hash'])) {
        return 'Email ou senha incorretos';
    }
    $_SESSION['user_id'] = (string) $u['id'];

    return null;
}

function app_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
    }
    session_destroy();
}

function app_require_login(): array
{
    $user = app_user();
    if ($user === null) {
        app_redirect('/login');
    }

    return $user;
}

function app_require_company(array $user): string
{
    $cid = $user['company_id'] ?? null;
    if (!is_string($cid) || $cid === '') {
        flash_set('error', 'Área da empresa: inicie sessão com um utilizador dono de empresa.');
        app_redirect('/dashboard');
    }

    return $cid;
}

function app_is_admin(array $user): bool
{
    $roles = $user['roles'] ?? [];

    return in_array('admin', $roles, true);
}

function app_platform_only(array $user): bool
{
    return app_is_admin($user) && (($user['company_id'] ?? null) === null || $user['company_id'] === '');
}

/** @return list<array{label: string, href: string}> */
function app_menu_items(?array $user): array
{
    if ($user === null) {
        return [];
    }
    if (app_platform_only($user)) {
        return [
            ['label' => 'Consola', 'href' => '/dashboard'],
            ['label' => 'Config. master', 'href' => '/dashboard/master-settings'],
            ['label' => 'Empresas', 'href' => '/admin/companies'],
            ['label' => 'Planos', 'href' => '/admin/plans'],
        ];
    }
    $items = [
        ['label' => 'Visão geral', 'href' => '/dashboard'],
        ['label' => 'Clientes', 'href' => '/dashboard/clients'],
        ['label' => 'Produtos', 'href' => '/dashboard/products'],
        ['label' => 'Cobranças', 'href' => '/dashboard/charges'],
        ['label' => 'Parcelas', 'href' => '/dashboard/installments'],
        ['label' => 'Mensagens', 'href' => '/dashboard/messages'],
        ['label' => 'Configurações', 'href' => '/dashboard/settings'],
    ];
    if (app_is_admin($user)) {
        $items[] = ['label' => 'Config. master', 'href' => '/dashboard/master-settings'];
        $items[] = ['label' => 'Empresas (SaaS)', 'href' => '/admin/companies'];
        $items[] = ['label' => 'Planos (SaaS)', 'href' => '/admin/plans'];
    }

    return $items;
}

function app_require_admin(array $user): void
{
    if (!app_is_admin($user)) {
        flash_set('error', 'Apenas super administrador.');
        app_redirect('/dashboard');
    }
}
