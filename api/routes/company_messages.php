<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/message_vars.php';

function company_message_settings(PDO $pdo, string $method, string $companyId): void
{
    try {
        cobx_message_settings_ensure_schema($pdo);
        if ($method === 'GET') {
            cobx_message_templates_ensure($pdo, $companyId);
            $st = $pdo->prepare(
                'SELECT send_payment_link, send_qrcode, send_copy_paste_key, billing_reminder_start_time, billing_reminder_gap_seconds
                 FROM companies WHERE id = ? LIMIT 1'
            );
            $st->execute([$companyId]);
            $co = $st->fetch(PDO::FETCH_ASSOC);
            if (!$co) {
                json_response(404, ['error' => 'Empresa não encontrada']);
            }
            $st = $pdo->prepare(
                'SELECT id, name, channel, trigger_type, days_offset, subject, body, is_active, send_qrcode, send_copy_paste
                 FROM message_templates WHERE company_id = ? ORDER BY trigger_type, channel'
            );
            $st->execute([$companyId]);
            $templates = $st->fetchAll(PDO::FETCH_ASSOC);
            $sendQr = (int) ($co['send_qrcode'] ?? 1) === 1;
            $sendCp = (int) ($co['send_copy_paste_key'] ?? 1) === 1;
            $sendLink = (int) ($co['send_payment_link'] ?? 0) === 1 && !$sendQr && !$sendCp;
            json_response(200, [
                'send_payment_link' => $sendLink,
                'send_qrcode' => $sendQr,
                'send_copy_paste_key' => $sendCp,
                'billing_reminder_start_time' => cobx_time_hhmm((string) ($co['billing_reminder_start_time'] ?? '08:00:00')),
                'billing_reminder_gap_seconds' => max(5, min(3600, (int) ($co['billing_reminder_gap_seconds'] ?? 60))),
                'templates' => $templates,
                'available_variables' => cobx_message_placeholder_catalog(),
            ]);
        }

        if ($method === 'PUT') {
            $in = json_input();
            $st = $pdo->prepare(
                'SELECT send_payment_link, send_qrcode, send_copy_paste_key, billing_reminder_start_time, billing_reminder_gap_seconds
                 FROM companies WHERE id = ? LIMIT 1'
            );
            $st->execute([$companyId]);
            $cur = $st->fetch(PDO::FETCH_ASSOC);
            if (!$cur) {
                json_response(404, ['error' => 'Empresa não encontrada']);
            }

            $sendLink = array_key_exists('send_payment_link', $in) ? (!empty($in['send_payment_link']) ? 1 : 0) : ((int) ($cur['send_payment_link'] ?? 0) === 1 ? 1 : 0);
            $sendQr = array_key_exists('send_qrcode', $in) ? (!empty($in['send_qrcode']) ? 1 : 0) : ((int) ($cur['send_qrcode'] ?? 1) === 1 ? 1 : 0);
            $sendCp = array_key_exists('send_copy_paste_key', $in) ? (!empty($in['send_copy_paste_key']) ? 1 : 0) : ((int) ($cur['send_copy_paste_key'] ?? 1) === 1 ? 1 : 0);
            if ($sendLink === 1) {
                $sendQr = 0;
                $sendCp = 0;
            } elseif ($sendQr === 1 || $sendCp === 1) {
                $sendLink = 0;
            }
            $start = array_key_exists('billing_reminder_start_time', $in)
                ? cobx_parse_hhmm_time((string) $in['billing_reminder_start_time'])
                : (string) ($cur['billing_reminder_start_time'] ?? '08:00:00');
            $gap = array_key_exists('billing_reminder_gap_seconds', $in)
                ? max(5, min(3600, (int) $in['billing_reminder_gap_seconds']))
                : max(5, min(3600, (int) ($cur['billing_reminder_gap_seconds'] ?? 60)));

            $pdo->prepare(
                'UPDATE companies SET send_payment_link=?, send_qrcode=?, send_copy_paste_key=?, billing_reminder_start_time=?, billing_reminder_gap_seconds=?, updated_at=NOW(3) WHERE id=?'
            )->execute([$sendLink, $sendQr, $sendCp, $start, $gap, $companyId]);

            if (isset($in['templates']) && is_array($in['templates'])) {
                company_message_templates_apply($pdo, $companyId, $in['templates']);
            }

            json_response(200, ['ok' => true]);
        }
    } catch (Throwable $e) {
        error_log('company_message_settings: ' . $e->getMessage());
        json_response(500, [
            'error' => 'Não foi possível carregar as configurações de mensagens agora. Confirme se a base foi criada com o database/mysql_schema.sql atual.',
        ]);
    }

    json_response(405, ['error' => 'Método não permitido']);
}

