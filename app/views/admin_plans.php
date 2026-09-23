<?php declare(strict_types=1); ?>
<h1>Planos (SaaS)</h1>
<div class="grid-2">
  <div class="card">
    <h2 style="margin-top:0"><?= $edit ? 'Editar' : 'Novo' ?> plano</h2>
    <form method="post">
      <?php if ($edit): ?><input type="hidden" name="id" value="<?= e($edit['id']) ?>"><?php endif; ?>
      <div class="form-row"><label>Nome *</label><input name="name" required value="<?= e($edit['name'] ?? '') ?>"></div>
      <div class="form-row"><label>Preço (R$)</label><input name="price" value="<?= e($edit['price'] ?? '0') ?>"></div>
      <div class="form-row"><label>Limite cobranças</label><input type="number" name="charges_limit" value="<?= e((string) ($edit['charges_limit'] ?? '100')) ?>"></div>
      <div class="form-row"><label>Limite utilizadores</label><input type="number" name="users_limit" value="<?= e((string) ($edit['users_limit'] ?? '5')) ?>"></div>
      <div class="checkbox-row"><input type="checkbox" name="is_active" value="1" <?= !isset($edit) || !empty($edit['is_active']) ? 'checked' : '' ?>><label>Ativo</label></div>
      <button type="submit" class="btn btn-primary"><?= $edit ? 'Guardar' : 'Criar' ?></button>
      <?php if ($edit): ?><a class="btn btn-outline" href="<?= e(app_url('/admin/plans')) ?>">Cancelar</a><?php endif; ?>
    </form>
  </div>
  <div class="card">
    <table>
      <thead><tr><th>Nome</th><th>Preço</th><th>Ativo</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($items as $p): ?>
        <tr>
          <td><?= e($p['name']) ?></td>
          <td>R$ <?= e(brl((float) $p['price'])) ?></td>
          <td><?= !empty($p['is_active']) ? 'Sim' : 'Não' ?></td>
          <td>
            <a class="btn btn-sm btn-outline" href="<?= e(app_url('/admin/plans?edit=' . urlencode($p['id']))) ?>">Editar</a>
            <form method="post" style="display:inline" onsubmit="return confirm('Apagar plano?');">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= e($p['id']) ?>">
              <button type="submit" class="btn btn-sm btn-danger">Apagar</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
