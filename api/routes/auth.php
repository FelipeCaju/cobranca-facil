<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/request.php';
require_once __DIR__ . '/../lib/admin_notifications.php';
require_once __DIR__ . '/../lib/phone.php';
require_once __DIR__ . '/../lib/welcome_notifications.php';
require_once __DIR__ . '/../lib/subscriptions.php';
require_once __DIR__ . '/../lib/mail.php';
require_once __DIR__ . '/../lib/system_branding.php';

/**
 * @param array{method: string, segments: list<string>} $route
 */
function handle_auth(PDO $pdo, array $route): void
{
    $method = $route['method'];
    $seg = $route['segments'];
    $action = $seg[1] ?? '';

    if ($method === 'POST' && $action === 'register') {
        auth_register($pdo);
    }
    if ($method === 'POST' && $action === 'login') {
        auth_login($pdo);
    }
    if ($method === 'GET' && $action === 'me') {
        auth_me($pdo);
    }
    if ($method === 'POST' && $action === 'forgot-password') {
        auth_forgot_password($pdo);
    }
    if ($method === 'POST' && $action === 'reset-password') {
        auth_reset_password($pdo);
    }
    if ($method === 'POST' && $action === 'change-password') {
        auth_change_password($pdo);
    }

    json_response(404, ['error' => 'Rota não encontrada']);
}

function auth_register(PDO $pdo): void
{
    $in = json_input();
    $email = isset($in['email']) ? strtolower(trim((string) $in['email'])) : '';
    $password = isset($in['password']) ? (string) $in['password'] : '';
    $fullName = isset($in['full_name']) ? trim((string) $in['full_name']) : '';
    $companyName = isset($in['company_name']) ? trim((string) $in['company_name']) : '';
    $phone = cobx_normalize_phone((string) ($in['phone'] ?? $in['whatsapp'] ?? ''));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(422, ['error' => 'Email inválido']);
    }
    if (strlen($password) < 6) {
        json_response(422, ['error' => 'Senha deve ter pelo menos 6 caracteres']);
    }
    if ($companyName === '') {
        json_response(422, ['error' => 'Nome da empresa é obrigatório']);
    }
    if ($phone === '' || strlen($phone) < 10) {
        json_response(422, ['error' => 'Informe o WhatsApp com DDI (ex.: 5511999999999).']);
    }

    $st = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $st->execute([$email]);
    if ($st->fetch()) {
        json_response(409, ['error' => 'Este email já está cadastrado']);
    }

    $userId = uuid_v4();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($hash === false) {
        json_response(500, ['error' => 'Erro ao processar senha']);
    }

    $planId = null;
    $p = $pdo->query("SELECT id FROM plans WHERE is_active = 1 ORDER BY price ASC LIMIT 1");
    $row = $p ? $p->fetch(PDO::FETCH_ASSOC) : false;
    if ($row && isset($row['id'])) {
        $planId = $row['id'];
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO users (id, email, password_hash) VALUES (?, ?, ?)')->execute([$userId, $email, $hash]);
        $profileId = uuid_v4();
        $pdo->prepare('INSERT INTO profiles (id, user_id, full_name, email) VALUES (?, ?, ?, ?)')
            ->execute([$profileId, $userId, $fullName !== '' ? $fullName : null, $email]);
        $roleId = uuid_v4();
        $pdo->prepare('INSERT INTO user_roles (id, user_id, role) VALUES (?, ?, ?)')
            ->execute([$roleId, $userId, 'company_owner']);
        $companyId = uuid_v4();
        $trialRenewsAt = (new DateTimeImmutable('today'))->modify('+3 days')->format('Y-m-d');
        $pdo->prepare('INSERT INTO companies (id, owner_id, plan_id, plan_renews_at, name, email, phone) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$companyId, $userId, $planId, $trialRenewsAt, $companyName, $email, $phone]);
        cobx_subscription_sync_current($pdo, $companyId, $planId !== null ? (string) $planId : null, $trialRenewsAt, 'trialing');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_response(500, ['error' => 'Não foi possível criar a conta']);
    }

    cobx_send_register_welcome($pdo, $email, $fullName, $companyName, $phone);

    $token = jwt_encode(['sub' => $userId, 'email' => $email]);
    admin_notify_superadmin(
        $pdo,
        'register',
        'Novo cadastro na plataforma',
        [
            'Empresa: ' . $companyName,
            'Email: ' . $email,
            'WhatsApp: ' . $phone,
            'Nome: ' . ($fullName !== '' ? $fullName : 'Não informado'),
        ]
    );
    json_response(201, [
        'token' => $token,
        'user' => auth_user_payload($pdo, $userId),
    ]);
}

