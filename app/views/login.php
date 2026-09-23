<?php declare(strict_types=1); ?>
<div class="login-wrap">
  <div class="login-card">
    <h1 style="margin-top:0">Entrar</h1>
    <?php if (!empty($error)): ?>
      <div class="flash flash-error"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="<?= e(app_url('/login')) ?>">
      <div class="form-row">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" required autocomplete="username" value="<?= e($email ?? '') ?>">
      </div>
      <div class="form-row">
        <label for="password">Senha</label>
        <input type="password" id="password" name="password" required autocomplete="current-password">
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%">Entrar</button>
    </form>
    <p class="muted" style="margin-top:1.5rem">
      Demo: <code>usuario.starter@cobx.local</code> / <code>UsuarioStarter@2026</code>
    </p>
  </div>
</div>
