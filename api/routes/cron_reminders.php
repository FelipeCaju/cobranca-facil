<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/integration_queue.php';

require_once __DIR__ . '/company_messages.php';
require_once __DIR__ . '/../lib/mail.php';
require_once __DIR__ . '/../lib/evolution_http.php';

/**
 * Marca parcelas em atraso, depois envia lembretes respeitando horário de início e intervalo entre clientes.
 *
 * @return array<string, mixed>
 */
function cobx_cron_run_reminders(PDO $pdo): array
{
    $out = [
        'overdue_updated' => 0,
        'companies_processed' => 0,
        'skipped_before_start_time' => 0,
        'sent_whatsapp' => 0,
        'sent_email' => 0,
        'failed' => 0,
        'details' => [],
    ];

    try {
        cobx_reminder_ensure_dispatch_log($pdo);
        cobx_reminder_ensure_delivery_log($pdo);
        $n = $pdo->exec(
            "UPDATE installments i
             INNER JOIN companies c ON c.id = i.company_id
             SET i.status = 'overdue', i.updated_at = NOW(3)
             WHERE i.status = 'pending' AND i.due_date < CURDATE() AND c.is_active = 1"
        );
        $out['overdue_updated'] = (int) $n;
    } catch (Throwable $e) {
        $out['details'][] = 'overdue: ' . $e->getMessage();
    }

    $st = $pdo->query(
        "SELECT id, name, billing_reminder_start_time, billing_reminder_gap_seconds, evolution_instance_name
         FROM companies WHERE is_active = 1"
    );
    if ($st === false) {
        return $out;
    }

    while ($co = $st->fetch(PDO::FETCH_ASSOC)) {
        $cid = (string) $co['id'];
        try {
            cobx_message_templates_ensure($pdo, $cid);
        } catch (Throwable $e) {
            $out['details'][] = $cid . ' templates: ' . $e->getMessage();
            continue;
        }

        if (!cobx_cron_past_reminder_start((string) ($co['billing_reminder_start_time'] ?? '08:00:00'))) {
            $out['skipped_before_start_time']++;
            continue;
        }

        $gap = max(5, min(3600, (int) ($co['billing_reminder_gap_seconds'] ?? 60)));
        $tplSt = $pdo->prepare(
            "SELECT * FROM message_templates WHERE company_id = ? AND is_active = 1 AND channel IN ('whatsapp','email')"
        );
        $tplSt->execute([$cid]);
        $templates = $tplSt->fetchAll(PDO::FETCH_ASSOC);
        if ($templates === []) {
            continue;
        }

        $queue = [];
        foreach ($templates as $tpl) {
            $rows = cobx_reminder_select_pending($pdo, $cid, $tpl);
            foreach ($rows as $row) {
                $queue[] = ['tpl' => $tpl, 'row' => $row];
            }
        }

        if ($queue === []) {
            continue;
        }

        usort(
            $queue,
            static function (array $a, array $b): int {
                $ca = (string) ($a['row']['client_id'] ?? '');
                $cb = (string) ($b['row']['client_id'] ?? '');
                if ($ca !== $cb) {
                    return strcmp($ca, $cb);
                }

                return strcmp((string) ($a['row']['installment_id'] ?? ''), (string) ($b['row']['installment_id'] ?? ''));
            }
        );

        $out['companies_processed']++;
        $lastClient = null;
        foreach ($queue as $item) {
            $row = $item['row'];
            $tpl = $item['tpl'];
            $clientId = (string) ($row['client_id'] ?? '');
            if ($lastClient !== null && $clientId !== '' && $clientId !== $lastClient) {
                usleep($gap * 1_000_000);
            }
            $lastClient = $clientId;

            $ctx = cobx_reminder_build_context_row($row);
            $body = cobx_message_replace_placeholders((string) $tpl['body'], $ctx);
            $channel = (string) $tpl['channel'];
            $trigger = (string) $tpl['trigger_type'];
            $iid = (string) ($row['installment_id'] ?? '');
            $body = cobx_reminder_append_payment_parts($pdo, $cid, $ctx, $tpl, $body, $channel);

            if (!cobx_reminder_reserve_log($pdo, $cid, $iid, $channel, $trigger)) {
                continue;
            }

            $ok = false;
            if ($channel === 'email') {
                $ok = cobx_reminder_try_send_email($pdo, $cid, $ctx, $tpl, $body);
            } elseif ($channel === 'whatsapp') {
                $waDetail = '';
                $ok = cobx_reminder_try_send_whatsapp($pdo, $co, $ctx, $body, $waDetail);
            }

            if ($ok) {
                if ($channel === 'email') {
                    $out['sent_email']++;
                } else {
                    $out['sent_whatsapp']++;
                }
            } else {
                $out['failed']++;
                cobx_queue_enqueue($pdo, $cid, 'send_installment', ['installment_id'=>$iid]);
                if ($channel === 'whatsapp' && !empty($waDetail)) {
                    $out['details'][] = $cid . ' whatsapp: ' . $waDetail;
                }
            }
            cobx_reminder_log_delivery($pdo, $cid, $iid, $channel, $trigger, $ok, $ok ? 'Enviado.' : ($channel === 'whatsapp' ? ($waDetail ?? 'Falha no envio WhatsApp.') : 'Falha no envio de email.'));
        }
    }

    return $out;
}

