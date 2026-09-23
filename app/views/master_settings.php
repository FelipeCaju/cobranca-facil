<?php declare(strict_types=1); ?>
<h1>Configurações master</h1>
<div class="card">
  <form method="post">
    <div class="form-row"><label>Mercado Pago — chave pública</label><input name="mercadopago_public_key" value="<?= e($row['mercadopago_public_key'] ?? '') ?>"></div>
    <div class="form-row"><label>Mercado Pago — access token</label><input name="mercadopago_access_token" placeholder="<?= !empty($row['mercadopago_access_token']) ? '(definido — deixe vazio para manter)' : '' ?>"></div>
    <div class="form-row"><label>Segredo do cron</label><input name="cron_secret" placeholder="<?= !empty($row['cron_secret']) ? '(definido)' : '' ?>"></div>
    <button type="submit" class="btn btn-primary">Guardar</button>
  </form>
</div>
<div class="card">
  <p class="muted">Endpoint do cron (agende no servidor):</p>
  <input readonly value="<?= e($cronUrl) ?>" onclick="this.select()">
</div>
