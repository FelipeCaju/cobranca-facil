<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/evolution_http.php';

/** @return array{name: string, token: string} */
function company_whatsapp_instance_record(PDO $pdo, string $companyId): array
{
    $st = $pdo->prepare('SELECT evolution_instance_name, whatsapp_token FROM companies WHERE id = ? LIMIT 1');
    $st->execute([$companyId]);
    $row = $st->fetch(PDO::FETCH_NUM);
    if ($row === false) return ['name' => '', 'token' => ''];
    return [
        'name' => trim((string) ($row[0] ?? '')),
        'token' => trim((string) (cobx_secret_decrypt(isset($row[1]) ? (string) $row[1] : null) ?? '')),
    ];
}

function company_whatsapp_instance_name(PDO $pdo, string $companyId): string
{
    return company_whatsapp_instance_record($pdo, $companyId)['name'];
}

function company_whatsapp_set_instance(PDO $pdo, string $companyId, ?string $name, ?string $token, string $provider): void
{
    $storedToken = $token !== null && trim($token) !== '' ? cobx_secret_encrypt(trim($token)) : null;
    $pdo->prepare('UPDATE companies SET evolution_instance_name=?, whatsapp_token=?, whatsapp_provider=?, updated_at=NOW(3) WHERE id=?')
        ->execute([$name !== null && trim($name) !== '' ? trim($name) : null, $storedToken, $provider, $companyId]);
}

/** @return array{url: string, apikey: string, global_apikey: string, provider: 'go'|'api'}|null */
function company_whatsapp_credentials(PDO $pdo, string $companyId): ?array
{
    $cred = evolution_resolve_credentials($pdo, $companyId);
    if ($cred === null || $cred['provider'] !== 'go') return $cred;
    $record = company_whatsapp_instance_record($pdo, $companyId);
    if ($record['token'] !== '') {
        $cred['apikey'] = $record['token'];
        return $cred;
    }
    if ($record['name'] === '') return $cred;
    $remote = evolution_go_find_instance($cred['url'], $cred['global_apikey'], $record['name']);
    $token = trim((string) ($remote['token'] ?? $remote['apikey'] ?? ''));
    if ($token !== '') {
        company_whatsapp_set_instance($pdo, $companyId, $record['name'], $token, 'evolution_go');
        $cred['apikey'] = $token;
    }
    return $cred;
}

function company_whatsapp_connection_dispatch(PDO $pdo, string $method, string $companyId, array $seg): void
{
    $sub = $seg[1] ?? null;
    if ($sub === 'create' && $method === 'POST') company_whatsapp_create($pdo, $companyId);
    if ($sub === 'qrcode' && $method === 'GET') company_whatsapp_qrcode($pdo, $companyId);
    if ($sub === 'disconnect' && $method === 'POST') company_whatsapp_disconnect($pdo, $companyId);
    if ($sub === null && $method === 'GET') company_whatsapp_status($pdo, $companyId);
    json_response(404, ['error' => 'Recurso não encontrado']);
}

function company_whatsapp_status(PDO $pdo, string $companyId): void
{
    try {
        json_response(200, company_whatsapp_status_payload($pdo, $companyId));
    } catch (Throwable $e) {
        error_log('company_whatsapp_status: ' . $e->getMessage());
        json_response(503, ['error' => 'Não foi possível ler o estado do WhatsApp.']);
    }
}

/** @return array<string, mixed> */
function company_whatsapp_status_payload(PDO $pdo, string $companyId): array
{
    $record = company_whatsapp_instance_record($pdo, $companyId);
    $out = [
        'instance_name' => $record['name'] !== '' ? $record['name'] : null,
        'connection_state' => 'none', 'connected' => false,
        'qrcode_base64' => null, 'pairing_code' => null, 'qrcode_connection_code' => null,
    ];
    if ($record['name'] === '') return $out;
    $cred = company_whatsapp_credentials($pdo, $companyId);
    if ($cred === null || ($cred['provider'] === 'go' && $record['token'] === '' && $cred['apikey'] === $cred['global_apikey'])) {
        $out['connection_state'] = 'unknown';
        return $out;
    }
    $r = evolution_call_instance_status($cred['url'], $cred['apikey'], $record['name']);
    if ($r['ok'] && is_array($r['body'])) {
        $out['connection_state'] = evolution_parse_connection_state($r['body']);
        $out['connected'] = $out['connection_state'] === 'open';
    } else {
        $out['connection_state'] = 'unknown';
    }
    if (!$out['connected']) {
        $qr = evolution_call_instance_qr($cred['url'], $cred['apikey'], $record['name']);
        if ($qr['ok']) {
            $out = array_merge($out, evolution_parse_qr_response($qr['body']));
            $out['connection_state'] = 'connecting';
        }
    }
    return $out;
}

