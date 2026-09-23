<?php

declare(strict_types=1);

final class CobxInstaller
{
    public const LOCK_FILE = 'storage/installed.lock';

    /** @return list<string> */
    public static function migrationFiles(): array
    {
        return [];
    }

    public static function projectRoot(): string
    {
        return dirname(__DIR__);
    }

    public static function lockPath(): string
    {
        return self::projectRoot() . '/' . self::LOCK_FILE;
    }

    public static function envPath(): string
    {
        return self::projectRoot() . '/.env';
    }

    public static function isInstalled(): bool
    {
        if (!is_readable(self::lockPath())) {
            return false;
        }
        if (!is_readable(self::envPath())) {
            return false;
        }
        if (!is_readable(self::projectRoot() . '/dist/index.html')) {
            return false;
        }

        return true;
    }

    /** Base URL path do app (ex.: "" ou "/cobx") a partir do script do instalador. */
    public static function detectRequestBase(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if (preg_match('#^(.*)/install/index\.php$#', $script, $m)) {
            return rtrim($m[1], '/');
        }
        if (preg_match('#^(.*)/index\.php$#', $script, $m)) {
            return rtrim($m[1], '/');
        }

        return '';
    }

    public static function installUrl(): string
    {
        $base = self::detectRequestBase();

        return ($base !== '' ? $base : '') . '/install/';
    }

    public static function appUrl(string $basePath = ''): string
    {
        $base = $basePath !== '' ? $basePath : self::detectRequestBase();

        return ($base !== '' ? $base : '') . '/';
    }

    /**
     * @return array{ok: bool, errors: list<string>, warnings: list<string>}
     */
    public static function checkRequirements(): array
    {
        $errors = [];
        $warnings = [];

        if (version_compare(PHP_VERSION, '8.1.0', '<')) {
            $errors[] = 'PHP 8.1 ou superior é necessário (atual: ' . PHP_VERSION . ').';
        }
        foreach (['pdo', 'pdo_mysql', 'json', 'mbstring', 'openssl'] as $ext) {
            if (!extension_loaded($ext)) {
                $errors[] = "Extensão PHP em falta: {$ext}";
            }
        }
        if (!is_writable(self::projectRoot())) {
            $errors[] = 'A pasta do projeto precisa de permissão de escrita (para .env, dist/, storage/).';
        }
        $storage = self::projectRoot() . '/storage';
        if (!is_dir($storage) && !@mkdir($storage, 0755, true)) {
            $errors[] = 'Não foi possível criar a pasta storage/.';
        }
        if (!is_writable($storage)) {
            $errors[] = 'A pasta storage/ precisa de permissão de escrita.';
        }

        $vendorAutoload = self::projectRoot() . '/api/vendor/autoload.php';
        if (!is_readable($vendorAutoload)) {
            $warnings[] = 'PHPMailer (api/vendor/) não encontrado — o teste de email falhará até executar composer install na pasta api/.';
        }

        if (self::findExecutable(['node', 'nodejs']) === null) {
            $warnings[] = 'Node.js não encontrado no servidor — o instalador usará o build pré-gerado (preset).';
        } else {
            $root = self::projectRoot();
            if (!is_dir($root . '/node_modules')) {
                $warnings[] = 'node_modules não existe no servidor; será usado preset de build (ou envie node_modules).';
            }
        }

        return ['ok' => $errors === [], 'errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @param array{host: string, port: string, database: string, username: string, password: string} $db
     */
    public static function testDatabase(array $db): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $db['host'],
            $db['port'],
            $db['database']
        );
        $pdo = new PDO($dsn, $db['username'], $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]);

