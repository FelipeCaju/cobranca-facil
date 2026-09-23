<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($page_title ?? 'CobrançaFácil') ?></title>
  <link rel="stylesheet" href="<?= e(app_url('/assets/app.css')) ?>">
</head>
<body>
<?php if (!empty($bare_layout)): ?>
  <?= $content ?? '' ?>
<?php else: ?>
<div class="layout">
  <aside class="sidebar">
    <div class="sidebar-brand">⚡ CobrançaFácil</div>
    <nav>
      <?php
      $current = app_route();
      foreach ($menu ?? [] as $item):
          $href = $item['href'];
          $path = trim($href, '/');
          $active = $path === $current || ($path !== 'dashboard' && str_starts_with($current, $path));
      ?>
        <a href="<?= e(app_url($href)) ?>" class="<?= $active ? 'active' : '' ?>"><?= e($item['label']) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
      <?= e($user['email'] ?? '') ?><br>
      <a href="<?= e(app_url('/logout')) ?>">Terminar sessão</a>
    </div>
  </aside>
  <main class="main">
    <?php $flash = flash_get(); if ($flash): ?>
      <div class="flash flash-<?= e($flash['type'] === 'ok' ? 'ok' : 'error') ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>
    <?= $content ?? '' ?>
  </main>
</div>
<?php endif; ?>
</body>
</html>