function cobx_message_settings_ensure_schema(PDO $pdo): void
{
    if (!cobx_has_column($pdo, 'companies', 'send_payment_link')) {
        $pdo->exec('ALTER TABLE companies ADD COLUMN send_payment_link TINYINT(1) NOT NULL DEFAULT 0 AFTER evolution_instance_name');
    }
    if (!cobx_has_column($pdo, 'companies', 'billing_reminder_start_time')) {
        $pdo->exec("ALTER TABLE companies ADD COLUMN billing_reminder_start_time TIME NOT NULL DEFAULT '08:00:00'");
    }
    if (!cobx_has_column($pdo, 'companies', 'billing_reminder_gap_seconds')) {
        $pdo->exec('ALTER TABLE companies ADD COLUMN billing_reminder_gap_seconds INT UNSIGNED NOT NULL DEFAULT 60');
    }
}

function cobx_has_column(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $st->execute([$table, $column]);

    return (int) $st->fetchColumn() > 0;
}

/** Normaliza TIME do MySQL para "HH:MM" do input type="time". */
function cobx_time_hhmm(string $sqlTime): string
{
    $t = trim($sqlTime);
    if (preg_match('/^(\d{1,2}):(\d{2})/', $t, $m)) {
        return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
    }

    return '08:00';
}

function cobx_parse_hhmm_time(string $in): string
{
    $in = trim($in);
    if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $in, $m)) {
        $h = max(0, min(23, (int) $m[1]));
        $i = max(0, min(59, (int) $m[2]));
        $s = isset($m[3]) ? max(0, min(59, (int) $m[3])) : 0;

        return sprintf('%02d:%02d:%02d', $h, $i, $s);
    }

    return '08:00:00';
}

function cobx_message_templates_ensure(PDO $pdo, string $companyId): void
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM message_templates WHERE company_id = ?');
    $st->execute([$companyId]);
    if ((int) $st->fetchColumn() > 0) {
        return;
    }

    $defs = cobx_default_message_template_rows($companyId);
    $ins = $pdo->prepare(
        'INSERT INTO message_templates (id, company_id, name, channel, trigger_type, days_offset, subject, body, is_active, send_qrcode, send_copy_paste)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    );
    foreach ($defs as $d) {
        $ins->execute([
            uuid_v4(),
            $companyId,
            $d['name'],
            $d['channel'],
            $d['trigger_type'],
            $d['days_offset'],
            $d['subject'],
            $d['body'],
            1,
            0,
            1,
        ]);
    }
}

