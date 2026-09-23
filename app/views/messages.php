<?php declare(strict_types=1); ?>
<h1>Mensagens</h1>
<div class="card">
  <h2 style="margin-top:0;font-size:1rem">Envio automático (cron)</h2>
  <form method="post">
    <div class="form-row">
      <label>Hora de início dos envios</label>
      <input type="time" name="billing_reminder_start_time" value="<?= e($startTime) ?>">
    </div>
    <div class="form-row">
      <label>Intervalo entre clientes (segundos)</label>
      <input type="number" name="billing_reminder_gap_seconds" min="5" max="3600" value="<?= e((string) ($co['billing_reminder_gap_seconds'] ?? 60)) ?>">
    </div>
    <div class="checkbox-row">
      <input type="checkbox" name="send_qrcode" id="qr" value="1" <?= !empty($co['send_qrcode']) ? 'checked' : '' ?>>
      <label for="qr">Enviar QR Code PIX no WhatsApp</label>
    </div>
    <div class="checkbox-row">
      <input type="checkbox" name="send_copy_paste_key" id="cp" value="1" <?= !empty($co['send_copy_paste_key']) ? 'checked' : '' ?>>
      <label for="cp">Enviar chave copia e cola</label>
    </div>
    <button type="submit" class="btn btn-primary">Guardar</button>
  </form>
</div>
<div class="card">
  <h2 style="margin-top:0;font-size:1rem">Variáveis disponíveis</h2>
  <ul class="muted">
    <?php foreach ($vars as $v): ?>
      <li><code><?= e($v['key'] ?? '') ?></code> — <?= e($v['label'] ?? '') ?></li>
    <?php endforeach; ?>
  </ul>
  <p class="muted">Templates completos (antes/no/após vencimento) podem ser editados na API ou numa versão futura desta página.</p>
</div>
