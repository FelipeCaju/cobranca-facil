<?php

declare(strict_types=1);

require_once __DIR__ . '/cron_reminders.php';
require_once __DIR__ . '/../lib/integration_queue.php';

/**
 * Tarefas agendadas (ex.: [cron-job.org](https://console.cron-job.org/jobs)).
 * Sem JWT: autenticação por chave partilhada (query ?key= ou cabeçalho X-Cron-Key).
 *
 * @param list<string> $segments segmentos completos da rota (ex.: cron, run)
 */
function handle_cron(PDO $pdo, string $method, array $segments): void
{
    if (($segments[1] ?? '') !== 'run') {
        json_response(404, ['error' => 'Rota não encontrada']);
    }
    if ($method !== 'GET' && $method !== 'POST') {
        json_response(405, ['error' => 'Método não permitido']);
    }

    $provided = trim((string) ($_GET['key'] ?? ''));
    if ($provided === '') {
        $provided = trim((string) ($_SERVER['HTTP_X_CRON_KEY'] ?? ''));
    }

    $st = $pdo->query('SELECT cron_secret FROM master_settings WHERE id = 1 LIMIT 1');
    $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
    $secret = $row ? (string) ($row['cron_secret'] ?? '') : '';
    if ($secret === '') {
        json_response(503, ['error' => 'Cron não configurado: defina o segredo em Configurações master → Crons.']);
    }
    if ($provided === '' || !hash_equals($secret, $provided)) {
        json_response(401, ['error' => 'Chave inválida']);
    }

    $ranAt = (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);

    $lockName = 'cobx_cron_run';
    $lockSt = $pdo->prepare('SELECT GET_LOCK(?, 0) AS locked');
    $lockSt->execute([$lockName]);
    $locked = (int) ($lockSt->fetch(PDO::FETCH_ASSOC)['locked'] ?? 0);
    if ($locked !== 1) {
        json_response(200, [
            'ok' => true,
            'ran_at' => $ranAt,
            'skipped' => true,
            'message' => 'Cron ignorado: já existe uma execução em andamento.',
        ]);
    }

    try {
        $reminders = cobx_cron_run_reminders($pdo);
        $queue = cobx_queue_process($pdo);
    } finally {
        $releaseSt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $releaseSt->execute([$lockName]);
    }

    json_response(200, [
        'ok' => true,
        'ran_at' => $ranAt,
        'message' => 'Cron executado: parcelas em atraso atualizadas e lembretes processados conforme horário e intervalo por empresa.',
        'reminders' => $reminders,
        'queue' => $queue,
    ]);
}
