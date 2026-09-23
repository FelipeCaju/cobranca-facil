<?php

declare(strict_types=1);

/**
 * Metadados para crawlers (WhatsApp, Facebook) — leem o HTML servido, não o React.
 *
 * @return array{title: string, description: string, url: string, image: string}
 */
function cobx_public_page_meta(): array
{
    $defaultName = 'CobrançaFácil';
    $envPath = dirname(__DIR__) . '/.env';
    if (is_readable($envPath)) {
        require_once dirname(__DIR__) . '/api/common.php';
        load_env_file($envPath);
    }

    $appUrl = rtrim((string) (env('APP_URL') ?? ''), '/');
    if ($appUrl === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $appUrl = $scheme . '://' . $host;
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if (preg_match('#^(.*)/index\.php$#', $script, $m) && $m[1] !== '') {
            $appUrl .= rtrim($m[1], '/');
        }
    }

    $name = $defaultName;
    if (is_readable(dirname(__DIR__) . '/api/lib/system_branding.php')) {
        require_once dirname(__DIR__) . '/api/db.php';
        require_once dirname(__DIR__) . '/api/lib/system_branding.php';
        try {
            $pdo = db();
            $name = cobx_system_name($pdo);
        } catch (Throwable $e) {
            // BD indisponível — mantém padrão
        }
    }

    $description = $name . ' — plataforma de cobranças automatizadas com PIX, lembretes por WhatsApp e email.';
    $image = $appUrl . '/favicon.png';

    return [
        'title' => $name,
        'description' => $description,
        'url' => $appUrl . '/',
        'image' => $image,
    ];
}

function cobx_inject_public_page_meta(string $html): string
{
    $meta = cobx_public_page_meta();
    $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $html = preg_replace('#<meta[^>]*(lovable\.dev|Lovable)[^>]*>\s*#i', '', $html) ?? $html;
    $html = preg_replace('#<meta\s+name="author"[^>]*>\s*#i', '', $html) ?? $html;
    $html = preg_replace('#<title>[^<]*</title>\s*#i', '', $html) ?? $html;
    $html = preg_replace(
        '#<meta\s+(?:name="description"|property="og:[^"]+"|name="twitter:[^"]+")[^>]*>\s*#i',
        '',
        $html
    ) ?? $html;

    $block = '    <title>' . $e($meta['title']) . "</title>\n"
        . '    <meta name="description" content="' . $e($meta['description']) . "\" />\n"
        . '    <meta property="og:title" content="' . $e($meta['title']) . "\" />\n"
        . '    <meta property="og:description" content="' . $e($meta['description']) . "\" />\n"
        . '    <meta property="og:type" content="website" />\n'
        . '    <meta property="og:url" content="' . $e($meta['url']) . "\" />\n"
        . '    <meta property="og:image" content="' . $e($meta['image']) . "\" />\n"
        . '    <meta property="og:site_name" content="' . $e($meta['title']) . "\" />\n"
        . '    <meta name="twitter:card" content="summary" />\n'
        . '    <meta name="twitter:title" content="' . $e($meta['title']) . "\" />\n"
        . '    <meta name="twitter:description" content="' . $e($meta['description']) . "\" />\n"
        . '    <meta name="twitter:image" content="' . $e($meta['image']) . "\" />\n";

    $insertAt = null;
    if (preg_match('#<meta\s+name="viewport"[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $insertAt = $m[0][1] + strlen($m[0][0]);
    } elseif (preg_match('#<head[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $insertAt = $m[0][1] + strlen($m[0][0]);
    }
    if ($insertAt !== null) {
        return substr($html, 0, $insertAt) . "\n" . $block . substr($html, $insertAt);
    }

    return $html;
}
