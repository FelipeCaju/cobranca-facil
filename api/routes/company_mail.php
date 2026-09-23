<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/mail.php';
require_once __DIR__ . '/../lib/system_branding.php';

function company_mail_settings(PDO $pdo, string $method, string $companyId): void
{
    if ($method === 'GET') {
        $st = $pdo->prepare(
            'SELECT smtp_use_custom, smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password, smtp_from_email, smtp_from_name
             FROM companies WHERE id = ? LIMIT 1'
        );
        $st->execute([$companyId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            json_response(404, ['error' => 'Empresa não encontrada']);
        }
        $pw = (string) (cobx_secret_decrypt($row['smtp_password'] ?? null) ?? '');
        json_response(200, [
            'smtp_use_custom' => (int) ($row['smtp_use_custom'] ?? 0) === 1,
            'smtp_host' => (string) ($row['smtp_host'] ?? ''),
            'smtp_port' => cobx_smtp_port((int) ($row['smtp_port'] ?? 587)),
            'smtp_encryption' => cobx_smtp_enc((string) ($row['smtp_encryption'] ?? 'tls')),
            'smtp_username' => (string) ($row['smtp_username'] ?? ''),
            'smtp_from_email' => (string) ($row['smtp_from_email'] ?? ''),
            'smtp_from_name' => (string) ($row['smtp_from_name'] ?? ''),
            'smtp_password_set' => $pw !== '',
            'smtp_password_masked' => $pw !== '' ? cobx_mask_secret($pw) : '',
            'master_smtp_ready' => cobx_master_smtp_ready($pdo),
        ]);
    }

    if ($method === 'PUT') {
        $in = json_input();
        $st = $pdo->prepare(
            'SELECT smtp_use_custom, smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password, smtp_from_email, smtp_from_name
             FROM companies WHERE id = ? LIMIT 1'
        );
        $st->execute([$companyId]);
        $cur = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cur) {
            json_response(404, ['error' => 'Empresa não encontrada']);
        }

        $useCustom = array_key_exists('smtp_use_custom', $in) ? !empty($in['smtp_use_custom']) : ((int) ($cur['smtp_use_custom'] ?? 0) === 1);
        $host = array_key_exists('smtp_host', $in) ? trim((string) $in['smtp_host']) : trim((string) ($cur['smtp_host'] ?? ''));
        $port = array_key_exists('smtp_port', $in) ? cobx_smtp_port((int) $in['smtp_port']) : cobx_smtp_port((int) ($cur['smtp_port'] ?? 587));
        $enc = array_key_exists('smtp_encryption', $in) ? cobx_smtp_enc((string) $in['smtp_encryption']) : cobx_smtp_enc((string) ($cur['smtp_encryption'] ?? 'tls'));
        $user = array_key_exists('smtp_username', $in) ? trim((string) $in['smtp_username']) : trim((string) ($cur['smtp_username'] ?? ''));
        $fromEmail = array_key_exists('smtp_from_email', $in) ? trim((string) $in['smtp_from_email']) : trim((string) ($cur['smtp_from_email'] ?? ''));
        $fromName = array_key_exists('smtp_from_name', $in) ? trim((string) $in['smtp_from_name']) : trim((string) ($cur['smtp_from_name'] ?? ''));

        $pw = (string) ($cur['smtp_password'] ?? '');
        if (array_key_exists('smtp_password', $in)) {
            $newPw = trim((string) $in['smtp_password']);
            if ($newPw !== '') {
                $pw = (string) cobx_secret_encrypt($newPw);
            }
        }

        if ($useCustom) {
            if ($host === '' || $fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                json_response(422, ['error' => 'SMTP próprio: preencha servidor e email remetente válido.']);
            }
            if ($pw === '') {
                json_response(422, ['error' => 'SMTP próprio: defina a palavra-passe da conta SMTP.']);
            }
        }

        $pdo->prepare(
            'UPDATE companies SET smtp_use_custom=?, smtp_host=?, smtp_port=?, smtp_encryption=?, smtp_username=?, smtp_password=?, smtp_from_email=?, smtp_from_name=?, updated_at=NOW(3) WHERE id=?'
        )->execute([
            $useCustom ? 1 : 0,
            $host !== '' ? $host : null,
            $port,
            $enc,
            $user !== '' ? $user : null,
            $pw !== '' ? $pw : null,
            $fromEmail !== '' ? $fromEmail : null,
            $fromName !== '' ? $fromName : null,
            $companyId,
        ]);
        json_response(200, ['ok' => true]);
    }

    json_response(405, ['error' => 'Método não permitido']);
}

function company_mail_settings_test(PDO $pdo, string $companyId): void
{
    $cfg = cobx_resolve_smtp_for_company($pdo, $companyId);
    if ($cfg === null) {
        json_response(422, ['error' => 'SMTP não disponível. Configure o SMTP master (administração) ou ative SMTP próprio com dados completos.']);
    }
    $in = json_input();
    $to = isset($in['to']) ? trim((string) $in['to']) : '';
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        json_response(422, ['error' => 'Indique um email de destino válido.']);
    }
    try {
        cobx_send_smtp_message(
            $cfg,
            $to,
            '',
            '[' . cobx_system_name($pdo) . '] Email de teste',
            '<p>Envio de teste concluído com sucesso.</p><p>Origem: <strong>' . htmlspecialchars($cfg['source'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong></p>',
            'Envio de teste OK. Origem: ' . $cfg['source']
        );
        json_response(200, ['ok' => true, 'via' => $cfg['source']]);
    } catch (Throwable $e) {
        json_response(502, ['error' => $e->getMessage()]);
    }
}
