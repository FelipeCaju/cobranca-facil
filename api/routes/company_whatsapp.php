<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/evolution_http.php';

function company_whatsapp_instance_name(PDO $pdo, string $companyId): string
{
    $st = $pdo->prepare('SELECT evolution_instance_name FROM companies WHERE id = ? LIMIT 1');
    $st->execute([$companyId]);
    $nr = $st->fetch(PDO::FETCH_NUM);
    if ($nr === false || !isset($nr[0]) || $nr[0] === null) {
        return '';
    }

    return trim((string) $nr[0]);
}

function company_whatsapp_connection_dispatch(PDO $pdo, string $method, string $companyId, array $seg): void
{
    $sub = $seg[1] ?? null;
    if ($sub === 'create' && $method === 'POST') {
        company_whatsapp_create($pdo, $companyId);
        return;
    }
    if ($sub === 'qrcode' && $method === 'GET') {
        company_whatsapp_qrcode($pdo, $companyId);
        return;
    }
    if ($sub === 'disconnect' && $method === 'POST') {
        company_whatsapp_disconnect($pdo, $companyId);
        return;
    }
    if ($sub === null && $method === 'GET') {
        company_whatsapp_status($pdo, $companyId);
        return;
    }
    json_response(404, ['error' => 'Recurso não encontrado']);
}

function company_whatsapp_status(PDO $pdo, string $companyId): void
{
    try {
        json_response(200, company_whatsapp_status_payload($pdo, $companyId));
    } catch (Throwable $e) {
        error_log('company_whatsapp_status: ' . $e->getMessage());
        json_response(503, [
            'error' => 'Não foi possível ler o estado do WhatsApp. Confirme se a base foi criada com o database/mysql_schema.sql atual.',
        ]);
    }
}

/** @return array<string, mixed> */
function company_whatsapp_status_payload(PDO $pdo, string $companyId): array
{
    $cred = evolution_resolve_credentials($pdo, $companyId);
    $masterConfigured = $cred !== null;

    $instanceName = company_whatsapp_instance_name($pdo, $companyId);

    $out = [
        'instance_name' => $instanceName !== '' ? $instanceName : null,
        'connection_state' => 'none',
        'connected' => false,
        'qrcode_base64' => null,
        'pairing_code' => null,
        'qrcode_connection_code' => null,
    ];

    if (!$masterConfigured || $instanceName === '') {
        return $out;
    }

    $enc = rawurlencode($instanceName);
    $r = evolution_http_get_path_variants($cred['url'], $cred['apikey'], evolution_instance_path_candidates($cred['url'], 'connectionState/' . $enc));
    $state = 'unknown';
    if ($r['ok'] && is_array($r['body'])) {
        $state = evolution_parse_connection_state($r['body']);
    }
    $out['connection_state'] = $state;
    $out['connected'] = $state === 'open';

    if (!$out['connected']) {
        $r2 = evolution_http_get_path_variants($cred['url'], $cred['apikey'], evolution_instance_path_candidates($cred['url'], 'connect/' . $enc));
        if ($r2['ok']) {
            $qrp = evolution_parse_qr_response($r2['body']);
            $out['qrcode_base64'] = $qrp['qrcode_base64'];
            $out['pairing_code'] = $qrp['pairing_code'];
            $out['qrcode_connection_code'] = $qrp['qrcode_connection_code'];
        }
    }

    return $out;
}

