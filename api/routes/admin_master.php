<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/request.php';
require_once __DIR__ . '/../lib/brand_accent.php';
require_once __DIR__ . '/../lib/system_branding.php';
require_once __DIR__ . '/../lib/cronjob_org.php';
require_once __DIR__ . '/../lib/platform_urls.php';
require_once __DIR__ . '/admin_master_whatsapp.php';

/**
 * @param list<string> $seg após "admin/"
 */
function handle_admin(PDO $pdo, string $method, array $seg): void
{
    $ctx = require_auth_context($pdo);
    require_admin_role($ctx);

    $resource = $seg[0] ?? '';
    if ($resource === 'plans') {
        require_once __DIR__ . '/admin_plans.php';
        handle_admin_plans($pdo, $method, array_slice($seg, 1));
        return;
    }

    if ($resource === 'companies') {
        require_once __DIR__ . '/admin_companies.php';
        handle_admin_companies($pdo, $method, array_slice($seg, 1));
        return;
    }

    if ($resource === 'overview' && $method === 'GET') {
        require_once __DIR__ . '/admin_overview.php';
        admin_platform_overview($pdo);
        return;
    }

    if ($resource === 'master-whatsapp') {
        admin_master_whatsapp_dispatch($pdo, $method, $seg);
        return;
    }

    if ($resource !== 'master-settings') {
        json_response(404, ['error' => 'Recurso não encontrado']);
    }

    if ($method === 'GET') {
        $st = $pdo->query(
            'SELECT id, mercadopago_public_key, mercadopago_access_token, evolution_master_url, evolution_master_api_key,
              master_whatsapp_instance_name, notification_email, notification_phone,
              smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password, smtp_from_email, smtp_from_name,
              cron_secret, cronjob_api_key, cronjob_job_id, cron_schedule_hour, cron_schedule_minute, system_name, updated_at
             FROM master_settings WHERE id = 1 LIMIT 1'
        );
        $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
        if (!$row) {
            json_response(200, [
                'mercadopago_public_key' => '',
                'mercadopago_access_token_set' => false,
                'evolution_master_url' => '',
                'evolution_master_api_key_set' => false,
                'smtp_host' => '',
                'smtp_port' => 587,
                'smtp_encryption' => 'tls',
                'smtp_username' => '',
                'smtp_from_email' => '',
                'smtp_from_name' => '',
                'smtp_password_set' => false,
                'smtp_password_masked' => '',
                'cron_secret_set' => false,
                'cron_secret_masked' => '',
                'cron_endpoint_url' => cobx_cron_public_endpoint(),
                'mercadopago_plans_webhook_url' => cobx_mercadopago_plans_webhook_url(),
                'mercadopago_plans_external_reference_hint' => 'cobx:{company_id}:{plan_id}',
                'cronjob_api_key_set' => false,
                'cronjob_api_key_masked' => '',
                'cronjob_job_id' => null,
                'cron_schedule_hour' => 8,
                'cron_schedule_minute' => 0,
                'brand_accent' => 'amber',
                'master_whatsapp_instance_name' => '',
                'notification_email' => '',
                'notification_phone' => '',
                'system_name' => COBX_DEFAULT_SYSTEM_NAME,
            ]);
            return;
        }
        $tok = (string) (cobx_secret_decrypt($row['mercadopago_access_token'] ?? null) ?? '');
        $evo = trim((string) (cobx_secret_decrypt($row['evolution_master_api_key'] ?? null) ?? ''));
        $smtpPw = (string) (cobx_secret_decrypt($row['smtp_password'] ?? null) ?? '');
        $cron = (string) ($row['cron_secret'] ?? '');
        $cronjobKey = trim((string) ($row['cronjob_api_key'] ?? ''));
        json_response(200, [
            'mercadopago_public_key' => (string) ($row['mercadopago_public_key'] ?? ''),
            'mercadopago_access_token_set' => $tok !== '',
            'mercadopago_access_token_masked' => admin_mask_secret($tok),
            'evolution_master_url' => (string) ($row['evolution_master_url'] ?? ''),
            'evolution_master_api_key_set' => $evo !== '',
            'evolution_master_api_key_masked' => admin_mask_secret($evo),
            'smtp_host' => (string) ($row['smtp_host'] ?? ''),
            'smtp_port' => (int) ($row['smtp_port'] ?? 587),
            'smtp_encryption' => (string) ($row['smtp_encryption'] ?? 'tls'),
            'smtp_username' => (string) ($row['smtp_username'] ?? ''),
            'smtp_from_email' => (string) ($row['smtp_from_email'] ?? ''),
            'smtp_from_name' => (string) ($row['smtp_from_name'] ?? ''),
            'smtp_password_set' => $smtpPw !== '',
            'smtp_password_masked' => admin_mask_secret($smtpPw),
            'cron_secret_set' => $cron !== '',
            'cron_secret_masked' => admin_mask_secret($cron),
            'cron_endpoint_url' => cobx_cron_public_endpoint(),
            'mercadopago_plans_webhook_url' => cobx_mercadopago_plans_webhook_url(),
            'mercadopago_plans_external_reference_hint' => 'cobx:{company_id}:{plan_id}',
            'cronjob_api_key_set' => $cronjobKey !== '',
            'cronjob_api_key_masked' => admin_mask_secret($cronjobKey),
            'cronjob_job_id' => isset($row['cronjob_job_id']) && $row['cronjob_job_id'] !== null ? (int) $row['cronjob_job_id'] : null,
            'cron_schedule_hour' => (int) ($row['cron_schedule_hour'] ?? 8),
            'cron_schedule_minute' => (int) ($row['cron_schedule_minute'] ?? 0),
            'brand_accent' => brand_accent_read($pdo),
            'master_whatsapp_instance_name' => (string) ($row['master_whatsapp_instance_name'] ?? ''),
            'notification_email' => (string) ($row['notification_email'] ?? ''),
            'notification_phone' => (string) ($row['notification_phone'] ?? ''),
            'system_name' => cobx_system_name($pdo),
            'updated_at' => $row['updated_at'] ?? null,
        ]);
    }

    if ($method === 'PUT') {
        $in = json_input();
        $st = $pdo->prepare('SELECT * FROM master_settings WHERE id = 1 LIMIT 1');
        $st->execute();
        $cur = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        $mpk = array_key_exists('mercadopago_public_key', $in) ? trim((string) $in['mercadopago_public_key']) : (string) ($cur['mercadopago_public_key'] ?? '');
        $mat = array_key_exists('mercadopago_access_token', $in)
            ? trim((string) $in['mercadopago_access_token'])
            : (string) ($cur['mercadopago_access_token'] ?? '');
        if (array_key_exists('mercadopago_access_token', $in) && trim((string) $in['mercadopago_access_token']) === '') {
            $mat = (string) ($cur['mercadopago_access_token'] ?? '');
        } elseif (array_key_exists('mercadopago_access_token', $in)) {
            $mat = (string) cobx_secret_encrypt($mat);
        }
        $eurl = array_key_exists('evolution_master_url', $in) ? trim((string) $in['evolution_master_url']) : (string) ($cur['evolution_master_url'] ?? '');
        $ekey = array_key_exists('evolution_master_api_key', $in)
            ? trim((string) $in['evolution_master_api_key'])
            : (string) ($cur['evolution_master_api_key'] ?? '');
        if (array_key_exists('evolution_master_api_key', $in) && trim((string) $in['evolution_master_api_key']) === '') {
            $ekey = (string) ($cur['evolution_master_api_key'] ?? '');
        } elseif (array_key_exists('evolution_master_api_key', $in)) {
            $ekey = (string) cobx_secret_encrypt($ekey);
        }

        $smtpHost = array_key_exists('smtp_host', $in) ? trim((string) $in['smtp_host']) : (string) ($cur['smtp_host'] ?? '');
        $smtpPort = array_key_exists('smtp_port', $in) ? (int) $in['smtp_port'] : (int) ($cur['smtp_port'] ?? 587);
        if ($smtpPort < 1 || $smtpPort > 65535) {
            $smtpPort = 587;
        }
        $smtpEnc = array_key_exists('smtp_encryption', $in) ? strtolower(trim((string) $in['smtp_encryption'])) : (string) ($cur['smtp_encryption'] ?? 'tls');
        if (!in_array($smtpEnc, ['tls', 'ssl', 'none'], true)) {
            $smtpEnc = 'tls';
        }
        $smtpUser = array_key_exists('smtp_username', $in) ? trim((string) $in['smtp_username']) : (string) ($cur['smtp_username'] ?? '');
        $smtpFrom = array_key_exists('smtp_from_email', $in) ? trim((string) $in['smtp_from_email']) : (string) ($cur['smtp_from_email'] ?? '');
        $smtpFromName = array_key_exists('smtp_from_name', $in) ? trim((string) $in['smtp_from_name']) : (string) ($cur['smtp_from_name'] ?? '');
        $smtpPw = (string) ($cur['smtp_password'] ?? '');
        if (array_key_exists('smtp_password', $in)) {
            $newSmtpPw = trim((string) $in['smtp_password']);
            if ($newSmtpPw !== '') {
                $smtpPw = (string) cobx_secret_encrypt($newSmtpPw);
            }
        }

        $cronSec = (string) ($cur['cron_secret'] ?? '');
        if (array_key_exists('cron_secret', $in)) {
            $newCron = trim((string) $in['cron_secret']);
            if ($newCron !== '') {
                $cronSec = $newCron;
            }
        }

        $cronjobKey = (string) ($cur['cronjob_api_key'] ?? '');
        if (array_key_exists('cronjob_api_key', $in)) {
            $newCronjobKey = trim((string) $in['cronjob_api_key']);
            if ($newCronjobKey !== '') {
                $cronjobKey = $newCronjobKey;
            }
        }

        $cronHour = array_key_exists('cron_schedule_hour', $in)
            ? (int) $in['cron_schedule_hour']
            : (int) ($cur['cron_schedule_hour'] ?? 8);
        $cronMinute = array_key_exists('cron_schedule_minute', $in)
            ? (int) $in['cron_schedule_minute']
            : (int) ($cur['cron_schedule_minute'] ?? 0);
        $cronHour = max(0, min(23, $cronHour));
        $cronMinute = max(0, min(59, $cronMinute));

        $cronjobJobId = isset($cur['cronjob_job_id']) && $cur['cronjob_job_id'] !== null && $cur['cronjob_job_id'] !== ''
            ? (int) $cur['cronjob_job_id']
            : null;

        $brandAccent = array_key_exists('brand_accent', $in)
            ? brand_accent_normalize((string) $in['brand_accent'])
            : brand_accent_read($pdo);
        $notifyEmail = array_key_exists('notification_email', $in)
            ? strtolower(trim((string) $in['notification_email']))
            : trim((string) ($cur['notification_email'] ?? ''));
        if ($notifyEmail !== '' && !filter_var($notifyEmail, FILTER_VALIDATE_EMAIL)) {
            json_response(422, ['error' => 'Email de notificação inválido']);
        }
        $notifyPhone = array_key_exists('notification_phone', $in)
            ? trim((string) $in['notification_phone'])
            : trim((string) ($cur['notification_phone'] ?? ''));
        $masterInstance = array_key_exists('master_whatsapp_instance_name', $in)
            ? trim((string) $in['master_whatsapp_instance_name'])
            : trim((string) ($cur['master_whatsapp_instance_name'] ?? ''));
        $systemName = array_key_exists('system_name', $in)
            ? trim((string) $in['system_name'])
            : trim((string) ($cur['system_name'] ?? COBX_DEFAULT_SYSTEM_NAME));
        if ($systemName === '') {
            json_response(422, ['error' => 'Nome do sistema é obrigatório']);
        }
        if (mb_strlen($systemName) > 120) {
            json_response(422, ['error' => 'Nome do sistema: máximo 120 caracteres']);
        }

        if ($cur !== []) {
            $pdo->prepare(
                'UPDATE master_settings SET mercadopago_public_key=?, mercadopago_access_token=?, evolution_master_url=?, evolution_master_api_key=?,
                 master_whatsapp_instance_name=?, notification_email=?, notification_phone=?,
                 smtp_host=?, smtp_port=?, smtp_encryption=?, smtp_username=?, smtp_password=?, smtp_from_email=?, smtp_from_name=?,
                 cron_secret=?, cronjob_api_key=?, cronjob_job_id=?, cron_schedule_hour=?, cron_schedule_minute=?, brand_accent=?, system_name=?, updated_at=NOW(3) WHERE id=1'
            )->execute([
                $mpk,
                $mat,
                $eurl,
                $ekey,
                $masterInstance !== '' ? $masterInstance : null,
                $notifyEmail !== '' ? $notifyEmail : null,
                $notifyPhone !== '' ? $notifyPhone : null,
                $smtpHost !== '' ? $smtpHost : null,
                $smtpPort,
                $smtpEnc,
                $smtpUser !== '' ? $smtpUser : null,
                $smtpPw !== '' ? $smtpPw : null,
                $smtpFrom !== '' ? $smtpFrom : null,
                $smtpFromName !== '' ? $smtpFromName : null,
                $cronSec !== '' ? $cronSec : null,
                $cronjobKey !== '' ? $cronjobKey : null,
                $cronjobJobId,
                $cronHour,
                $cronMinute,
                $brandAccent,
                $systemName,
            ]);
        } else {
            $pdo->prepare(
                'INSERT INTO master_settings (id, mercadopago_public_key, mercadopago_access_token, evolution_master_url, evolution_master_api_key,
                  master_whatsapp_instance_name, notification_email, notification_phone,
                  smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password, smtp_from_email, smtp_from_name,
                  cron_secret, cronjob_api_key, cronjob_job_id, cron_schedule_hour, cron_schedule_minute, brand_accent, system_name)
                 VALUES (1,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $mpk, $mat, $eurl, $ekey,
                $masterInstance !== '' ? $masterInstance : null,
                $notifyEmail !== '' ? $notifyEmail : null,
                $notifyPhone !== '' ? $notifyPhone : null,
                $smtpHost !== '' ? $smtpHost : null,
                $smtpPort,
                $smtpEnc,
                $smtpUser !== '' ? $smtpUser : null,
                $smtpPw !== '' ? $smtpPw : null,
                $smtpFrom !== '' ? $smtpFrom : null,
                $smtpFromName !== '' ? $smtpFromName : null,
                $cronSec !== '' ? $cronSec : null,
                $cronjobKey !== '' ? $cronjobKey : null,
                $cronjobJobId,
                $cronHour,
                $cronMinute,
                $brandAccent,
                $systemName,
            ]);
        }

        $payload = ['ok' => true];
        if ($cronjobKey !== '') {
            $sync = cobx_cronjob_sync($pdo);
            $payload['cronjob_sync'] = $sync;
            if (!$sync['ok']) {
                json_response(422, [
                    'error' => $sync['error'] ?? 'Falha ao sincronizar com cron-job.org',
                    'ok' => false,
                    'cronjob_sync' => $sync,
                ]);
            }
        }

        json_response(200, $payload);
    }

    json_response(405, ['error' => 'Método não permitido']);
}

function admin_mask_secret(string $s): string
{
    if ($s === '') {
        return '';
    }
    if (strlen($s) <= 6) {
        return '****';
    }
    return '****' . substr($s, -4);
}