function company_whatsapp_create(PDO $pdo, string $companyId): void
{
    $admin = evolution_master_credentials($pdo) ?? evolution_resolve_credentials($pdo, $companyId);
    if ($admin === null) json_response(422, ['error' => 'Configure a Evolution API antes de criar a conexão.']);
    if (company_whatsapp_instance_name($pdo, $companyId) !== '') {
        json_response(409, ['error' => 'Já existe uma instância. Desligue primeiro ou atualize o QR code.']);
    }
    $name = evolution_instance_name_for_company($companyId);
    if ($admin['provider'] === 'go') {
        $remote = evolution_go_find_instance($admin['url'], $admin['global_apikey'], $name);
        $token = trim((string) ($remote['token'] ?? $remote['apikey'] ?? ''));
        if ($remote === null) {
            $token = bin2hex(random_bytes(32));
            $create = evolution_call_create_instance($admin['url'], $admin['global_apikey'], $name, $token);
            if (!$create['ok']) {
                error_log('cobx evolution-go: create failed status=' . $create['status'] . ' detail=' . evolution_flatten_error_message($create['body']));
                json_response(502, ['error' => 'A Evolution GO não conseguiu criar a instância.']);
            }
        }
        if ($token === '') json_response(502, ['error' => 'A Evolution GO não devolveu o token da instância.']);
        company_whatsapp_set_instance($pdo, $companyId, $name, $token, 'evolution_go');
        $connect = evolution_go_connect($admin['url'], $token);
        if (!$connect['ok'] && !in_array((int) $connect['status'], [400, 409], true)) {
            error_log('cobx evolution-go: connect failed status=' . $connect['status']);
        }
        $payload = company_whatsapp_status_payload($pdo, $companyId);
        $payload['instance_name'] = $name;
        json_response(201, $payload);
    }
    $res = evolution_call_create_instance($admin['url'], $admin['global_apikey'], $name);
    if (!$res['ok'] && (int) ($res['status'] ?? 0) !== 403) {
        error_log('cobx evolution: create failed status=' . ($res['status'] ?? 0) . ' detail=' . evolution_flatten_error_message($res['body']));
        json_response(502, ['error' => 'Não foi possível criar a sessão.']);
    }
    company_whatsapp_set_instance($pdo, $companyId, $name, null, 'evolution');
    $payload = company_whatsapp_status_payload($pdo, $companyId);
    $payload['instance_name'] = $name;
    foreach (evolution_parse_qr_response($res['body']) as $key => $value) {
        if (($payload[$key] ?? null) === null && $value !== null) $payload[$key] = $value;
    }
    json_response(201, $payload);
}

function company_whatsapp_qrcode(PDO $pdo, string $companyId): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    $record = company_whatsapp_instance_record($pdo, $companyId);
    $cred = company_whatsapp_credentials($pdo, $companyId);
    if ($cred === null || $record['name'] === '') {
        json_response(404, ['error' => 'Nenhuma instância. Use “Criar e conectar” primeiro.']);
    }
    $r = evolution_call_instance_qr($cred['url'], $cred['apikey'], $record['name']);
    if (!$r['ok']) json_response(502, ['error' => 'Não foi possível obter o QR code agora.']);
    json_response(200, array_merge([
        'instance_name' => $record['name'], 'connection_state' => 'connecting',
        'connected' => false, 'qr_generated_at' => time(),
    ], evolution_parse_qr_response($r['body'])));
}

function company_whatsapp_disconnect(PDO $pdo, string $companyId): void
{
    $record = company_whatsapp_instance_record($pdo, $companyId);
    $cred = company_whatsapp_credentials($pdo, $companyId);
    if ($record['name'] !== '' && $cred !== null) {
        evolution_call_instance_logout($cred['url'], $cred['apikey'], $record['name']);
    }
    $provider = $cred !== null && $cred['provider'] === 'go' ? 'evolution_go' : 'evolution';
    company_whatsapp_set_instance($pdo, $companyId, null, null, $provider);
    json_response(200, company_whatsapp_status_payload($pdo, $companyId));
}
