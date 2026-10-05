<?php

declare(strict_types=1);

/**
 * Aceita a URL copiada do painel e devolve a raiz REST.
 * Evolution GO serve o Manager em /manager, mas a API em /.
 */
function evolution_normalize_base_url(string $url): string
{
    $url = rtrim(trim($url), '/');
    $url = (string) preg_replace('#/manager(?:/.*)?$#i', '', $url);
    $url = (string) preg_replace('#/swagger(?:/.*)?$#i', '', $url);

    return rtrim($url, '/');
}

/**
 * @param 'apikey'|'bearer' $authMode
 * @return array{ok: bool, status: int, body: mixed, raw: string}
 */
function evolution_http_request(string $method, string $baseUrl, string $apiKey, string $path, ?array $jsonBody = null, string $authMode = 'apikey', int $timeoutSeconds = 90): array
{
    $baseUrl = evolution_normalize_base_url($baseUrl);
    $url = $baseUrl . $path;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'raw' => 'curl_init failed'];
        }
        if ($authMode === 'bearer') {
            $headers = [
                'Authorization: Bearer ' . $apiKey,
                'Accept: application/json',
            ];
        } else {
            $headers = [
                'apikey: ' . $apiKey,
                'Accept: application/json',
            ];
        }
        if ($jsonBody !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        $curlOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => max(5, min(90, $timeoutSeconds)),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_POSTREDIR => 3,
        ];
        if (function_exists('env') && env('EVOLUTION_SSL_VERIFY') === '0') {
            $curlOpts[CURLOPT_SSL_VERIFYPEER] = false;
            $curlOpts[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        curl_setopt_array($ch, $curlOpts);
        if ($jsonBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody, JSON_THROW_ON_ERROR));
        }
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err !== '') {
            return ['ok' => false, 'status' => $status, 'body' => null, 'raw' => $err];
        }
    } else {
        if ($authMode === 'bearer') {
            $ctxHeaders = "Authorization: Bearer {$apiKey}\r\nAccept: application/json\r\n";
        } else {
            $ctxHeaders = "apikey: {$apiKey}\r\nAccept: application/json\r\n";
        }
        if ($jsonBody !== null) {
            $ctxHeaders .= "Content-Type: application/json\r\n";
        }
        $opts = [
            'http' => [
                'method' => strtoupper($method),
                'header' => $ctxHeaders,
                'timeout' => max(5, min(90, $timeoutSeconds)),
                'ignore_errors' => true,
            ],
        ];
        if ($jsonBody !== null) {
            $opts['http']['content'] = json_encode($jsonBody, JSON_THROW_ON_ERROR);
        }
        $raw = @file_get_contents($url, false, stream_context_create($opts));
        if ($raw === false) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'raw' => 'file_get_contents failed'];
        }
        $status = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
                    $status = (int) $m[1];
                    break;
                }
            }
        }
    }

    $body = null;
    if ($raw !== '') {
        try {
            $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $body = null;
        }
    }

    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => $body, 'raw' => $raw];
}

/**
 * Identifica o contrato sem fazer operações mutáveis. O endpoint /server/ok é
 * próprio do Evolution GO; instalações Node continuam no contrato tradicional.
 * O cache vive somente durante o request PHP.
 *
 * @return 'go'|'api'
 */
function evolution_detect_provider(string $baseUrl, string $apiKey = ''): string
{
    static $cache = [];
    $baseUrl = evolution_normalize_base_url($baseUrl);
    $cacheKey = strtolower($baseUrl);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $probe = evolution_http_request('GET', $baseUrl, $apiKey, '/server/ok', null, 'apikey', 5);
    if ($probe['ok']) {
        return $cache[$cacheKey] = 'go';
    }

    return $cache[$cacheKey] = 'api';
}

/** Nome estável da instância Evolution por empresa (único no servidor Evolution). */
function evolution_instance_name_for_company(string $companyId): string
{
    $hex = str_replace('-', '', $companyId);

    return 'cobx' . substr($hex, 0, 20);
}

/**
 * Lê a primeira linha numérica (URL, API key) — FETCH_NUM evita falhas com nomes de colunas do driver.
 *
 * @param \PDOStatement $st
 */
function evolution_master_settings_row_from_stmt(\PDOStatement $st): ?array
{
    $row = $st->fetch(PDO::FETCH_NUM);
    if ($row === false) {
        return null;
    }
    $url = evolution_normalize_base_url((string) ($row[0] ?? ''));
    $key = trim((string) (cobx_secret_decrypt(isset($row[1]) ? (string) $row[1] : null) ?? ''));
    if ($url === '' || $key === '') {
        return null;
    }

    return ['evolution_master_url' => $url, 'evolution_master_api_key' => $key];
}

/**
 * Linha master_settings com URL e API key da Evolution (trim em ambos).
 *
 * @return array{evolution_master_url: string, evolution_master_api_key: string}|null
 */
function evolution_master_settings_row(PDO $pdo): ?array
{
    $st = $pdo->prepare('SELECT evolution_master_url, evolution_master_api_key FROM master_settings WHERE id = 1 LIMIT 1');
    $st->execute();
    $got = evolution_master_settings_row_from_stmt($st);
    if ($got !== null) {
        return $got;
    }
    $st2 = $pdo->prepare('SELECT evolution_master_url, evolution_master_api_key FROM master_settings ORDER BY id ASC LIMIT 1');
    $st2->execute();

    return evolution_master_settings_row_from_stmt($st2);
}

