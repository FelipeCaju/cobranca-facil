<?php declare(strict_types=1); ?>
<h1>Clientes</h1>

<?php if ($viewClient): ?>
<div class="card">
  <h2 style="margin-top:0"><?= e($viewClient['name']) ?> — cobranças ativas</h2>
  <p class="muted"><?= e($viewClient['email'] ?? '') ?> · <?= e($viewClient['phone'] ?? '') ?></p>
  <?php if (empty($billingInstallments)): ?>
    <p class="muted">Sem parcelas pendentes.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Cobrança</th><th>Parcela</th><th>Valor</th><th>Vencimento</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($billingInstallments as $ins): ?>
        <tr>
          <td><?= e($ins['charge_description']) ?></td>
          <td><?= (int) $ins['installment_number'] ?>/<?= max(1, (int) $ins['charge_installments_count']) ?></td>
          <td>R$ <?= e(brl((float) $ins['amount'])) ?></td>
          <td><?= e(format_date_br($ins['due_date'])) ?></td>
          <td><?= status_badge((string) $ins['status']) ?></td>
          <td>
            <form method="post" style="display:inline" onsubmit="return confirm('Marcar como paga?');">
              <input type="hidden" name="action" value="mark_paid">
              <input type="hidden" name="installment_id" value="<?= e($ins['id']) ?>">
              <input type="hidden" name="client_id" value="<?= e($viewClient['id']) ?>">
              <button type="submit" class="btn btn-sm btn-primary">Baixa manual</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  <p><a href="<?= e(app_url('/dashboard/clients')) ?>">← Voltar à lista</a></p>
</div>
<?php else: ?>

<div class="grid-2">
  <div class="card">
    <h2 style="margin-top:0;font-size:1rem"><?= $edit ? 'Editar' : 'Novo' ?> cliente</h2>
    <form method="post">
      <?php if ($edit): ?><input type="hidden" name="id" value="<?= e($edit['id']) ?>"><?php endif; ?>
      <div class="form-row"><label>Nome *</label><input name="name" required value="<?= e($edit['name'] ?? '') ?>"></div>
      <div class="form-row"><label>Email</label><input type="email" name="email" value="<?= e($edit['email'] ?? '') ?>"></div>
      <div class="form-row"><label>Telefone</label><input name="phone" value="<?= e($edit['phone'] ?? '') ?>"></div>
      <div class="form-row"><label>Documento</label><input name="document" value="<?= e($edit['document'] ?? '') ?>"></div>
      <div class="form-row">
        <label>Categoria</label>
        <select name="category_id">
          <option value="">—</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= e($c['id']) ?>" <?= ($edit['category_id'] ?? '') === $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row"><label>Cidade</label><input name="address_city" value="<?= e($edit['address_city'] ?? '') ?>"></div>
      <div class="form-row"><label>UF</label><input name="address_state" maxlength="2" value="<?= e($edit['address_state'] ?? '') ?>"></div>
      <button type="submit" class="btn btn-primary"><?= $edit ? 'Guardar' : 'Criar' ?></button>
      <?php if ($edit): ?><a class="btn btn-outline" href="<?= e(app_url('/dashboard/clients')) ?>">Cancelar</a><?php endif; ?>
    </form>
  </div>
  <div class="card">
    <form method="get" class="toolbar">
      <input type="search" name="q" placeholder="Pesquisar…" value="<?= e($q) ?>">
      <button type="submit" class="btn btn-outline">Filtrar</button>
    </form>
    <table>
      <thead><tr><th>Nome</th><th>Cobranças ativas</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($items as $c): ?>
        <tr>
          <td><?= e($c['name']) ?></td>
          <td><?= (int) $c['active_charges'] ?></td>
          <td>
            <a class="btn btn-sm btn-outline" href="<?= e(app_url('/dashboard/clients?view=' . urlencode($c['id']))) ?>">Ver</a>
            <a class="btn btn-sm btn-outline" href="<?= e(app_url('/dashboard/clients?edit=' . urlencode($c['id']))) ?>">Editar</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <?php if ($page > 1): ?><a href="?page=<?= $page - 1 ?>&q=<?= urlencode($q) ?>">Anterior</a><?php endif; ?>
      <span>Página <?= $page ?> de <?= $totalPages ?> (<?= $total ?>)</span>
      <?php if ($page < $totalPages): ?><a href="?page=<?= $page + 1 ?>&q=<?= urlencode($q) ?>">Seguinte</a><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
