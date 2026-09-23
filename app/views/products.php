<?php declare(strict_types=1); ?>
<h1>Produtos</h1>
<div class="grid-2">
  <div class="card">
    <h2 style="margin-top:0;font-size:1rem"><?= $edit ? 'Editar produto' : 'Novo produto' ?></h2>
    <form method="post" action="<?= e(app_url('/dashboard/products')) ?>">
      <?php if ($edit): ?><input type="hidden" name="id" value="<?= e($edit['id']) ?>"><?php endif; ?>
      <div class="form-row">
        <label>Nome *</label>
        <input type="text" name="name" required value="<?= e($edit['name'] ?? '') ?>">
      </div>
      <div class="form-row">
        <label>Descrição</label>
        <textarea name="description"><?= e($edit['description'] ?? '') ?></textarea>
      </div>
      <?php
      $rec = !empty($edit['is_recurring_monthly']);
      $n = max(1, (int) ($edit['installments_count'] ?? 1));
      $priceVal = $edit ? (float) $edit['price'] : 0;
      if ($rec && $n > 0) {
          $priceVal = $priceVal / $n;
      }
      ?>
      <div class="form-row">
        <label><?= $rec ? 'Valor da parcela (R$) *' : 'Preço total (R$) *' ?></label>
        <input type="text" name="price" inputmode="decimal" required value="<?= e($priceVal > 0 ? (string) $priceVal : '') ?>">
      </div>
      <div class="form-row">
        <label>Nº parcelas</label>
        <input type="number" name="installments_count" min="1" max="120" value="<?= e((string) ($edit['installments_count'] ?? '1')) ?>">
      </div>
      <div class="checkbox-row">
        <input type="checkbox" name="is_monthly" id="is_monthly" value="1" <?= !isset($edit) || !empty($edit['is_monthly']) ? 'checked' : '' ?>>
        <label for="is_monthly">Parcelas mensais (senão, semanais)</label>
      </div>
      <div class="checkbox-row">
        <input type="checkbox" name="is_recurring_monthly" id="is_rec" value="1" <?= !empty($edit['is_recurring_monthly']) ? 'checked' : '' ?>>
        <label for="is_rec">Recorrente mensal (valor = parcela)</label>
      </div>
      <div class="checkbox-row">
        <input type="checkbox" name="is_active" id="is_active" value="1" <?= !isset($edit) || !empty($edit['is_active']) ? 'checked' : '' ?>>
        <label for="is_active">Ativo</label>
      </div>
      <button type="submit" class="btn btn-primary"><?= $edit ? 'Guardar' : 'Criar' ?></button>
      <?php if ($edit): ?>
        <a class="btn btn-outline" href="<?= e(app_url('/dashboard/products')) ?>">Cancelar</a>
      <?php endif; ?>
    </form>
  </div>
  <div class="card">
    <h2 style="margin-top:0;font-size:1rem">Lista</h2>
    <?php if (empty($items)): ?>
      <p class="muted">Nenhum produto.</p>
    <?php else: ?>
      <table>
        <thead><tr><th>Nome</th><th>Preço</th><th>Parcelas</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($items as $p): ?>
          <tr>
            <td><?= e($p['name']) ?><?= empty($p['is_active']) ? ' <span class="muted">(inativo)</span>' : '' ?></td>
            <td>R$ <?= e(brl((float) $p['price'])) ?></td>
            <td><?= (int) $p['installments_count'] ?>x</td>
            <td>
              <a class="btn btn-sm btn-outline" href="<?= e(app_url('/dashboard/products?edit=' . urlencode($p['id']))) ?>">Editar</a>
              <form method="post" style="display:inline" onsubmit="return confirm('Remover produto?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= e($p['id']) ?>">
                <button type="submit" class="btn btn-sm btn-danger">Apagar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
