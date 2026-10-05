<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/lib/evolution_http.php';

function evo_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

evo_assert(evolution_normalize_base_url('https://evo.example/manager') === 'https://evo.example', 'remove /manager');
evo_assert(evolution_normalize_base_url('https://evo.example/manager/login') === 'https://evo.example', 'remove manager subpath');
evo_assert(evolution_normalize_base_url('https://evo.example/swagger/index.html') === 'https://evo.example', 'remove swagger subpath');
evo_assert(evolution_normalize_base_url('https://evo.example/api/') === 'https://evo.example/api', 'preserve /api');

evo_assert(evolution_parse_connection_state(['data' => ['Connected' => true, 'LoggedIn' => true]]) === 'open', 'parse GO logged in');
evo_assert(evolution_parse_connection_state(['data' => ['Connected' => true, 'LoggedIn' => false]]) === 'connecting', 'do not treat socket as logged in');
evo_assert(evolution_parse_connection_state(['data' => ['Connected' => false, 'LoggedIn' => false]]) === 'close', 'parse GO disconnected');
evo_assert(evolution_parse_connection_state(['instance' => ['state' => 'open']]) === 'open', 'preserve Node parser');

$png = str_repeat('A', 240);
$qr = evolution_parse_qr_response(['data' => ['qrcode' => $png, 'pairingCode' => 'ABCD1234']]);
evo_assert($qr['qrcode_base64'] === $png, 'parse GO qrcode');
evo_assert($qr['pairing_code'] === 'ABCD1234', 'parse GO pairing code');

$source = (string) file_get_contents(__DIR__ . '/../../api/lib/evolution_http.php');
evo_assert(str_contains($source, "'/instance/all'"), 'GO listing exists');
evo_assert(str_contains($source, "'/send/text'"), 'GO text exists');
evo_assert(str_contains($source, "'/send/media'"), 'GO media exists');

echo "Evolution GO smoke: OK\n";
