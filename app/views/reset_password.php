<?php declare(strict_types=1); ?>
<div class="login-wrap">
  <div class="login-card">
    <h1 style="margin-top:0">Redefinir senha</h1>
    <?php if ($ok): ?>
      <div class="flash flash-ok">Senha atualizada. <a href="<?= e(app_url('/login')) ?>">Entrar</a></div>
    <?php else: ?>
      <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="form-row"><label>Nova senha</label><input type="password" name="password" required minlength="6"></div>
        <button type="submit" class="btn btn-primary" style="width:100%">Guardar</button>
      </form>
    <?php endif; ?>
  </div>
</div>