/** @return list<array{name: string, channel: string, trigger_type: string, days_offset: int, subject: ?string, body: string}> */
function cobx_default_message_template_rows(string $companyId): array
{
    return [
        [
            'name' => 'WhatsApp — Antes do vencimento',
            'channel' => 'whatsapp',
            'trigger_type' => 'before_due',
            'days_offset' => 3,
            'subject' => null,
            'body' => "Olá {nome_cliente}! 👋\n\nLembramos que sua fatura de {valor} vence em {data_vencimento}.\n\nEvite juros, pague no prazo!\n\n{nome_empresa}",
        ],
        [
            'name' => 'Email — Antes do vencimento',
            'channel' => 'email',
            'trigger_type' => 'before_due',
            'days_offset' => 3,
            'subject' => 'Lembrete: Sua fatura vence em breve',
            'body' => "Prezado(a) {nome_cliente},\n\nGostaríamos de lembrar que sua fatura no valor de {valor} tem vencimento em {data_vencimento}.\n\nAtenciosamente,\n{nome_empresa}",
        ],
        [
            'name' => 'WhatsApp — No vencimento',
            'channel' => 'whatsapp',
            'trigger_type' => 'on_due',
            'days_offset' => 0,
            'subject' => null,
            'body' => "Olá {nome_cliente}! 📋\n\nSua fatura de {valor} vence HOJE ({data_vencimento}).\n\nPague via PIX para evitar juros!\n\n{nome_empresa}",
        ],
        [
            'name' => 'Email — No vencimento',
            'channel' => 'email',
            'trigger_type' => 'on_due',
            'days_offset' => 0,
            'subject' => 'Sua fatura vence hoje!',
            'body' => "Prezado(a) {nome_cliente},\n\nSua fatura no valor de {valor} vence hoje, {data_vencimento}.\n\nEfetue o pagamento para evitar encargos.\n\nAtenciosamente,\n{nome_empresa}",
        ],
        [
            'name' => 'WhatsApp — Após vencimento',
            'channel' => 'whatsapp',
            'trigger_type' => 'after_due',
            'days_offset' => 1,
            'subject' => null,
            'body' => "Olá {nome_cliente},\n\nIdentificamos que sua fatura de {valor} com vencimento em {data_vencimento} ainda não foi paga.\n\nRegularize para evitar encargos adicionais.\n\n{nome_empresa}",
        ],
        [
            'name' => 'Email — Após vencimento',
            'channel' => 'email',
            'trigger_type' => 'after_due',
            'days_offset' => 1,
            'subject' => 'Atenção: Fatura em atraso',
            'body' => "Prezado(a) {nome_cliente},\n\nSua fatura no valor de {valor}, com vencimento em {data_vencimento}, encontra-se em atraso.\n\nPor favor, regularize o pagamento o mais breve possível.\n\nAtenciosamente,\n{nome_empresa}",
        ],
    ];
}

/**
 * @param array<string, mixed> $templates keyed by trigger_type: before_due|on_due|after_due
 */
function company_message_templates_apply(PDO $pdo, string $companyId, array $templates): void
{
    $allowed = ['before_due', 'on_due', 'after_due'];
    foreach ($allowed as $trigger) {
        if (!isset($templates[$trigger]) || !is_array($templates[$trigger])) {
            continue;
        }
        $block = $templates[$trigger];
        $days = isset($block['days_offset']) ? max(0, min(120, (int) $block['days_offset'])) : 0;
        if ($trigger === 'on_due') {
            $days = 0;
        }

        if (isset($block['whatsapp']) && is_array($block['whatsapp'])) {
            $w = $block['whatsapp'];
            $body = isset($w['body']) ? (string) $w['body'] : '';
            if ($body !== '') {
                cobx_message_template_upsert($pdo, $companyId, 'whatsapp', $trigger, $days, null, $body, 'WhatsApp — ' . cobx_trigger_label($trigger));
            }
        }
        if (isset($block['email']) && is_array($block['email'])) {
            $e = $block['email'];
            $sub = isset($e['subject']) ? trim((string) $e['subject']) : '';
            $body = isset($e['body']) ? (string) $e['body'] : '';
            if ($body !== '') {
                cobx_message_template_upsert($pdo, $companyId, 'email', $trigger, $days, $sub !== '' ? $sub : null, $body, 'Email — ' . cobx_trigger_label($trigger));
            }
        }
    }
}

function cobx_trigger_label(string $t): string
{
    return match ($t) {
        'before_due' => 'Antes do vencimento',
        'on_due' => 'No vencimento',
        'after_due' => 'Após vencimento',
        default => $t,
    };
}

function cobx_message_template_upsert(
    PDO $pdo,
    string $companyId,
    string $channel,
    string $triggerType,
    int $daysOffset,
    ?string $subject,
    string $body,
    string $name
): void {
    $st = $pdo->prepare(
        'SELECT id FROM message_templates WHERE company_id = ? AND channel = ? AND trigger_type = ? LIMIT 1'
    );
    $st->execute([$companyId, $channel, $triggerType]);
    $id = $st->fetchColumn();
    if ($id) {
        $pdo->prepare(
            'UPDATE message_templates SET name=?, days_offset=?, subject=?, body=?, updated_at=NOW(3) WHERE id=?'
        )->execute([$name, $daysOffset, $subject, $body, (string) $id]);
    } else {
        $pdo->prepare(
            'INSERT INTO message_templates (id, company_id, name, channel, trigger_type, days_offset, subject, body, is_active, send_qrcode, send_copy_paste)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([uuid_v4(), $companyId, $name, $channel, $triggerType, $daysOffset, $subject, $body, 1, 0, 1]);
    }
}