/**
 * Credenciais para chamadas à API Evolution: master_settings, depois .env, depois URL/token da empresa.
 *
 * @return array{url: string, apikey: string, global_apikey: string, provider: 'go'|'api'}|null
 */
function evolution_resolve_credentials(PDO $pdo, ?string $companyId = null): ?array
{
    $master = evolution_master_settings_row($pdo);
    if ($master !== null) {
        $url = $master['evolution_master_url'];
        $globalKey = $master['evolution_master_api_key'];
        $provider = evolution_detect_provider($url, $globalKey);
        $key = $globalKey;
        if ($provider === 'go' && $companyId !== null && $companyId !== '') {
            $st = $pdo->prepare('SELECT whatsapp_token FROM companies WHERE id = ? LIMIT 1');
            $st->execute([$companyId]);
            $stored = $st->fetchColumn();
            $instanceKey = trim((string) (cobx_secret_decrypt(is_string($stored) ? $stored : null) ?? ''));
            if ($instanceKey !== '') {
                $key = $instanceKey;
            }
        }
        return ['url' => $url, 'apikey' => $key, 'global_apikey' => $globalKey, 'provider' => $provider];
    }

    if (function_exists('env')) {
        $eu = trim((string) (env('EVOLUTION_MASTER_URL') ?? ''));
        $ek = trim((string) (env('EVOLUTION_MASTER_API_KEY') ?? ''));
        if ($eu !== '' && $ek !== '') {
            $eu = evolution_normalize_base_url($eu);
            return ['url' => $eu, 'apikey' => $ek, 'global_apikey' => $ek, 'provider' => evolution_detect_provider($eu, $ek)];
        }
    }

    if ($companyId !== null && $companyId !== '') {
        $stc = $pdo->prepare('SELECT whatsapp_api_url, whatsapp_token FROM companies WHERE id = ? LIMIT 1');
        $stc->execute([$companyId]);
        $num = $stc->fetch(PDO::FETCH_NUM);
        if ($num !== false) {
            $u = trim((string) ($num[0] ?? ''));
            $t = trim((string) (cobx_secret_decrypt(isset($num[1]) ? (string) $num[1] : null) ?? ''));
            if ($u !== '' && $t !== '') {
                $u = evolution_normalize_base_url($u);
                return ['url' => $u, 'apikey' => $t, 'global_apikey' => $t, 'provider' => evolution_detect_provider($u, $t)];
            }
        }
    }

    return null;
}

/** @return array{url: string, apikey: string, global_apikey: string, provider: 'go'|'api'}|null */
function evolution_master_credentials(PDO $pdo): ?array
{
    $cred = evolution_resolve_credentials($pdo, null);
    if ($cred === null || $cred['provider'] !== 'go') {
        return $cred;
    }
    try {
        $st = $pdo->query('SELECT master_whatsapp_instance_token FROM master_settings WHERE id=1 LIMIT 1');
        $stored = $st ? $st->fetchColumn() : false;
        $token = trim((string) (cobx_secret_decrypt(is_string($stored) ? $stored : null) ?? ''));
        if ($token !== '') {
            $cred['apikey'] = $token;
        }
    } catch (Throwable $e) {
        // A migration ainda não foi aplicada; operações administrativas continuam usando a chave global.
    }
    return $cred;
}

/** @param mixed $body */
function evolution_parse_connection_state($body): string
{
    if (!is_array($body)) {
        return 'unknown';
    }
    if (isset($body['instance']['state']) && is_string($body['instance']['state'])) {
        return strtolower($body['instance']['state']);
    }
    if (isset($body['state']) && is_string($body['state'])) {
        return strtolower($body['state']);
    }
    if (isset($body['data']) && is_array($body['data'])) {
        if (array_key_exists('Connected', $body['data']) && array_key_exists('LoggedIn', $body['data'])) {
            $connected = filter_var($body['data']['Connected'], FILTER_VALIDATE_BOOLEAN);
            $loggedIn = filter_var($body['data']['LoggedIn'], FILTER_VALIDATE_BOOLEAN);
            if ($connected && $loggedIn) {
                return 'open';
            }
            return $connected ? 'connecting' : 'close';
        }
        if (array_key_exists('Connected', $body['data'])) {
            return filter_var($body['data']['Connected'], FILTER_VALIDATE_BOOLEAN) ? 'open' : 'close';
        }
        if (array_key_exists('connected', $body['data'])) {
            return filter_var($body['data']['connected'], FILTER_VALIDATE_BOOLEAN) ? 'open' : 'close';
        }
        return evolution_parse_connection_state($body['data']);
    }

    return 'unknown';
}

/**
 * Extrai imagem base64, código de emparelhamento numérico e string de ligação WhatsApp (Evolution v2 usa `code` para o QR).
 *
 * @return array{qrcode_base64: ?string, pairing_code: ?string, qrcode_connection_code: ?string}
 */
