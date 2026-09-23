<?php

declare(strict_types=1);

require_once __DIR__ . '/phone.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/evolution_http.php';
require_once __DIR__ . '/admin_notifications.php';
require_once __DIR__ . '/system_branding.php';

/**
 * Email e WhatsApp de boas-vindas para quem acabou de criar conta (best-effort).
 */
function cobx_send_register_welcome(
    PDO $pdo,
    string $email,
    string $fullName,
    string $companyName,
    string $phoneDigits
): void {
    $appUrl = rtrim((string) env('APP_URL', ''), '/');
    $loginUrl = $appUrl !== '' ? $appUrl . '/login' : '/login';
    $displayName = $fullName !== '' ? $fullName : $companyName;
    $systemName = cobx_system_name($pdo);
    $safeName = htmlspecialchars($displayName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeCompany = htmlspecialchars($companyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeLogin = htmlspecialchars($loginUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeSystem = htmlspecialchars($systemName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $cfg = admin_master_smtp_cfg($pdo);
    if ($cfg !== null) {
        try {
            $subject = 'Bem-vindo ao ' . $systemName;
            $html = '<div style="font-family:system-ui,sans-serif;line-height:1.5;color:#1a2332">'
                . '<h2 style="color:#1e3a5f">Olá, ' . $safeName . '!</h2>'
                . '<p>Sua empresa <strong>' . $safeCompany . '</strong> foi cadastrada com sucesso no <strong>' . $safeSystem . '</strong>.</p>'
                . '<p>A partir de agora você pode gerir cobranças, clientes, lembretes por WhatsApp e email no painel.</p>'
                . '<p><a href="' . $safeLogin . '" style="display:inline-block;background:#e8a020;color:#1a2332;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:600">Acesse o painel</a></p>'
                . '<p style="font-size:12px;color:#5c6b7a">Se não foi você quem criou esta conta, contacte o suporte.</p>'
                . '</div>';
            $text = "Olá, {$displayName}!\n\nBem-vindo ao {$systemName}. Empresa: {$companyName}.\nAcesse o painel: {$loginUrl}";
            cobx_send_smtp_message($cfg, $email, $displayName, $subject, $html, $text);
        } catch (Throwable $e) {
            error_log('[cobx welcome][email] ' . $e->getMessage());
        }
    } else {
        error_log('[cobx welcome][email] SMTP master não configurado — email de boas-vindas não enviado para ' . $email);
    }

    if ($phoneDigits === '') {
        error_log('[cobx welcome][wa] telefone em falta');

        return;
    }

    $instance = cobx_master_whatsapp_instance_name($pdo);
    if ($instance === '') {
        error_log('[cobx welcome][wa] instância master WhatsApp não configurada');

        return;
    }

    try {
        $cred = evolution_master_credentials($pdo);
        if ($cred === null) {
            error_log('[cobx welcome][wa] Evolution master não configurada');

            return;
        }
        $waText = "Olá, *{$displayName}*! 👋\n\n"
            . "Bem-vindo ao *{$systemName}*. A empresa *{$companyName}* já está ativa.\n\n"
            . "Acesse o painel: {$loginUrl}\n\n"
            . "Qualquer dúvida, responda por aqui.";
        $r = cobx_evolution_send_text(
            $cred['url'],
            $cred['apikey'],
            $instance,
            $phoneDigits,
            $waText
        );
        if (!$r['ok']) {
            error_log('[cobx welcome][wa] ' . ($r['detail'] ?? 'falha') . ' HTTP ' . ($r['status'] ?? 0));
        }
    } catch (Throwable $e) {
        error_log('[cobx welcome][wa] ' . $e->getMessage());
    }
}