function auth_login(PDO $pdo): void
{
    $in = json_input();
    $email = isset($in['email']) ? strtolower(trim((string) $in['email'])) : '';
    $password = isset($in['password']) ? (string) $in['password'] : '';

    if ($email === '' || $password === '') {
        json_response(422, ['error' => 'Email e senha são obrigatórios']);
    }

    $st = $pdo->prepare('SELECT id, email, password_hash FROM users WHERE email = ? LIMIT 1');
    $st->execute([$email]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u || !password_verify($password, (string) $u['password_hash'])) {
        json_response(401, ['error' => 'Email ou senha incorretos']);
    }

    $token = jwt_encode(['sub' => (string) $u['id'], 'email' => (string) $u['email']]);
    json_response(200, [
        'token' => $token,
        'user' => auth_user_payload($pdo, (string) $u['id']),
    ]);
}

function auth_change_password(PDO $pdo): void
{
    $ctx = require_auth_context($pdo);
    $in = json_input();
    $current = (string) ($in['current_password'] ?? '');
    $new = (string) ($in['new_password'] ?? '');
    $confirm = (string) ($in['confirm_password'] ?? '');

    if ($current === '' || $new === '' || $confirm === '') {
        json_response(422, ['error' => 'Preencha a senha atual, a nova senha e a confirmação']);
    }
    if ($new !== $confirm) {
        json_response(422, ['error' => 'A confirmação da nova senha não confere']);
    }
    if (strlen($new) < 6) {
        json_response(422, ['error' => 'A nova senha deve ter pelo menos 6 caracteres']);
    }
    if ($current === $new) {
        json_response(422, ['error' => 'A nova senha deve ser diferente da senha atual']);
    }

    $st = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $st->execute([$ctx['user_id']]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || !password_verify($current, (string) $row['password_hash'])) {
        json_response(401, ['error' => 'Senha atual incorreta']);
    }

    $hash = password_hash($new, PASSWORD_DEFAULT);
    if ($hash === false) {
        json_response(500, ['error' => 'Erro ao processar senha']);
    }

    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $ctx['user_id']]);
    json_response(200, ['ok' => true]);
}

function auth_me(PDO $pdo): void
{
    $token = bearer_token();
    if ($token === null) {
        json_response(401, ['error' => 'Não autenticado']);
    }
    $payload = jwt_decode($token);
    if ($payload === null || !isset($payload['sub'])) {
        json_response(401, ['error' => 'Sessão inválida ou expirada']);
    }
    $userId = (string) $payload['sub'];
    $user = auth_user_payload($pdo, $userId);
    if ($user === null) {
        json_response(401, ['error' => 'Usuário não encontrado']);
    }
    json_response(200, ['user' => $user]);
}

function auth_forgot_password(PDO $pdo): void
{
    $in = json_input();
    $email = isset($in['email']) ? strtolower(trim((string) $in['email'])) : '';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(200, ['ok' => true]);
    }

    $st = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $st->execute([$email]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        json_response(200, ['ok' => true]);
    }

    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $id = uuid_v4();
    $expires = (new DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s.v');
    $pdo->prepare('INSERT INTO password_resets (id, user_id, token_hash, expires_at) VALUES (?, ?, ?, ?)')
        ->execute([$id, (string) $u['id'], $tokenHash, $expires]);

    $base = rtrim((string) env('APP_URL', ''), '/');
    $link = ($base !== '' ? $base : '') . '/reset-password?token=' . $rawToken;
    $sent = auth_send_reset_password_email($pdo, $email, $link);
    admin_notify_superadmin(
        $pdo,
        'forgot-password',
        'Pedido de recuperação de senha',
        [
            'Email: ' . $email,
            $sent ? 'Email de recuperação enviado ao utilizador.' : 'Email não enviado: SMTP master não configurado ou falhou.',
        ]
    );

    json_response(200, ['ok' => true]);
}

