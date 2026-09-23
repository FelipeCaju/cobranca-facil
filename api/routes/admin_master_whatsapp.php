<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/evolution_http.php';
require_once __DIR__ . '/../lib/admin_notifications.php';

function admin_master_whatsapp_dispatch(PDO $pdo, string $method, array $seg): void
{
    $sub = $seg[1] ?? null;
    if ($sub === null && $method === 'GET') {
        admin_master_whatsapp_status($pdo);
        return;
    }
    if ($sub === 'create' && $method === 'POST') {
        admin_master_whatsapp_create($pdo);
        return;
    }
    if ($sub === 'qrcode' && $method === 'GET') {
        admin_master_whatsapp_qrcode($pdo);
        return;
    }
    if ($sub === 'disconnect' && $method === 'POST') {
        admin_master_whatsapp_disconnect($pdo);
        return;
    }
    json_response(404, ['error' => 'Recurso não encontrado']);
}

function admin_master_whatsapp_instance(PDO $pdo): string
{
    $st = $pdo->query('SELECT master_whatsapp_instance_name FROM master_settings WHERE id = 1 LIMIT 1');
    $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
    return trim((string) ($row['master_whatsapp_instance_name'] ?? ''));
}

function admin_master_whatsapp_set_instance(PDO $pdo, ?string $name): void
{
    $pdo->prepare('UPDATE master_settings SET master_whatsapp_instance_name = ?, updated_at = NOW(3) WHERE id = 1')
        ->execute([$name !== null && $name !== '' ? $name : null]);
}

/** @return array<string, mixed> */
function admin_master_whatsapp_status_payload(PDO $pdo): array
{
    $cred = evolution_master_credentials($pdo);
    $instanceName = admin_master_whatsapp_instance($pdo);
    $out = [
        'instance_name' => $instanceName !== '' ? $instanceName : null,
        'connection_state' => 'none',
        'connected' => false,
        'qrcode_base64' => null,
        'pairing_code' => null,
        'qrcode_connection_code' => null,
        'master_configured' => $cred !== null,
        'smtp_configured' => admin_master_smtp_cfg($pdo) !== null,
    ];
    if ($cred === null || $instanceName === '') {
        return $out;
    }
    $enc = rawurlencode($instanceName);
    $r = evolution_http_get_path_variants($cred['url'], $cred['apikey'], evolution_instance_path_candidates($cred['url'], 'connectionState/' . $enc));
    if ($r['ok'] && is_array($r['body'])) {
        $state = evolution_parse_connection_state($r['body']);
        $out['connection_state'] = $state;
        $out['connected'] = $state === 'open';
    }
    if (!$out['connected']) {
        $r2 = evolution_http_get_path_variants($cred['url'], $cred['apikey'], evolution_instance_path_candidates($cred['url'], 'connect/' . $enc));
        if ($r2['ok']) {
            $qr = evolution_parse_qr_response($r2['body']);
            $out['qrcode_base64'] = $qr['qrcode_base64'];
            $out['pairing_code'] = $qr['pairing_code'];
            $out['qrcode_connection_code'] = $qr['qrcode_connection_code'];
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
    if ($cred === null) {
        json_response(422, ['error' => 'Configure a Evolution API master antes de sincronizar WhatsApp.']);
    }
    $existing = admin_master_whatsapp_instance($pdo);
    if ($existing !== '') {
        json_response(409, ['error' => 'Já existe sessão master. Desligue para criar novamente.']);
    }
    $instance = 'cobx-master';
    $res = evolution_call_create_instance($cred['url'], $cred['apikey'], $instance);
    if (!$res['ok'] && (int) ($res['status'] ?? 0) !== 403) {
        json_response(502, ['error' => 'Não foi possível criar a sessão master no Evolution.']);
    }
    admin_master_whatsapp_set_instance($pdo, $instance);
    $payload = admin_master_whatsapp_status_payload($pdo);
    $payload['instance_name'] = $instance;
    json_response(201, $payload);
}

function admin_master_whatsapp_qrcode(PDO $pdo): void
{
    $cred = evolution_master_credentials($pdo);
    $name = admin_master_whatsapp_instance($pdo);
    if ($cred === null || $name === '') {
        json_response(422, ['error' => 'Sessão master não configurada.']);
    }
    $enc = rawurlencode($name);
    $r = evolution_http_get_path_variants($cred['url'], $cred['apikey'], evolution_instance_path_candidates($cred['url'], 'connect/' . $enc));
    if (!$r['ok']) {
        json_response(502, ['error' => 'Não foi possível atualizar o QR agora.']);
    }
    json_response(200, evolution_parse_qr_response($r['body']));
}

function admin_master_whatsapp_disconnect(PDO $pdo): void
{
    $cred = evolution_master_credentials($pdo);
    $name = admin_master_whatsapp_instance($pdo);
    if ($cred !== null && $name !== '') {
        $enc = rawurlencode($name);
        foreach (evolution_instance_path_candidates($cred['url'], 'logout/' . $enc) as $path) {
            $dr = evolution_http_request('DELETE', $cred['url'], $cred['apikey'], $path, null);
            if ($dr['ok'] || (int) ($dr['status'] ?? 0) !== 404) {
                break;
            }
        }
    }
    admin_master_whatsapp_set_instance($pdo, null);
    json_response(200, admin_master_whatsapp_status_payload($pdo));
}