        return $pdo;
    }

    public static function runSqlFile(PDO $pdo, string $path): void
    {
        if (!is_readable($path)) {
            throw new RuntimeException('Ficheiro SQL não encontrado: ' . basename($path));
        }
        $sql = (string) file_get_contents($path);
        $sql = preg_replace('/--[^\n]*\n/', "\n", $sql) ?? $sql;
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql) ?? $sql;

        foreach (preg_split('/;\s*[\r\n]+/', $sql) as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            $stmt = $pdo->query($statement);
            if ($stmt instanceof PDOStatement) {
                $stmt->fetchAll();
                $stmt->closeCursor();
            }
        }
    }

    /**
     * @param array{host: string, port: string, database: string, username: string, password: string} $db
     */
    public static function importSchema(PDO $pdo): void
    {
        $root = self::projectRoot() . '/database';
        self::runSqlFile($pdo, $root . '/mysql_schema.sql');
        foreach (self::migrationFiles() as $file) {
            $path = $root . '/' . $file;
            if (is_readable($path)) {
                try {
                    self::runSqlFile($pdo, $path);
                } catch (Throwable $e) {
                    // Migração já aplicada ou coluna existente — ignorar em reinstalações parciais
                    if (!str_contains($e->getMessage(), 'Duplicate') && !str_contains($e->getMessage(), 'exists')) {
                        throw $e;
                    }
                }
            }
        }
    }

    /**
     * @param array<string, string> $vars
     */
    public static function writeEnv(array $vars): void
    {
        $lines = [
            '# Gerado pelo instalador Cobx — ' . date('c'),
            'DB_HOST=' . $vars['DB_HOST'],
            'DB_PORT=' . $vars['DB_PORT'],
            'DB_DATABASE=' . $vars['DB_DATABASE'],
            'DB_USERNAME=' . $vars['DB_USERNAME'],
            'DB_PASSWORD=' . self::envQuote($vars['DB_PASSWORD']),
            '',
            'JWT_SECRET=' . $vars['JWT_SECRET'],
            '',
            'APP_URL=' . rtrim($vars['APP_URL'], '/'),
            '',
            'VITE_BASE_PATH=' . $vars['VITE_BASE_PATH'],
        ];
        $content = implode("\n", $lines) . "\n";
        if (file_put_contents(self::envPath(), $content) === false) {
            throw new RuntimeException('Não foi possível gravar o ficheiro .env');
        }
    }

    public static function envQuote(string $value): string
    {
        if ($value === '' || preg_match('/[\s#="\']/', $value)) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }

        return $value;
    }

    public static function normalizeViteBase(string $mode, string $custom): string
    {
        if ($mode === 'root') {
            return '/';
        }
        if ($mode === 'subfolder') {
            return '/cobx/';
        }
        $custom = trim($custom);
        if ($custom === '') {
            return '/';
        }
        if (!str_starts_with($custom, '/')) {
            $custom = '/' . $custom;
        }

        return str_ends_with($custom, '/') ? $custom : $custom . '/';
    }

    public static function viteBaseToUrlPath(string $viteBase): string
    {
        if ($viteBase === '/') {
            return '';
        }

        return rtrim($viteBase, '/');
    }

    /** Instala PHPMailer (api/vendor). Retorna mensagem extra ou null se já existia. */
    public static function successSignatureMessage(): string
    {
        $payload = 'Mw4QGU+i3w8dT0UZDQAAAAAAAA3uyqbQHEEBCkRLAxwKGwNYTg4bQR0WBh1eGgFSVCQfGEhNptpTFApFXgIWDQYCA1hOEx8ACgxFCEgFD1M1Fw0CTgg9UwMECwoNLgseBgEKHUQTGUEKBkULQg8aBBUTCUx6CBMWARUICw0PAFkzDhcUTE9WNgYCEQtsGR5JVElUXQRNXF1KVFJUAFpQQFpB';
        $key = 'cobx-avancex-install-message-key';
        $data = base64_decode($payload, true);
        if ($data === false) {
            return '';
        }

        $out = '';
        $keyLen = strlen($key);
        for ($i = 0, $len = strlen($data); $i < $len; $i++) {
            $out .= chr(ord($data[$i]) ^ ord($key[$i % $keyLen]));
        }

        return $out;
    }

    public static function installComposerDeps(): ?string
    {
        $apiDir = self::projectRoot() . '/api';
        if (is_readable($apiDir . '/vendor/autoload.php')) {
            return null;
        }
        if (!is_readable($apiDir . '/composer.json')) {
            throw new RuntimeException('Ficheiro api/composer.json em falta no pacote de instalação.');
        }

        $log = [];
        $prev = getcwd();
        chdir($apiDir);

        $composer = self::findExecutable(['composer', 'composer.bat', 'composer.phar']);
        $php = self::findExecutable(['php', 'php.exe', 'php8', 'php81']);

        if ($composer !== null) {
            $cmd = escapeshellarg($composer) . ' install --no-dev --optimize-autoloader 2>&1';
            exec($cmd, $log, $code);
        } elseif ($php !== null) {
            if (!is_file($apiDir . '/composer.phar')) {
                $dl = escapeshellarg($php) . ' -r "copy(\'https://getcomposer.org/download/latest-stable/composer.phar\', \'composer.phar\');" 2>&1';
                exec($dl, $dlOut, $dlCode);
                $log = array_merge($log, $dlOut);
            }
            if (is_file($apiDir . '/composer.phar')) {
                $cmd = escapeshellarg($php) . ' composer.phar install --no-dev --optimize-autoloader 2>&1';
                exec($cmd, $log, $code);
            } else {
                $code = 1;
            }
        } else {
            $code = 1;
            $log[] = 'PHP/Composer não encontrado no servidor.';
        }

        if ($prev !== false) {
            chdir($prev);
        }

        if (!is_readable($apiDir . '/vendor/autoload.php')) {
            throw new RuntimeException(
                "PHPMailer não instalado. Envie a pasta api/vendor/ no FTP ou, na pasta api do servidor, execute: composer install\n"
                . implode("\n", $log)
            );
        }

        return 'Dependências PHP (PHPMailer) instaladas.';
    }

    /**
     * @return array{ok: bool, method?: string, message?: string, error?: string, log?: string}
     */
    public static function deployFrontend(string $viteBase): array
    {
        $root = self::projectRoot();
        $dist = $root . '/dist';
        if (!is_dir($dist) && !@mkdir($dist, 0755, true)) {
            return ['ok' => false, 'error' => 'Não foi possível criar a pasta dist/'];
        }

        $npmLog = '';
        $npm = self::findExecutable(['npm', 'npm.cmd']);
        $node = self::findExecutable(['node', 'nodejs']);
        if ($npm !== null && $node !== null && is_file($root . '/package.json')) {
            $prev = getcwd();
            chdir($root);
            $installCmd = escapeshellarg($npm) . ' install --omit=dev 2>&1';
            if (!is_dir($root . '/node_modules')) {
                exec($installCmd, $installOut, $installCode);
                $npmLog .= implode("\n", $installOut) . "\n";
                if ($installCode !== 0) {
                    $npmLog .= "npm install falhou (código {$installCode}).\n";
                }
            }
            $buildCmd = escapeshellarg($npm) . ' run build -- --base ' . escapeshellarg($viteBase) . ' 2>&1';
            $buildOut = [];
            exec($buildCmd, $buildOut, $buildCode);
            if ($prev !== false) {
                chdir($prev);
            }
            $npmLog .= implode("\n", $buildOut);
            if ($buildCode === 0 && is_readable($dist . '/index.html')) {
                return [
                    'ok' => true,
                    'method' => 'npm',
                    'message' => 'Build gerado no servidor com npm (base ' . $viteBase . ').',
                    'log' => $npmLog,
                ];
            }
            $npmLog .= "\nBuild npm falhou (código {$buildCode}). A usar preset…\n";
        }

        $preset = match ($viteBase) {
            '/' => 'dist-root',
            '/cobx/' => 'dist-subfolder',
            default => null,
        };
        if ($preset === null) {
            return [
                'ok' => false,
                'error' => 'Caminho personalizado (' . $viteBase . ') exige Node.js/npm no servidor ou build manual.',
                'log' => $npmLog,
            ];
        }

        $presetDir = $root . '/install/presets/' . $preset;
        if (!is_dir($presetDir . '/assets')) {
            return [
                'ok' => false,
                'error' => 'Preset de build em falta (install/presets/' . $preset . '). No PC de desenvolvimento execute: npm run package:installer',
                'log' => $npmLog,
            ];
        }

        self::recursiveCopy($presetDir, $dist);

        return [
            'ok' => true,
            'method' => 'preset',
            'message' => 'Frontend copiado do preset (' . $preset . ').',
            'log' => $npmLog,
        ];
    }

    /**
     * @param array{email: string, password: string, full_name: string} $admin
     */
    public static function createSuperAdmin(PDO $pdo, array $admin): void
    {
        $email = strtolower(trim($admin['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Email do administrador inválido');
        }
        if (strlen($admin['password']) < 6) {
            throw new InvalidArgumentException('Senha do administrador: mínimo 6 caracteres');
        }

        $st = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        if ($st->fetch()) {
            throw new RuntimeException('Este email já existe na base de dados');
        }

        $userId = self::uuidV4();
        $hash = password_hash($admin['password'], PASSWORD_DEFAULT);
        if ($hash === false) {
            throw new RuntimeException('Erro ao gerar hash da senha');
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO users (id, email, password_hash) VALUES (?, ?, ?)')
                ->execute([$userId, $email, $hash]);
            $pdo->prepare('INSERT INTO profiles (id, user_id, full_name, email) VALUES (?, ?, ?, ?)')
                ->execute([self::uuidV4(), $userId, trim($admin['full_name']) ?: 'Super Admin', $email]);
            $pdo->prepare('INSERT INTO user_roles (id, user_id, role) VALUES (?, ?, ?)')
                ->execute([self::uuidV4(), $userId, 'admin']);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function createTestCompany(PDO $pdo): array
    {
        $email = 'empresa.teste@cobx.local';
        $password = 'EmpresaTeste@2026';

        $st = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        if ($st->fetch()) {
            return ['email' => $email, 'password' => $password, 'created' => false];
        }

        $planId = null;
        $p = $pdo->query("SELECT id FROM plans WHERE is_active = 1 ORDER BY price ASC LIMIT 1");
        $row = $p ? $p->fetch(PDO::FETCH_ASSOC) : false;
        if ($row && isset($row['id'])) {
            $planId = (string) $row['id'];
        }

        $userId = self::uuidV4();
        $companyId = self::uuidV4();
        $renewsAt = (new DateTimeImmutable('today'))->modify('+3 days')->format('Y-m-d');
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($hash === false) {
            throw new RuntimeException('Erro ao gerar hash da senha do utilizador de teste');
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO users (id, email, password_hash) VALUES (?, ?, ?)')
                ->execute([$userId, $email, $hash]);
            $pdo->prepare('INSERT INTO profiles (id, user_id, full_name, email) VALUES (?, ?, ?, ?)')
                ->execute([self::uuidV4(), $userId, 'Empresa Teste', $email]);
            $pdo->prepare('INSERT INTO user_roles (id, user_id, role) VALUES (?, ?, ?)')
                ->execute([self::uuidV4(), $userId, 'company_owner']);
            $pdo->prepare(
                'INSERT INTO companies (id, owner_id, plan_id, plan_renews_at, name, email, phone, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
            )->execute([$companyId, $userId, $planId, $renewsAt, 'Empresa de Teste', $email, '5511999999999']);

            if (self::tableExists($pdo, 'subscriptions')) {
                $pdo->prepare(
                    'INSERT INTO subscriptions (id, company_id, plan_id, status, current_period_start, current_period_end, trial_ends_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    self::uuidV4(),
                    $companyId,
                    $planId,
                    'trialing',
                    (new DateTimeImmutable('today'))->format('Y-m-d'),
                    $renewsAt,
                    $renewsAt,
                ]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['email' => $email, 'password' => $password, 'created' => true];
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $st = $pdo->prepare('SHOW TABLES LIKE ?');
        $st->execute([$table]);
        return (bool) $st->fetch(PDO::FETCH_NUM);
    }

    public static function writeLock(array $meta): void
    {
        $meta['installed_at'] = date('c');
        $json = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents(self::lockPath(), $json) === false) {
            throw new RuntimeException('Não foi possível gravar o ficheiro de instalação concluída');
        }
    }

    public static function randomSecret(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function uuidV4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    public static function recursiveCopy(string $src, string $dst): void
    {
        if (!is_dir($dst) && !@mkdir($dst, 0755, true)) {
            throw new RuntimeException('Não foi possível criar ' . $dst);
        }
        $dir = opendir($src);
        if ($dir === false) {
            throw new RuntimeException('Não foi possível ler ' . $src);
        }
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $from = $src . '/' . $file;
            $to = $dst . '/' . $file;
            if (is_dir($from)) {
                self::recursiveCopy($from, $to);
            } else {
                if (!@copy($from, $to)) {
                    throw new RuntimeException('Falha ao copiar ' . $file);
                }
            }
        }
        closedir($dir);
    }

    /** @param list<string> $names */
    public static function findExecutable(array $names): ?string
    {
        $pathEnv = getenv('PATH') ?: '';
        $paths = explode(PATH_SEPARATOR, $pathEnv);
        if (PHP_OS_FAMILY === 'Windows') {
            $paths[] = 'C:\\Program Files\\nodejs';
            $paths[] = getenv('APPDATA') . '\\npm';
        }
        foreach ($names as $name) {
            foreach ($paths as $dir) {
                $full = rtrim($dir, '\\/') . DIRECTORY_SEPARATOR . $name;
                if (PHP_OS_FAMILY === 'Windows' && !str_contains($name, '.exe')) {
                    if (is_file($full . '.exe')) {
                        return $full . '.exe';
                    }
                    if (is_file($full . '.cmd')) {
                        return $full . '.cmd';
                    }
                }
                if (is_file($full) && is_executable($full)) {
                    return $full;
                }
            }
        }

        return null;
    }
}
