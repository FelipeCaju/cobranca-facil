<?php

declare(strict_types=1);

$error = null;
$ok = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $token = trim((string) ($_POST['token'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if (strlen($password) < 6) {
        $error = 'Senha deve ter pelo menos 6 caracteres';
    } else {
        $tokenHash = hash('sha256', $token);
        $st = app_pdo()->prepare(
            'SELECT id, user_id FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW(3) LIMIT 1'
        );
        $st->execute([$tokenHash]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $error = 'Link inválido ou expirado';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            app_pdo()->beginTransaction();
            try {
                app_pdo()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $row['user_id']]);
                app_pdo()->prepare('UPDATE password_resets SET used_at = NOW(3) WHERE id = ?')->execute([$row['id']]);
                app_pdo()->commit();
                $ok = true;
            } catch (Throwable) {
                app_pdo()->rollBack();
                $error = 'Não foi possível atualizar a senha';
            }
        }
    }
}

app_render('reset_password', [
    'bare_layout' => true,
    'error' => $error,
    'ok' => $ok,
    'token' => $_GET['token'] ?? $_POST['token'] ?? '',
], 'Redefinir senha');
