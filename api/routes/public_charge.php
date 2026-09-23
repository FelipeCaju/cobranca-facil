<?php
declare(strict_types=1);

/** Consulta pública, assinada e com expiração, de uma parcela. */
function handle_public_charge(PDO $pdo, string $method, array $segments): void
{
    if ($method !== 'GET') json_response(405, ['error' => 'Método não permitido']);
    $id = (string) ($segments[2] ?? ''); $expires = (int) ($_GET['expires'] ?? 0); $signature = (string) ($_GET['signature'] ?? '');
    if (!preg_match('/^[0-9a-f-]{36}$/i', $id) || $expires < time() || $signature === '') json_response(403, ['error' => 'Link inválido ou expirado']);
    $secret = (string) env('JWT_SECRET', '');
    $expected = hash_hmac('sha256', $id . '|' . $expires, $secret);
    if ($secret === '' || !hash_equals($expected, $signature)) json_response(403, ['error' => 'Link inválido ou expirado']);
    $st = $pdo->prepare("SELECT i.id, i.installment_number, i.amount, i.due_date, i.status, i.paid_at, i.payment_url, i.boleto_digitable_line, i.boleto_pdf_url, i.receipt_url, i.pix_qrcode, i.pix_copy_paste, c.name AS company_name, ch.description FROM installments i INNER JOIN charges ch ON ch.id=i.charge_id INNER JOIN companies c ON c.id=i.company_id WHERE i.id=? AND c.is_active=1 LIMIT 1");
    $st->execute([$id]); $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) json_response(404, ['error' => 'Cobrança não encontrada']);
    json_response(200, ['installment' => $row]);
}

function cobx_public_charge_link(string $installmentId, int $days = 90): ?string
{
    $base = rtrim((string) env('APP_URL', ''), '/'); $secret = (string) env('JWT_SECRET', '');
    if ($base === '' || $secret === '') return null;
    $expires = time() + max(1, min(365, $days)) * 86400;
    return $base . '/payer/' . rawurlencode($installmentId) . '?expires=' . $expires . '&signature=' . hash_hmac('sha256', $installmentId . '|' . $expires, $secret);
}