function evolution_parse_qr_response($body, int $depth = 0): array
{
    $empty = ['qrcode_base64' => null, 'pairing_code' => null, 'qrcode_connection_code' => null];
    if (!is_array($body) || $depth > 4) {
        return $empty;
    }

    $merge = static function (array $a, array $b): array {
        foreach (['qrcode_base64', 'pairing_code', 'qrcode_connection_code'] as $k) {
            if ($a[$k] === null && $b[$k] !== null && $b[$k] !== '') {
                $a[$k] = $b[$k];
            }
        }

        return $a;
    };

    $out = $empty;
    if (isset($body['data']) && is_array($body['data'])) {
        $out = $merge($out, evolution_parse_qr_response($body['data'], $depth + 1));
    }

    $setBase64 = static function (?string $current, string $s): ?string {
        $s = trim($s);
        if ($s === '') {
            return $current;
        }
        if (str_starts_with($s, 'data:image') && preg_match('#base64,(.+)$#s', $s, $m)) {
            return $current ?? trim($m[1]);
        }
        $compact = preg_replace('/\s+/', '', $s) ?? $s;
        if ($current === null && (str_starts_with($compact, 'iVBOR') || str_starts_with($compact, '/9j/') || strlen($compact) > 200)) {
            return $compact;
        }

        return $current;
    };

    foreach ($body as $k => $v) {
        if (!is_string($v) || $v === '') {
            continue;
        }
        $lk = strtolower((string) $k);
        if ($lk === 'pairingcode' || $lk === 'pairing_code') {
            $out['pairing_code'] = $out['pairing_code'] ?? $v;
        }
        if ($lk === 'base64') {
            $out['qrcode_base64'] = $setBase64($out['qrcode_base64'], $v);
        }
        if ($lk === 'qrcode' || $lk === 'qrcodebase64') {
            $out['qrcode_base64'] = $setBase64($out['qrcode_base64'], $v);
        }
    }

    if (isset($body['qrcode']) && is_array($body['qrcode'])) {
        $q = $body['qrcode'];
        foreach (['pairingCode', 'pairing_code'] as $pk) {
            if (isset($q[$pk]) && is_string($q[$pk]) && $q[$pk] !== '') {
                $out['pairing_code'] = $out['pairing_code'] ?? $q[$pk];
                break;
            }
        }
        foreach (['base64', 'code'] as $qk) {
            if (!isset($q[$qk]) || !is_string($q[$qk]) || $q[$qk] === '') {
                continue;
            }
            $s = $q[$qk];
            if ($qk === 'base64') {
                $out['qrcode_base64'] = $setBase64($out['qrcode_base64'], $s);
            } elseif (str_starts_with($s, 'data:image')) {
                $out['qrcode_base64'] = $setBase64($out['qrcode_base64'], $s);
            } elseif (strlen($s) > 8) {
                $out['qrcode_connection_code'] = $out['qrcode_connection_code'] ?? $s;
            }
        }
    }

    if (isset($body['code']) && is_string($body['code']) && $body['code'] !== '') {
        $c = $body['code'];
        if (str_starts_with($c, 'data:image')) {
            $out['qrcode_base64'] = $setBase64($out['qrcode_base64'], $c);
        } elseif (strlen($c) > 8) {
            $out['qrcode_connection_code'] = $out['qrcode_connection_code'] ?? $c;
        }
    }

    foreach (['qrCode', 'qrCodeString'] as $k) {
        if (!isset($body[$k]) || !is_string($body[$k]) || $body[$k] === '') {
            continue;
        }
        $s = $body[$k];
        $out['qrcode_base64'] = $setBase64($out['qrcode_base64'], $s);
        if ($out['qrcode_base64'] === null && strlen($s) > 8 && !str_starts_with($s, 'data:')) {
            $out['qrcode_connection_code'] = $out['qrcode_connection_code'] ?? $s;
        }
    }

    return $out;
}

/** @param mixed $body */
function evolution_extract_qrcode($body): ?string
{
    $p = evolution_parse_qr_response($body);

    return $p['qrcode_base64'];
}

/**
 * Caminhos /instance/... e /api/instance/... exceto quando a base já termina em /api.
 *
 * @return list<string>
 */
function evolution_instance_path_candidates(string $baseUrl, string $fragment): array
{
    $baseUrl = rtrim($baseUrl, '/');
    $paths = ['/instance/' . $fragment];
    if (!preg_match('#/api/?$#i', $baseUrl)) {
        $paths[] = '/api/instance/' . $fragment;
    }

    return $paths;
}

/** @param mixed $body */
function evolution_flatten_error_message($body): string
{
    if (!is_array($body)) {
        return '';
    }
    if (isset($body['error']) && is_string($body['error'])) {
        return $body['error'];
    }
    if (isset($body['message']) && is_string($body['message'])) {
        return $body['message'];
    }
    $resp = $body['response'] ?? null;
    if (is_array($resp) && isset($resp['message'])) {
        $m = $resp['message'];
        if (is_array($m)) {
            return implode(' ', array_map('strval', $m));
        }
        if (is_string($m)) {
            return $m;
        }
    }

    return '';
}

/** @return list<array<string, mixed>> */
function evolution_go_list_instances(string $baseUrl, string $globalApiKey): array
{
    $r = evolution_http_request('GET', $baseUrl, $globalApiKey, '/instance/all', null, 'apikey', 15);
    if (!$r['ok'] || !is_array($r['body'])) {
        return [];
    }
    $items = $r['body']['data'] ?? [];

    return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
}

