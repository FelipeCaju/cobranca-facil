<?php

declare(strict_types=1);

/** Empresa da qual o utilizador é dono (owner_id), ou null. */
function user_owned_company_id(PDO $pdo, string $userId): ?string
{
    $st = $pdo->prepare('SELECT id FROM companies WHERE owner_id = ? LIMIT 1');
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row && isset($row['id']) ? (string) $row['id'] : null;
}

/**
 * @return array{user_id: string, roles: list<string>, company_id: ?string}
 */
function require_auth_context(PDO $pdo): array
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
    $st = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = ?');
    $st->execute([$userId]);
    $roles = array_values(array_map(static fn ($r) => (string) $r['role'], $st->fetchAll(PDO::FETCH_ASSOC)));

    $companyId = user_owned_company_id($pdo, $userId);

    return ['user_id' => $userId, 'roles' => $roles, 'company_id' => $companyId];
}

function require_company_id(array $ctx): string
{
    if ($ctx['company_id'] === null || $ctx['company_id'] === '') {
        json_response(403, ['error' => 'Nenhuma empresa vinculada a este utilizador']);
    }
    return $ctx['company_id'];
}

/** @param list<string> $roles */
function require_admin_role(array $ctx): void
{
    if (!in_array('admin', $ctx['roles'], true)) {
        json_response(403, ['error' => 'Apenas super administrador']);
    }
}
