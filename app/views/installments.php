<?php declare(strict_types=1); ?>
<h1>Parcelas</h1>
<div class="card">
  <form method="get" class="grid-2">
    <div class="form-row"><label>Pesquisar</label><input name="q" value="<?= e($q) ?>" placeholder="Cliente ou cobrança"></div>
    <div class="form-row">
      <label>Status</label>
      <select name="status">
        <option value="">Todos</option>
        <?php foreach (['pending' => 'Pendente', 'paid' => 'Paga', 'overdue' => 'Atrasada', 'cancelled' => 'Cancelada'] as $k => $l): ?>
          <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($l) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-row"><label>Vencimento de (dd/mm/aaaa)</label><input name="due_from" placeholder="dd/mm/aaaa" maxlength="10" value="<?= e($due_from) ?>"></div>
    <div class="form-row"><label>Vencimento até</label><input name="due_to" placeholder="dd/mm/aaaa" maxlength="10" value="<?= e($due_to) ?>"></div>
    <div><button type="submit" class="btn btn-primary">Filtrar</button></div>
  </form>
</div>
<div class="card">
  <table>
    <thead><tr><th>Cliente</th><th>Cobrança</th><th>Parcela</th><th>Valor</th><th>Vencimento</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($items as $i): ?>
      <tr>
        <td><?= e($i['client_name']) ?></td>
        <td><?= e($i['charge_description']) ?></td>
        <td><?= (int) $i['installment_number'] ?>/<?= max(1, (int) $i['charge_installments_count']) ?></td>
        <td>R$ <?= e(brl((float) $i['amount'])) ?></td>
        <td><?= e(format_date_br($i['due_date'])) ?></td>
        <td><?= status_badge((string) $i['status']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($totalPages > 1): ?>
  <div class="pagination">
    <?php
    $qs = http_build_query(array_filter(['q' => $q, 'status' => $status, 'due_from' => $due_from, 'due_to' => $due_to]));
    ?>
    <?php if ($page > 1): ?><a href="?<?= e($qs) ?>&page=<?= $page - 1 ?>">Anterior</a><?php endif; ?>
    <span><?= $page ?> / <?= $totalPages ?> (<?= $total ?>)</span>
    <?php if ($page < $totalPages): ?><a href="?<?= e($qs) ?>&page=<?= $page + 1 ?>">Seguinte</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
