<?php
declare(strict_types=1);
$statusOptions = [
    '' => 'Todos',
    'pending' => 'Pendente',
    'paid' => 'Paga',
    'overdue' => 'Atrasada',
    'cancelled' => 'Cancelada',
];
?>
<h1>Cobranças</h1>

<?php if (!$edit): ?>
<div class="card">
  <h2 style="margin-top:0;font-size:1rem">Nova cobrança</h2>
  <form method="post" class="grid-2">
    <div class="form-row">
      <label>Cliente *</label>
      <select name="client_id" required>
        <option value="">Selecione</option>
        <?php foreach ($clients as $c): ?>
          <option value="<?= e($c['id']) ?>"><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-row">
      <label>Produto *</label>
      <select name="product_id" required>
        <option value="">Selecione</option>
        <?php foreach ($products as $p): ?>
          <option value="<?= e($p['id']) ?>"><?= e($p['name']) ?> — R$ <?= e(brl((float) $p['price'])) ?> (<?= (int) $p['installments_count'] ?>x)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-row">
      <label>Gateway *</label>
      <select name="payment_gateway" required>
        <option value="mercadopago">Mercado Pago</option>
        <option value="asaas">Asaas</option>
      </select>
    </div>
    <div class="form-row">
      <label>Primeiro vencimento (dd/mm/aaaa)</label>
      <input type="text" name="first_due_date" placeholder="dd/mm/aaaa" maxlength="10">
      <span class="muted">Opcional — por padrão usa hoje.</span>
    </div>
    <div><button type="submit" class="btn btn-primary">Criar cobrança</button></div>
  </form>
</div>
<?php endif; ?>

