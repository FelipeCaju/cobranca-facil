<?php declare(strict_types=1); ?>
<h1>Configurações</h1>
<p class="muted" style="margin-top:-0.5rem;margin-bottom:1rem">
  Página em PHP — não depende de Node/npm. Configure pagamento e email aqui.
</p>

<div class="tabs">
  <a href="<?= e(app_url('/dashboard/settings?tab=payment')) ?>" class="<?= $tab === 'payment' ? 'active' : '' ?>">Pagamento</a>
  <a href="<?= e(app_url('/dashboard/settings?tab=email')) ?>" class="<?= $tab === 'email' ? 'active' : '' ?>">Email (SMTP)</a>
</div>

<?php if ($tab === 'payment'): ?>
<div class="card">
  <h2 style="margin-top:0;font-size:1rem">Gateway de pagamento</h2>
  <form method="post">
    <input type="hidden" name="tab" value="payment">
    <div class="form-row">
      <label>Gateway</label>
      <select name="payment_gateway">
        <option value="">— Nenhum —</option>
        <option value="mercadopago" <?= ($company['payment_gateway'] ?? '') === 'mercadopago' ? 'selected' : '' ?>>Mercado Pago</option>
        <option value="asaas" <?= ($company['payment_gateway'] ?? '') === 'asaas' ? 'selected' : '' ?>>Asaas</option>
      </select>
    </div>
    <div class="form-row"><label>Chave API / Access Token</label><input name="gateway_api_key" value="<?= e($company['gateway_api_key'] ?? '') ?>" autocomplete="off"></div>
    <div class="form-row"><label>Chave pública (Mercado Pago)</label><input name="gateway_public_key" value="<?= e($company['gateway_public_key'] ?? '') ?>" autocomplete="off"></div>
    <div class="form-row">
      <label>Ambiente</label>
      <select name="gateway_environment">
        <option value="sandbox" <?= ($company['gateway_environment'] ?? '') !== 'production' ? 'selected' : '' ?>>Sandbox (testes)</option>
        <option value="production" <?= ($company['gateway_environment'] ?? '') === 'production' ? 'selected' : '' ?>>Produção</option>
      </select>
    </div>
    <button type="submit" class="btn btn-primary">Guardar</button>
  </form>
</div>
<div class="card">
  <h2 style="margin-top:0;font-size:1rem">URLs de webhook</h2>
  <p class="muted">Copie e cole no painel do gateway:</p>
  <div class="form-row">
    <label>Mercado Pago</label>
    <input readonly value="<?= e($webhookMp) ?>" onclick="this.select()">
  </div>
  <div class="form-row">
    <label>Asaas</label>
    <input readonly value="<?= e($webhookAsaas) ?>" onclick="this.select()">
  </div>
</div>
<?php else: ?>
<div class="card">
  <h2 style="margin-top:0;font-size:1rem">Email (SMTP)</h2>
  <p class="muted">Por defeito usa o SMTP da plataforma (master). Ative SMTP próprio só se necessário.</p>
  <form method="post">
    <input type="hidden" name="tab" value="email">
    <div class="checkbox-row">
      <input type="checkbox" name="smtp_use_custom" id="smtp_custom" value="1" <?= !empty($company['smtp_use_custom']) ? 'checked' : '' ?>>
      <label for="smtp_custom">Usar SMTP próprio desta empresa</label>
    </div>
    <div class="grid-2" style="margin-top:1rem">
      <div class="form-row"><label>Servidor SMTP</label><input name="smtp_host" value="<?= e($company['smtp_host'] ?? '') ?>" placeholder="smtp.gmail.com"></div>
      <div class="form-row"><label>Porta</label><input type="number" name="smtp_port" value="<?= e((string) ($company['smtp_port'] ?? '587')) ?>"></div>
      <div class="form-row">
        <label>Encriptação</label>
        <select name="smtp_encryption">
          <option value="tls" <?= ($company['smtp_encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS</option>
          <option value="ssl" <?= ($company['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
          <option value="none" <?= ($company['smtp_encryption'] ?? '') === 'none' ? 'selected' : '' ?>>Nenhuma</option>
        </select>
      </div>
      <div class="form-row"><label>Utilizador SMTP</label><input name="smtp_username" value="<?= e($company['smtp_username'] ?? '') ?>" autocomplete="off"></div>
      <div class="form-row"><label>Palavra-passe SMTP</label><input type="password" name="smtp_password" placeholder="<?= !empty($company['smtp_password']) ? '(preencha só para alterar)' : '' ?>" autocomplete="new-password"></div>
      <div class="form-row"><label>Email remetente</label><input type="email" name="smtp_from_email" value="<?= e($company['smtp_from_email'] ?? '') ?>"></div>
      <div class="form-row"><label>Nome remetente</label><input name="smtp_from_name" value="<?= e($company['smtp_from_name'] ?? '') ?>"></div>
    </div>
    <button type="submit" class="btn btn-primary">Guardar email</button>
  </form>
</div>
<?php endif; ?>
