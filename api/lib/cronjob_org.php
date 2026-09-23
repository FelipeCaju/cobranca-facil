<?php

declare(strict_types=1);

const COBX_CRONJOB_API_BASE = 'https://api.cron-job.org';
const COBX_CRONJOB_JOB_TITLE = 'Cobx — lembretes de cobrança';

/** URL pública do endpoint de cron (variável APP_URL no `.env`). */
function cobx_cron_public_endpoint(): ?string
{
    $b = rtrim((string) env('APP_URL', ''), '/');

    return $b !== '' ? $b . '/api/cron/run' : null;
}

/**
 * @return array{ok: bool, status: int, body: mixed, raw: string, error?: string}
 */
function cronjob_org_http(string $method, string $apiKey, string $path, ?array $jsonBody = null): array
{
    $url = COBX_CRONJOB_API_BASE . $path;
    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Accept: application/json',
    ];
    if ($jsonBody !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'raw' => '', 'error' => 'curl_init failed'];
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 45,
        ];
        curl_setopt_array($ch, $opts);
        if ($jsonBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody, JSON_THROW_ON_ERROR));
        }
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err !== '') {
            return ['ok' => false, 'status' => $status, 'body' => null, 'raw' => $err, 'error' => $err];
        }
    } else {
        $ctxHeaders = implode("\r\n", $headers) . "\r\n";
        if ($jsonBody !== null) {
            $ctxHeaders .= "Content-Type: application/json\r\n";
        }
        $opts = [
            'http' => [
                'method' => strtoupper($method),
                'header' => $ctxHeaders,
                'timeout' => 45,
                'ignore_errors' => true,
            ],
        ];
        if ($jsonBody !== null) {
            $opts['http']['content'] = json_encode($jsonBody, JSON_THROW_ON_ERROR);
        }
        $raw = @file_get_contents($url, false, stream_context_create($opts));
        if ($raw === false) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'raw' => '', 'error' => 'Pedido HTTP falhou'];
        }
        $status = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d+)/', $line, $m)) {
                    $status = (int) $m[1];
                    break;
                }
            }
        }
    }

    $decoded = null;
    if ($raw !== '') {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = null;
        }
    }

    $ok = $status >= 200 && $status < 300;

    return ['ok' => $ok, 'status' => $status, 'body' => $decoded, 'raw' => $raw];
}

function cronjob_org_schedule_timezone(): string
{
    $tz = trim((string) env('CRON_TIMEZONE', 'America/Sao_Paulo'));
    if ($tz === '') {
        return 'America/Sao_Paulo';
    }

    return $tz;
}

/**
 * @return array{hours: list<int>, minutes: list<int>, mdays: list<int>, months: list<int>, wdays: list<int>, timezone: string, expiresAt: int}
 */
function cronjob_org_every_minute_schedule(): array
{
    return [
        'timezone' => cronjob_org_schedule_timezone(),
        'expiresAt' => 0,
        'hours' => [-1],
        'mdays' => [-1],
        'minutes' => [-1],
        'months' => [-1],
        'wdays' => [-1],
    ];
}

function cronjob_org_build_job_url(string $endpointBase, string $cronSecret): string
{
    $base = rtrim($endpointBase, '/');
    $sep = str_contains($base, '?') ? '&' : '?';

    return $base . $sep . 'key=' . rawurlencode($cronSecret);
}

/**
 * @return array{ok: bool, action?: string, job_id?: int, message?: string, error?: string}
 */