<?php if ($edit): ?>
<div class="card">
  <div class="toolbar">
    <div>
      <h2 style="margin:0;font-size:1rem">Editar cobrança</h2>
      <p class="muted" style="margin:0.25rem 0 0"><?= e($editClientName) ?> · <?= e($edit['product_name'] ?? $edit['description']) ?> · <?= status_badge((string) $edit['status']) ?></p>
    </div>
    <a class="btn btn-outline" href="<?= e(app_url('/dashboard/charges' . $filterQuery)) ?>">← Voltar à lista</a>
  </div>

  <?php if ($editPaidCount > 0): ?>
    <p class="muted">Há <?= (int) $editPaidCount ?> parcela(s) paga(s). Só pode alterar <strong>cliente</strong> e <strong>gateway</strong>.</p>
  <?php endif; ?>

  <form method="post" class="grid-2" style="margin-top:1rem">
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="id" value="<?= e($edit['id']) ?>">
    <div class="form-row">
      <label>Cliente *</label>
      <select name="client_id" required>
        <?php foreach ($clients as $c): ?>
          <option value="<?= e($c['id']) ?>" <?= ($edit['client_id'] ?? '') === $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-row">
      <label>Produto *</label>
      <select name="product_id" required <?= $editPaidCount > 0 ? 'disabled' : '' ?>>
        <?php foreach ($products as $p): ?>
          <option value="<?= e($p['id']) ?>" <?= ($edit['product_id'] ?? '') === $p['id'] ? 'selected' : '' ?>>
            <?= e($p['name']) ?> — R$ <?= e(brl((float) $p['price'])) ?> (<?= (int) $p['installments_count'] ?>x)
          </option>
        <?php endforeach; ?>
      </select>
      <?php if ($editPaidCount > 0): ?>
        <input type="hidden" name="product_id" value="<?= e($edit['product_id'] ?? '') ?>">
      <?php endif; ?>
    </div>
    <div class="form-row">
      <label>Gateway *</label>
      <select name="payment_gateway" required>
        <option value="mercadopago" <?= ($edit['payment_gateway'] ?? '') === 'mercadopago' ? 'selected' : '' ?>>Mercado Pago</option>
        <option value="asaas" <?= ($edit['payment_gateway'] ?? '') === 'asaas' ? 'selected' : '' ?>>Asaas</option>
      </select>
    </div>
    <div class="form-row">
      <label>Primeiro vencimento (dd/mm/aaaa)</label>
      <input type="text" name="first_due_date" placeholder="dd/mm/aaaa" maxlength="10"
        value="<?= e($editFirstDue) ?>" <?= $editPaidCount > 0 ? 'disabled' : '' ?>>
      <?php if ($editPaidCount === 0): ?>
        <span class="muted">Alterar regera todas as parcelas pendentes.</span>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:0.5rem;flex-wrap:wrap">
      <button type="submit" class="btn btn-primary">Guardar alterações</button>
    </div>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0;font-size:1rem">Parcelas desta cobrança</h2>
  <?php if (empty($editInstallments)): ?>
    <p class="muted">Sem parcelas.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Valor</th>
          <th>Vencimento</th>
          <th>Status</th>
          <th>Pago em</th>
          <th>Ação</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($editInstallments as $ins): ?>
        <?php
          $canPay = in_array($ins['status'], ['pending', 'overdue'], true);
          $totalN = max(1, (int) ($edit['installments_count'] ?? 1));
        ?>
        <tr>
          <td><?= (int) $ins['installment_number'] ?>/<?= $totalN ?></td>
          <td>R$ <?= e(brl((float) $ins['amount'])) ?></td>
          <td><?= e(format_date_br($ins['due_date'])) ?></td>
          <td><?= status_badge((string) $ins['status']) ?></td>
          <td class="muted"><?= !empty($ins['paid_at']) ? e(format_date_br(substr((string) $ins['paid_at'], 0, 10))) : '—' ?></td>
          <td>
            <?php if ($canPay): ?>
              <form method="post" style="display:inline" onsubmit="return confirm('Marcar parcela como paga (baixa manual)?');">
                <input type="hidden" name="action" value="mark_paid">
                <input type="hidden" name="installment_id" value="<?= e($ins['id']) ?>">
                <input type="hidden" name="charge_id" value="<?= e($edit['id']) ?>">
                <button type="submit" class="btn btn-sm btn-primary">Baixa manual</button>
              </form>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <h2 style="margin-top:0;font-size:1rem">Pesquisar cobranças</h2>
  <form method="get" class="grid-2">
    <?php if ($edit): ?><input type="hidden" name="edit" value="<?= e($edit['id']) ?>"><?php endif; ?>
    <div class="form-row">
      <label>Texto</label>
      <input type="search" name="q" placeholder="Cliente, produto ou descrição…" value="<?= e($filters['q'] ?? '') ?>">
    </div>
    <div class="form-row">
      <label>Status da cobrança</label>
      <select name="status">
        <?php foreach ($statusOptions as $val => $label): ?>
          <option value="<?= e($val) ?>" <?= ($filters['status'] ?? '') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-row">
      <label>Cliente</label>
      <select name="client_id">
        <option value="">Todos</option>
        <?php foreach ($clients as $c): ?>
          <option value="<?= e($c['id']) ?>" <?= ($filters['client_id'] ?? '') === $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-row">
      <label>Gateway</label>
      <select name="gateway">
        <option value="">Todos</option>
        <option value="mercadopago" <?= ($filters['gateway'] ?? '') === 'mercadopago' ? 'selected' : '' ?>>Mercado Pago</option>
        <option value="asaas" <?= ($filters['gateway'] ?? '') === 'asaas' ? 'selected' : '' ?>>Asaas</option>
      </select>
    </div>
    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:flex-end">
      <button type="submit" class="btn btn-primary">Filtrar</button>
      <?php if (!$edit): ?>
        <a class="btn btn-outline" href="<?= e(app_url('/dashboard/charges')) ?>">Limpar</a>
      <?php else: ?>
        <a class="btn btn-outline" href="<?= e(app_url('/dashboard/charges?edit=' . urlencode($edit['id']))) ?>">Limpar filtros</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<?php if (!$edit): ?>
<div class="card">
  <table>
    <thead>
      <tr>
        <th>Cliente</th>
        <th>Produto</th>
        <th>Valor</th>
        <th>Parcelas</th>
        <th>Status</th>
        <th>Gateway</th>
        <th>Ações</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($charges as $ch): ?>
      <tr>
        <td><?= e($ch['client_name']) ?></td>
        <td><?= e($ch['product_name'] ?? $ch['description']) ?></td>
        <td>R$ <?= e(brl((float) $ch['total_amount'])) ?></td>
        <td><?= (int) $ch['installments_count'] ?>x</td>
        <td><?= status_badge((string) $ch['status']) ?></td>
        <td><?= e($ch['payment_gateway']) ?></td>
        <td style="white-space:nowrap">
          <a class="btn btn-sm btn-outline" href="<?= e(app_url('/dashboard/charges' . charges_filter_query(['edit' => $ch['id']]))) ?>">Editar</a>
          <form method="post" style="display:inline" onsubmit="return confirm('Excluir esta cobrança e todas as parcelas?');">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= e($ch['id']) ?>">
            <button type="submit" class="btn btn-sm btn-danger">Excluir</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (empty($charges)): ?><p class="muted">Nenhuma cobrança com estes filtros.</p><?php endif; ?>
</div>
<?php endif; ?>