function company_whatsapp_create(PDO $pdo, string $companyId): void
{
    $cred = evolution_resolve_credentials($pdo, $companyId);
    if ($cred === null) {
        json_response(422, ['error' => 'Não foi possível iniciar a ligação ao WhatsApp neste momento.']);
    }

    $existing = company_whatsapp_instance_name($pdo, $companyId);
    if ($existing !== '') {
        json_response(409, ['error' => 'Já existe uma instância. Desligue primeiro ou atualize o QR na opção «Atualizar QR code».']);
    }

    $name = evolution_instance_name_for_company($companyId);
    $res = evolution_call_create_instance($cred['url'], $cred['apikey'], $name);

    if (!$res['ok'] && (int) ($res['status'] ?? 0) === 403) {
        $enc = rawurlencode($name);
        $adopt = evolution_http_get_path_variants($cred['url'], $cred['apikey'], evolution_instance_path_candidates($cred['url'], 'connectionState/' . $enc));
        if ($adopt['ok']) {
            $pdo->prepare('UPDATE companies SET evolution_instance_name = ?, whatsapp_provider = ?, updated_at = NOW(3) WHERE id = ?')
                ->execute([$name, 'evolution', $companyId]);
            $parsedCreate = evolution_parse_qr_response($res['body']);
            $payload = company_whatsapp_status_payload($pdo, $companyId);
            foreach (['qrcode_base64', 'pairing_code', 'qrcode_connection_code'] as $f) {
                if (($payload[$f] ?? null) === null && ($parsedCreate[$f] ?? null) !== null) {
                    $payload[$f] = $parsedCreate[$f];
                }
            }
            company_whatsapp_create_response_finalize($payload, $name);
            json_response(201, $payload);
        }
        error_log(
            'cobx evolution: create 403, adoption failed name=' . $name
            . ' adopt_http=' . ($adopt['status'] ?? 0)
            . ' msg=' . evolution_flatten_error_message($res['body'])
        );
    }

    if (!$res['ok']) {
        $detail = evolution_flatten_error_message($res['body']);
        error_log(
            'cobx evolution: create failed status=' . ($res['status'] ?? 0)
            . ' path=' . ($res['_evolution_path'] ?? '')
            . ' auth=' . ($res['_evolution_auth'] ?? '')
            . ' raw=' . substr((string) ($res['raw'] ?? ''), 0, 800)
            . ' detail=' . $detail
        );
        json_response(502, ['error' => 'Não foi possível criar a sessão. Tente novamente mais tarde.']);
    }

    $pdo->prepare('UPDATE companies SET evolution_instance_name = ?, whatsapp_provider = ?, updated_at = NOW(3) WHERE id = ?')
        ->execute([$name, 'evolution', $companyId]);

    $parsedCreate = evolution_parse_qr_response($res['body']);
    $payload = company_whatsapp_status_payload($pdo, $companyId);
    foreach (['qrcode_base64', 'pairing_code', 'qrcode_connection_code'] as $f) {
        if (($payload[$f] ?? null) === null && ($parsedCreate[$f] ?? null) !== null) {
            $payload[$f] = $parsedCreate[$f];
        }
    }

    company_whatsapp_create_response_finalize($payload, $name);
    json_response(201, $payload);
}

/**
 * Garante que o JSON de sucesso inclui sempre o nome da instância (evita UI presa em «sem sessão»).
 *
 * @param array<string, mixed> $payload
 */
function company_whatsapp_create_response_finalize(array &$payload, string $instanceName): void
{
    $payload['instance_name'] = $instanceName;
}

function company_whatsapp_qrcode(PDO $pdo, string $companyId): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $cred = evolution_resolve_credentials($pdo, $companyId);
    if ($cred === null) {
        json_response(422, ['error' => 'Não foi possível atualizar o código neste momento.']);
    }

    $name = company_whatsapp_instance_name($pdo, $companyId);
    if ($name === '') {
        json_response(404, ['error' => 'Nenhuma instância. Use «Criar e conectar» primeiro.']);
    }

    $enc = rawurlencode($name);
    $r = evolution_http_get_path_variants($cred['url'], $cred['apikey'], evolution_instance_path_candidates($cred['url'], 'connect/' . $enc));
    if (!$r['ok']) {
        json_response(502, ['error' => 'Não foi possível obter o código. Tente novamente mais tarde.']);
    }

    $qrp = evolution_parse_qr_response($r['body']);
    $payload = [
        'instance_name' => $name,
        'connection_state' => 'connecting',
        'connected' => false,
        'qrcode_base64' => $qrp['qrcode_base64'],
        'pairing_code' => $qrp['pairing_code'],
        'qrcode_connection_code' => $qrp['qrcode_connection_code'],
        'qr_generated_at' => time(),
    ];

    json_response(200, $payload);
}

function company_whatsapp_disconnect(PDO $pdo, string $companyId): void
{
    $cred = evolution_resolve_credentials($pdo, $companyId);

    $name = company_whatsapp_instance_name($pdo, $companyId);

    if ($name !== '' && $cred !== null) {
        $enc = rawurlencode($name);
        foreach (evolution_instance_path_candidates($cred['url'], 'logout/' . $enc) as $lp) {
            $dr = evolution_http_request('DELETE', $cred['url'], $cred['apikey'], $lp, null);
            if ($dr['ok'] || (int) ($dr['status'] ?? 0) !== 404) {
                break;
            }
        }
    }

    $pdo->prepare('UPDATE companies SET evolution_instance_name = NULL, updated_at = NOW(3) WHERE id = ?')->execute([$companyId]);

    json_response(200, company_whatsapp_status_payload($pdo, $companyId));
}