function auth_send_reset_password_email(PDO $pdo, string $email, string $link): bool
{
    $st = $pdo->query(
        'SELECT smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password, smtp_from_email, smtp_from_name
         FROM master_settings WHERE id = 1 LIMIT 1'
    );
    $m = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
    if (!$m) {
        return false;
    }

    $host = trim((string) ($m['smtp_host'] ?? ''));
    $from = trim((string) ($m['smtp_from_email'] ?? ''));
    $password = (string) (cobx_secret_decrypt($m['smtp_password'] ?? null) ?? '');
    if ($host === '' || $from === '' || $password === '') {
        return false;
    }

    $cfg = [
        'host' => $host,
        'port' => cobx_smtp_port((int) ($m['smtp_port'] ?? 587)),
        'encryption' => cobx_smtp_enc((string) ($m['smtp_encryption'] ?? 'tls')),
        'username' => trim((string) ($m['smtp_username'] ?? '')),
        'password' => $password,
        'from_email' => $from,
        'from_name' => trim((string) ($m['smtp_from_name'] ?? '')),
        'source' => 'master',
    ];

    $system = cobx_system_name($pdo);
    $safeSystem = htmlspecialchars($system, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeLink = htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = '<p>Recebemos um pedido para redefinir a sua senha no ' . $safeSystem . '.</p>'
        . '<p><a href="' . $safeLink . '">Clique aqui para criar uma nova senha</a>.</p>'
        . '<p>Este link expira em 1 hora. Se não foi você, ignore esta mensagem.</p>';
    $text = "Recebemos um pedido para redefinir a sua senha no {$system}.\n\n"
        . "Acesse o link abaixo para criar uma nova senha:\n{$link}\n\n"
        . "Este link expira em 1 hora. Se não foi você, ignore esta mensagem.";

    try {
        cobx_send_smtp_message($cfg, $email, '', '[' . $system . '] Redefinir senha', $html, $text);

        return true;
    } catch (Throwable $e) {
        error_log('[cobx] reset email failed for ' . $email . ': ' . $e->getMessage());

        return false;
    }
}

function auth_reset_password(PDO $pdo): void
{
    $in = json_input();
    $token = isset($in['token']) ? trim((string) $in['token']) : '';
    $password = isset($in['password']) ? (string) $in['password'] : '';

    if ($token === '' || strlen($password) < 6) {
        json_response(422, ['error' => 'Token ou senha inválidos']);
    }

    $tokenHash = hash('sha256', $token);
    $st = $pdo->prepare(
        'SELECT id, user_id FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW(3) LIMIT 1'
    );
    $st->execute([$tokenHash]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        json_response(400, ['error' => 'Link inválido ou expirado']);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($hash === false) {
        json_response(500, ['error' => 'Erro ao processar senha']);
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, (string) $row['user_id']]);
        $pdo->prepare('UPDATE password_resets SET used_at = NOW(3) WHERE id = ?')->execute([(string) $row['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_response(500, ['error' => 'Não foi possível atualizar a senha']);
    }
    $st = $pdo->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
    $st->execute([(string) $row['user_id']]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    admin_notify_superadmin(
        $pdo,
        'reset-password',
        'Senha redefinida com sucesso',
        [
            'Email: ' . (string) ($u['email'] ?? 'desconhecido'),
        ]
    );

    json_response(200, ['ok' => true]);
}

/** @return list<string> */
function auth_user_roles(PDO $pdo, string $userId): array
{
    $st = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = ?');
    $st->execute([$userId]);
    return array_values(array_map(static fn ($r) => (string) $r['role'], $st->fetchAll(PDO::FETCH_ASSOC)));
}

/** @return array<string, mixed>|null */
function auth_user_payload(PDO $pdo, string $userId): ?array
{
    $st = $pdo->prepare(
        'SELECT u.id, u.email, p.full_name FROM users u
         LEFT JOIN profiles p ON p.user_id = u.id
         WHERE u.id = ? LIMIT 1'
    );
    $st->execute([$userId]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        return null;
    }
    $companyId = user_owned_company_id($pdo, $userId);

    return [
        'id' => (string) $u['id'],
        'email' => (string) $u['email'],
        'user_metadata' => [
            'full_name' => $u['full_name'] !== null && $u['full_name'] !== '' ? (string) $u['full_name'] : null,
        ],
        'roles' => auth_user_roles($pdo, $userId),
        'company_id' => $companyId,
    ];
}
