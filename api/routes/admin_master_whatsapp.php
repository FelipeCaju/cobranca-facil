<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/evolution_http.php';
require_once __DIR__ . '/../lib/admin_notifications.php';

function admin_master_whatsapp_dispatch(PDO $pdo, string $method, array $seg): void
{
    $sub = $seg[1] ?? null;
    if ($sub === null && $method === 'GET') admin_master_whatsapp_status($pdo);
    if ($sub === 'create' && $method === 'POST') admin_master_whatsapp_create($pdo);
    if ($sub === 'qrcode' && $method === 'GET') admin_master_whatsapp_qrcode($pdo);
    if ($sub === 'disconnect' && $method === 'POST') admin_master_whatsapp_disconnect($pdo);
    json_response(404, ['error' => 'Recurso não encontrado']);
}

/** @return array{name: string, token: string} */
function admin_master_whatsapp_record(PDO $pdo): array
{
    $st = $pdo->query('SELECT master_whatsapp_instance_name, master_whatsapp_instance_token FROM master_settings WHERE id=1 LIMIT 1');
    $row = $st ? $st->fetch(PDO::FETCH_NUM) : false;
    if ($row === false) return ['name' => '', 'token' => ''];
    return [
        'name' => trim((string) ($row[0] ?? '')),
        'token' => trim((string) (cobx_secret_decrypt(isset($row[1]) ? (string) $row[1] : null) ?? '')),
    ];
}

function admin_master_whatsapp_instance(PDO $pdo): string
{
    return admin_master_whatsapp_record($pdo)['name'];
}

function admin_master_whatsapp_set_instance(PDO $pdo, ?string $name, ?string $token = null): void
{
    $stored = $token !== null && trim($token) !== '' ? cobx_secret_encrypt(trim($token)) : null;
    $pdo->prepare('UPDATE master_settings SET master_whatsapp_instance_name=?, master_whatsapp_instance_token=?, updated_at=NOW(3) WHERE id=1')
        ->execute([$name !== null && trim($name) !== '' ? trim($name) : null, $stored]);
}

/** @return array{url: string, apikey: string, global_apikey: string, provider: 'go'|'api'}|null */
function admin_master_whatsapp_credentials(PDO $pdo): ?array
{
    $cred = evolution_master_credentials($pdo);
    if ($cred === null || $cred['provider'] !== 'go') return $cred;
    $record = admin_master_whatsapp_record($pdo);
    if ($record['token'] !== '') {
        $cred['apikey'] = $record['token'];
        return $cred;
    }
    if ($record['name'] === '') return $cred;
    $remote = evolution_go_find_instance($cred['url'], $cred['global_apikey'], $record['name']);
    $token = trim((string) ($remote['token'] ?? $remote['apikey'] ?? ''));
    if ($token !== '') {
        admin_master_whatsapp_set_instance($pdo, $record['name'], $token);
        $cred['apikey'] = $token;
    }
    return $cred;
}

/** @return array<string, mixed> */
function admin_master_whatsapp_status_payload(PDO $pdo): array
{
    $record = admin_master_whatsapp_record($pdo);
    $cred = admin_master_whatsapp_credentials($pdo);
    $out = [
        'instance_name' => $record['name'] !== '' ? $record['name'] : null,
        'connection_state' => 'none', 'connected' => false,
        'qrcode_base64' => null, 'pairing_code' => null, 'qrcode_connection_code' => null,
        'master_configured' => evolution_master_credentials($pdo) !== null,
        'smtp_configured' => admin_master_smtp_cfg($pdo) !== null,
        'evolution_product' => $cred['provider'] ?? null,
    ];
    if ($cred === null || $record['name'] === '') return $out;
    if ($cred['provider'] === 'go' && $cred['apikey'] === $cred['global_apikey']) {
        $out['connection_state'] = 'unknown';
        return $out;
    }
    $r = evolution_call_instance_status($cred['url'], $cred['apikey'], $record['name']);
    if ($r['ok']) {
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

function admin_master_whatsapp_status(PDO $pdo): void
{
    json_response(200, admin_master_whatsapp_status_payload($pdo));
}

function admin_master_whatsapp_create(PDO $pdo): void
{
    $cred = evolution_master_credentials($pdo);
    if ($cred === null) json_response(422, ['error' => 'Configure a Evolution API antes de sincronizar o WhatsApp.']);
    if (admin_master_whatsapp_instance($pdo) !== '') json_response(409, ['error' => 'Já existe sessão master. Desligue para criar novamente.']);
    $name = 'cobx-master';
    if ($cred['provider'] === 'go') {
        $remote = evolution_go_find_instance($cred['url'], $cred['global_apikey'], $name);
        $token = trim((string) ($remote['token'] ?? $remote['apikey'] ?? ''));
        if ($remote === null) {
            $token = bin2hex(random_bytes(32));
            $res = evolution_call_create_instance($cred['url'], $cred['global_apikey'], $name, $token);
            if (!$res['ok']) json_response(502, ['error' => 'Não foi possível criar a sessão master na Evolution GO.']);
        }
        if ($token === '') json_response(502, ['error' => 'A Evolution GO não devolveu o token da instância master.']);
        admin_master_whatsapp_set_instance($pdo, $name, $token);
        evolution_go_connect($cred['url'], $token);
    } else {
        $res = evolution_call_create_instance($cred['url'], $cred['global_apikey'], $name);
        if (!$res['ok'] && (int) ($res['status'] ?? 0) !== 403) {
            json_response(502, ['error' => 'Não foi possível criar a sessão master no Evolution.']);
        }
        admin_master_whatsapp_set_instance($pdo, $name, null);
    }
    $payload = admin_master_whatsapp_status_payload($pdo);
    $payload['instance_name'] = $name;
    json_response(201, $payload);
}

function admin_master_whatsapp_qrcode(PDO $pdo): void
{
    $cred = admin_master_whatsapp_credentials($pdo);
    $name = admin_master_whatsapp_instance($pdo);
    if ($cred === null || $name === '') json_response(422, ['error' => 'Sessão master não configurada.']);
    $r = evolution_call_instance_qr($cred['url'], $cred['apikey'], $name);
    if (!$r['ok']) json_response(502, ['error' => 'Não foi possível atualizar o QR agora.']);
    json_response(200, evolution_parse_qr_response($r['body']));
}

function admin_master_whatsapp_disconnect(PDO $pdo): void
{
    $cred = admin_master_whatsapp_credentials($pdo);
    $name = admin_master_whatsapp_instance($pdo);
    if ($cred !== null && $name !== '') evolution_call_instance_logout($cred['url'], $cred['apikey'], $name);
    admin_master_whatsapp_set_instance($pdo, null, null);
    json_response(200, admin_master_whatsapp_status_payload($pdo));
}
