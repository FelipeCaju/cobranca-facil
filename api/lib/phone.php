<?php

declare(strict_types=1);

/** Apenas dígitos; Brasil sem DDI vira 55 + número. */
function cobx_normalize_phone(string $raw): string
{
    $d = preg_replace('/\D+/', '', trim($raw)) ?? '';
    if ($d === '') {
        return '';
    }
    if (strlen($d) >= 10 && strlen($d) <= 11 && !str_starts_with($d, '55')) {
        $d = '55' . ltrim($d, '0');
    }

    return $d;
}
