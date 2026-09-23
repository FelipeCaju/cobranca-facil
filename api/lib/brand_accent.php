<?php

declare(strict_types=1);

/** @return list<string> */
function brand_accent_allowed_keys(): array
{
    return ['amber', 'teal', 'blue', 'purple', 'rose', 'emerald', 'navy'];
}

function brand_accent_normalize(string $key): string
{
    $k = strtolower(trim($key));
    if (!in_array($k, brand_accent_allowed_keys(), true)) {
        return 'amber';
    }

    return $k;
}

function brand_accent_read(PDO $pdo): string
{
    try {
        $st = $pdo->query('SELECT brand_accent FROM master_settings WHERE id = 1 LIMIT 1');
        $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
        if ($row && isset($row['brand_accent'])) {
            return brand_accent_normalize((string) $row['brand_accent']);
        }
    } catch (Throwable) {
        /* coluna pode não existir antes da migração */
    }

    return 'amber';
}
