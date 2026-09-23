<?php

declare(strict_types=1);

const COBX_VERSION_MAJOR = 1;
const COBX_VERSION_MINOR = 1;
const COBX_VERSION_PATCH = 0;
const COBX_VERSION_BUILD = '20260923.1';

function cobx_version_number(): string
{
    return COBX_VERSION_MAJOR . '.' . COBX_VERSION_MINOR . '.' . COBX_VERSION_PATCH;
}

function cobx_version_commit(): ?string
{
    $commit = trim((string) (getenv('APP_COMMIT_SHA') ?: ''));
    if ($commit === '' || preg_match('/^[a-f0-9]{7,64}$/i', $commit) !== 1) {
        return null;
    }

    return substr($commit, 0, 7);
}

function cobx_version_label(): string
{
    $label = 'Versão ' . cobx_version_number() . ' · Build ' . COBX_VERSION_BUILD;
    $commit = cobx_version_commit();

    return $commit === null ? $label : $label . ' · ' . $commit;
}

function cobx_version_payload(): array
{
    return [
        'version' => cobx_version_number(),
        'build' => COBX_VERSION_BUILD,
        'commit' => cobx_version_commit(),
        'label' => cobx_version_label(),
    ];
}
