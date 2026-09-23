<?php

declare(strict_types=1);

require_once __DIR__ . '/init.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$route = app_route();

try {
    $pdo = app_pdo();
} catch (Throwable) {
    http_response_code(503);
    echo '<h1>Base de dados indisponível</h1><p>Confirme MySQL no Laragon e execute <code>scripts/install-local.ps1</code>.</p>';
    exit;
}

// URLs antigas do React/Vite (dist/ ou :8080) — redirecionar para o PHP
if ($route === 'index.html' || str_starts_with($route, 'dist')) {
    app_redirect('/login');
}

// ——— Público ———
if ($route === 'home') {
    if (app_user()) {
        app_redirect('/dashboard');
    }
    app_redirect('/login');
}

if ($route === 'login') {
    if ($method === 'POST') {
        $err = app_login((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
        if ($err === null) {
            app_redirect('/dashboard');
        }
        app_render('login', ['bare_layout' => true, 'error' => $err, 'email' => $_POST['email'] ?? ''], 'Entrar');
        exit;
    }
    if (app_user()) {
        app_redirect('/dashboard');
    }
    app_render('login', ['bare_layout' => true], 'Entrar');
    exit;
}

if ($route === 'logout') {
    app_logout();
    app_redirect('/login');
}

if ($route === 'reset-password') {
    require __DIR__ . '/handlers/reset_password.php';
    exit;
}

// ——— Autenticado ———
$user = app_require_login();

if ($route === 'dashboard') {
    require __DIR__ . '/handlers/dashboard.php';
    exit;
}

if (str_starts_with($route, 'dashboard/')) {
    $page = substr($route, strlen('dashboard/'));
    $map = [
        'clients' => 'clients.php',
        'products' => 'products.php',
        'charges' => 'charges.php',
        'installments' => 'installments.php',
        'messages' => 'messages.php',
        'settings' => 'settings.php',
        'master-settings' => 'master_settings.php',
    ];
    if (isset($map[$page])) {
        require __DIR__ . '/handlers/' . $map[$page];
        exit;
    }
}

if (str_starts_with($route, 'admin/')) {
    app_require_admin($user);
    $page = substr($route, strlen('admin/'));
    if ($page === 'companies') {
        require __DIR__ . '/handlers/admin_companies.php';
        exit;
    }
    if ($page === 'plans') {
        require __DIR__ . '/handlers/admin_plans.php';
        exit;
    }
}

http_response_code(404);
app_render('error_404', ['user' => $user], 'Página não encontrada');
