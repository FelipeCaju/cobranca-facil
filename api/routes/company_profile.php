<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/phone.php';

function company_profile(PDO $pdo, string $method, string $companyId): void
{
    if ($method === 'GET') {
        $st = $pdo->prepare(
            'SELECT name, cnpj, email, phone FROM companies WHERE id = ? LIMIT 1'
        );
        $st->execute([$companyId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            json_response(404, ['error' => 'Empresa não encontrada']);
        }
        json_response(200, [
            'name' => (string) ($row['name'] ?? ''),
            'cnpj' => $row['cnpj'] !== null ? (string) $row['cnpj'] : '',
            'email' => $row['email'] !== null ? (string) $row['email'] : '',
            'phone' => $row['phone'] !== null ? (string) $row['phone'] : '',
        ]);
    }

    if ($method === 'PUT') {
        $in = json_input();
        $name = array_key_exists('name', $in) ? trim((string) $in['name']) : '';
        if ($name === '') {
            json_response(422, ['error' => 'Nome da empresa é obrigatório']);
        }
        $email = array_key_exists('email', $in) ? trim((string) $in['email']) : '';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_response(422, ['error' => 'Email inválido']);
        }
        $cnpj = array_key_exists('cnpj', $in) ? trim((string) $in['cnpj']) : '';
        $phoneRaw = array_key_exists('phone', $in) ? (string) $in['phone'] : '';
        $phone = $phoneRaw !== '' ? cobx_normalize_phone($phoneRaw) : '';
        if ($phone !== '' && strlen($phone) < 10) {
            json_response(422, ['error' => 'Telefone/WhatsApp inválido (use DDI, ex.: 5511999999999)']);
        }

        $pdo->prepare(
            'UPDATE companies SET name=?, cnpj=?, email=?, phone=?, updated_at=NOW(3) WHERE id=?'
        )->execute([
            $name,
            $cnpj !== '' ? $cnpj : null,
            $email !== '' ? $email : null,
            $phone !== '' ? $phone : null,
            $companyId,
        ]);
        company_profile($pdo, 'GET', $companyId);

        return;
    }

    json_response(405, ['error' => 'Método não permitido']);
}
