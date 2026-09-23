<?php

declare(strict_types=1);

require_once __DIR__ . '/Installer.php';

session_start();

$base = CobxInstaller::detectRequestBase();
$installUrl = CobxInstaller::installUrl();
$done = isset($_GET['done']) && isset($_SESSION['install_done']);

if (CobxInstaller::isInstalled() && !$done && !isset($_GET['force'])) {
    header('Location: ' . CobxInstaller::appUrl($base));
    exit;
}

$error = '';
$success = '';
$req = CobxInstaller::checkRequirements();
$step = max(1, min(4, (int) ($_POST['step'] ?? $_GET['step'] ?? 1)));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'install') {
    $step = 4;
    try {
        if (!$req['ok']) {
            throw new RuntimeException('Requisitos do servidor não satisfeitos.');
        }

        $db = [
            'host' => trim((string) ($_POST['db_host'] ?? 'localhost')),
            'port' => trim((string) ($_POST['db_port'] ?? '3306')),
            'database' => trim((string) ($_POST['db_database'] ?? '')),
            'username' => trim((string) ($_POST['db_username'] ?? '')),
            'password' => (string) ($_POST['db_password'] ?? ''),
        ];
        $appUrl = rtrim(trim((string) ($_POST['app_url'] ?? '')), '/');
        $viteMode = (string) ($_POST['vite_mode'] ?? 'root');
        $viteCustom = trim((string) ($_POST['vite_custom'] ?? ''));
        $jwt = trim((string) ($_POST['jwt_secret'] ?? ''));
        $adminEmail = strtolower(trim((string) ($_POST['admin_email'] ?? '')));
        $adminPass = (string) ($_POST['admin_password'] ?? '');
        $adminName = trim((string) ($_POST['admin_name'] ?? 'Super Admin'));

        if ($db['database'] === '' || $db['username'] === '') {
            throw new RuntimeException('Preencha base de dados e utilizador MySQL.');
        }
        if ($appUrl === '' || !filter_var($appUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('URL pública inválida (ex.: https://seusite.com.br).');
        }
        if ($jwt === '' || strlen($jwt) < 16) {
            throw new RuntimeException('JWT_SECRET deve ter pelo menos 16 caracteres.');
        }
        if (strlen($adminPass) < 6) {
            throw new RuntimeException('Senha do administrador: mínimo 6 caracteres.');
        }

        $viteBase = CobxInstaller::normalizeViteBase($viteMode, $viteCustom);
        $pdo = CobxInstaller::testDatabase($db);
        CobxInstaller::importSchema($pdo);
        CobxInstaller::createSuperAdmin($pdo, [
            'email' => $adminEmail,
            'password' => $adminPass,
            'full_name' => $adminName,
        ]);
        $testCompany = CobxInstaller::createTestCompany($pdo);
        CobxInstaller::writeEnv([
            'DB_HOST' => $db['host'],
            'DB_PORT' => $db['port'],
            'DB_DATABASE' => $db['database'],
            'DB_USERNAME' => $db['username'],
            'DB_PASSWORD' => $db['password'],
            'JWT_SECRET' => $jwt,
            'APP_URL' => $appUrl,
            'VITE_BASE_PATH' => $viteBase,
        ]);

        $build = CobxInstaller::deployFrontend($viteBase);
        if (!$build['ok']) {
            throw new RuntimeException($build['error'] ?? 'Falha ao gerar o frontend.');
        }

        $composerMsg = CobxInstaller::installComposerDeps();
        if ($composerMsg !== null) {
            $build['message'] = trim(($build['message'] ?? '') . ' ' . $composerMsg);
        }

        CobxInstaller::writeLock([
            'app_url' => $appUrl,
            'vite_base' => $viteBase,
            'build_method' => $build['method'] ?? 'unknown',
            'admin_email' => $adminEmail,
        ]);

        $_SESSION['install_done'] = [
            'app_url' => $appUrl,
            'build' => $build,
            'admin_email' => $adminEmail,
            'test_company' => $testCompany,
        ];
        header('Location: ' . $installUrl . '?done=1');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if (isset($build) && is_array($build) && !empty($build['log'])) {
            $error .= "\n\n" . $build['log'];
        }
    }
}

$doneInfo = $done ? $_SESSION['install_done'] : null;

$guessUrl = '';
if (!empty($_SERVER['HTTP_HOST'])) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $guessUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . ($base !== '' ? $base : '');
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Instalação — CobrançaFácil</title>
  <style>
    :root { --bg: #f4f6f9; --card: #fff; --text: #1a2332; --muted: #5c6b7a; --primary: #1e3a5f; --accent: #e8a020; --err: #dc2626; --ok: #16a34a; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: system-ui, sans-serif; background: var(--bg); color: var(--text); line-height: 1.5; }
    .wrap { max-width: 640px; margin: 2rem auto; padding: 0 1rem 3rem; }
    .card { background: var(--card); border-radius: 12px; padding: 1.5rem 1.75rem; box-shadow: 0 4px 24px rgba(30,58,95,.08); }
    h1 { font-size: 1.35rem; margin: 0 0 .25rem; color: var(--primary); }
    .sub { color: var(--muted); font-size: .9rem; margin-bottom: 1.25rem; }
    .steps { display: flex; gap: .5rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
    .steps span { font-size: .75rem; padding: .25rem .6rem; border-radius: 999px; background: #e8ecf1; color: var(--muted); }
    .steps span.on { background: var(--primary); color: #fff; }
    label { display: block; font-size: .85rem; font-weight: 600; margin: .75rem 0 .35rem; }
    input, select { width: 100%; padding: .55rem .65rem; border: 1px solid #d0d7e2; border-radius: 8px; font-size: .95rem; }
    .row { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
    @media (max-width: 520px) { .row { grid-template-columns: 1fr; } }
    .btn { display: inline-block; margin-top: 1.25rem; padding: .65rem 1.25rem; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: .95rem; text-decoration: none; }
    .btn-primary { background: var(--accent); color: #1a2332; }
    .btn-secondary { background: #e8ecf1; color: var(--text); margin-right: .5rem; }
    .alert { padding: .75rem 1rem; border-radius: 8px; font-size: .88rem; margin-bottom: 1rem; white-space: pre-wrap; }
    .alert-err { background: #fef2f2; color: var(--err); border: 1px solid #fecaca; }
    .alert-ok { background: #f0fdf4; color: var(--ok); border: 1px solid #bbf7d0; }
    .alert-warn { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
    ul.chk { margin: 0; padding-left: 1.2rem; font-size: .9rem; }
    ul.chk li { margin: .25rem 0; }
    .hint { font-size: .8rem; color: var(--muted); margin-top: .25rem; }
    fieldset { border: 1px solid #e2e8f0; border-radius: 8px; padding: .75rem 1rem; margin-top: .5rem; }
    legend { font-size: .85rem; font-weight: 600; padding: 0 .25rem; }
    .radio { display: flex; align-items: center; gap: .5rem; margin: .4rem 0; font-weight: normal; }
    .radio input { width: auto; }
    #custom_path { margin-top: .5rem; display: none; }
  </style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <?php if ($done && is_array($doneInfo)) : ?>
      <h1>Instalação concluída</h1>
      <p class="sub">O CobrançaFácil está pronto para usar.</p>
      <div class="alert alert-ok">
        <?= htmlspecialchars(CobxInstaller::successSignatureMessage(), ENT_QUOTES, 'UTF-8') ?>
      </div>
      <div class="alert alert-ok">
        <?= htmlspecialchars((string) ($doneInfo['build']['message'] ?? 'Frontend instalado.'), ENT_QUOTES, 'UTF-8') ?>
        (método: <?= htmlspecialchars((string) ($doneInfo['build']['method'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>)
      </div>
      <p><strong>URL do site:</strong> <a href="<?= htmlspecialchars((string) $doneInfo['app_url'], ENT_QUOTES) ?>"><?= htmlspecialchars((string) $doneInfo['app_url'], ENT_QUOTES) ?></a></p>
      <p class="hint">Por segurança, apague ou renomeie a pasta <code>install/</code> no servidor após confirmar que tudo funciona.</p>
      <p><strong>Super admin:</strong> <?= htmlspecialchars((string) ($doneInfo['admin_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
      <?php if (!empty($doneInfo['test_company']) && is_array($doneInfo['test_company'])) : ?>
        <div class="alert alert-warn">
          Utilizador de teste criado:
          Email: <?= htmlspecialchars((string) ($doneInfo['test_company']['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
          Senha: <?= htmlspecialchars((string) ($doneInfo['test_company']['password'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
          Troque esta senha ou remova o utilizador antes de usar em produção.
        </div>
      <?php endif; ?>
      <a class="btn btn-primary" href="<?= htmlspecialchars(CobxInstaller::appUrl($base), ENT_QUOTES) ?>">Abrir o site</a>
    <?php else : ?>
      <h1>Instalação CobrançaFácil</h1>
      <p class="sub">Base de dados, administrador e build do painel (automático).</p>

      <?php if ($error !== '') : ?>
        <div class="alert alert-err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <?php if (!$req['ok']) : ?>
        <div class="alert alert-err">
          <strong>Corrija antes de continuar:</strong>
          <ul class="chk">
            <?php foreach ($req['errors'] as $e) : ?>
              <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
      <?php foreach ($req['warnings'] as $w) : ?>
        <div class="alert alert-warn"><?= htmlspecialchars($w, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endforeach; ?>

      <form method="post" action="">
        <input type="hidden" name="action" value="install" />

        <div class="steps">
          <span class="on">1. MySQL</span>
          <span class="on">2. Site</span>
          <span class="on">3. Admin</span>
          <span class="on">4. Instalar</span>
        </div>

        <h2 style="font-size:1rem;margin:1rem 0 .5rem;">Base de dados</h2>
        <div class="row">
          <div>
            <label for="db_host">Host</label>
            <input id="db_host" name="db_host" value="<?= htmlspecialchars((string) ($_POST['db_host'] ?? 'localhost'), ENT_QUOTES) ?>" required />
          </div>
          <div>
            <label for="db_port">Porta</label>
            <input id="db_port" name="db_port" value="<?= htmlspecialchars((string) ($_POST['db_port'] ?? '3306'), ENT_QUOTES) ?>" required />
          </div>
        </div>
        <label for="db_database">Nome da base</label>
        <input id="db_database" name="db_database" value="<?= htmlspecialchars((string) ($_POST['db_database'] ?? ''), ENT_QUOTES) ?>" required />
        <label for="db_username">Utilizador MySQL</label>
        <input id="db_username" name="db_username" value="<?= htmlspecialchars((string) ($_POST['db_username'] ?? ''), ENT_QUOTES) ?>" required />
        <label for="db_password">Senha MySQL</label>
        <input id="db_password" name="db_password" type="password" value="<?= htmlspecialchars((string) ($_POST['db_password'] ?? ''), ENT_QUOTES) ?>" />
        <p class="hint">Senha com # ou espaços: pode escrever normalmente; o instalador grava com aspas no .env.</p>

        <h2 style="font-size:1rem;margin:1.25rem 0 .5rem;">Site e build do frontend</h2>
        <label for="app_url">URL pública (sem / no final)</label>
        <input id="app_url" name="app_url" type="url" placeholder="https://cobrefacil.digitalavance.com.br" value="<?= htmlspecialchars((string) ($_POST['app_url'] ?? $guessUrl), ENT_QUOTES) ?>" required />

        <fieldset>
          <legend>Onde o site fica no domínio?</legend>
          <label class="radio"><input type="radio" name="vite_mode" value="root" <?= ($_POST['vite_mode'] ?? 'root') === 'root' ? 'checked' : '' ?> /> Raiz do domínio ou subdomínio dedicado (recomendado)</label>
          <label class="radio"><input type="radio" name="vite_mode" value="subfolder" <?= ($_POST['vite_mode'] ?? '') === 'subfolder' ? 'checked' : '' ?> /> Subpasta /cobx/ (ex.: Laragon local)</label>
          <label class="radio"><input type="radio" name="vite_mode" value="custom" id="mode_custom" <?= ($_POST['vite_mode'] ?? '') === 'custom' ? 'checked' : '' ?> /> Outra subpasta</label>
          <div id="custom_path">
            <label for="vite_custom">Caminho (ex.: /minhaapp/)</label>
            <input id="vite_custom" name="vite_custom" value="<?= htmlspecialchars((string) ($_POST['vite_custom'] ?? '/'), ENT_QUOTES) ?>" />
          </div>
        </fieldset>
        <p class="hint">O instalador tenta <strong>npm run build</strong> no servidor; se Node não existir, copia um build pré-gerado (preset). Hospedagem só PHP: execute <code>npm run package:installer</code> no PC antes de enviar os ficheiros.</p>

        <label for="jwt_secret">JWT_SECRET</label>
        <input id="jwt_secret" name="jwt_secret" value="<?= htmlspecialchars((string) ($_POST['jwt_secret'] ?? CobxInstaller::randomSecret()), ENT_QUOTES) ?>" required />

        <h2 style="font-size:1rem;margin:1.25rem 0 .5rem;">Conta super administrador</h2>
        <label for="admin_name">Nome</label>
        <input id="admin_name" name="admin_name" value="<?= htmlspecialchars((string) ($_POST['admin_name'] ?? 'Super Admin'), ENT_QUOTES) ?>" />
        <label for="admin_email">Email (login)</label>
        <input id="admin_email" name="admin_email" type="email" value="<?= htmlspecialchars((string) ($_POST['admin_email'] ?? ''), ENT_QUOTES) ?>" required />
        <label for="admin_password">Senha</label>
        <input id="admin_password" name="admin_password" type="password" required minlength="6" />

        <button type="submit" class="btn btn-primary" <?= $req['ok'] ? '' : 'disabled' ?>>Instalar agora (BD + build)</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<script>
(function () {
  const custom = document.getElementById('mode_custom');
  const box = document.getElementById('custom_path');
  function sync() {
    const on = document.querySelector('input[name="vite_mode"]:checked');
    box.style.display = on && on.value === 'custom' ? 'block' : 'none';
  }
  document.querySelectorAll('input[name="vite_mode"]').forEach((el) => el.addEventListener('change', sync));
  sync();
})();
</script>
</body>
</html>