function cobx_reminder_ensure_delivery_log(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS message_delivery_log (
      id CHAR(36) NOT NULL PRIMARY KEY, company_id CHAR(36) NOT NULL, installment_id CHAR(36) NOT NULL,
      channel VARCHAR(16) NOT NULL, trigger_type VARCHAR(64) NOT NULL, status ENUM('sent','failed') NOT NULL,
      detail TEXT NULL, created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
      KEY idx_message_delivery_company_created (company_id, created_at),
      KEY idx_message_delivery_installment (installment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function cobx_reminder_log_delivery(PDO $pdo, string $companyId, string $installmentId, string $channel, string $trigger, bool $ok, string $detail): void
{
    try {
        $pdo->prepare('INSERT INTO message_delivery_log (id, company_id, installment_id, channel, trigger_type, status, detail) VALUES (?,?,?,?,?,?,?)')
            ->execute([uuid_v4(), $companyId, $installmentId, $channel, $trigger, $ok ? 'sent' : 'failed', mb_substr($detail, 0, 4000)]);
    } catch (Throwable $e) {
        error_log('message_delivery_log: ' . $e->getMessage());
    }
}

function cobx_reminder_ensure_dispatch_log(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS reminder_dispatch_log (
          id CHAR(36) NOT NULL PRIMARY KEY,
          company_id CHAR(36) NOT NULL,
          installment_id CHAR(36) NOT NULL,
          channel VARCHAR(16) NOT NULL,
          trigger_type VARCHAR(64) NOT NULL,
          sent_on DATE NOT NULL,
          created_at DATETIME(3) NOT NULL DEFAULT (CURRENT_TIMESTAMP(3)),
          UNIQUE KEY uq_reminder_day (installment_id, channel, trigger_type, sent_on),
          KEY idx_reminder_company_day (company_id, sent_on)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function cobx_cron_past_reminder_start(string $startSql): bool
{
    $t = trim($startSql);
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?/', $t, $m)) {
        return true;
    }
    $start = sprintf('%02d:%02d:%02d', (int) $m[1], (int) $m[2], isset($m[3]) ? (int) $m[3] : 0);
    $now = (new DateTimeImmutable('now'))->format('H:i:s');

    return strcmp($now, $start) >= 0;
}

/**
 * @param array<string, mixed> $tpl
 * @return list<array<string, mixed>>
 */
function cobx_reminder_select_pending(PDO $pdo, string $companyId, array $tpl): array
{
    $channel = (string) ($tpl['channel'] ?? '');
    $trigger = (string) ($tpl['trigger_type'] ?? '');
    $d = max(0, min(120, (int) ($tpl['days_offset'] ?? 0)));
    if ($trigger === 'on_due') {
        $d = 0;
    }

    $statusSql = "i.status = 'pending'";
    if ($trigger === 'before_due') {
        if ($d < 1) {
            return [];
        }
        $dateSql = 'i.due_date > CURDATE() AND i.due_date <= DATE_ADD(CURDATE(), INTERVAL ' . (int) $d . ' DAY)';
    } elseif ($trigger === 'on_due') {
        $dateSql = 'i.due_date = CURDATE()';
    } elseif ($trigger === 'after_due') {
        $dateSql = 'i.due_date < CURDATE()';
        $statusSql = "i.status IN ('pending','overdue')";
    } else {
        return [];
    }

    $interestSelect = cobx_reminder_interest_sql($pdo);
    $paymentLinkSelect = cobx_reminder_payment_link_sql($pdo);

    $sql = "SELECT i.id AS installment_id, {$interestSelect} AS installment_amount, i.amount AS installment_original_amount, i.due_date, i.installment_number,
                   ch.id AS charge_id, ch.description AS charge_description, ch.total_amount AS charge_total,
                   ch.installments_count AS charge_installments_count,
                   {$paymentLinkSelect} AS payment_link,
                   " . cobx_reminder_installment_column_sql($pdo, 'pix_qrcode') . " AS pix_qrcode,
                   " . cobx_reminder_installment_column_sql($pdo, 'pix_copy_paste') . " AS pix_copy_paste,
                   cl.id AS client_id, cl.name AS client_name, cl.email AS client_email, cl.phone AS client_phone,
                   cl.document AS client_document,
                   cl.address_street, cl.address_number, cl.address_complement, cl.address_neighborhood,
                   cl.address_city, cl.address_state, cl.address_postal_code, cl.address_country,
                   co.name AS company_name, co.cnpj AS company_cnpj
            FROM installments i
            INNER JOIN charges ch ON ch.id = i.charge_id
            INNER JOIN clients cl ON cl.id = ch.client_id
            INNER JOIN companies co ON co.id = i.company_id
            LEFT JOIN products pr ON pr.id = ch.product_id
            LEFT JOIN reminder_dispatch_log lg ON lg.installment_id = i.id AND lg.channel = :ch
              AND lg.trigger_type = :tt AND lg.sent_on = CURDATE()
            WHERE i.company_id = :cid AND lg.id IS NULL AND {$statusSql} AND {$dateSql}";

    $st = $pdo->prepare($sql);
    $st->execute([':cid' => $companyId, ':ch' => $channel, ':tt' => $trigger]);

    $f = $st->fetchAll(PDO::FETCH_ASSOC);

    return is_array($f) ? $f : [];
}

function cobx_reminder_interest_sql(PDO $pdo): string
{
    if (!function_exists('cobx_charge_db_column_exists')) {
        require_once __DIR__ . '/../lib/charge_helpers.php';
    }
    if (
        !cobx_charge_db_column_exists($pdo, 'products', 'has_daily_interest')
        || !cobx_charge_db_column_exists($pdo, 'products', 'daily_interest_percent')
    ) {
        return 'i.amount';
    }

    return "ROUND(
        CASE
          WHEN i.due_date < CURDATE()
           AND IFNULL(pr.has_daily_interest, 0) = 1
           AND IFNULL(pr.daily_interest_percent, 0) > 0
          THEN i.amount * (1 + (IFNULL(pr.daily_interest_percent, 0) / 100) * DATEDIFF(CURDATE(), i.due_date))
          ELSE i.amount
        END,
        2
    )";
}

function cobx_reminder_payment_link_sql(PDO $pdo): string
{
    if (!function_exists('cobx_charge_db_column_exists')) {
        require_once __DIR__ . '/../lib/charge_helpers.php';
    }
    if (!cobx_charge_db_column_exists($pdo, 'installments', 'payment_url')) {
        return "''";
    }

    return 'IFNULL(i.payment_url, \'\')';
}

function cobx_reminder_installment_column_sql(PDO $pdo, string $column): string
{
    if (!function_exists('cobx_charge_db_column_exists')) {
        require_once __DIR__ . '/../lib/charge_helpers.php';
    }
    if (!preg_match('/^[a-z_]+$/', $column) || !cobx_charge_db_column_exists($pdo, 'installments', $column)) {
        return "''";
    }

    return 'IFNULL(i.' . $column . ', \'\')';
}

/**
 * @return array<string, mixed>|null
 */
function cobx_reminder_fetch_installment_context(PDO $pdo, string $companyId, string $installmentId): ?array
{
    $interestSelect = cobx_reminder_interest_sql($pdo);
    $paymentLinkSelect = cobx_reminder_payment_link_sql($pdo);

    $st = $pdo->prepare(
        "SELECT i.id AS installment_id, {$interestSelect} AS installment_amount, i.amount AS installment_original_amount, i.due_date, i.installment_number,
                ch.id AS charge_id, ch.description AS charge_description, ch.total_amount AS charge_total,
                ch.installments_count AS charge_installments_count,
                {$paymentLinkSelect} AS payment_link,
                " . cobx_reminder_installment_column_sql($pdo, 'pix_qrcode') . " AS pix_qrcode,
                " . cobx_reminder_installment_column_sql($pdo, 'pix_copy_paste') . " AS pix_copy_paste,
                cl.id AS client_id, cl.name AS client_name, cl.email AS client_email, cl.phone AS client_phone,
                cl.document AS client_document,
                cl.address_street, cl.address_number, cl.address_complement, cl.address_neighborhood,
                cl.address_city, cl.address_state, cl.address_postal_code, cl.address_country,
                co.name AS company_name, co.cnpj AS company_cnpj,
                co.id AS company_id, co.evolution_instance_name
         FROM installments i
         INNER JOIN charges ch ON ch.id = i.charge_id
         INNER JOIN clients cl ON cl.id = ch.client_id
         INNER JOIN companies co ON co.id = i.company_id
         LEFT JOIN products pr ON pr.id = ch.product_id
         WHERE i.id = ? AND i.company_id = ? AND i.status IN ('pending','overdue')
         LIMIT 1"
    );
    $st->execute([$installmentId, $companyId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function cobx_reminder_trigger_for_due_date(string $dueDate): string
{
    $today = (new DateTimeImmutable('today'))->format('Y-m-d');
    if ($dueDate > $today) {
        return 'before_due';
    }
    if ($dueDate === $today) {
        return 'on_due';
    }

    return 'after_due';
}

/**
 * Envio manual, sem gravar reminder_dispatch_log, para permitir reenvio imediato quando a empresa precisar.
 *
 * @return array{ok: bool, sent_whatsapp: int, sent_email: int, failed: int, trigger_type: string, details: list<string>}
 */
function cobx_reminder_send_installment_now(PDO $pdo, string $companyId, string $installmentId): array
{
    cobx_message_templates_ensure($pdo, $companyId);
    $row = cobx_reminder_fetch_installment_context($pdo, $companyId, $installmentId);
    if ($row === null) {
        return [
            'ok' => false,
            'sent_whatsapp' => 0,
            'sent_email' => 0,
            'failed' => 0,
            'trigger_type' => '',
            'details' => ['Parcela pendente não encontrada.'],
        ];
    }

    $trigger = cobx_reminder_trigger_for_due_date((string) ($row['due_date'] ?? ''));
    $tplSt = $pdo->prepare(
        "SELECT * FROM message_templates
         WHERE company_id = ? AND is_active = 1 AND trigger_type = ? AND channel IN ('whatsapp','email')
         ORDER BY channel"
    );
    $tplSt->execute([$companyId, $trigger]);
    $templates = $tplSt->fetchAll(PDO::FETCH_ASSOC);
    if ($templates === []) {
        return [
            'ok' => false,
            'sent_whatsapp' => 0,
            'sent_email' => 0,
            'failed' => 0,
            'trigger_type' => $trigger,
            'details' => ['Nenhum template ativo para o momento desta cobrança.'],
        ];
    }

    $ctx = cobx_reminder_build_context_row($row);
    $co = [
        'id' => $companyId,
        'evolution_instance_name' => (string) ($row['evolution_instance_name'] ?? ''),
    ];
    $out = [
        'ok' => false,
        'sent_whatsapp' => 0,
        'sent_email' => 0,
        'failed' => 0,
        'trigger_type' => $trigger,
        'details' => [],
    ];

    foreach ($templates as $tpl) {
        $body = cobx_message_replace_placeholders((string) $tpl['body'], $ctx);
        $channel = (string) $tpl['channel'];
        $body = cobx_reminder_append_payment_parts($pdo, $companyId, $ctx, $tpl, $body, $channel);
        $ok = false;
        if ($channel === 'email') {
            $ok = cobx_reminder_try_send_email($pdo, $companyId, $ctx, $tpl, $body);
        } elseif ($channel === 'whatsapp') {
            $waDetail = '';
            $ok = cobx_reminder_try_send_whatsapp($pdo, $co, $ctx, $body, $waDetail);
        }

        if ($ok && $channel === 'email') {
            $out['sent_email']++;
        } elseif ($ok && $channel === 'whatsapp') {
            $out['sent_whatsapp']++;
            if (!empty($waDetail)) {
                $out['details'][] = 'WhatsApp enviado, mas houve aviso: ' . $waDetail;
            }
        } else {
            $out['failed']++;
            $out['details'][] = $channel === 'email'
                ? 'Falha ao enviar email.'
                : ('Falha ao enviar WhatsApp' . (!empty($waDetail) ? ': ' . $waDetail : '.'));
        }
    }

    $out['ok'] = ($out['sent_email'] + $out['sent_whatsapp']) > 0;

    return $out;
}

/** @param array<string, mixed> $row */
function cobx_reminder_build_context_row(array $row): array
{
    return [
        'client_name' => (string) ($row['client_name'] ?? ''),
        'client_email' => (string) ($row['client_email'] ?? ''),
        'client_phone' => (string) ($row['client_phone'] ?? ''),
        'client_document' => (string) ($row['client_document'] ?? ''),
        'address_street' => (string) ($row['address_street'] ?? ''),
        'address_number' => (string) ($row['address_number'] ?? ''),
        'address_complement' => (string) ($row['address_complement'] ?? ''),
        'address_neighborhood' => (string) ($row['address_neighborhood'] ?? ''),
        'address_city' => (string) ($row['address_city'] ?? ''),
        'address_state' => (string) ($row['address_state'] ?? ''),
        'address_postal_code' => (string) ($row['address_postal_code'] ?? ''),
        'address_country' => (string) ($row['address_country'] ?? ''),
        'installment_amount' => (string) ($row['installment_amount'] ?? '0'),
        'charge_total' => (string) ($row['charge_total'] ?? '0'),
        'due_date' => (string) ($row['due_date'] ?? ''),
        'charge_description' => (string) ($row['charge_description'] ?? ''),
        'company_name' => (string) ($row['company_name'] ?? ''),
        'company_cnpj' => (string) ($row['company_cnpj'] ?? ''),
        'installment_number' => (string) ($row['installment_number'] ?? ''),
        'charge_installments_count' => (string) ($row['charge_installments_count'] ?? ''),
        'charge_id' => (string) ($row['charge_id'] ?? ''),
        'payment_link' => (string) ($row['payment_link'] ?? ''),
        'pix_qrcode' => (string) ($row['pix_qrcode'] ?? ''),
        'pix_copy_paste' => (string) ($row['pix_copy_paste'] ?? ''),
    ];
}

/**
 * @param array<string, mixed> $ctx
 * @param array<string, mixed> $tpl
 */
function cobx_reminder_append_payment_parts(PDO $pdo, string $companyId, array $ctx, array $tpl, string $body, string $channel): string
{
    $parts = [];
    $paymentLink = trim((string) ($ctx['payment_link'] ?? ''));
    $pixCopy = trim((string) ($ctx['pix_copy_paste'] ?? ''));
    $pixQr = cobx_reminder_qr_source($ctx);

    $companyFlags = cobx_reminder_company_payment_message_flags($pdo, $companyId);
    $sendLink = $companyFlags['send_payment_link'];
    $sendCopy = (int) ($tpl['send_copy_paste'] ?? 1) === 1 && $companyFlags['send_copy_paste_key'];
    $sendQr = (int) ($tpl['send_qrcode'] ?? 0) === 1 || $companyFlags['send_qrcode'];

    if ($channel !== 'whatsapp' && $sendLink && $paymentLink !== '' && !str_contains($body, $paymentLink)) {
        $parts[] = "Link para pagamento:\n" . $paymentLink;
    }

    if ($sendQr && $pixQr !== '') {
        if (str_starts_with($pixQr, 'http://') || str_starts_with($pixQr, 'https://')) {
            if ($channel === 'email') {
                $parts[] = '<img alt="QR Code PIX" src="' . htmlspecialchars($pixQr, ENT_QUOTES, 'UTF-8') . '" style="max-width:260px;width:100%;height:auto;border:1px solid #ddd;padding:8px" />';
            } else {
                $parts[] = "QR Code PIX:\n" . $pixQr;
            }
        } elseif ($channel === 'email') {
            $src = str_starts_with($pixQr, 'data:image') ? $pixQr : 'data:image/png;base64,' . $pixQr;
            $parts[] = '<img alt="QR Code PIX" src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" style="max-width:260px;width:100%;height:auto;border:1px solid #ddd;padding:8px" />';
        }
    }
    if ($channel !== 'whatsapp' && $sendCopy && $pixCopy !== '' && !str_contains($body, $pixCopy)) {
        $parts[] = "PIX copia e cola:\n" . $pixCopy;
    }

    if ($parts === []) {
        return $body;
    }

    return rtrim($body) . "\n\n" . implode("\n\n", $parts);
}

/**
 * Retorna a imagem/URL do QR. Se o gateway não guardou imagem, gera uma URL de QR a partir do Pix copia e cola.
 *
 * @param array<string, mixed> $ctx
 */
function cobx_reminder_qr_source(array $ctx): string
{
    $pixQr = trim((string) ($ctx['pix_qrcode'] ?? ''));
    if ($pixQr !== '') {
        return $pixQr;
    }

    $pixCopy = trim((string) ($ctx['pix_copy_paste'] ?? ''));
    if ($pixCopy === '') {
        return '';
    }

    return 'https://quickchart.io/qr?size=320&margin=2&text=' . rawurlencode($pixCopy);
}

/**
 * Para WhatsApp, usar URL de QR gerada a partir do copia e cola evita uploads base64 pesados na Evolution.
 *
 * @param array<string, mixed> $ctx
 */
function cobx_reminder_whatsapp_qr_source(array $ctx): string
{
    $pixCopy = trim((string) ($ctx['pix_copy_paste'] ?? ''));
    if ($pixCopy !== '') {
        return 'https://quickchart.io/qr?size=320&margin=2&text=' . rawurlencode($pixCopy);
    }

    return cobx_reminder_qr_source($ctx);
}

/**
 * @return array{send_payment_link: bool, send_qrcode: bool, send_copy_paste_key: bool}
 */
function cobx_reminder_company_payment_message_flags(PDO $pdo, string $companyId): array
{
    try {
        $st = $pdo->prepare('SELECT send_payment_link, send_qrcode, send_copy_paste_key FROM companies WHERE id = ? LIMIT 1');
        $st->execute([$companyId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $sendQr = (int) ($row['send_qrcode'] ?? 0) === 1;
        $sendCopy = (int) ($row['send_copy_paste_key'] ?? 0) === 1;
        $sendLink = (int) ($row['send_payment_link'] ?? 0) === 1 && !$sendQr && !$sendCopy;

        return [
            'send_payment_link' => $sendLink,
            'send_qrcode' => $sendQr,
            'send_copy_paste_key' => $sendCopy,
        ];
    } catch (Throwable) {
        return ['send_payment_link' => false, 'send_qrcode' => true, 'send_copy_paste_key' => true];
    }
}

/**
 * @param array<string, mixed> $ctx
 * @param array<string, mixed> $tpl
 */
function cobx_reminder_try_send_email(PDO $pdo, string $companyId, array $ctx, array $tpl, string $bodyHtmlText): bool
{
    $to = trim((string) ($ctx['client_email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $cfg = cobx_resolve_smtp_for_company($pdo, $companyId);
    if ($cfg === null) {
        return false;
    }
    $sub = isset($tpl['subject']) ? trim((string) $tpl['subject']) : '';
    if ($sub === '') {
        $sub = 'Lembrete de pagamento';
    }
    $sub = cobx_message_replace_placeholders($sub, $ctx);
    $html = cobx_reminder_text_to_email_html($bodyHtmlText, $ctx);
    try {
        cobx_send_smtp_message($cfg, $to, (string) ($ctx['client_name'] ?? ''), $sub, $html, $bodyHtmlText);

        return true;
    } catch (Throwable) {
        return false;
    }
}

/** @param array<string, mixed> $ctx */
function cobx_reminder_text_to_email_html(string $text, array $ctx = []): string
{
    $tokens = [];
    $text = preg_replace_callback(
        '#<img\b[^>]*>#i',
        static function (array $m) use (&$tokens): string {
            $key = '%%COBX_IMG_' . count($tokens) . '%%';
            $tokens[$key] = $m[0];
            return $key;
        },
        $text
    ) ?? $text;

    $safe = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safe = preg_replace_callback(
        '#(https?://[^\s<]+)#',
        static function (array $m): string {
            $url = $m[1];
            return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . $url . '</a>';
        },
        $safe
    ) ?? $safe;

    foreach ($tokens as $key => $imgHtml) {
        $safe = str_replace(htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $imgHtml, $safe);
    }

    $company = htmlspecialchars((string) ($ctx['company_name'] ?? 'Cobrança Fácil'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $client = htmlspecialchars((string) ($ctx['client_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $amount = htmlspecialchars(cobx_reminder_money((string) ($ctx['installment_amount'] ?? '0')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $due = htmlspecialchars(cobx_reminder_date_br((string) ($ctx['due_date'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return '<!doctype html><html><body style="margin:0;background:#f3f5f8;padding:24px;font-family:Arial,Helvetica,sans-serif;color:#0f172a">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;border-collapse:collapse;background:#ffffff;border:1px solid #dbe2ea;border-radius:12px;overflow:hidden">'
        . '<tr><td style="background:#0f2347;color:#ffffff;padding:22px 26px">'
        . '<div style="font-size:13px;opacity:.82">Cobrança</div>'
        . '<div style="font-size:22px;font-weight:700;margin-top:4px">' . $company . '</div>'
        . '</td></tr>'
        . '<tr><td style="padding:24px 26px">'
        . ($client !== '' ? '<div style="font-size:16px;font-weight:700;margin-bottom:14px">Olá, ' . $client . '</div>' : '')
        . '<div style="font-size:15px;line-height:1.6;color:#334155">' . nl2br($safe, false) . '</div>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:22px 0;border-collapse:collapse;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px">'
        . '<tr><td style="padding:14px 16px;font-size:12px;color:#64748b">Valor</td><td style="padding:14px 16px;text-align:right;font-size:16px;font-weight:700">' . $amount . '</td></tr>'
        . '<tr><td style="padding:0 16px 14px;font-size:12px;color:#64748b">Vencimento</td><td style="padding:0 16px 14px;text-align:right;font-size:14px;font-weight:700">' . $due . '</td></tr>'
        . '</table>'
        . '</td></tr>'
        . '<tr><td style="padding:16px 26px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:12px;color:#64748b">Mensagem automática enviada por ' . $company . '.</td></tr>'
        . '</table>'
        . '</td></tr></table>'
        . '</body></html>';
}

function cobx_reminder_money(string $value): string
{
    return 'R$ ' . number_format((float) $value, 2, ',', '.');
}

function cobx_reminder_date_br(string $date): string
{
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date, $m)) {
        return $m[3] . '/' . $m[2] . '/' . $m[1];
    }

    return $date !== '' ? $date : '--';
}

/**
 * @param array<string, mixed> $co company row
 * @param array<string, mixed> $ctx
 */
function cobx_reminder_try_send_whatsapp(PDO $pdo, array $co, array $ctx, string $text, ?string &$detail = null): bool
{
    $phone = trim((string) ($ctx['client_phone'] ?? ''));
    if ($phone === '') {
        $detail = 'Cliente sem telefone/WhatsApp.';
        return false;
    }
    $inst = trim((string) ($co['evolution_instance_name'] ?? ''));
    if ($inst === '') {
        $detail = 'Empresa sem instância WhatsApp conectada.';
        return false;
    }
    $cred = evolution_resolve_credentials($pdo, (string) $co['id']);
    if ($cred === null) {
        $detail = 'Evolution API não configurada.';
        return false;
    }
    $r = cobx_evolution_send_text($cred['url'], $cred['apikey'], $inst, $phone, $text);
    $detail = (string) ($r['detail'] ?? '');
    if ($r['ok'] === true) {
        $flags = cobx_reminder_company_payment_message_flags($pdo, (string) $co['id']);
        $paymentLink = trim((string) ($ctx['payment_link'] ?? ''));
        if ($flags['send_payment_link'] && $paymentLink !== '') {
            $link = cobx_evolution_send_text($cred['url'], $cred['apikey'], $inst, $phone, "Link para pagamento:\n" . $paymentLink);
            if ($link['ok'] !== true) {
                $detail = trim($detail . ' Link: ' . (string) ($link['detail'] ?? 'Falha ao enviar link de pagamento.'));
            }
        }

        $pixQr = cobx_reminder_whatsapp_qr_source($ctx);
        if ($flags['send_qrcode'] && $pixQr !== '') {
            if (str_starts_with($pixQr, 'http://') || str_starts_with($pixQr, 'https://')) {
                $img = cobx_evolution_send_image_url($cred['url'], $cred['apikey'], $inst, $phone, $pixQr, 'QR Code PIX para pagamento');
                if ($img['ok'] !== true) {
                    $qrText = cobx_evolution_send_text($cred['url'], $cred['apikey'], $inst, $phone, "QR Code PIX:\n" . $pixQr);
                    if ($qrText['ok'] !== true) {
                        $detail = trim($detail . ' QR: ' . (string) ($img['detail'] ?? $qrText['detail'] ?? 'Falha ao enviar QR.'));
                    }
                }
            } else {
                $img = cobx_evolution_send_image_base64($cred['url'], $cred['apikey'], $inst, $phone, $pixQr, 'QR Code PIX para pagamento');
                if ($img['ok'] !== true) {
                    $detail = trim($detail . ' QR: ' . (string) ($img['detail'] ?? 'Falha ao enviar QR.'));
                }
            }
        }

        $pixCopy = trim((string) ($ctx['pix_copy_paste'] ?? ''));
        if ($flags['send_copy_paste_key'] && $pixCopy !== '') {
            $copyLabel = cobx_reminder_send_whatsapp_text_retry($cred, $inst, $phone, 'PIX copia e cola:', 2);
            if ($copyLabel['ok'] !== true) {
                $detail = trim($detail . ' PIX: ' . (string) ($copyLabel['detail'] ?? 'Falha ao enviar aviso do copia e cola.'));
            }

            usleep(1_200_000);
            $copy = cobx_evolution_send_pix_text($cred['url'], $cred['apikey'], $inst, $phone, ' ' . $pixCopy);
            if ($copy['ok'] !== true) {
                $copyFile = cobx_evolution_send_pix_text_file($cred['url'], $cred['apikey'], $inst, $phone, $pixCopy);
                if ($copyFile['ok'] !== true) {
                    $detail = trim($detail . ' PIX: ' . (string) ($copy['detail'] ?? $copyFile['detail'] ?? 'Falha ao enviar copia e cola.'));
                }
            }
        }
    }

    return $r['ok'] === true;
}

/**
 * @param array{url: string, apikey: string} $cred
 * @return array{ok: bool, status: int, detail: string}
 */
function cobx_reminder_send_whatsapp_text_retry(array $cred, string $instance, string $phone, string $text, int $attempts = 3): array
{
    $attempts = max(1, min(5, $attempts));
    $last = ['ok' => false, 'status' => 0, 'detail' => 'Falha ao enviar WhatsApp'];
    for ($i = 0; $i < $attempts; $i++) {
        if ($i > 0) {
            usleep(700_000 * $i);
        }
        $last = cobx_evolution_send_text($cred['url'], $cred['apikey'], $instance, $phone, $text);
        if ($last['ok'] === true) {
            return $last;
        }
    }

    return $last;
}

function cobx_reminder_reserve_log(PDO $pdo, string $companyId, string $installmentId, string $channel, string $triggerType): bool
{
    try {
        $st = $pdo->prepare(
            'INSERT IGNORE INTO reminder_dispatch_log (id, company_id, installment_id, channel, trigger_type, sent_on)
             VALUES (?,?,?,?,?, CURDATE())'
        );
        $st->execute([uuid_v4(), $companyId, $installmentId, $channel, $triggerType]);

        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('reminder_dispatch_log: ' . $e->getMessage());

        return false;
    }
}