function cobx_cronjob_sync(PDO $pdo): array
{
    $st = $pdo->query(
        'SELECT cron_secret, cronjob_api_key, cronjob_job_id, cron_schedule_hour, cron_schedule_minute
         FROM master_settings WHERE id = 1 LIMIT 1'
    );
    $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
    if (!$row) {
        return ['ok' => false, 'error' => 'Configurações master não encontradas'];
    }

    $apiKey = trim((string) ($row['cronjob_api_key'] ?? ''));
    if ($apiKey === '') {
        return ['ok' => true, 'action' => 'skipped', 'message' => 'API key cron-job.org não configurada'];
    }

    $endpoint = cobx_cron_public_endpoint();
    if ($endpoint === null || $endpoint === '') {
        return ['ok' => false, 'error' => 'Defina APP_URL no .env da API (URL pública do site).'];
    }

    $secret = trim((string) ($row['cron_secret'] ?? ''));
    if ($secret === '') {
        $secret = bin2hex(random_bytes(24));
        $pdo->prepare('UPDATE master_settings SET cron_secret = ?, updated_at = NOW(3) WHERE id = 1')
            ->execute([$secret]);
    }

    $jobUrl = cronjob_org_build_job_url($endpoint, $secret);

    $jobPayload = [
        'job' => [
            'title' => COBX_CRONJOB_JOB_TITLE,
            'enabled' => true,
            'saveResponses' => false,
            'url' => $jobUrl,
            'requestMethod' => 0,
            'schedule' => cronjob_org_every_minute_schedule(),
        ],
    ];

    $storedJobId = (int) ($row['cronjob_job_id'] ?? 0);
    if ($storedJobId > 0) {
        $patch = cronjob_org_http('PATCH', $apiKey, '/jobs/' . $storedJobId, $jobPayload);
        if ($patch['ok']) {
            return [
                'ok' => true,
                'action' => 'updated',
                'job_id' => $storedJobId,
                'message' => 'Job atualizado no cron-job.org (#' . $storedJobId . ') para verificar os envios a cada minuto.',
            ];
        }
        if ($patch['status'] !== 404) {
            return ['ok' => false, 'error' => cronjob_org_error_message($patch, 'Falha ao atualizar job no cron-job.org')];
        }
    }

    $remoteId = cronjob_org_find_existing_job_id($apiKey, $endpoint);
    if ($remoteId !== null) {
        $patch = cronjob_org_http('PATCH', $apiKey, '/jobs/' . $remoteId, $jobPayload);
        if (!$patch['ok']) {
            return ['ok' => false, 'error' => cronjob_org_error_message($patch, 'Falha ao atualizar job existente')];
        }
        $pdo->prepare('UPDATE master_settings SET cronjob_job_id = ?, updated_at = NOW(3) WHERE id = 1')
            ->execute([$remoteId]);

        return [
            'ok' => true,
            'action' => 'updated',
            'job_id' => $remoteId,
            'message' => 'Job existente associado e atualizado (#' . $remoteId . ') para verificar os envios a cada minuto.',
        ];
    }

    $create = cronjob_org_http('PUT', $apiKey, '/jobs', $jobPayload);
    if (!$create['ok']) {
        return ['ok' => false, 'error' => cronjob_org_error_message($create, 'Falha ao criar job no cron-job.org')];
    }
    $body = is_array($create['body']) ? $create['body'] : [];
    $newId = (int) ($body['jobId'] ?? 0);
    if ($newId <= 0) {
        return ['ok' => false, 'error' => 'Resposta inválida ao criar job (jobId em falta).'];
    }

    $pdo->prepare('UPDATE master_settings SET cronjob_job_id = ?, updated_at = NOW(3) WHERE id = 1')
        ->execute([$newId]);

    return [
        'ok' => true,
        'action' => 'created',
        'job_id' => $newId,
        'message' => 'Job criado no cron-job.org (#' . $newId . ') para verificar os envios a cada minuto.',
    ];
}

function cronjob_org_find_existing_job_id(string $apiKey, string $endpointBase): ?int
{
    $list = cronjob_org_http('GET', $apiKey, '/jobs');
    if (!$list['ok'] || !is_array($list['body'])) {
        return null;
    }
    $jobs = $list['body']['jobs'] ?? [];
    if (!is_array($jobs)) {
        return null;
    }

    $needle = '/api/cron/run';
    foreach ($jobs as $job) {
        if (!is_array($job)) {
            continue;
        }
        $jobId = (int) ($job['jobId'] ?? 0);
        $title = (string) ($job['title'] ?? '');
        $url = (string) ($job['url'] ?? '');
        if ($jobId <= 0) {
            continue;
        }
        if ($title === COBX_CRONJOB_JOB_TITLE || str_contains($url, $needle)) {
            return $jobId;
        }
    }

    return null;
}

/**
 * @param array{ok: bool, status: int, body: mixed, raw: string} $res
 */
function cronjob_org_error_message(array $res, string $fallback): string
{
    if (is_array($res['body'])) {
        $msg = trim((string) ($res['body']['error'] ?? $res['body']['message'] ?? ''));
        if ($msg !== '') {
            return $msg;
        }
    }
    if ($res['status'] === 401) {
        return 'API key cron-job.org inválida ou expirada.';
    }
    if ($res['status'] === 403) {
        return 'API key rejeitada (verifique restrição de IP na consola cron-job.org).';
    }
    if ($res['status'] === 429) {
        return 'Limite de pedidos da API cron-job.org excedido. Tente mais tarde.';
    }
    if ($res['status'] > 0) {
        return $fallback . ' (HTTP ' . $res['status'] . ')';
    }

    return $fallback;
}
