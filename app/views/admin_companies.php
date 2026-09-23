<?php declare(strict_types=1); ?>
<h1>Empresas (SaaS)</h1>
<?php if ($edit): ?>
<div class="card">
  <h2 style="margin-top:0">Editar: <?= e($edit['name']) ?></h2>
  <form method="post">
    <input type="hidden" name="id" value="<?= e($edit['id']) ?>">
    <div class="form-row"><label>Nome</label><input name="name" required value="<?= e($edit['name']) ?>"></div>
    <div class="form-row"><label>Email</label><input type="email" name="email" value="<?= e($edit['email'] ?? '') ?>"></div>
    <div class="form-row"><label>Telefone</label><input name="phone" value="<?= e($edit['phone'] ?? '') ?>"></div>
    <div class="form-row">
      <label>Plano</label>
      <select name="plan_id">
        <option value="">—</option>
        <?php foreach ($plans as $p): ?>
          <option value="<?= e($p['id']) ?>" <?= ($edit['plan_id'] ?? '') === $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-row">
      <label>Renovação (dd/mm/aaaa)</label>
      <input name="plan_renews_at" placeholder="dd/mm/aaaa" value="<?= e(format_date_br($edit['plan_renews_at'] ?? null)) ?>">
    </div>
    <div class="checkbox-row">
      <input type="checkbox" name="is_active" value="1" id="act" <?= !empty($edit['is_active']) ? 'checked' : '' ?>>
      <label for="act">Conta ativa</label>
    </div>
    <button type="submit" class="btn btn-primary">Guardar</button>
    <a class="btn btn-outline" href="<?= e(app_url('/admin/companies')) ?>">Cancelar</a>
  </form>
</div>
<?php endif; ?>
<div class="card">
  <table>
    <thead><tr><th>Empresa</th><th>Dono</th><th>Plano</th><th>Renovação</th><th>Ativa</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($items as $r): ?>
      <tr>
        <td><?= e($r['name']) ?></td>
        <td><?= e($r['owner_email']) ?></td>
        <td><?= e($r['plan_name'] ?: '—') ?></td>
        <td><?= e(format_date_br($r['plan_renews_at'] ?? null)) ?></td>
        <td><?= !empty($r['is_active']) ? 'Sim' : 'Não' ?></td>
        <td><a class="btn btn-sm btn-outline" href="<?= e(app_url('/admin/companies?edit=' . urlencode($r['id']))) ?>">Editar</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