/** @return array<string, mixed>|null */
function evolution_go_find_instance(string $baseUrl, string $globalApiKey, string $instanceName): ?array
{
    foreach (evolution_go_list_instances($baseUrl, $globalApiKey) as $item) {
        $name = trim((string) ($item['name'] ?? $item['instanceName'] ?? ''));
        if ($name !== '' && hash_equals($instanceName, $name)) {
            return $item;
        }
    }

    return null;
}

/** @return array{ok: bool, status: int, body: mixed, raw: string} */
function evolution_go_connect(string $baseUrl, string $instanceApiKey): array
{
    return evolution_http_request('POST', $baseUrl, $instanceApiKey, '/instance/connect', [
        'subscribe' => [],
        'immediate' => true,
    ], 'apikey', 30);
}

/** @return array{ok: bool, status: int, body: mixed, raw: string} */
function evolution_call_instance_status(string $baseUrl, string $apiKey, string $instanceName): array
{
    if (evolution_detect_provider($baseUrl, $apiKey) === 'go') {
        return evolution_http_request('GET', $baseUrl, $apiKey, '/instance/status', null, 'apikey', 15);
    }

    $enc = rawurlencode($instanceName);
    return evolution_http_get_path_variants($baseUrl, $apiKey, evolution_instance_path_candidates($baseUrl, 'connectionState/' . $enc));
}

/** @return array{ok: bool, status: int, body: mixed, raw: string} */
function evolution_call_instance_qr(string $baseUrl, string $apiKey, string $instanceName): array
{
    if (evolution_detect_provider($baseUrl, $apiKey) === 'go') {
        $qr = evolution_http_request('GET', $baseUrl, $apiKey, '/instance/qr', null, 'apikey', 15);
        if ($qr['ok']) {
            return $qr;
        }
        // Uma instância recém-criada precisa iniciar a sessão antes de expor o QR.
        if (in_array((int) $qr['status'], [400, 404, 409], true)) {
            $connect = evolution_go_connect($baseUrl, $apiKey);
            if (!$connect['ok']) {
                return $connect;
            }
            return evolution_http_request('GET', $baseUrl, $apiKey, '/instance/qr', null, 'apikey', 15);
        }
        return $qr;
    }

    $enc = rawurlencode($instanceName);
    return evolution_http_get_path_variants($baseUrl, $apiKey, evolution_instance_path_candidates($baseUrl, 'connect/' . $enc));
}

/** @return array{ok: bool, status: int, body: mixed, raw: string} */
function evolution_call_instance_logout(string $baseUrl, string $apiKey, string $instanceName): array
{
    if (evolution_detect_provider($baseUrl, $apiKey) === 'go') {
        return evolution_http_request('DELETE', $baseUrl, $apiKey, '/instance/logout', null, 'apikey', 20);
    }

    $last = ['ok' => false, 'status' => 0, 'body' => null, 'raw' => ''];
    $enc = rawurlencode($instanceName);
    foreach (evolution_instance_path_candidates($baseUrl, 'logout/' . $enc) as $path) {
        $last = evolution_http_request('DELETE', $baseUrl, $apiKey, $path, null);
        if ($last['ok'] || (int) $last['status'] !== 404) {
            break;
        }
    }

    return $last;
}

/**
 * Tenta POST /instance/create com caminhos e autenticação usados em instalações diferentes.
 *
 * @return array{ok: bool, status: int, body: mixed, raw: string, _evolution_path?: string, _evolution_auth?: string}
 */
function evolution_call_create_instance(string $baseUrl, string $apiKey, string $instanceName, ?string $instanceToken = null): array
{
    if (evolution_detect_provider($baseUrl, $apiKey) === 'go') {
        $token = trim((string) $instanceToken);
        if ($token === '') {
            $token = bin2hex(random_bytes(32));
        }
        $result = evolution_http_request('POST', $baseUrl, $apiKey, '/instance/create', [
            'name' => $instanceName,
            'token' => $token,
        ], 'apikey', 30);
        $result['_evolution_path'] = '/instance/create';
        $result['_evolution_auth'] = 'global-apikey';
        $result['_evolution_instance_token'] = $token;

        return $result;
    }

    $payload = [
        'instanceName' => $instanceName,
        'integration' => 'WHATSAPP-BAILEYS',
        'qrcode' => true,
    ];
    $paths = ['/instance/create'];
    if (!preg_match('#/api/?$#i', rtrim($baseUrl, '/'))) {
        $paths[] = '/api/instance/create';
    }
    $modes = ['apikey', 'bearer'];
    $last = ['ok' => false, 'status' => 0, 'body' => null, 'raw' => ''];
    foreach ($paths as $path) {
        foreach ($modes as $mode) {
            $last = evolution_http_request('POST', $baseUrl, $apiKey, $path, $payload, $mode);
            $last['_evolution_path'] = $path;
            $last['_evolution_auth'] = $mode;
            if ($last['ok']) {
                return $last;
            }
            if ($last['status'] === 403) {
                return $last;
            }
            if ($last['status'] !== 401 && $last['status'] !== 404) {
                return $last;
            }
        }
    }

    return $last;
}

