<?php

declare(strict_types=1);

require_once __DIR__ . '/secrets.php';

final class CobxBankCertificateManager
{
    public function inspect(string $format, string $certificate, ?string $privateKey = null, ?string $password = null): array
    {
        $format = strtolower(trim($format));
        $certPem = $certificate;
        $keyPem = $privateKey ?? '';
        $chain = '';
        if (in_array($format, ['pfx', 'p12'], true)) {
            $decoded = base64_decode($certificate, true);
            if ($decoded === false) $decoded = $certificate;
            $bag = [];
            if (!@openssl_pkcs12_read($decoded, $bag, $password ?? '')) throw new InvalidArgumentException('PFX/P12 inválido ou senha incorreta.');
            $certPem = (string) ($bag['cert'] ?? '');
            $keyPem = (string) ($bag['pkey'] ?? '');
            $chain = implode("\n", array_map('strval', (array) ($bag['extracerts'] ?? [])));
        }
        if ($certPem === '' || @openssl_x509_read($certPem) === false) throw new InvalidArgumentException('Certificado inválido.');
        if ($keyPem !== '' && @openssl_pkey_get_private($keyPem, $password ?? '') === false) throw new InvalidArgumentException('Chave privada inválida ou senha incorreta.');
        $parsed = openssl_x509_parse($certPem, false);
        $fingerprint = openssl_x509_fingerprint($certPem, 'sha256');
        return [
            'format' => $format, 'certificate_pem' => $certPem, 'private_key_pem' => $keyPem,
            'chain_pem' => $chain, 'fingerprint' => $fingerprint ?: null,
            'valid_from' => !empty($parsed['validFrom_time_t']) ? gmdate('Y-m-d H:i:s', (int) $parsed['validFrom_time_t']) : null,
            'valid_until' => !empty($parsed['validTo_time_t']) ? gmdate('Y-m-d H:i:s', (int) $parsed['validTo_time_t']) : null,
        ];
    }

    public function store(PDO $pdo, string $accountId, string $format, string $certificate, ?string $privateKey, ?string $password): array
    {
        $info = $this->inspect($format, $certificate, $privateKey, $password);
        $pdo->prepare(
            'INSERT INTO payment_account_certificates (id,payment_account_id,format,certificate_encrypted,private_key_encrypted,chain_encrypted,password_encrypted,fingerprint,valid_from,valid_until)
             VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE format=VALUES(format),certificate_encrypted=VALUES(certificate_encrypted),private_key_encrypted=VALUES(private_key_encrypted),chain_encrypted=VALUES(chain_encrypted),password_encrypted=VALUES(password_encrypted),fingerprint=VALUES(fingerprint),valid_from=VALUES(valid_from),valid_until=VALUES(valid_until),updated_at=NOW(3)'
        )->execute([
            uuid_v4(), $accountId, $info['format'], cobx_secret_encrypt($info['certificate_pem']),
            cobx_secret_encrypt($info['private_key_pem']), cobx_secret_encrypt($info['chain_pem']),
            cobx_secret_encrypt($password), $info['fingerprint'], $info['valid_from'], $info['valid_until'],
        ]);
        return $this->metadata($pdo, $accountId) ?? [];
    }

    public function metadata(PDO $pdo, string $accountId): ?array
    {
        $s = $pdo->prepare('SELECT format,fingerprint,valid_from,valid_until,updated_at FROM payment_account_certificates WHERE payment_account_id=? LIMIT 1');
        $s->execute([$accountId]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function material(PDO $pdo, string $accountId): ?array
    {
        $s = $pdo->prepare('SELECT * FROM payment_account_certificates WHERE payment_account_id=? LIMIT 1');
        $s->execute([$accountId]); $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        return [
            'format' => $row['format'], 'certificate_pem' => cobx_secret_decrypt($row['certificate_encrypted']),
            'private_key_pem' => cobx_secret_decrypt($row['private_key_encrypted']),
            'chain_pem' => cobx_secret_decrypt($row['chain_encrypted']), 'password' => cobx_secret_decrypt($row['password_encrypted']),
        ];
    }

    /** Material temporário para cURL; o chamador deve sempre executar cleanup(). */
    public function temporaryFiles(array $material): array
    {
        $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'cobx-mtls-' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Não foi possível preparar o certificado.');
        $cert = $dir . DIRECTORY_SEPARATOR . 'client-cert.pem';
        $key = $dir . DIRECTORY_SEPARATOR . 'client-key.pem';
        file_put_contents($cert, (string) ($material['certificate_pem'] ?? '') . "\n" . (string) ($material['chain_pem'] ?? ''), LOCK_EX);
        file_put_contents($key, (string) ($material['private_key_pem'] ?? ''), LOCK_EX);
        @chmod($cert, 0600); @chmod($key, 0600);
        return ['cert_path' => $cert, 'key_path' => $key, 'key_password' => (string) ($material['password'] ?? ''), 'directory' => $dir];
    }

    public function cleanup(array $files): void
    {
        foreach (['cert_path', 'key_path', 'ca_path'] as $key) if (!empty($files[$key]) && is_file($files[$key])) @unlink($files[$key]);
        if (!empty($files['directory']) && is_dir($files['directory'])) @rmdir($files['directory']);
    }
}
