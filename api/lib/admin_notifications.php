<?php

declare(strict_types=1);

require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/evolution_http.php';
require_once __DIR__ . '/system_branding.php';

/**
 * Destinatários de email para alertas da plataforma (SMTP master).
 * Ordem: notification_email (legado/opcional) → utilizadores admin → email remetente SMTP.
 *
 * @return list<string>
 */
function admin_notification_emails(PDO $pdo): array
{
    $targets = admin_notification_targets($pdo);
    $legacy = trim($targets['notification_email']);
    if ($legacy !== '' && filter_var($legacy, FILTER_VALIDATE_EMAIL)) {
        return [$legacy];
    }

    $st = $pdo->query(
        "SELECT DISTINCT LOWER(TRIM(u.email)) AS email
         FROM users u
         INNER JOIN user_roles ur ON ur.user_id = u.id
         WHERE ur.role = 'admin' AND TRIM(u.email) <> ''"
    );
    $emails = [];
    if ($st) {
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $row) {
            $e = strtolower(trim((string) $row));
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $emails[$e] = true;
            }
        }
    }
    if ($emails !== []) {
        return array_keys($emails);
    }

    $cfg = admin_master_smtp_cfg($pdo);
    if ($cfg !== null) {
        $from = trim($cfg['from_email']);
        if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return [$from];
        }
    }

    return [];
}

/**
 * @return array{notification_email: string, notification_phone: string, master_whatsapp_instance_name: string}
 */
function admin_notification_targets(PDO $pdo): array
{
    $st = $pdo->query(
        'SELECT notification_email, notification_phone, master_whatsapp_instance_name
         FROM master_settings WHERE id = 1 LIMIT 1'
    );
    $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
    if (!$row) {
        return ['notification_email' => '', 'notification_phone' => '', 'master_whatsapp_instance_name' => ''];
    }

    return [
        'notification_email' => trim((string) ($row['notification_email'] ?? '')),
        'notification_phone' => trim((string) ($row['notification_phone'] ?? '')),
        'master_whatsapp_instance_name' => trim((string) ($row['master_whatsapp_instance_name'] ?? '')),
    ];
}

/**
 * @return array{host: string, port: int, encryption: string, username: string, password: string, from_email: string, from_name: string}|null
 */
function admin_master_smtp_cfg(PDO $pdo): ?array
{
    $st = $pdo->query(
        'SELECT smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password, smtp_from_email, smtp_from_name
         FROM master_settings WHERE id = 1 LIMIT 1'
    );
    $m = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
    if (!$m) {
        return null;
    }
    $host = trim((string) ($m['smtp_host'] ?? ''));
    $from = trim((string) ($m['smtp_from_email'] ?? ''));
    $pw = (string) (cobx_secret_decrypt($m['smtp_password'] ?? null) ?? '');
    if ($host === '' || $from === '' || $pw === '') {
        return null;
    }

    return [
        'host' => $host,
        'port' => cobx_smtp_port((int) ($m['smtp_port'] ?? 587)),
        'encryption' => cobx_smtp_enc((string) ($m['smtp_encryption'] ?? 'tls')),
        'username' => trim((string) ($m['smtp_username'] ?? '')),
        'password' => $pw,
        'from_email' => $from,
        'from_name' => trim((string) ($m['smtp_from_name'] ?? '')),
    ];
}

/**
 * Notificação global para super admin (best-effort, sem quebrar fluxo principal).
 *
 * @param list<string> $lines
 */
function admin_notify_superadmin(PDO $pdo, string $eventCode, string $title, array $lines = []): void
{
    try {
        $targets = admin_notification_targets($pdo);
        $bodyText = implode("\n", array_filter($lines, static fn ($v) => trim((string) $v) !== ''));

        $cfg = admin_master_smtp_cfg($pdo);
        $emailRecipients = admin_notification_emails($pdo);
        if ($cfg !== null && $emailRecipients !== []) {
            try {
                $htmlLines = '';
                foreach ($lines as $line) {
                    $htmlLines .= '<li>' . htmlspecialchars((string) $line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
                }
                $html = '<h3>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h3>'
                    . '<p>Evento: <strong>' . htmlspecialchars($eventCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong></p>'
                    . ($htmlLines !== '' ? '<ul>' . $htmlLines . '</ul>' : '');
                foreach ($emailRecipients as $to) {
                    $prefix = '[' . cobx_system_name($pdo) . '] ';
                    cobx_send_smtp_message($cfg, $to, 'Administrador', $prefix . $title, $html, $title . "\n" . $bodyText);
                }
            } catch (Throwable $e) {
                error_log('[cobx admin notify][email] ' . $e->getMessage());
            }
        }

        $waInstance = cobx_master_whatsapp_instance_name($pdo);
        if ($targets['notification_phone'] !== '' && $waInstance !== '') {
            try {
                $cred = evolution_master_credentials($pdo);
                if ($cred !== null) {
                    $msg = '*' . $title . "*\n";
                    if ($bodyText !== '') {
                        $msg .= $bodyText . "\n";
                    }
                    $msg .= "\nEvento: " . $eventCode;
                    $r = cobx_evolution_send_text(
                        $cred['url'],
                        $cred['apikey'],
                        $waInstance,
                        $targets['notification_phone'],
                        $msg
                    );
                    if (!$r['ok']) {
                        error_log('[cobx admin notify][wa] HTTP ' . $r['status'] . ' ' . $r['detail']);
                    }
                }
            } catch (Throwable $e) {
                error_log('[cobx admin notify][wa] ' . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        error_log('[cobx admin notify] ' . $e->getMessage());
    }
}
