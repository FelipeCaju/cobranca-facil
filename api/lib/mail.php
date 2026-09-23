<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

function cobx_mask_secret(string $s): string
{
    if ($s === '') {
        return '';
    }
    if (strlen($s) <= 6) {
        return '****';
    }
    return '****' . substr($s, -4);
}

/**
 * @return array{host: string, port: int, encryption: string, username: string, password: string, from_email: string, from_name: string, source: string}|null
 */
function cobx_resolve_smtp_for_company(PDO $pdo, string $companyId): ?array
{
    $st = $pdo->prepare(
        'SELECT smtp_use_custom, smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password, smtp_from_email, smtp_from_name
         FROM companies WHERE id = ? LIMIT 1'
    );
    $st->execute([$companyId]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) {
        return null;
    }
    $useCustom = (int) ($c['smtp_use_custom'] ?? 0) === 1;
    $hostC = trim((string) ($c['smtp_host'] ?? ''));
    $fromC = trim((string) ($c['smtp_from_email'] ?? ''));
    $pwC = (string) (cobx_secret_decrypt($c['smtp_password'] ?? null) ?? '');
    if ($useCustom && $hostC !== '' && $fromC !== '' && $pwC !== '') {
        return [
            'host' => $hostC,
            'port' => cobx_smtp_port((int) ($c['smtp_port'] ?? 587)),
            'encryption' => cobx_smtp_enc((string) ($c['smtp_encryption'] ?? 'tls')),
            'username' => trim((string) ($c['smtp_username'] ?? '')),
            'password' => $pwC,
            'from_email' => $fromC,
            'from_name' => trim((string) ($c['smtp_from_name'] ?? '')),
            'source' => 'company',
        ];
    }

    $stM = $pdo->query(
        'SELECT smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password, smtp_from_email, smtp_from_name
         FROM master_settings WHERE id = 1 LIMIT 1'
    );
    $m = $stM ? $stM->fetch(PDO::FETCH_ASSOC) : false;
    if (!$m) {
        return null;
    }
    $mh = trim((string) ($m['smtp_host'] ?? ''));
    $mf = trim((string) ($m['smtp_from_email'] ?? ''));
    $mpw = (string) (cobx_secret_decrypt($m['smtp_password'] ?? null) ?? '');
    if ($mh === '' || $mf === '' || $mpw === '') {
        return null;
    }
    return [
        'host' => $mh,
        'port' => cobx_smtp_port((int) ($m['smtp_port'] ?? 587)),
        'encryption' => cobx_smtp_enc((string) ($m['smtp_encryption'] ?? 'tls')),
        'username' => trim((string) ($m['smtp_username'] ?? '')),
        'password' => $mpw,
        'from_email' => $mf,
        'from_name' => trim((string) ($m['smtp_from_name'] ?? '')),
        'source' => 'master',
    ];
}

function cobx_master_smtp_ready(PDO $pdo): bool
{
    $st = $pdo->query(
        'SELECT smtp_host, smtp_from_email, smtp_password FROM master_settings WHERE id = 1 LIMIT 1'
    );
    $m = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
    if (!$m) {
        return false;
    }
    $h = trim((string) ($m['smtp_host'] ?? ''));
    $f = trim((string) ($m['smtp_from_email'] ?? ''));
    $p = (string) (cobx_secret_decrypt($m['smtp_password'] ?? null) ?? '');

    return $h !== '' && $f !== '' && $p !== '';
}

function cobx_smtp_port(int $p): int
{
    return $p >= 1 && $p <= 65535 ? $p : 587;
}

function cobx_smtp_enc(string $e): string
{
    $e = strtolower(trim($e));
    return in_array($e, ['tls', 'ssl', 'none'], true) ? $e : 'tls';
}

/**
 * @param array{host: string, port: int, encryption: string, username: string, password: string, from_email: string, from_name: string, source: string} $cfg
 */
function cobx_send_smtp_message(array $cfg, string $toEmail, string $toName, string $subject, string $html, ?string $text = null): void
{
    if (!class_exists(PHPMailer::class)) {
        throw new RuntimeException('PHPMailer não instalado. Na pasta api execute: composer install');
    }

    $mail = new PHPMailer(true);
    $mail->CharSet = PHPMailer::CHARSET_UTF8;
    $mail->isSMTP();
    $mail->Host = $cfg['host'];
    $mail->Port = $cfg['port'];
    $mail->SMTPAuth = $cfg['username'] !== '' || $cfg['password'] !== '';
    $mail->Username = $cfg['username'];
    $mail->Password = $cfg['password'];

    if ($cfg['encryption'] === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($cfg['encryption'] === 'tls') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } else {
        $mail->SMTPAutoTLS = false;
        $mail->SMTPSecure = '';
    }

    $mail->setFrom($cfg['from_email'], $cfg['from_name'] !== '' ? $cfg['from_name'] : $cfg['from_email']);
    $mail->addAddress($toEmail, $toName !== '' ? $toName : $toEmail);
    $mail->Subject = $subject;
    $mail->isHTML(true);
    $html = cobx_mail_embed_data_images($mail, $html);
    $mail->Body = $html;
    $mail->AltBody = $text ?? strip_tags($html);

    if (!$mail->send()) {
        throw new RuntimeException($mail->ErrorInfo !== '' ? $mail->ErrorInfo : 'Envio falhou');
    }
}

function cobx_mail_embed_data_images(PHPMailer $mail, string $html): string
{
    $index = 0;

    return preg_replace_callback(
        '#<img\b([^>]*?)\bsrc=["\']data:image/(png|jpeg|jpg);base64,([^"\']+)["\']([^>]*)>#i',
        static function (array $m) use ($mail, &$index): string {
            $type = strtolower($m[2]) === 'jpg' ? 'jpeg' : strtolower($m[2]);
            $raw = base64_decode(preg_replace('/\s+/', '', $m[3]) ?? '', true);
            if ($raw === false || $raw === '') {
                return $m[0];
            }
            $cid = 'cobx-img-' . (++$index);
            $name = $cid . '.' . ($type === 'jpeg' ? 'jpg' : $type);
            $mime = 'image/' . $type;
            $mail->addStringEmbeddedImage($raw, $cid, $name, 'base64', $mime);

            return '<img' . $m[1] . 'src="cid:' . $cid . '"' . $m[4] . '>';
        },
        $html
    ) ?? $html;
}
