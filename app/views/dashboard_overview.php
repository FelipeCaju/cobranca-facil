<?php declare(strict_types=1); ?>
<h1>Visão geral</h1>
<div class="stats">
  <div class="stat"><strong><?= (int) $clientsTotal ?></strong><span>Clientes</span></div>
  <div class="stat"><strong><?= (int) $chargesActive ?></strong><span>Cobranças ativas</span></div>
  <div class="stat"><strong>R$ <?= e(brl($revenueMonth)) ?></strong><span>Recebido no mês</span></div>
  <div class="stat"><strong><?= $delinqPct !== null ? e((string) $delinqPct) . '%' : '—' ?></strong><span>Parcelas em aberto</span></div>
</div>
<div class="card">
  <h2 style="margin-top:0;font-size:1rem">Cobranças recentes</h2>
  <?php if (empty($recent)): ?>
    <p class="muted">Nenhuma cobrança ainda. Crie clientes, produtos e cobranças.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Cliente</th><th>Valor</th><th>Status</th><th>Criada</th></tr></thead>
      <tbody>
      <?php foreach ($recent as $r): ?>
        <tr>
          <td><?= e($r['client_name']) ?></td>
          <td>R$ <?= e(brl((float) $r['total_amount'])) ?></td>
          <td><?= status_badge((string) $r['status']) ?></td>
          <td class="muted"><?= e(format_date_br(substr((string) $r['created_at'], 0, 10))) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