/**
 * GET com variantes /instance/... e /api/instance/... (404 → tenta o outro).
 *
 * @param list<string> $pathCandidates
 * @return array{ok: bool, status: int, body: mixed, raw: string, path_used?: string}
 */
function evolution_http_get_path_variants(string $baseUrl, string $apiKey, array $pathCandidates, string $authMode = 'apikey'): array
{
    $last = ['ok' => false, 'status' => 0, 'body' => null, 'raw' => '', 'path_used' => ''];
    foreach ($pathCandidates as $path) {
        $last = evolution_http_request('GET', $baseUrl, $apiKey, $path, null, $authMode);
        $last['path_used'] = $path;
        if ($last['ok']) {
            return $last;
        }
        if ($last['status'] !== 404) {
            return $last;
        }
    }

    return $last;
}

/** Nome da instância Evolution master (BD ou padrão cobx-master). */
function cobx_master_whatsapp_instance_name(PDO $pdo): string
{
    $st = $pdo->query('SELECT master_whatsapp_instance_name FROM master_settings WHERE id = 1 LIMIT 1');
    $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
    $name = trim((string) ($row['master_whatsapp_instance_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }

    return 'cobx-master';
}

/**
 * Corpos JSON aceites por Evolution v1/v2 para sendText.
 *
 * @return list<array<string, mixed>>
 */
function cobx_evolution_send_text_payloads(string $digits, string $text): array
{
    $textMsg = ['textMessage' => ['text' => $text]];
    $plain = ['text' => $text];
    $options = ['options' => ['delay' => 1200, 'presence' => 'composing', 'linkPreview' => false]];
    $numbers = [$digits];
    if (strlen($digits) === 10 || strlen($digits) === 11) {
        array_unshift($numbers, '55' . $digits);
    }

    $out = [];
    foreach (array_values(array_unique($numbers)) as $number) {
        $out[] = array_merge(['number' => $number], $plain);
        $out[] = array_merge(['number' => $number], $plain, $options);
        $out[] = array_merge(['number' => $number . '@c.us'], $plain);
        $out[] = array_merge(['number' => $number . '@s.whatsapp.net'], $plain);
        $out[] = array_merge(['number' => $number], $textMsg);
        $out[] = array_merge(['number' => $number], $textMsg, $options);
        $out[] = array_merge(['number' => $number . '@s.whatsapp.net'], $textMsg);
    }

    return $out;
}

/**
 * Envio curto para Pix copia e cola. Evita tentativas longas que causam timeout no endpoint PHP.
 *
 * @return array{ok: bool, status: int, detail: string}
 */
function cobx_evolution_send_pix_text(string $baseUrl, string $apiKey, string $instanceName, string $phoneRaw, string $pixText): array
{
    $digits = preg_replace('/\D+/', '', $phoneRaw) ?? '';
    if ($digits === '' || strlen($digits) < 10 || trim($pixText) === '') {
        return ['ok' => false, 'status' => 0, 'detail' => 'Dados insuficientes para enviar PIX copia e cola'];
    }
    if (evolution_detect_provider($baseUrl, $apiKey) === 'go') {
        return cobx_evolution_go_send_text($baseUrl, $apiKey, $digits, $pixText, 800);
    }

    $numbers = [$digits];
    if (strlen($digits) === 10 || strlen($digits) === 11) {
        array_unshift($numbers, '55' . $digits);
    }
    $enc = rawurlencode($instanceName);
    $paths = evolution_instance_path_candidates($baseUrl, 'message/sendText/' . $enc);
    $paths[] = '/message/sendText/' . $enc;
    if (!preg_match('#/api/?$#i', rtrim($baseUrl, '/'))) {
        $paths[] = '/api/message/sendText/' . $enc;
    }
    $modes = ['apikey', 'bearer'];
    $last = ['ok' => false, 'status' => 0, 'body' => null, 'raw' => ''];

    foreach (array_values(array_unique($numbers)) as $number) {
        $payloads = [
            [
                'number' => $number,
                'text' => $pixText,
                'options' => ['delay' => 800, 'presence' => 'composing', 'linkPreview' => false],
            ],
            [
                'number' => $number . '@s.whatsapp.net',
                'text' => $pixText,
                'options' => ['delay' => 800, 'presence' => 'composing', 'linkPreview' => false],
            ],
            [
                'number' => $number,
                'textMessage' => ['text' => $pixText],
                'options' => ['delay' => 800, 'presence' => 'composing', 'linkPreview' => false],
            ],
        ];

        foreach ($paths as $path) {
            foreach ($payloads as $payload) {
                foreach ($modes as $mode) {
                    $last = evolution_http_request('POST', $baseUrl, $apiKey, $path, $payload, $mode, 4);
                    if ($last['ok']) {
                        return ['ok' => true, 'status' => (int) ($last['status'] ?? 200), 'detail' => ''];
                    }
                    if ((int) ($last['status'] ?? 0) === 0 || (int) ($last['status'] ?? 0) >= 500) {
                        $detail = evolution_flatten_error_message($last['body'] ?? null);

                        return [
                            'ok' => false,
                            'status' => (int) ($last['status'] ?? 0),
                            'detail' => $detail !== '' ? $detail : 'Evolution demorou para aceitar a chave PIX',
                        ];
                    }
                    if ((int) ($last['status'] ?? 0) === 403) {
                        break 3;
                    }
                }
            }
        }
    }

    $detail = evolution_flatten_error_message($last['body'] ?? null);

    return ['ok' => false, 'status' => (int) ($last['status'] ?? 0), 'detail' => $detail !== '' ? $detail : 'Falha ao enviar PIX copia e cola'];
}

/**
 * Envia o Pix copia e cola como arquivo .txt quando o WhatsApp/Evolution bloqueia texto puro do payload.
 *
 * @return array{ok: bool, status: int, detail: string}
 */
function cobx_evolution_send_pix_text_file(string $baseUrl, string $apiKey, string $instanceName, string $phoneRaw, string $pixText): array
{
    $digits = preg_replace('/\D+/', '', $phoneRaw) ?? '';
    $pixText = trim($pixText);
    if ($digits === '' || strlen($digits) < 10 || $pixText === '') {
        return ['ok' => false, 'status' => 0, 'detail' => 'Dados insuficientes para enviar arquivo PIX'];
    }
    if (evolution_detect_provider($baseUrl, $apiKey) === 'go') {
        return ['ok' => false, 'status' => 422, 'detail' => 'Evolution GO não aceita documento em base64; envie o PIX como texto ou URL pública.'];
    }

    $numbers = [$digits];
    if (strlen($digits) === 10 || strlen($digits) === 11) {
        array_unshift($numbers, '55' . $digits);
    }

    $media = base64_encode($pixText);
    $dataUri = 'data:text/plain;base64,' . $media;
    $enc = rawurlencode($instanceName);
    $paths = array_merge(
        evolution_instance_path_candidates($baseUrl, 'message/sendMedia/' . $enc),
        ['/message/sendMedia/' . $enc]
    );
    if (!preg_match('#/api/?$#i', rtrim($baseUrl, '/'))) {
        $paths[] = '/api/message/sendMedia/' . $enc;
    }
    $modes = ['apikey', 'bearer'];
    $last = ['ok' => false, 'status' => 0, 'body' => null, 'raw' => ''];

    foreach (array_values(array_unique($numbers)) as $number) {
        $payloads = [
            [
                'number' => $number,
                'mediatype' => 'document',
                'mimetype' => 'text/plain',
                'caption' => 'PIX copia e cola',
                'media' => $media,
                'fileName' => 'pix-copia-e-cola.txt',
            ],
            [
                'number' => $number,
                'mediaType' => 'document',
                'mimeType' => 'text/plain',
                'caption' => 'PIX copia e cola',
                'media' => $dataUri,
                'fileName' => 'pix-copia-e-cola.txt',
            ],
            [
                'number' => $number . '@s.whatsapp.net',
                'mediatype' => 'document',
                'mimetype' => 'text/plain',
                'caption' => 'PIX copia e cola',
                'media' => $media,
                'fileName' => 'pix-copia-e-cola.txt',
            ],
            [
                'number' => $number,
                'mediaMessage' => [
                    'mediatype' => 'document',
                    'mimetype' => 'text/plain',
                    'caption' => 'PIX copia e cola',
                    'media' => $media,
                    'fileName' => 'pix-copia-e-cola.txt',
                ],
            ],
        ];

        foreach ($paths as $path) {
            foreach ($payloads as $payload) {
                foreach ($modes as $mode) {
                    $last = evolution_http_request('POST', $baseUrl, $apiKey, $path, $payload, $mode, 8);
                    if ($last['ok']) {
                        return ['ok' => true, 'status' => (int) ($last['status'] ?? 200), 'detail' => ''];
                    }
                    if ((int) ($last['status'] ?? 0) === 403) {
                        break 3;
                    }
                }
            }
        }
    }

    $detail = evolution_flatten_error_message($last['body'] ?? null);

    return ['ok' => false, 'status' => (int) ($last['status'] ?? 0), 'detail' => $detail !== '' ? $detail : 'Falha ao enviar arquivo PIX'];
}

/**
 * Envia texto simples (Evolution API v1/v2). Tenta caminhos, auth e formatos de payload.
 *
 * @return array{ok: bool, status: int, detail: string}
 */
function cobx_evolution_send_text(string $baseUrl, string $apiKey, string $instanceName, string $phoneRaw, string $text): array
{
    $digits = preg_replace('/\D+/', '', $phoneRaw) ?? '';
    if ($digits === '' || strlen($digits) < 10) {
        return ['ok' => false, 'status' => 0, 'detail' => 'Telefone do cliente inválido para WhatsApp'];
    }
    if (evolution_detect_provider($baseUrl, $apiKey) === 'go') {
        return cobx_evolution_go_send_text($baseUrl, $apiKey, $digits, $text, 1200);
    }
    $enc = rawurlencode($instanceName);
    $payloads = cobx_evolution_send_text_payloads($digits, $text);
    $modes = ['apikey', 'bearer'];
    $paths = evolution_instance_path_candidates($baseUrl, 'message/sendText/' . $enc);
    $paths[] = '/message/sendText/' . $enc;
    if (!preg_match('#/api/?$#i', rtrim($baseUrl, '/'))) {
        $paths[] = '/api/message/sendText/' . $enc;
    }
    $last = ['ok' => false, 'status' => 0, 'body' => null, 'raw' => ''];
    foreach ($paths as $path) {
        foreach ($payloads as $payload) {
            foreach ($modes as $mode) {
                $last = evolution_http_request('POST', $baseUrl, $apiKey, $path, $payload, $mode);
                if ($last['ok']) {
                    return ['ok' => true, 'status' => (int) ($last['status'] ?? 200), 'detail' => ''];
                }
                if ((int) ($last['status'] ?? 0) === 403) {
                    break 2;
                }
            }
        }
    }
    $detail = evolution_flatten_error_message($last['body'] ?? null);

    return ['ok' => false, 'status' => (int) ($last['status'] ?? 0), 'detail' => $detail !== '' ? $detail : 'Falha ao enviar WhatsApp'];
}

/** @return array{ok: bool, status: int, detail: string} */
function cobx_evolution_go_send_text(string $baseUrl, string $instanceApiKey, string $phoneDigits, string $text, int $delay = 1200): array
{
    $number = preg_replace('/\D+/', '', $phoneDigits) ?? '';
    if (strlen($number) === 10 || strlen($number) === 11) {
        $number = '55' . $number;
    }
    $r = evolution_http_request('POST', $baseUrl, $instanceApiKey, '/send/text', [
        'number' => $number,
        'text' => $text,
        'delay' => max(0, $delay),
    ], 'apikey', 30);
    $detail = evolution_flatten_error_message($r['body'] ?? null);

    return [
        'ok' => (bool) $r['ok'],
        'status' => (int) $r['status'],
        'detail' => $r['ok'] ? '' : ($detail !== '' ? $detail : substr((string) ($r['raw'] ?? ''), 0, 300)),
    ];
}

/**
 * Envia uma imagem base64 como mídia, usado para QR Code PIX quando a Evolution aceitar sendMedia.
 *
 * @return array{ok: bool, status: int, detail: string}
 */
function cobx_evolution_send_image_base64(
    string $baseUrl,
    string $apiKey,
    string $instanceName,
    string $phoneRaw,
    string $base64,
    string $caption = ''
): array {
    $digits = preg_replace('/\D+/', '', $phoneRaw) ?? '';
    $base64 = trim($base64);
    if (str_starts_with($base64, 'data:image') && preg_match('#base64,(.+)$#s', $base64, $m)) {
        $base64 = trim($m[1]);
    }
    $dataUri = 'data:image/png;base64,' . $base64;
    if ($digits === '' || strlen($digits) < 10 || $base64 === '') {
        return ['ok' => false, 'status' => 0, 'detail' => 'Dados insuficientes para enviar imagem no WhatsApp'];
    }
    if (evolution_detect_provider($baseUrl, $apiKey) === 'go') {
        return ['ok' => false, 'status' => 422, 'detail' => 'Evolution GO exige URL pública para mídia; o texto do PIX continuará como fallback.'];
    }

    $numbers = [$digits];
    if (strlen($digits) === 10 || strlen($digits) === 11) {
        array_unshift($numbers, '55' . $digits);
    }
    $enc = rawurlencode($instanceName);
    $paths = array_merge(
        evolution_instance_path_candidates($baseUrl, 'message/sendMedia/' . $enc),
        evolution_instance_path_candidates($baseUrl, 'message/sendImage/' . $enc),
    );
    $paths[] = '/message/sendMedia/' . $enc;
    $paths[] = '/message/sendImage/' . $enc;
    if (!preg_match('#/api/?$#i', rtrim($baseUrl, '/'))) {
        $paths[] = '/api/message/sendMedia/' . $enc;
        $paths[] = '/api/message/sendImage/' . $enc;
    }
    $modes = ['apikey', 'bearer'];
    $last = ['ok' => false, 'status' => 0, 'body' => null, 'raw' => ''];

    foreach (array_values(array_unique($numbers)) as $number) {
        $payloads = [
            [
                'number' => $number,
                'mediatype' => 'image',
                'mimetype' => 'image/png',
                'caption' => $caption,
                'media' => $base64,
                'fileName' => 'qrcode-pix.png',
            ],
            [
                'number' => $number,
                'mediaType' => 'image',
                'mimeType' => 'image/png',
                'caption' => $caption,
                'media' => $dataUri,
                'fileName' => 'qrcode-pix.png',
            ],
            [
                'number' => $number,
                'base64' => $base64,
                'caption' => $caption,
                'fileName' => 'qrcode-pix.png',
            ],
            [
                'number' => $number . '@s.whatsapp.net',
                'mediatype' => 'image',
                'mimetype' => 'image/png',
                'caption' => $caption,
                'media' => $base64,
                'fileName' => 'qrcode-pix.png',
            ],
            [
                'number' => $number . '@c.us',
                'mediatype' => 'image',
                'mimetype' => 'image/png',
                'caption' => $caption,
                'media' => $base64,
                'fileName' => 'qrcode-pix.png',
            ],
            [
                'number' => $number,
                'mediaMessage' => [
                    'mediatype' => 'image',
                    'mimetype' => 'image/png',
                    'caption' => $caption,
                    'media' => $base64,
                    'fileName' => 'qrcode-pix.png',
                ],
            ],
            [
                'number' => $number,
                'mediaMessage' => [
                    'mediaType' => 'image',
                    'mimeType' => 'image/png',
                    'caption' => $caption,
                    'media' => $dataUri,
                    'fileName' => 'qrcode-pix.png',
                ],
            ],
        ];

        foreach ($paths as $path) {
            foreach ($payloads as $payload) {
                foreach ($modes as $mode) {
                    $last = evolution_http_request('POST', $baseUrl, $apiKey, $path, $payload, $mode);
                    if ($last['ok']) {
                        return ['ok' => true, 'status' => (int) ($last['status'] ?? 200), 'detail' => ''];
                    }
                    if ((int) ($last['status'] ?? 0) === 403) {
                        break 3;
                    }
                }
            }
        }
    }

    $detail = evolution_flatten_error_message($last['body'] ?? null);

    return ['ok' => false, 'status' => (int) ($last['status'] ?? 0), 'detail' => $detail !== '' ? $detail : 'Falha ao enviar imagem do QR Code'];
}

/**
 * Envia imagem por URL. Usado como fallback para QR Code gerado a partir do Pix copia e cola.
 *
 * @return array{ok: bool, status: int, detail: string}
 */
function cobx_evolution_send_image_url(
    string $baseUrl,
    string $apiKey,
    string $instanceName,
    string $phoneRaw,
    string $imageUrl,
    string $caption = ''
): array {
    $digits = preg_replace('/\D+/', '', $phoneRaw) ?? '';
    $imageUrl = trim($imageUrl);
    if ($digits === '' || strlen($digits) < 10 || !filter_var($imageUrl, FILTER_VALIDATE_URL)) {
        return ['ok' => false, 'status' => 0, 'detail' => 'Dados insuficientes para enviar URL de imagem no WhatsApp'];
    }
    if (evolution_detect_provider($baseUrl, $apiKey) === 'go') {
        $number = $digits;
        if (strlen($number) === 10 || strlen($number) === 11) {
            $number = '55' . $number;
        }
        $r = evolution_http_request('POST', $baseUrl, $apiKey, '/send/media', [
            'number' => $number,
            'type' => 'image',
            'url' => $imageUrl,
            'caption' => $caption,
            'filename' => 'qrcode-pix.png',
        ], 'apikey', 30);
        $detail = evolution_flatten_error_message($r['body'] ?? null);
        return [
            'ok' => (bool) $r['ok'],
            'status' => (int) $r['status'],
            'detail' => $r['ok'] ? '' : ($detail !== '' ? $detail : substr((string) ($r['raw'] ?? ''), 0, 300)),
        ];
    }

    $numbers = [$digits];
    if (strlen($digits) === 10 || strlen($digits) === 11) {
        array_unshift($numbers, '55' . $digits);
    }

    $enc = rawurlencode($instanceName);
    $paths = array_merge(
        evolution_instance_path_candidates($baseUrl, 'message/sendMedia/' . $enc),
        evolution_instance_path_candidates($baseUrl, 'message/sendImage/' . $enc),
    );
    $paths[] = '/message/sendMedia/' . $enc;
    $paths[] = '/message/sendImage/' . $enc;
    if (!preg_match('#/api/?$#i', rtrim($baseUrl, '/'))) {
        $paths[] = '/api/message/sendMedia/' . $enc;
        $paths[] = '/api/message/sendImage/' . $enc;
    }

    $modes = ['apikey', 'bearer'];
    $last = ['ok' => false, 'status' => 0, 'body' => null, 'raw' => ''];

    foreach (array_values(array_unique($numbers)) as $number) {
        $payloads = [
            [
                'number' => $number,
                'mediatype' => 'image',
                'mimetype' => 'image/png',
                'caption' => $caption,
                'media' => $imageUrl,
                'fileName' => 'qrcode-pix.png',
            ],
            [
                'number' => $number . '@s.whatsapp.net',
                'mediatype' => 'image',
                'mimetype' => 'image/png',
                'caption' => $caption,
                'media' => $imageUrl,
                'fileName' => 'qrcode-pix.png',
            ],
            [
                'number' => $number . '@c.us',
                'mediatype' => 'image',
                'mimetype' => 'image/png',
                'caption' => $caption,
                'media' => $imageUrl,
                'fileName' => 'qrcode-pix.png',
            ],
            [
                'number' => $number,
                'image' => $imageUrl,
                'caption' => $caption,
            ],
            [
                'number' => $number,
                'mediaMessage' => [
                    'mediatype' => 'image',
                    'mimetype' => 'image/png',
                    'caption' => $caption,
                    'media' => $imageUrl,
                    'fileName' => 'qrcode-pix.png',
                ],
            ],
        ];

        foreach ($paths as $path) {
            foreach ($payloads as $payload) {
                foreach ($modes as $mode) {
                    $last = evolution_http_request('POST', $baseUrl, $apiKey, $path, $payload, $mode);
                    if ($last['ok']) {
                        return ['ok' => true, 'status' => (int) ($last['status'] ?? 200), 'detail' => ''];
                    }
                    if ((int) ($last['status'] ?? 0) === 403) {
                        break 3;
                    }
                }
            }
        }
    }

    $detail = evolution_flatten_error_message($last['body'] ?? null);

    return ['ok' => false, 'status' => (int) ($last['status'] ?? 0), 'detail' => $detail !== '' ? $detail : 'Falha ao enviar URL do QR Code'];
}
