<?php

declare(strict_types=1);

const COBX_DEFAULT_SYSTEM_NAME = 'CobrançaFácil';

function cobx_system_name(PDO $pdo): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $st = $pdo->query('SELECT system_name FROM master_settings WHERE id = 1 LIMIT 1');
        $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
        if ($row && isset($row['system_name'])) {
            $name = trim((string) $row['system_name']);
            if ($name !== '') {
                $cached = mb_substr($name, 0, 120);

                return $cached;
            }
        }
    } catch (Throwable $e) {
        // Coluna ainda não migrada
    }
    $cached = COBX_DEFAULT_SYSTEM_NAME;

    return $cached;
}

function cobx_system_description(PDO $pdo): string
{
    $name = cobx_system_name($pdo);

    return $name . ' — plataforma de cobranças automatizadas com PIX, lembretes por WhatsApp e email.';
}
