<?php declare(strict_types=1); ?>
<h1>Consola da plataforma</h1>
<p class="muted">Sessão de super administrador. Utilize o menu para gerir empresas, planos e configurações master.</p>
<div class="card">
  <p><a class="btn btn-primary" href="<?= e(app_url('/admin/companies')) ?>">Empresas</a>
  <a class="btn btn-outline" href="<?= e(app_url('/admin/plans')) ?>">Planos</a>
  <a class="btn btn-outline" href="<?= e(app_url('/dashboard/master-settings')) ?>">Config. master</a></p>
</div>
