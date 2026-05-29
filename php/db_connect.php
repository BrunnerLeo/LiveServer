<?php
declare(strict_types=1);

class DuplicateUsernameException extends RuntimeException
{
}

function project_root(): string
{
    return dirname(__DIR__);
}

function project_types(): array
{
    return ['file', 'webpage', 'java', 'c'];
}

function runtime_project_types(): array
{
    return ['java', 'c'];
}

function editor_project_types(): array
{
    return ['webpage', 'java', 'c'];
}

function normalize_project_type(string $type): string
{
    $type = strtolower(trim($type));
    if (!in_array($type, project_types(), true)) {
        throw new InvalidArgumentException('Ungültiger Projekttyp.');
    }

    return $type;
}

function is_runtime_project_type(string $type): bool
{
    return in_array($type, runtime_project_types(), true);
}

function is_editor_project_type(string $type): bool
{
    return in_array($type, editor_project_types(), true);
}

function load_settings(): array
{
    static $settings = null;

    if ($settings !== null) {
        return $settings;
    }

    $settingsPath = project_root() . '/config/settings.json';
    if (!is_file($settingsPath)) {
        throw new RuntimeException('settings.json wurde nicht gefunden.');
    }

    $json = file_get_contents($settingsPath);
    $decoded = json_decode((string) $json, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('settings.json enthält kein gültiges JSON.');
    }

    $settings = $decoded;
    return $settings;
}

function resolve_project_path(string $path): string
{
    $path = str_replace('{PROJECT_ROOT}', project_root(), $path);

    if ($path === '') {
        return '';
    }

    if ($path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
        return $path;
    }

    return project_root() . '/' . ltrim($path, '/');
}

function is_https_request(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    $forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    return $forwardedProto === 'https';
}

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $settings = load_settings();
    $security = $settings['security'] ?? [];
    $sessionName = preg_replace('/[^a-zA-Z0-9,_-]/', '', (string) ($security['sessionName'] ?? 'LIVESERVER_SESSION'));

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');

    if ($sessionName !== '') {
        session_name($sessionName);
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (bool) ($security['secureCookie'] ?? false) && is_https_request(),
        'httponly' => true,
        'samesite' => (string) ($security['sameSite'] ?? 'Strict'),
    ]);

    session_start();
}

function json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function is_detailed_errors_enabled(): bool
{
    $settings = load_settings();
    return (bool) (($settings['security'] ?? [])['showDetailedErrors'] ?? false);
}

function log_api_exception(Throwable $exception): void
{
    $logDirectory = project_root() . '/logs';

    if (!is_dir($logDirectory)) {
        @mkdir($logDirectory, 0775, true);
    }

    $line = sprintf(
        "[%s] %s in %s:%d\n",
        date('c'),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    );

    @file_put_contents($logDirectory . '/api-error.log', $line, FILE_APPEND);
}

function api_exception_response(Throwable $exception, string $publicMessage, int $statusCode = 500): void
{
    log_api_exception($exception);

    $payload = [
        'success' => false,
        'message' => $publicMessage,
    ];

    if (is_detailed_errors_enabled()) {
        $payload['debug'] = [
            'error' => $exception->getMessage(),
            'type' => get_class($exception),
            'file' => basename($exception->getFile()),
            'line' => $exception->getLine(),
        ];
    }

    json_response($payload, $statusCode);
}

function require_post_request(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_response([
            'success' => false,
            'message' => 'Diese Aktion erlaubt nur POST-Requests.',
        ], 405);
    }
}

function get_json_input(): array
{
    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || trim($rawBody) === '') {
        return [];
    }

    $decoded = json_decode($rawBody, true);
    if (!is_array($decoded)) {
        json_response([
            'success' => false,
            'message' => 'Ungültige JSON-Daten.',
        ], 400);
    }

    return $decoded;
}

function get_database_settings(): array
{
    $settings = load_settings();
    return is_array($settings['database'] ?? null) ? $settings['database'] : [];
}

function get_sqlite_database_path(): string
{
    $database = get_database_settings();
    $path = (string) ($database['sqlitePath'] ?? '{PROJECT_ROOT}/database/liveserver.sqlite');
    return resolve_project_path($path);
}

function ensure_sqlite_directory(): void
{
    $path = get_sqlite_database_path();
    $directory = dirname($path);

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('SQLite-Datenbankordner konnte nicht erstellt werden: ' . $directory);
    }

    if (!is_writable($directory)) {
        throw new RuntimeException('SQLite-Datenbankordner ist nicht beschreibbar: ' . $directory);
    }
}

function get_db_connection(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    ensure_sqlite_directory();
    $path = get_sqlite_database_path();
    $isNewDatabase = !is_file($path);

    try {
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        ensure_sqlite_schema($pdo);

        if ($isNewDatabase) {
            @chmod($path, 0664);
        }
    } catch (PDOException $exception) {
        throw new RuntimeException('SQLite-Datenbankverbindung fehlgeschlagen: ' . $exception->getMessage(), 0, $exception);
    }

    return $pdo;
}

function ensure_projects_table_runtime_types(PDO $pdo): void
{
    $statement = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'projects'");
    $createSql = $statement !== false ? (string) $statement->fetchColumn() : '';
    $statement = null;

    if ($createSql === '' || strpos($createSql, "'java'") !== false) {
        return;
    }

    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->beginTransaction();

    try {
        $pdo->exec('DROP TABLE IF EXISTS projects_runtime_migration');
        $pdo->exec(
            "CREATE TABLE projects_runtime_migration (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                owner_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                type TEXT NOT NULL CHECK (type IN ('file', 'webpage', 'java', 'c')),
                visibility TEXT NOT NULL CHECK (visibility IN ('public', 'private', 'shared')),
                upload_kind TEXT NOT NULL DEFAULT 'single' CHECK (upload_kind IN ('single', 'folder')),
                stored_filename TEXT,
                original_filename TEXT,
                mime_type TEXT,
                file_size INTEGER,
                html_content TEXT,
                runtime_config TEXT NOT NULL DEFAULT '{}',
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                public_permission TEXT NOT NULL DEFAULT 'read',
                site_refresh_mode TEXT NOT NULL DEFAULT 'manual',
                site_refresh_seconds INTEGER NOT NULL DEFAULT 5,
                FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE
            )"
        );

        $columns = $pdo->query('PRAGMA table_info(projects)')->fetchAll();
        $existing = [];
        foreach ($columns as $column) {
            $name = (string) ($column['name'] ?? '');
            if ($name !== '') {
                $existing[$name] = true;
            }
        }

        $targetColumns = [
            'id',
            'owner_id',
            'title',
            'type',
            'visibility',
            'upload_kind',
            'stored_filename',
            'original_filename',
            'mime_type',
            'file_size',
            'html_content',
            'runtime_config',
            'created_at',
            'updated_at',
            'public_permission',
            'site_refresh_mode',
            'site_refresh_seconds',
        ];
        $selectParts = [];
        foreach ($targetColumns as $column) {
            if (isset($existing[$column])) {
                $selectParts[] = $column;
                continue;
            }

            $selectParts[] = match ($column) {
                'runtime_config' => "'{}' AS runtime_config",
                'public_permission' => "'read' AS public_permission",
                'site_refresh_mode' => "'manual' AS site_refresh_mode",
                'site_refresh_seconds' => '5 AS site_refresh_seconds',
                'upload_kind' => "'single' AS upload_kind",
                'created_at', 'updated_at' => 'CURRENT_TIMESTAMP AS ' . $column,
                default => 'NULL AS ' . $column,
            };
        }

        $pdo->exec(
            'INSERT INTO projects_runtime_migration (' . implode(', ', $targetColumns) . ')
             SELECT ' . implode(', ', $selectParts) . ' FROM projects'
        );
        $pdo->exec('DROP TABLE projects');
        $pdo->exec('ALTER TABLE projects_runtime_migration RENAME TO projects');
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        $pdo->exec('PRAGMA foreign_keys = ON');
        throw $exception;
    }

    $pdo->exec('PRAGMA foreign_keys = ON');
}

function ensure_sqlite_schema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE COLLATE NOCASE,
            password_hash TEXT NOT NULL,
            realname TEXT NOT NULL DEFAULT '',
            role TEXT NOT NULL DEFAULT 'student',
            theme_json TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );

    ensure_sqlite_column($pdo, 'users', 'theme_json', 'TEXT');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_role ON users (role)');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS system_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT NOT NULL DEFAULT '',
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
    ensure_builtin_admin_user($pdo);
    ensure_system_setting_default($pdo, 'ai_enabled', '1');
    ensure_system_setting_default($pdo, 'aris_context', '');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            type TEXT NOT NULL CHECK (type IN ('file', 'webpage', 'java', 'c')),
            visibility TEXT NOT NULL CHECK (visibility IN ('public', 'private', 'shared')),
            upload_kind TEXT NOT NULL DEFAULT 'single' CHECK (upload_kind IN ('single', 'folder')),
            stored_filename TEXT,
            original_filename TEXT,
            mime_type TEXT,
            file_size INTEGER,
            html_content TEXT,
            runtime_config TEXT NOT NULL DEFAULT '{}',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE
        )"
    );

    ensure_projects_table_runtime_types($pdo);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_projects_owner_id ON projects (owner_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_projects_visibility ON projects (visibility)');
    ensure_sqlite_column($pdo, 'projects', 'upload_kind', "TEXT NOT NULL DEFAULT 'single'");
    ensure_sqlite_column($pdo, 'projects', 'runtime_config', "TEXT NOT NULL DEFAULT '{}'");
    ensure_sqlite_column($pdo, 'projects', 'public_permission', "TEXT NOT NULL DEFAULT 'read'");
    ensure_sqlite_column($pdo, 'projects', 'site_refresh_mode', "TEXT NOT NULL DEFAULT 'manual'");
    ensure_sqlite_column($pdo, 'projects', 'site_refresh_seconds', "INTEGER NOT NULL DEFAULT 5");

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS project_orders (
            user_id INTEGER NOT NULL,
            project_id INTEGER NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, project_id),
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
        )"
    );

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_project_orders_project_id ON project_orders (project_id)');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS project_files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            stored_filename TEXT NOT NULL,
            original_filename TEXT NOT NULL,
            relative_path TEXT NOT NULL,
            mime_type TEXT NOT NULL DEFAULT 'application/octet-stream',
            file_size INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
        )"
    );

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_project_files_project_id ON project_files (project_id)');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS project_folders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            relative_path TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
            UNIQUE (project_id, relative_path)
        )"
    );

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_project_folders_project_id ON project_folders (project_id)');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS project_shares (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            username TEXT NOT NULL COLLATE NOCASE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
            UNIQUE (project_id, username)
        )"
    );

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_project_shares_username ON project_shares (username)');
    ensure_sqlite_column($pdo, 'project_shares', 'permission', "TEXT NOT NULL DEFAULT 'read'");

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS project_class_shares (
            project_id INTEGER NOT NULL,
            class_id INTEGER NOT NULL,
            permission TEXT NOT NULL DEFAULT 'read',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (project_id, class_id),
            FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
            FOREIGN KEY (class_id) REFERENCES school_classes (id) ON DELETE CASCADE
        )"
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_project_class_shares_class_id ON project_class_shares (class_id)');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_ai_settings (
            user_id INTEGER PRIMARY KEY,
            provider TEXT NOT NULL DEFAULT 'openai',
            model TEXT NOT NULL DEFAULT '',
            base_url TEXT NOT NULL DEFAULT '',
            api_key_encrypted TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        )"
    );
    ensure_sqlite_column($pdo, 'user_ai_settings', 'base_url', "TEXT NOT NULL DEFAULT ''");

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS school_classes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            teacher_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            code TEXT NOT NULL UNIQUE COLLATE NOCASE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (teacher_id) REFERENCES users (id) ON DELETE CASCADE
        )"
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_school_classes_teacher_id ON school_classes (teacher_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_school_classes_code ON school_classes (code)');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS school_class_members (
            class_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            joined_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (class_id, user_id),
            FOREIGN KEY (class_id) REFERENCES school_classes (id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        )"
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_school_class_members_user_id ON school_class_members (user_id)');

    $pdo->exec("UPDATE projects SET public_permission = 'read' WHERE public_permission NOT IN ('read', 'write')");
    $pdo->exec("UPDATE projects SET site_refresh_mode = 'manual' WHERE site_refresh_mode NOT IN ('manual', 'auto')");
    $pdo->exec("UPDATE projects SET site_refresh_seconds = 5 WHERE site_refresh_seconds < 1 OR site_refresh_seconds > 3600");
    $pdo->exec("UPDATE project_shares SET permission = 'read' WHERE permission NOT IN ('read', 'write')");
    $pdo->exec("UPDATE user_ai_settings SET provider = 'openai' WHERE provider NOT IN ('openai', 'anthropic', 'selfhosted')");
    $pdo->exec("UPDATE users SET role = 'student' WHERE role NOT IN ('student', 'teacher', 'admin')");
}

function ensure_system_setting_default(PDO $pdo, string $key, string $value): void
{
    $statement = $pdo->prepare(
        "INSERT OR IGNORE INTO system_settings (setting_key, setting_value, updated_at)
         VALUES (:setting_key, :setting_value, CURRENT_TIMESTAMP)"
    );
    $statement->execute([
        ':setting_key' => $key,
        ':setting_value' => $value,
    ]);
}

function ensure_builtin_admin_user(PDO $pdo): void
{
    $username = 'admin';
    $statement = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = :setting_key LIMIT 1');
    $statement->execute([':setting_key' => 'builtin_admin_initialized']);
    $alreadyInitialized = (string) ($statement->fetchColumn() ?: '') === '1';

    $statement = $pdo->prepare('SELECT id, role FROM users WHERE username = :username LIMIT 1');
    $statement->execute([':username' => $username]);
    $existing = $statement->fetch();

    if (!is_array($existing)) {
        $passwordHash = hash_plain_password('admin123');
        $statement = $pdo->prepare(
            'INSERT INTO users (username, password_hash, realname, role, updated_at)
             VALUES (:username, :password_hash, :realname, :role, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            ':username' => $username,
            ':password_hash' => $passwordHash,
            ':realname' => 'System Admin',
            ':role' => 'admin',
        ]);
    } else {
        $sql = "UPDATE users SET role = 'admin', updated_at = CURRENT_TIMESTAMP";
        if (!$alreadyInitialized) {
            $passwordHash = hash_plain_password('admin123');
            $sql .= ', password_hash = :password_hash';
        }
        $sql .= ' WHERE id = :id';

        $statement = $pdo->prepare($sql);
        $statement->bindValue(':id', (int) $existing['id'], PDO::PARAM_INT);
        if (!$alreadyInitialized) {
            $statement->bindValue(':password_hash', $passwordHash, PDO::PARAM_STR);
        }
        $statement->execute();
    }

    $statement = $pdo->prepare(
        "INSERT INTO system_settings (setting_key, setting_value, updated_at)
         VALUES ('builtin_admin_initialized', '1', CURRENT_TIMESTAMP)
         ON CONFLICT(setting_key) DO UPDATE SET setting_value = '1', updated_at = CURRENT_TIMESTAMP"
    );
    $statement->execute();
}

function ensure_sqlite_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $columns = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();

    foreach ($columns as $existingColumn) {
        if ((string) ($existingColumn['name'] ?? '') === $column) {
            return;
        }
    }

    $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
}

function get_password_algorithm()
{
    $database = get_database_settings();
    $algorithm = strtolower((string) ($database['passwordAlgorithm'] ?? 'argon2id'));

    if ($algorithm === 'argon2id' && defined('PASSWORD_ARGON2ID')) {
        return PASSWORD_ARGON2ID;
    }

    if ($algorithm === 'bcrypt') {
        return PASSWORD_BCRYPT;
    }

    return PASSWORD_DEFAULT;
}

function hash_plain_password(string $password): string
{
    return password_hash($password, get_password_algorithm());
}

function normalize_user_role(string $role): string
{
    $role = strtolower(trim($role));
    if (!in_array($role, ['student', 'teacher', 'admin'], true)) {
        throw new InvalidArgumentException('Ungültige Benutzerrolle.');
    }

    return $role;
}

function get_theme_keys(): array
{
    return ['primary', 'secondary', 'accent', 'background', 'surface', 'text'];
}

function get_ai_settings_config(): array
{
    $settings = load_settings();
    return is_array($settings['ai'] ?? null) ? $settings['ai'] : [];
}

function get_ai_provider_models(): array
{
    $settings = get_ai_settings_config();
    $configured = $settings['models'] ?? [];
    if (is_array($configured) && $configured !== []) {
        return $configured;
    }

    return [
        'openai' => ['gpt-4.1-mini', 'gpt-4.1'],
        'anthropic' => ['claude-3-5-sonnet-latest', 'claude-3-5-haiku-latest'],
        'selfhosted' => ['llama3.1', 'qwen2.5-coder', 'codellama'],
    ];
}

function normalize_ai_provider(string $provider): string
{
    $provider = strtolower(trim($provider));
    if (!in_array($provider, ['openai', 'anthropic', 'selfhosted'], true)) {
        throw new InvalidArgumentException('Unbekannter KI-Provider.');
    }

    return $provider;
}

function normalize_ai_model(string $provider, string $model): string
{
    $provider = normalize_ai_provider($provider);
    $model = trim($model);
    $models = get_ai_provider_models();
    $allowed = is_array($models[$provider] ?? null) ? $models[$provider] : [];

    if ($model === '' && $allowed !== []) {
        return (string) $allowed[0];
    }

    if ($model === '' || preg_match('/^[a-zA-Z0-9._:-]+$/', $model) !== 1) {
        throw new InvalidArgumentException('Bitte wähle ein gültiges KI-Modell.');
    }

    return $model;
}

function get_ai_allowed_selfhosted_base_urls(): array
{
    $settings = get_ai_settings_config();
    $urls = $settings['selfHostedAllowedBaseUrls'] ?? [
        'http://localhost:8080/v1',
        'http://127.0.0.1:8080/v1',
        'http://localhost:11434/v1',
        'http://127.0.0.1:11434/v1',
    ];

    if (!is_array($urls)) {
        return [];
    }

    $normalized = [];
    foreach ($urls as $url) {
        $clean = normalize_ai_base_url((string) $url, false);
        if ($clean !== '') {
            $normalized[] = $clean;
        }
    }

    return array_values(array_unique($normalized));
}

function get_ai_local_coder_settings(): array
{
    $settings = get_ai_settings_config();
    $local = is_array($settings['localCoder'] ?? null) ? $settings['localCoder'] : [];
    $baseUrl = normalize_ai_base_url((string) ($local['baseUrl'] ?? 'http://localhost:8080/v1'), false);
    $model = normalize_ai_model('selfhosted', (string) ($local['model'] ?? 'qwen2.5-coder'));

    return [
        'enabled' => (bool) ($local['enabled'] ?? true),
        'baseUrl' => $baseUrl,
        'model' => $model,
        'apiKey' => (string) ($local['apiKey'] ?? ''),
    ];
}

function normalize_ai_base_url(string $baseUrl, bool $validateAllowed = true): string
{
    $baseUrl = rtrim(trim($baseUrl), '/');
    if ($baseUrl === '') {
        return '';
    }

    $parts = parse_url($baseUrl);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = (string) ($parts['host'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        throw new InvalidArgumentException('Bitte gib eine gültige Selfhosted Base-URL an.');
    }

    if ($validateAllowed && !in_array($baseUrl, get_ai_allowed_selfhosted_base_urls(), true)) {
        throw new InvalidArgumentException('Diese Selfhosted Base-URL ist nicht freigegeben.');
    }

    return $baseUrl;
}

function ai_encryption_key(): string
{
    $settings = load_settings();
    $configured = (string) (($settings['ai'] ?? [])['encryptionSecret'] ?? '');
    $material = $configured !== '' ? $configured : project_root() . '|' . get_sqlite_database_path() . '|' . session_name();
    return hash('sha256', $material, true);
}

function encrypt_ai_api_key(string $plainText): string
{
    if ($plainText === '') {
        return '';
    }

    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('OpenSSL ist für die KI-Key-Verschlüsselung nicht verfügbar.');
    }

    $iv = random_bytes(12);
    $tag = '';
    $cipherText = openssl_encrypt($plainText, 'aes-256-gcm', ai_encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipherText === false) {
        throw new RuntimeException('KI-Key konnte nicht verschlüsselt werden.');
    }

    return base64_encode($iv . $tag . $cipherText);
}

function decrypt_ai_api_key(?string $encrypted): string
{
    if (!is_string($encrypted) || trim($encrypted) === '') {
        return '';
    }

    $raw = base64_decode($encrypted, true);
    if ($raw === false || strlen($raw) <= 28 || !function_exists('openssl_decrypt')) {
        return '';
    }

    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipherText = substr($raw, 28);
    $plainText = openssl_decrypt($cipherText, 'aes-256-gcm', ai_encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);

    return is_string($plainText) ? $plainText : '';
}

function public_ai_settings(?array $settings): array
{
    $models = get_ai_provider_models();
    $provider = 'selfhosted';
    $allowedModels = is_array($models['selfhosted'] ?? null) ? $models['selfhosted'] : [];
    $requestedModel = (string) ($settings['model'] ?? '');
    if ($requestedModel === '' || ($allowedModels !== [] && !in_array($requestedModel, $allowedModels, true))) {
        $requestedModel = (string) ($allowedModels[0] ?? 'aris');
    }
    $model = normalize_ai_model($provider, $requestedModel);
    $allowedBaseUrls = get_ai_allowed_selfhosted_base_urls();
    $baseUrl = (string) ($settings['base_url'] ?? '');
    if ($baseUrl === '' || !in_array($baseUrl, $allowedBaseUrls, true)) {
        $baseUrl = (string) ($allowedBaseUrls[0] ?? '');
    }

    return [
        'provider' => $provider,
        'model' => $model,
        'baseUrl' => $baseUrl,
        'hasApiKey' => !empty($settings['api_key_encrypted']),
        'models' => $models,
        'selfHostedAllowedBaseUrls' => $allowedBaseUrls,
    ];
}

function get_system_setting(string $key, string $fallback = ''): string
{
    $pdo = get_db_connection();
    $statement = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = :setting_key LIMIT 1');
    $statement->execute([':setting_key' => $key]);
    $value = $statement->fetchColumn();

    return is_string($value) ? $value : $fallback;
}

function save_system_setting(string $key, string $value): void
{
    $pdo = get_db_connection();
    $statement = $pdo->prepare(
        "INSERT INTO system_settings (setting_key, setting_value, updated_at)
         VALUES (:setting_key, :setting_value, CURRENT_TIMESTAMP)
         ON CONFLICT(setting_key) DO UPDATE SET
            setting_value = excluded.setting_value,
            updated_at = CURRENT_TIMESTAMP"
    );
    $statement->execute([
        ':setting_key' => $key,
        ':setting_value' => $value,
    ]);
}

function is_system_ai_enabled(): bool
{
    return get_system_setting('ai_enabled', '1') === '1';
}

function get_system_aris_context(): string
{
    return get_system_setting('aris_context', '');
}

function public_system_ai_settings(): array
{
    return [
        'enabled' => is_system_ai_enabled(),
        'arisContext' => get_system_aris_context(),
    ];
}

function save_system_ai_settings(bool $enabled, string $arisContext): array
{
    $context = str_replace("\r\n", "\n", $arisContext);
    $context = str_replace("\r", "\n", $context);
    $context = trim($context);
    if (strlen($context) > 12000) {
        throw new InvalidArgumentException('Der ARIS-Kontext darf maximal 12000 Zeichen lang sein.');
    }

    save_system_setting('ai_enabled', $enabled ? '1' : '0');
    save_system_setting('aris_context', $context);

    return public_system_ai_settings();
}

function get_user_ai_settings(int $userId): ?array
{
    $pdo = get_db_connection();
    $statement = $pdo->prepare('SELECT user_id, provider, model, base_url, api_key_encrypted FROM user_ai_settings WHERE user_id = :user_id LIMIT 1');
    $statement->execute([':user_id' => $userId]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

function save_user_ai_settings(int $userId, string $provider, string $model, ?string $apiKey, bool $clearApiKey = false, string $baseUrl = ''): array
{
    $provider = 'selfhosted';
    $models = get_ai_provider_models();
    $allowedModels = is_array($models['selfhosted'] ?? null) ? $models['selfhosted'] : [];
    if ($model === '' || ($allowedModels !== [] && !in_array($model, $allowedModels, true))) {
        $model = (string) ($allowedModels[0] ?? 'aris');
    }
    $model = normalize_ai_model($provider, $model);
    $allowedBaseUrls = get_ai_allowed_selfhosted_base_urls();
    if ($baseUrl === '') {
        $baseUrl = (string) ($allowedBaseUrls[0] ?? '');
    }
    $baseUrl = normalize_ai_base_url($baseUrl);
    $existing = get_user_ai_settings($userId);
    $encrypted = $existing['api_key_encrypted'] ?? null;

    if ($clearApiKey) {
        $encrypted = null;
    } elseif ($apiKey !== null && trim($apiKey) !== '') {
        $encrypted = encrypt_ai_api_key(trim($apiKey));
    }

    $pdo = get_db_connection();
    $statement = $pdo->prepare(
        "INSERT INTO user_ai_settings (user_id, provider, model, base_url, api_key_encrypted, updated_at)
         VALUES (:user_id, :provider, :model, :base_url, :api_key_encrypted, CURRENT_TIMESTAMP)
         ON CONFLICT(user_id) DO UPDATE SET
            provider = excluded.provider,
            model = excluded.model,
            base_url = excluded.base_url,
            api_key_encrypted = excluded.api_key_encrypted,
            updated_at = CURRENT_TIMESTAMP"
    );
    $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $statement->bindValue(':provider', $provider, PDO::PARAM_STR);
    $statement->bindValue(':model', $model, PDO::PARAM_STR);
    $statement->bindValue(':base_url', $baseUrl, PDO::PARAM_STR);
    $statement->bindValue(':api_key_encrypted', $encrypted, $encrypted === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $statement->execute();

    return get_user_ai_settings($userId) ?? [
        'user_id' => $userId,
        'provider' => $provider,
        'model' => $model,
        'base_url' => $baseUrl,
        'api_key_encrypted' => $encrypted,
    ];
}

function sanitize_user_theme(array $theme): ?array
{
    $cleanTheme = [];

    foreach (get_theme_keys() as $key) {
        if (!array_key_exists($key, $theme)) {
            continue;
        }

        $value = strtolower(trim((string) $theme[$key]));
        if ($value === '') {
            continue;
        }

        if (preg_match('/^#[0-9a-f]{6}$/', $value) !== 1) {
            throw new InvalidArgumentException('Bitte verwende gültige Hex-Farben im Format #RRGGBB.');
        }

        $cleanTheme[$key] = $value;
    }

    return $cleanTheme !== [] ? $cleanTheme : null;
}

function decode_user_theme($themeJson): array
{
    if (!is_string($themeJson) || trim($themeJson) === '') {
        return [];
    }

    $decoded = json_decode($themeJson, true);
    if (!is_array($decoded)) {
        return [];
    }

    try {
        return sanitize_user_theme($decoded) ?? [];
    } catch (InvalidArgumentException $exception) {
        return [];
    }
}

function find_user_by_username(string $username): ?array
{
    $pdo = get_db_connection();
    $statement = $pdo->prepare('SELECT id, username, password_hash, realname, role, theme_json FROM users WHERE username = :username LIMIT 1');
    $statement->execute([':username' => $username]);
    $user = $statement->fetch();

    return is_array($user) ? $user : null;
}

function find_user_by_id(int $id): ?array
{
    $pdo = get_db_connection();
    $statement = $pdo->prepare('SELECT id, username, password_hash, realname, role, theme_json FROM users WHERE id = :id LIMIT 1');
    $statement->execute([':id' => $id]);
    $user = $statement->fetch();

    return is_array($user) ? $user : null;
}

function create_user(string $username, string $passwordHash, string $realname, string $role = 'student'): array
{
    $pdo = get_db_connection();
    $role = normalize_user_role($role);

    try {
        $statement = $pdo->prepare(
            'INSERT INTO users (username, password_hash, realname, role) VALUES (:username, :password_hash, :realname, :role)'
        );
        $statement->execute([
            ':username' => $username,
            ':password_hash' => $passwordHash,
            ':realname' => $realname,
            ':role' => $role,
        ]);
    } catch (PDOException $exception) {
        if ((string) $exception->getCode() === '23000') {
            throw new DuplicateUsernameException('Dieser Benutzername ist bereits vergeben.', 0, $exception);
        }

        throw $exception;
    }

    $user = find_user_by_id((int) $pdo->lastInsertId());
    if ($user === null) {
        throw new RuntimeException('Der neue Benutzer konnte nicht geladen werden.');
    }

    return $user;
}

function list_student_users(): array
{
    $pdo = get_db_connection();
    $statement = $pdo->query(
        "SELECT u.id, u.username, u.realname, u.role, u.created_at, u.updated_at,
                COUNT(DISTINCT p.id) AS project_count,
                COUNT(DISTINCT sc.id) AS class_count
         FROM users u
         LEFT JOIN projects p ON p.owner_id = u.id
         LEFT JOIN school_classes sc ON sc.teacher_id = u.id
         WHERE u.role IN ('student', 'teacher')
         GROUP BY u.id, u.username, u.realname, u.role, u.created_at, u.updated_at
         ORDER BY
            CASE u.role WHEN 'teacher' THEN 0 ELSE 1 END,
            u.username COLLATE NOCASE ASC"
    );

    return array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'realname' => (string) ($row['realname'] ?? ''),
            'role' => (string) ($row['role'] ?? 'student'),
            'projectCount' => (int) ($row['project_count'] ?? 0),
            'classCount' => (int) ($row['class_count'] ?? 0),
            'createdAt' => (string) ($row['created_at'] ?? ''),
            'updatedAt' => (string) ($row['updated_at'] ?? ''),
        ];
    }, $statement !== false ? $statement->fetchAll() : []);
}

function validate_student_password(string $password): void
{
    $passwordLength = function_exists('mb_strlen') ? mb_strlen($password) : strlen($password);
    if ($passwordLength < 8) {
        throw new InvalidArgumentException('Das Passwort muss mindestens 8 Zeichen lang sein.');
    }
}

function update_student_password_by_admin(int $userId, string $password): array
{
    validate_student_password($password);

    $user = find_user_by_id($userId);
    if ($user === null || !in_array((string) ($user['role'] ?? ''), ['student', 'teacher'], true)) {
        throw new InvalidArgumentException('Account wurde nicht gefunden.');
    }

    $username = (string) $user['username'];
    require_smb_supported_credentials($username, $password);
    provision_smb_user_credentials($username, $password);

    $updated = update_user_profile($userId, (string) ($user['realname'] ?? ''), hash_plain_password($password));
    if ($updated === null) {
        throw new RuntimeException('Passwort konnte nicht aktualisiert werden.');
    }

    return $updated;
}

function upgrade_school_account_to_teacher(int $userId): array
{
    $user = find_user_by_id($userId);
    if ($user === null || (string) ($user['role'] ?? '') !== 'student') {
        throw new InvalidArgumentException('Nur Schüler-Accounts können zu Lehrer-Accounts gemacht werden.');
    }

    $pdo = get_db_connection();
    $statement = $pdo->prepare("UPDATE users SET role = 'teacher', updated_at = CURRENT_TIMESTAMP WHERE id = :id AND role = 'student'");
    $statement->execute([':id' => $userId]);
    if ($statement->rowCount() < 1) {
        throw new RuntimeException('Rolle konnte nicht geändert werden.');
    }

    $updated = find_user_by_id($userId);
    if ($updated === null) {
        throw new RuntimeException('Account konnte nach der Rollenänderung nicht geladen werden.');
    }

    return $updated;
}

function update_user_profile(int $id, string $realname, ?string $passwordHash = null): ?array
{
    $pdo = get_db_connection();

    if ($passwordHash !== null) {
        $statement = $pdo->prepare(
            "UPDATE users SET realname = :realname, password_hash = :password_hash, updated_at = CURRENT_TIMESTAMP WHERE id = :id"
        );
        $statement->execute([
            ':realname' => $realname,
            ':password_hash' => $passwordHash,
            ':id' => $id,
        ]);
    } else {
        $statement = $pdo->prepare("UPDATE users SET realname = :realname, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $statement->execute([
            ':realname' => $realname,
            ':id' => $id,
        ]);
    }

    return find_user_by_id($id);
}

function update_user_theme(int $id, ?array $theme): ?array
{
    $pdo = get_db_connection();
    $cleanTheme = $theme !== null ? sanitize_user_theme($theme) : null;
    $themeJson = $cleanTheme !== null ? json_encode($cleanTheme, JSON_UNESCAPED_SLASHES) : null;

    $statement = $pdo->prepare('UPDATE users SET theme_json = :theme_json, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
    $statement->bindValue(':theme_json', $themeJson, $themeJson === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $statement->bindValue(':id', $id, PDO::PARAM_INT);
    $statement->execute();

    return find_user_by_id($id);
}

function count_users(): int
{
    $pdo = get_db_connection();
    $statement = $pdo->query('SELECT COUNT(*) AS user_count FROM users');
    $row = $statement !== false ? $statement->fetch() : false;

    return is_array($row) ? (int) ($row['user_count'] ?? 0) : 0;
}

function database_health(): array
{
    $path = get_sqlite_database_path();
    $health = [
        'driver' => 'sqlite',
        'connected' => false,
        'usersTableReadable' => false,
        'sqliteLoaded' => extension_loaded('pdo_sqlite') || extension_loaded('sqlite3'),
        'message' => 'Nicht geprüft.',
    ];

    try {
        $userCount = count_users();
        $projectCount = count_projects();
        $health['connected'] = true;
        $health['usersTableReadable'] = true;
        $health['projectsTableReadable'] = true;
        $health['projectFilesTableReadable'] = true;
        $health['writable'] = is_writable($path) || (!is_file($path) && is_writable(dirname($path)));
        $health['userCount'] = $userCount;
        $health['projectCount'] = $projectCount;
        $health['message'] = 'SQLite-Datenbank und Tabellen users/projects sind erreichbar.';

        if (is_detailed_errors_enabled()) {
            $health['path'] = $path;
        }
    } catch (Throwable $exception) {
        log_api_exception($exception);
        $health['message'] = $exception->getMessage();
    }

    return $health;
}

function public_user(array $user): array
{
    $theme = decode_user_theme($user['theme_json'] ?? null);

    return [
        'id' => (int) $user['id'],
        'username' => (string) $user['username'],
        'realname' => (string) ($user['realname'] ?? ''),
        'role' => (string) ($user['role'] ?? 'student'),
        'capabilities' => [
            'admin' => is_admin_user($user),
            'teacher' => is_teacher_user($user),
            'student' => is_student_user($user),
            'coding' => user_can_code($user),
        ],
        'theme' => $theme !== [] ? $theme : new stdClass(),
    ];
}

function is_full_feature_admin_user(?array $user): bool
{
    return false;
}

function is_admin_user(?array $user): bool
{
    return is_array($user) && (string) ($user['role'] ?? '') === 'admin';
}

function is_teacher_user(?array $user): bool
{
    return is_array($user) && ((string) ($user['role'] ?? '') === 'teacher' || is_full_feature_admin_user($user));
}

function is_student_user(?array $user): bool
{
    return is_array($user) && ((string) ($user['role'] ?? '') === 'student' || is_full_feature_admin_user($user));
}

function user_can_code(?array $user): bool
{
    return is_array($user) && (!is_admin_user($user) || is_full_feature_admin_user($user));
}

function current_user_record(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $user = find_user_by_id((int) $_SESSION['user_id']);
    if ($user === null) {
        unset($_SESSION['user_id'], $_SESSION['username']);
    }

    return $user;
}

function require_logged_in_user(): array
{
    $user = current_user_record();
    if ($user === null) {
        json_response([
            'success' => false,
            'message' => 'Bitte melde dich zuerst an.',
        ], 401);
    }

    return $user;
}

function require_admin_user(): array
{
    $user = require_logged_in_user();
    if (!is_admin_user($user)) {
        json_response([
            'success' => false,
            'message' => 'Nur der Admin darf diese Einstellung ändern.',
            'csrfToken' => ensure_csrf_token(),
        ], 403);
    }

    return $user;
}

function require_teacher_user(): array
{
    $user = require_logged_in_user();
    if (!is_teacher_user($user)) {
        json_response([
            'success' => false,
            'message' => 'Nur Lehrer können Klassen erstellen.',
            'csrfToken' => ensure_csrf_token(),
        ], 403);
    }

    return $user;
}

function require_student_user(): array
{
    $user = require_logged_in_user();
    if (!is_student_user($user)) {
        json_response([
            'success' => false,
            'message' => 'Nur Schüler können einer Klasse beitreten.',
            'csrfToken' => ensure_csrf_token(),
        ], 403);
    }

    return $user;
}

function require_coding_user(array $user): void
{
    if (!user_can_code($user)) {
        json_response([
            'success' => false,
            'message' => 'Der Admin verwaltet das System und darf keine Projekte bearbeiten oder ausführen.',
            'csrfToken' => ensure_csrf_token(),
        ], 403);
    }
}

function normalize_school_class_name(string $name): string
{
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
    $length = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
    if ($name === '' || $length > 100) {
        throw new InvalidArgumentException('Bitte gib einen Klassennamen mit maximal 100 Zeichen ein.');
    }

    return $name;
}

function normalize_school_class_code(string $code): string
{
    $code = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $code) ?? '');
    if (strlen($code) < 6 || strlen($code) > 12) {
        throw new InvalidArgumentException('Der Klassencode ist ungültig.');
    }

    return $code;
}

function generate_school_class_code(): string
{
    $pdo = get_db_connection();
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    for ($attempt = 0; $attempt < 30; $attempt++) {
        $code = '';
        for ($index = 0; $index < 8; $index++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $statement = $pdo->prepare('SELECT 1 FROM school_classes WHERE code = :code LIMIT 1');
        $statement->execute([':code' => $code]);
        if ($statement->fetchColumn() === false) {
            return $code;
        }
    }

    throw new RuntimeException('Es konnte kein eindeutiger Klassencode erzeugt werden.');
}

function public_school_class(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'code' => (string) $row['code'],
        'teacherId' => (int) ($row['teacher_id'] ?? 0),
        'teacherUsername' => (string) ($row['teacher_username'] ?? ''),
        'teacherName' => (string) ($row['teacher_realname'] ?? ''),
        'memberCount' => (int) ($row['member_count'] ?? 0),
        'createdAt' => (string) ($row['created_at'] ?? ''),
        'joinedAt' => (string) ($row['joined_at'] ?? ''),
    ];
}

function list_teacher_school_classes(int $teacherId): array
{
    $pdo = get_db_connection();
    $statement = $pdo->prepare(
        "SELECT sc.*, u.username AS teacher_username, u.realname AS teacher_realname, COUNT(scm.user_id) AS member_count
         FROM school_classes sc
         INNER JOIN users u ON u.id = sc.teacher_id
         LEFT JOIN school_class_members scm ON scm.class_id = sc.id
         WHERE sc.teacher_id = :teacher_id
         GROUP BY sc.id, sc.teacher_id, sc.name, sc.code, sc.created_at, sc.updated_at, u.username, u.realname
         ORDER BY sc.created_at DESC, sc.id DESC"
    );
    $statement->execute([':teacher_id' => $teacherId]);

    return array_map(static fn (array $row): array => public_school_class($row), $statement->fetchAll());
}

function create_school_class(int $teacherId, string $name): array
{
    $name = normalize_school_class_name($name);
    $code = generate_school_class_code();
    $pdo = get_db_connection();
    $statement = $pdo->prepare(
        'INSERT INTO school_classes (teacher_id, name, code, updated_at)
         VALUES (:teacher_id, :name, :code, CURRENT_TIMESTAMP)'
    );
    $statement->execute([
        ':teacher_id' => $teacherId,
        ':name' => $name,
        ':code' => $code,
    ]);
    $classId = (int) $pdo->lastInsertId();

    $classes = list_teacher_school_classes($teacherId);
    foreach ($classes as $class) {
        if ((int) $class['id'] === $classId) {
            return $class;
        }
    }

    throw new RuntimeException('Klasse konnte nach dem Erstellen nicht geladen werden.');
}

function list_student_school_classes(int $userId): array
{
    $pdo = get_db_connection();
    $statement = $pdo->prepare(
        "SELECT sc.*, u.username AS teacher_username, u.realname AS teacher_realname, scm.joined_at, COUNT(all_members.user_id) AS member_count
         FROM school_class_members scm
         INNER JOIN school_classes sc ON sc.id = scm.class_id
         INNER JOIN users u ON u.id = sc.teacher_id
         LEFT JOIN school_class_members all_members ON all_members.class_id = sc.id
         WHERE scm.user_id = :user_id
         GROUP BY sc.id, sc.teacher_id, sc.name, sc.code, sc.created_at, sc.updated_at, u.username, u.realname, scm.joined_at
         ORDER BY scm.joined_at DESC, sc.name COLLATE NOCASE ASC"
    );
    $statement->execute([':user_id' => $userId]);

    return array_map(static fn (array $row): array => public_school_class($row), $statement->fetchAll());
}

function join_school_class_by_code(int $userId, string $code): array
{
    $code = normalize_school_class_code($code);
    $pdo = get_db_connection();
    $statement = $pdo->prepare(
        "SELECT sc.*, u.username AS teacher_username, u.realname AS teacher_realname
         FROM school_classes sc
         INNER JOIN users u ON u.id = sc.teacher_id
         WHERE sc.code = :code
         LIMIT 1"
    );
    $statement->execute([':code' => $code]);
    $class = $statement->fetch();
    if (!is_array($class)) {
        throw new InvalidArgumentException('Klassencode wurde nicht gefunden.');
    }

    $insert = $pdo->prepare('INSERT OR IGNORE INTO school_class_members (class_id, user_id) VALUES (:class_id, :user_id)');
    $insert->execute([
        ':class_id' => (int) $class['id'],
        ':user_id' => $userId,
    ]);

    foreach (list_student_school_classes($userId) as $joinedClass) {
        if ((int) $joinedClass['id'] === (int) $class['id']) {
            return $joinedClass;
        }
    }

    throw new RuntimeException('Klasse konnte nach dem Beitritt nicht geladen werden.');
}

function ensure_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf_token'];
}

function rotate_csrf_token(): string
{
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return (string) $_SESSION['csrf_token'];
}

function require_csrf_token(array $input = []): void
{
    $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
    $requestToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrfToken'] ?? ''));

    if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
        json_response([
            'success' => false,
            'message' => 'Die Sicherheitsprüfung ist fehlgeschlagen. Bitte lade die Seite neu.',
        ], 419);
    }
}

function update_password_hash_if_needed(array $user, string $plainPassword): void
{
    if (!password_needs_rehash((string) $user['password_hash'], get_password_algorithm())) {
        return;
    }

    update_user_profile((int) $user['id'], (string) ($user['realname'] ?? ''), hash_plain_password($plainPassword));
}

function sanitize_content_item(array $item): array
{
    $url = trim((string) ($item['url'] ?? ''));
    if (preg_match('/^\s*javascript:/i', $url) === 1) {
        $url = '';
    }

    return [
        'id' => (string) ($item['id'] ?? ''),
        'title' => (string) ($item['title'] ?? 'Freigegebener Inhalt'),
        'description' => (string) ($item['description'] ?? ''),
        'type' => (string) ($item['type'] ?? 'Freigabe'),
        'url' => $url,
    ];
}

function merge_content_items(array $baseItems, array $additionalItems): array
{
    $items = [];

    foreach (array_merge($baseItems, $additionalItems) as $item) {
        if (is_array($item)) {
            $items[] = sanitize_content_item($item);
        }
    }

    return $items;
}

function get_allowed_content_for_user(array $user): array
{
    $settings = load_settings();
    $content = $settings['studentContent'] ?? [];
    $defaultItems = is_array($content['default'] ?? null) ? $content['default'] : [];
    $userId = (string) $user['id'];
    $username = (string) $user['username'];

    $byUserId = is_array($content['byUserId'] ?? null) ? $content['byUserId'] : [];
    $byUsername = is_array($content['byUsername'] ?? null) ? $content['byUsername'] : [];
    $specificItems = [];

    if (isset($byUserId[$userId]) && is_array($byUserId[$userId])) {
        $specificItems = array_merge($specificItems, $byUserId[$userId]);
    }

    if (isset($byUsername[$username]) && is_array($byUsername[$username])) {
        $specificItems = array_merge($specificItems, $byUsername[$username]);
    }

    return merge_content_items($defaultItems, $specificItems);
}

function get_project_settings(): array
{
    $settings = load_settings();
    return is_array($settings['projects'] ?? null) ? $settings['projects'] : [];
}

function get_project_upload_directory(): string
{
    $projectSettings = get_project_settings();
    $path = (string) ($projectSettings['uploadPath'] ?? '{PROJECT_ROOT}/uploads/projects');
    return resolve_project_path($path);
}

function get_max_upload_bytes(): int
{
    $projectSettings = get_project_settings();
    return max(1, (int) ($projectSettings['maxUploadBytes'] ?? 10485760));
}

function get_runtime_settings(): array
{
    $settings = load_settings();
    return is_array($settings['runtimes'] ?? null) ? $settings['runtimes'] : [];
}

function are_project_runtimes_enabled(): bool
{
    $settings = get_runtime_settings();
    return (bool) ($settings['enabled'] ?? false);
}

function get_runtime_workspace_directory(): string
{
    $settings = get_runtime_settings();
    return resolve_project_path((string) ($settings['workspacePath'] ?? '{PROJECT_ROOT}/runtime/jobs'));
}

function get_runtime_timeout_seconds(): int
{
    $settings = get_runtime_settings();
    $seconds = (int) ($settings['timeoutSeconds'] ?? 5);
    return min(max($seconds, 1), 30);
}

function get_runtime_max_output_bytes(): int
{
    $settings = get_runtime_settings();
    $bytes = (int) ($settings['maxOutputBytes'] ?? 65536);
    return min(max($bytes, 4096), 1048576);
}

function get_runtime_max_input_bytes(): int
{
    $settings = get_runtime_settings();
    $bytes = (int) ($settings['maxInputBytes'] ?? 16384);
    return min(max($bytes, 0), 262144);
}

function get_runtime_max_source_bytes(): int
{
    $settings = get_runtime_settings();
    $bytes = (int) ($settings['maxSourceBytes'] ?? 1048576);
    return min(max($bytes, 4096), get_max_upload_bytes());
}

function get_runtime_max_memory_kb(): int
{
    $settings = get_runtime_settings();
    $kilobytes = (int) ($settings['maxMemoryKb'] ?? 524288);
    return min(max($kilobytes, 131072), 2097152);
}

function is_runtime_sandbox_enabled(): bool
{
    $settings = get_runtime_settings();
    return (bool) ($settings['sandboxEnabled'] ?? true);
}

function get_runtime_max_tasks(): int
{
    $settings = get_runtime_settings();
    $tasks = (int) ($settings['maxTasks'] ?? 32);
    return min(max($tasks, 4), 128);
}

function get_runtime_java_max_heap_mb(): int
{
    $settings = get_runtime_settings();
    $megabytes = (int) ($settings['javaMaxHeapMb'] ?? 96);
    return min(max($megabytes, 32), 256);
}

function get_runtime_java_max_memory_kb(): int
{
    $settings = get_runtime_settings();
    $kilobytes = (int) ($settings['javaMaxMemoryKb'] ?? 1048576);
    return min(max($kilobytes, get_runtime_max_memory_kb()), 2097152);
}

function get_runtime_sandbox_readonly_paths(): array
{
    $settings = get_runtime_settings();
    $paths = $settings['sandboxReadonlyPaths'] ?? ['/usr', '/bin', '/lib', '/lib64', '/etc/alternatives', '/etc/java-17-openjdk', '/etc/java-21-openjdk'];
    if (!is_array($paths)) {
        return [];
    }

    $normalized = [];
    foreach ($paths as $path) {
        $path = rtrim((string) $path, '/');
        if ($path !== '' && $path[0] === '/') {
            $normalized[] = $path;
        }
    }

    return array_values(array_unique($normalized));
}

function ensure_runtime_workspace_directory(): void
{
    $directory = get_runtime_workspace_directory();
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Runtime-Arbeitsordner konnte nicht erstellt werden.');
    }

    if (!is_writable($directory)) {
        throw new RuntimeException('Runtime-Arbeitsordner ist nicht beschreibbar.');
    }
}

function get_smb_settings(): array
{
    $settings = load_settings();
    return is_array($settings['smb'] ?? null) ? $settings['smb'] : [];
}

function is_smb_sync_enabled(): bool
{
    $settings = get_smb_settings();
    return (bool) ($settings['enabled'] ?? false);
}

function is_smb_auto_import_enabled(): bool
{
    $settings = get_smb_settings();
    return is_smb_sync_enabled() && (bool) ($settings['autoImport'] ?? true);
}

function is_smb_auto_export_enabled(): bool
{
    $settings = get_smb_settings();
    return is_smb_sync_enabled() && (bool) ($settings['autoExport'] ?? true);
}

function get_smb_workspace_directory(): string
{
    $settings = get_smb_settings();
    $path = (string) ($settings['workspacePath'] ?? '{PROJECT_ROOT}/smb/projects');
    return resolve_project_path($path);
}

function is_smb_user_provisioning_enabled(): bool
{
    $settings = get_smb_settings();
    return is_smb_sync_enabled() && (bool) ($settings['userProvisioning'] ?? false);
}

function get_smb_provision_helper_path(): string
{
    $settings = get_smb_settings();
    return (string) ($settings['provisionHelperPath'] ?? '/usr/local/sbin/liveserver-samba-user-sync');
}

function get_smb_acl_helper_path(): string
{
    $settings = get_smb_settings();
    return (string) ($settings['aclHelperPath'] ?? '/usr/local/sbin/liveserver-samba-acl-sync');
}

function is_smb_username_supported(string $username): bool
{
    return preg_match('/^[a-zA-Z0-9_][a-zA-Z0-9._-]{2,31}$/', $username) === 1;
}

function is_smb_password_supported(string $password): bool
{
    return strlen($password) >= 8 && strpos($password, "\n") === false && strpos($password, "\r") === false;
}

function require_smb_supported_credentials(string $username, string $password): void
{
    if (!is_smb_user_provisioning_enabled()) {
        return;
    }

    if (!is_smb_username_supported($username)) {
        json_response([
            'success' => false,
            'message' => 'Der Benutzername muss für Samba 3 bis 32 Zeichen lang sein, mit Buchstabe/Zahl/Unterstrich beginnen und darf danach Buchstaben, Zahlen, Punkt, Unterstrich und Bindestrich enthalten.',
        ], 400);
    }

    if (!is_smb_password_supported($password)) {
        json_response([
            'success' => false,
            'message' => 'Das Passwort muss für Samba mindestens 8 Zeichen lang sein und darf keinen Zeilenumbruch enthalten.',
        ], 400);
    }
}

function provision_smb_user_credentials(string $username, string $password): void
{
    if (!is_smb_user_provisioning_enabled()) {
        return;
    }

    if (!is_smb_username_supported($username) || !is_smb_password_supported($password)) {
        throw new RuntimeException('Samba-Zugangsdaten sind nicht kompatibel.');
    }

    $helperPath = get_smb_provision_helper_path();
    if (!is_file($helperPath) || !is_executable($helperPath)) {
        throw new RuntimeException('Samba-Provisionierungshelfer ist nicht ausführbar.');
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open(['sudo', '-n', $helperPath, $username], $descriptors, $pipes);

    if (!is_resource($process)) {
        throw new RuntimeException('Samba-Provisionierungshelfer konnte nicht gestartet werden.');
    }

    fwrite($pipes[0], $password);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        $detail = trim((string) ($stderr !== '' ? $stderr : $stdout));
        throw new RuntimeException('Samba-Benutzer konnte nicht vorbereitet werden.' . ($detail !== '' ? ' ' . $detail : ''));
    }

    try {
        smb_apply_editable_project_permissions_for_username($username);
    } catch (Throwable $exception) {
        log_api_exception($exception);
    }
}

function ensure_project_upload_directory(): void
{
    $directory = get_project_upload_directory();

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Upload-Ordner konnte nicht erstellt werden.');
    }

    if (!is_writable($directory)) {
        throw new RuntimeException('Upload-Ordner ist nicht beschreibbar.');
    }

    $htaccess = $directory . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\nDeny from all\nOptions -Indexes\n");
    }
    @chmod($htaccess, 0600);
}

function normalize_shared_usernames(string $sharedUsernames): array
{
    $parts = preg_split('/[,;\n\r]+/', $sharedUsernames) ?: [];
    $usernames = [];

    foreach ($parts as $part) {
        $username = trim((string) $part);
        if ($username === '') {
            continue;
        }

        if (preg_match('/^[a-zA-Z0-9._-]{3,100}$/', $username) !== 1) {
            throw new InvalidArgumentException('Shared-Benutzername ist ungültig: ' . $username);
        }

        $usernames[strtolower($username)] = $username;
    }

    return array_values($usernames);
}

function shared_target_key(string $value): string
{
    $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';
    return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
}

function get_teacher_class_targets_by_name(int $teacherId): array
{
    $classes = list_teacher_school_classes($teacherId);
    $targets = [];

    foreach ($classes as $class) {
        $name = (string) ($class['name'] ?? '');
        if ($name !== '') {
            $targets[shared_target_key($name)] = [
                'id' => (int) $class['id'],
                'name' => $name,
            ];
        }
    }

    return $targets;
}

function split_shared_targets(string $sharedTargets): array
{
    $parts = preg_split('/[,;\n\r]+/', $sharedTargets) ?: [];
    $targets = [];

    foreach ($parts as $part) {
        $target = preg_replace('/\s+/', ' ', trim((string) $part)) ?? '';
        if ($target !== '') {
            $targets[] = $target;
        }
    }

    return $targets;
}

function normalize_shared_targets(string $sharedTargets, array $owner, string $projectType): array
{
    $usernames = [];
    $classes = [];
    $classLookup = is_teacher_user($owner) && $projectType === 'webpage'
        ? get_teacher_class_targets_by_name((int) $owner['id'])
        : [];

    foreach (split_shared_targets($sharedTargets) as $target) {
        $class = $classLookup[shared_target_key($target)] ?? null;
        if (is_array($class)) {
            $classes[(int) $class['id']] = $class;
            continue;
        }

        if (preg_match('/^[a-zA-Z0-9._-]{3,100}$/', $target) !== 1) {
            throw new InvalidArgumentException('Shared-Benutzername oder Klasse ist ungültig: ' . $target);
        }

        $usernames[strtolower($target)] = $target;
    }

    return [
        'usernames' => array_values($usernames),
        'classes' => array_values($classes),
    ];
}

function normalize_project_permission(string $permission): string
{
    return $permission === 'write' ? 'write' : 'read';
}

function normalize_site_refresh_mode(string $mode): string
{
    return $mode === 'auto' ? 'auto' : 'manual';
}

function normalize_site_refresh_seconds($seconds): int
{
    $seconds = (int) $seconds;
    if ($seconds < 1) {
        return 5;
    }

    return min($seconds, 3600);
}

function default_runtime_entry_file(string $type): string
{
    return match ($type) {
        'java' => 'Main.java',
        'c' => 'main.c',
        default => '',
    };
}

function normalize_runtime_entry_file(string $type, string $entryFile): string
{
    $entryFile = normalize_project_folder_path($entryFile);
    if ($entryFile === '') {
        return default_runtime_entry_file($type);
    }

    $extension = strtolower(pathinfo($entryFile, PATHINFO_EXTENSION));
    if ($type === 'java' && $extension !== 'java') {
        throw new InvalidArgumentException('Java-Projekte brauchen eine .java Entry-Datei.');
    }

    if ($type === 'c' && $extension !== 'c') {
        throw new InvalidArgumentException('C-Projekte brauchen eine .c Entry-Datei.');
    }

    return $entryFile;
}

function normalize_runtime_config(string $type, $config): array
{
    if (!is_runtime_project_type($type)) {
        return [];
    }

    if (is_string($config)) {
        $decoded = json_decode($config, true);
        $config = is_array($decoded) ? $decoded : [];
    }

    if (!is_array($config)) {
        $config = [];
    }

    return [
        'entryFile' => normalize_runtime_entry_file($type, (string) ($config['entryFile'] ?? default_runtime_entry_file($type))),
    ];
}

function encode_runtime_config(string $type, array $config): string
{
    $normalized = normalize_runtime_config($type, $config);
    if ($normalized === []) {
        return '{}';
    }

    return json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
}

function project_runtime_config(array $project): array
{
    return normalize_runtime_config((string) ($project['type'] ?? ''), $project['runtime_config'] ?? '{}');
}

function update_project_runtime_config(int $projectId, string $type, array $config): void
{
    if (!is_runtime_project_type($type)) {
        return;
    }

    $pdo = get_db_connection();
    $statement = $pdo->prepare(
        'UPDATE projects SET runtime_config = :runtime_config, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
    );
    $statement->execute([
        ':runtime_config' => encode_runtime_config($type, $config),
        ':id' => $projectId,
    ]);
}

function normalize_project_folder_path(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $parts = [];

    foreach (explode('/', $path) as $part) {
        $part = trim($part);
        if ($part === '' || $part === '.' || $part === '..') {
            continue;
        }

        $part = preg_replace('/[^a-zA-Z0-9._ -]/', '_', $part) ?: 'ordner';
        $part = trim($part, '. ');
        if ($part !== '') {
            $parts[] = $part;
        }
    }

    return implode('/', $parts);
}

function collect_parent_folder_paths(string $path): array
{
    $path = normalize_project_folder_path($path);
    if ($path === '') {
        return [];
    }

    $parts = explode('/', $path);
    array_pop($parts);
    $folders = [];

    while ($parts !== []) {
        $folders[] = implode('/', $parts);
        array_pop($parts);
    }

    return array_reverse($folders);
}

function collect_project_folder_paths(array $fileInfos, array $folderPaths = []): array
{
    $folders = [];

    foreach ($fileInfos as $fileInfo) {
        $relativePath = (string) ($fileInfo['relative_path'] ?? '');
        foreach (collect_parent_folder_paths($relativePath) as $folderPath) {
            $folders[$folderPath] = $folderPath;
        }
    }

    foreach ($folderPaths as $folderPath) {
        $normalized = normalize_project_folder_path((string) $folderPath);
        if ($normalized === '') {
            continue;
        }

        $folders[$normalized] = $normalized;
        foreach (collect_parent_folder_paths($normalized . '/placeholder.txt') as $parentPath) {
            $folders[$parentPath] = $parentPath;
        }
    }

    ksort($folders, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values($folders);
}

function normalize_shared_permissions($value, array $sharedUsernames): array
{
    $permissions = [];
    if (is_string($value) && trim($value) !== '') {
        $decoded = json_decode($value, true);
        $value = is_array($decoded) ? $decoded : [];
    }

    if (is_array($value)) {
        foreach ($value as $username => $permission) {
            $username = strtolower(trim((string) $username));
            if ($username !== '') {
                $permissions[$username] = normalize_project_permission((string) $permission);
            }
        }
    }

    $normalized = [];
    foreach ($sharedUsernames as $username) {
        $normalized[$username] = $permissions[strtolower($username)] ?? 'read';
    }

    return $normalized;
}

function decode_shared_permissions($value): array
{
    if (is_string($value) && trim($value) !== '') {
        $decoded = json_decode($value, true);
        $value = is_array($decoded) ? $decoded : [];
    }

    $permissions = [];
    if (is_array($value)) {
        foreach ($value as $target => $permission) {
            $key = shared_target_key((string) $target);
            if ($key !== '') {
                $permissions[$key] = normalize_project_permission((string) $permission);
            }
        }
    }

    return $permissions;
}

function normalize_shared_target_permissions($value, array $targets): array
{
    $permissions = decode_shared_permissions($value);
    $userPermissions = [];
    $classPermissions = [];

    foreach ($targets['usernames'] ?? [] as $username) {
        $userPermissions[$username] = $permissions[shared_target_key((string) $username)] ?? 'read';
    }

    foreach ($targets['classes'] ?? [] as $class) {
        $classId = (int) ($class['id'] ?? 0);
        $className = (string) ($class['name'] ?? '');
        if ($classId > 0) {
            $classPermissions[$classId] = $permissions[shared_target_key($className)] ?? 'read';
        }
    }

    return [
        'usernames' => $userPermissions,
        'classes' => $classPermissions,
    ];
}

function count_projects(): int
{
    $pdo = get_db_connection();
    $statement = $pdo->query('SELECT COUNT(*) AS project_count FROM projects');
    $row = $statement !== false ? $statement->fetch() : false;

    return is_array($row) ? (int) ($row['project_count'] ?? 0) : 0;
}

function find_project_by_id(int $id): ?array
{
    $pdo = get_db_connection();
    $statement = $pdo->prepare(
        'SELECT p.*, u.username AS owner_username
         FROM projects p
         INNER JOIN users u ON u.id = p.owner_id
         WHERE p.id = :id
         LIMIT 1'
    );
    $statement->execute([':id' => $id]);
    $project = $statement->fetch();

    return is_array($project) ? $project : null;
}

function get_project_shares(int $projectId): array
{
    $pdo = get_db_connection();
    $statement = $pdo->prepare(
        "SELECT username AS target, 0 AS sort_group FROM project_shares WHERE project_id = :project_id
         UNION ALL
         SELECT sc.name AS target, 1 AS sort_group
         FROM project_class_shares pcs
         INNER JOIN school_classes sc ON sc.id = pcs.class_id
         WHERE pcs.project_id = :project_id
         ORDER BY sort_group ASC, target COLLATE NOCASE ASC"
    );
    $statement->execute([':project_id' => $projectId]);

    return array_map(static fn (array $row): string => (string) $row['target'], $statement->fetchAll());
}

function get_project_user_share_permissions(int $projectId): array
{
    $pdo = get_db_connection();
    $statement = $pdo->prepare('SELECT username, permission FROM project_shares WHERE project_id = :project_id ORDER BY username ASC');
    $statement->execute([':project_id' => $projectId]);
    $permissions = [];

    foreach ($statement->fetchAll() as $row) {
        $permissions[(string) $row['username']] = normalize_project_permission((string) ($row['permission'] ?? 'read'));
    }

    return $permissions;
}

function get_project_share_permissions(int $projectId): array
{
    $pdo = get_db_connection();
    $permissions = get_project_user_share_permissions($projectId);

    $statement = $pdo->prepare(
        'SELECT sc.name, pcs.permission
         FROM project_class_shares pcs
         INNER JOIN school_classes sc ON sc.id = pcs.class_id
         WHERE pcs.project_id = :project_id
         ORDER BY sc.name COLLATE NOCASE ASC'
    );
    $statement->execute([':project_id' => $projectId]);
    foreach ($statement->fetchAll() as $row) {
        $permissions[(string) $row['name']] = normalize_project_permission((string) ($row['permission'] ?? 'read'));
    }

    return $permissions;
}

function get_project_class_share_permissions(int $projectId): array
{
    $pdo = get_db_connection();
    $statement = $pdo->prepare('SELECT class_id, permission FROM project_class_shares WHERE project_id = :project_id');
    $statement->execute([':project_id' => $projectId]);
    $permissions = [];

    foreach ($statement->fetchAll() as $row) {
        $permissions[(int) $row['class_id']] = normalize_project_permission((string) ($row['permission'] ?? 'read'));
    }

    return $permissions;
}

function get_project_class_member_permissions(int $projectId): array
{
    $pdo = get_db_connection();
    $statement = $pdo->prepare(
        "SELECT u.username, pcs.permission
         FROM project_class_shares pcs
         INNER JOIN school_class_members scm ON scm.class_id = pcs.class_id
         INNER JOIN users u ON u.id = scm.user_id
         WHERE pcs.project_id = :project_id
         ORDER BY u.username COLLATE NOCASE ASC"
    );
    $statement->execute([':project_id' => $projectId]);
    $permissions = [];

    foreach ($statement->fetchAll() as $row) {
        $username = (string) ($row['username'] ?? '');
        if ($username !== '') {
            $permissions[$username] = normalize_project_permission((string) ($row['permission'] ?? 'read'));
        }
    }

    return $permissions;
}

function replace_project_folders(PDO $pdo, int $projectId, array $folderPaths): void
{
    $deleteStatement = $pdo->prepare('DELETE FROM project_folders WHERE project_id = :project_id');
    $deleteStatement->execute([':project_id' => $projectId]);

    if ($folderPaths === []) {
        return;
    }

    $insertStatement = $pdo->prepare(
        'INSERT OR IGNORE INTO project_folders (project_id, relative_path) VALUES (:project_id, :relative_path)'
    );

    foreach ($folderPaths as $folderPath) {
        $insertStatement->execute([
            ':project_id' => $projectId,
            ':relative_path' => $folderPath,
        ]);
    }
}

function get_project_folders(array $project): array
{
    $pdo = get_db_connection();
    $statement = $pdo->prepare(
        'SELECT relative_path FROM project_folders WHERE project_id = :project_id ORDER BY relative_path COLLATE NOCASE ASC'
    );
    $statement->execute([':project_id' => (int) $project['id']]);

    $folders = [];
    foreach ($statement->fetchAll() as $row) {
        $relativePath = normalize_project_folder_path((string) ($row['relative_path'] ?? ''));
        if ($relativePath !== '') {
            $folders[$relativePath] = $relativePath;
        }
    }

    foreach (collect_project_folder_paths(get_project_files($project)) as $folderPath) {
        $folders[$folderPath] = $folderPath;
    }

    ksort($folders, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values($folders);
}

function require_project_owner(int $projectId, array $user): array
{
    $project = find_project_by_id($projectId);
    if ($project === null) {
        json_response([
            'success' => false,
            'message' => 'Projekt wurde nicht gefunden.',
        ], 404);
    }

    if ((int) $project['owner_id'] !== (int) $user['id']) {
        json_response([
            'success' => false,
            'message' => 'Nur der Ersteller kann dieses Projekt ändern.',
        ], 403);
    }

    return $project;
}

function create_project_record(
    int $ownerId,
    string $title,
    string $type,
    string $visibility,
    ?string $htmlContent,
    string $uploadKind,
    array $fileInfos,
    array $sharedUsernames,
    string $publicPermission = 'read',
    array $sharedPermissions = [],
    array $folderPaths = [],
    string $siteRefreshMode = 'manual',
    int $siteRefreshSeconds = 5,
    array $runtimeConfig = [],
    array $sharedClassIds = [],
    array $sharedClassPermissions = []
): array {
    $type = normalize_project_type($type);
    $pdo = get_db_connection();
    $pdo->beginTransaction();

    try {
        $statement = $pdo->prepare(
            'INSERT INTO projects (
                owner_id, title, type, visibility, public_permission, site_refresh_mode, site_refresh_seconds, runtime_config, upload_kind, stored_filename, original_filename, mime_type, file_size, html_content
            ) VALUES (
                :owner_id, :title, :type, :visibility, :public_permission, :site_refresh_mode, :site_refresh_seconds, :runtime_config, :upload_kind, :stored_filename, :original_filename, :mime_type, :file_size, :html_content
            )'
        );
        $firstFile = $fileInfos[0] ?? [];
        $statement->execute([
            ':owner_id' => $ownerId,
            ':title' => $title,
            ':type' => $type,
            ':visibility' => $visibility,
            ':public_permission' => normalize_project_permission($publicPermission),
            ':site_refresh_mode' => $type === 'webpage' ? normalize_site_refresh_mode($siteRefreshMode) : 'manual',
            ':site_refresh_seconds' => normalize_site_refresh_seconds($siteRefreshSeconds),
            ':runtime_config' => encode_runtime_config($type, $runtimeConfig),
            ':upload_kind' => $uploadKind,
            ':stored_filename' => $firstFile['stored_filename'] ?? null,
            ':original_filename' => $firstFile['original_filename'] ?? null,
            ':mime_type' => $firstFile['mime_type'] ?? null,
            ':file_size' => array_sum(array_map(static fn (array $file): int => (int) ($file['file_size'] ?? 0), $fileInfos)) ?: null,
            ':html_content' => $htmlContent,
        ]);

        $projectId = (int) $pdo->lastInsertId();

        if ($fileInfos !== []) {
            $fileStatement = $pdo->prepare(
                'INSERT INTO project_files (
                    project_id, stored_filename, original_filename, relative_path, mime_type, file_size
                ) VALUES (
                    :project_id, :stored_filename, :original_filename, :relative_path, :mime_type, :file_size
                )'
            );

            foreach ($fileInfos as $fileInfo) {
                $fileStatement->execute([
                    ':project_id' => $projectId,
                    ':stored_filename' => (string) $fileInfo['stored_filename'],
                    ':original_filename' => (string) $fileInfo['original_filename'],
                    ':relative_path' => (string) $fileInfo['relative_path'],
                    ':mime_type' => (string) $fileInfo['mime_type'],
                    ':file_size' => (int) $fileInfo['file_size'],
                ]);
            }
        }

        if ($visibility === 'shared' && $sharedUsernames !== []) {
            $shareStatement = $pdo->prepare(
                'INSERT OR IGNORE INTO project_shares (project_id, username, permission) VALUES (:project_id, :username, :permission)'
            );

            foreach ($sharedUsernames as $username) {
                $shareStatement->execute([
                    ':project_id' => $projectId,
                    ':username' => $username,
                    ':permission' => $sharedPermissions[$username] ?? 'read',
                ]);
            }
        }

        if ($visibility === 'shared' && $sharedClassIds !== []) {
            $classShareStatement = $pdo->prepare(
                'INSERT OR REPLACE INTO project_class_shares (project_id, class_id, permission) VALUES (:project_id, :class_id, :permission)'
            );

            foreach ($sharedClassIds as $classId) {
                $classId = (int) $classId;
                if ($classId <= 0) {
                    continue;
                }

                $classShareStatement->execute([
                    ':project_id' => $projectId,
                    ':class_id' => $classId,
                    ':permission' => $sharedClassPermissions[$classId] ?? 'read',
                ]);
            }
        }

        replace_project_folders($pdo, $projectId, collect_project_folder_paths($fileInfos, $folderPaths));

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    $project = find_project_by_id($projectId);
    if ($project === null) {
        throw new RuntimeException('Projekt konnte nach dem Erstellen nicht geladen werden.');
    }

    return $project;
}

function update_project_visibility(
    int $projectId,
    int $ownerId,
    string $visibility,
    array $sharedUsernames,
    string $publicPermission = 'read',
    array $sharedPermissions = [],
    string $siteRefreshMode = 'manual',
    int $siteRefreshSeconds = 5,
    array $sharedClassIds = [],
    array $sharedClassPermissions = []
): array
{
    $pdo = get_db_connection();
    $pdo->beginTransaction();

    try {
        $statement = $pdo->prepare(
            'UPDATE projects
             SET visibility = :visibility,
                 public_permission = :public_permission,
                 site_refresh_mode = :site_refresh_mode,
                 site_refresh_seconds = :site_refresh_seconds,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND owner_id = :owner_id'
        );
        $statement->execute([
            ':visibility' => $visibility,
            ':public_permission' => normalize_project_permission($publicPermission),
            ':site_refresh_mode' => normalize_site_refresh_mode($siteRefreshMode),
            ':site_refresh_seconds' => normalize_site_refresh_seconds($siteRefreshSeconds),
            ':id' => $projectId,
            ':owner_id' => $ownerId,
        ]);

        if ($statement->rowCount() < 1) {
            throw new RuntimeException('Projekt konnte nicht aktualisiert werden.');
        }

        $deleteShares = $pdo->prepare('DELETE FROM project_shares WHERE project_id = :project_id');
        $deleteShares->execute([':project_id' => $projectId]);
        $deleteClassShares = $pdo->prepare('DELETE FROM project_class_shares WHERE project_id = :project_id');
        $deleteClassShares->execute([':project_id' => $projectId]);

        if ($visibility === 'shared' && $sharedUsernames !== []) {
            $shareStatement = $pdo->prepare(
                'INSERT OR IGNORE INTO project_shares (project_id, username, permission) VALUES (:project_id, :username, :permission)'
            );

            foreach ($sharedUsernames as $username) {
                $shareStatement->execute([
                    ':project_id' => $projectId,
                    ':username' => $username,
                    ':permission' => $sharedPermissions[$username] ?? 'read',
                ]);
            }
        }

        if ($visibility === 'shared' && $sharedClassIds !== []) {
            $classShareStatement = $pdo->prepare(
                'INSERT OR REPLACE INTO project_class_shares (project_id, class_id, permission) VALUES (:project_id, :class_id, :permission)'
            );

            foreach ($sharedClassIds as $classId) {
                $classId = (int) $classId;
                if ($classId <= 0) {
                    continue;
                }

                $classShareStatement->execute([
                    ':project_id' => $projectId,
                    ':class_id' => $classId,
                    ':permission' => $sharedClassPermissions[$classId] ?? 'read',
                ]);
            }
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    $project = find_project_by_id($projectId);
    if ($project === null) {
        throw new RuntimeException('Projekt konnte nach dem Aktualisieren nicht geladen werden.');
    }

    try {
        smb_apply_project_access_control($project, true);
    } catch (Throwable $exception) {
        log_api_exception($exception);
    }

    return $project;
}

function delete_project_record(array $project): void
{
    $files = get_project_files($project);
    $pdo = get_db_connection();

    smb_delete_project_workspace($project);

    $statement = $pdo->prepare('DELETE FROM projects WHERE id = :id AND owner_id = :owner_id');
    $statement->execute([
        ':id' => (int) $project['id'],
        ':owner_id' => (int) $project['owner_id'],
    ]);

    foreach ($files as $file) {
        $storedFilename = (string) ($file['stored_filename'] ?? '');
        if ($storedFilename === '') {
            continue;
        }

        $path = get_project_upload_directory() . '/' . $storedFilename;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

function update_project_order_for_user(array $user, array $projectIds): void
{
    $userId = (int) $user['id'];
    $normalizedProjectIds = [];

    foreach ($projectIds as $projectId) {
        $projectId = (int) $projectId;
        if ($projectId <= 0 || isset($normalizedProjectIds[$projectId])) {
            continue;
        }

        $project = find_project_by_id($projectId);
        if ($project !== null && user_can_access_project($project, $user)) {
            $normalizedProjectIds[$projectId] = $projectId;
        }
    }

    if ($normalizedProjectIds === []) {
        return;
    }

    $pdo = get_db_connection();
    $pdo->beginTransaction();

    try {
        $statement = $pdo->prepare(
            'INSERT OR REPLACE INTO project_orders (user_id, project_id, sort_order, updated_at)
             VALUES (:user_id, :project_id, :sort_order, CURRENT_TIMESTAMP)'
        );

        $sortOrder = 10;
        foreach (array_values($normalizedProjectIds) as $projectId) {
            $statement->execute([
                ':user_id' => $userId,
                ':project_id' => $projectId,
                ':sort_order' => $sortOrder,
            ]);
            $sortOrder += 10;
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

function user_can_access_project(array $project, ?array $user): bool
{
    if ((string) $project['visibility'] === 'public') {
        return true;
    }

    if ($user === null) {
        return false;
    }

    if ((int) $project['owner_id'] === (int) $user['id']) {
        return true;
    }

    if ((string) $project['visibility'] !== 'shared') {
        return false;
    }

    $pdo = get_db_connection();
    $statement = $pdo->prepare(
        'SELECT 1 FROM project_shares WHERE project_id = :project_id AND username = :username LIMIT 1'
    );
    $statement->execute([
        ':project_id' => (int) $project['id'],
        ':username' => (string) $user['username'],
    ]);

    if ($statement->fetchColumn() !== false) {
        return true;
    }

    $statement = $pdo->prepare(
        'SELECT 1
         FROM project_class_shares pcs
         INNER JOIN school_class_members scm ON scm.class_id = pcs.class_id
         WHERE pcs.project_id = :project_id AND scm.user_id = :user_id
         LIMIT 1'
    );
    $statement->execute([
        ':project_id' => (int) $project['id'],
        ':user_id' => (int) $user['id'],
    ]);

    return $statement->fetchColumn() !== false;
}

function user_can_edit_project(array $project, ?array $user): bool
{
    if ($user === null || !user_can_code($user) || !is_editor_project_type((string) $project['type'])) {
        return false;
    }

    if ((int) $project['owner_id'] === (int) $user['id']) {
        return true;
    }

    if ((string) $project['visibility'] === 'public') {
        return normalize_project_permission((string) ($project['public_permission'] ?? 'read')) === 'write';
    }

    if ((string) $project['visibility'] !== 'shared') {
        return false;
    }

    $pdo = get_db_connection();
    $statement = $pdo->prepare(
        'SELECT permission FROM project_shares WHERE project_id = :project_id AND username = :username LIMIT 1'
    );
    $statement->execute([
        ':project_id' => (int) $project['id'],
        ':username' => (string) $user['username'],
    ]);

    if (normalize_project_permission((string) ($statement->fetchColumn() ?: 'read')) === 'write') {
        return true;
    }

    $statement = $pdo->prepare(
        'SELECT pcs.permission
         FROM project_class_shares pcs
         INNER JOIN school_class_members scm ON scm.class_id = pcs.class_id
         WHERE pcs.project_id = :project_id AND scm.user_id = :user_id
         LIMIT 1'
    );
    $statement->execute([
        ':project_id' => (int) $project['id'],
        ':user_id' => (int) $user['id'],
    ]);

    return normalize_project_permission((string) ($statement->fetchColumn() ?: 'read')) === 'write';
}

function require_project_editor_access(int $projectId, array $user): array
{
    require_coding_user($user);
    $project = require_project_access($projectId);
    if (!is_editor_project_type((string) $project['type'])) {
        json_response([
            'success' => false,
            'message' => 'Nur Webpage-, Java- und C-Projekte können im Editor geöffnet werden.',
        ], 400);
    }

    return $project;
}

function require_project_edit_access(int $projectId, array $user): array
{
    $project = require_project_editor_access($projectId, $user);
    if (!user_can_edit_project($project, $user)) {
        json_response([
            'success' => false,
            'message' => 'Dieses Projekt ist für dich read-only.',
        ], 403);
    }

    return $project;
}

function require_project_access(int $projectId): array
{
    $project = find_project_by_id($projectId);
    if ($project === null) {
        json_response([
            'success' => false,
            'message' => 'Projekt wurde nicht gefunden.',
        ], 404);
    }

    $user = current_user_record();
    if (!user_can_access_project($project, $user)) {
        json_response([
            'success' => false,
            'message' => $user === null ? 'Bitte melde dich an, um dieses Projekt zu öffnen.' : 'Du hast keinen Zugriff auf dieses Projekt.',
        ], $user === null ? 401 : 403);
    }

    return $project;
}

function public_project(array $project, ?array $viewer = null): array
{
    $id = (int) $project['id'];
    $type = (string) $project['type'];
    $isOwner = $viewer !== null && (int) $viewer['id'] === (int) $project['owner_id'];
    $fileCount = count_project_files($project);

    return [
        'id' => $id,
        'title' => (string) $project['title'],
        'type' => $type,
        'visibility' => (string) $project['visibility'],
        'publicPermission' => normalize_project_permission((string) ($project['public_permission'] ?? 'read')),
        'siteRefreshMode' => normalize_site_refresh_mode((string) ($project['site_refresh_mode'] ?? 'manual')),
        'siteRefreshSeconds' => normalize_site_refresh_seconds($project['site_refresh_seconds'] ?? 5),
        'runtimeConfig' => project_runtime_config($project),
        'uploadKind' => (string) ($project['upload_kind'] ?? 'single'),
        'ownerId' => (int) $project['owner_id'],
        'ownerUsername' => (string) $project['owner_username'],
        'isOwner' => $isOwner,
        'originalFilename' => $project['original_filename'] !== null ? (string) $project['original_filename'] : null,
        'mimeType' => $project['mime_type'] !== null ? (string) $project['mime_type'] : null,
        'fileSize' => $project['file_size'] !== null ? (int) $project['file_size'] : null,
        'fileCount' => $fileCount,
        'files' => public_project_files($project),
        'folders' => public_project_folders($project),
        'shares' => $isOwner ? get_project_shares($id) : [],
        'sharePermissions' => $isOwner ? get_project_share_permissions($id) : [],
        'canEdit' => user_can_edit_project($project, $viewer),
        'sortOrder' => isset($project['viewer_sort_order']) ? (int) $project['viewer_sort_order'] : null,
        'createdAt' => (string) $project['created_at'],
        'viewUrl' => $type === 'webpage' ? '/site.php/' . $id . '/' : 'view_project.php?id=' . $id,
        'downloadUrl' => 'download_project.php?id=' . $id,
    ];
}

function public_project_files(array $project): array
{
    $id = (int) $project['id'];
    $type = (string) $project['type'];
    $files = get_project_files($project);
    $rootPrefix = $type === 'webpage' ? project_webpage_root_prefix($files) : '';
    $publicFiles = [];

    foreach ($files as $file) {
        $relativePath = (string) ($file['relative_path'] ?? $file['original_filename'] ?? '');
        if ($relativePath === '') {
            continue;
        }

        $openPath = $type === 'webpage' ? project_webpage_public_path($relativePath, $rootPrefix) : $relativePath;
        $fileId = (int) ($file['id'] ?? 0);
        $openUrl = $type === 'webpage'
            ? '/site.php/' . $id . '/' . project_url_path_encode($openPath === '' ? 'index.html' : $openPath)
            : 'download_project.php?id=' . $id . '&file_id=' . $fileId;

        $publicFiles[] = [
            'id' => $fileId,
            'path' => $relativePath,
            'openPath' => $openPath,
            'openUrl' => $openUrl,
            'mimeType' => (string) ($file['mime_type'] ?? 'application/octet-stream'),
            'fileSize' => (int) ($file['file_size'] ?? 0),
        ];
    }

    return $publicFiles;
}

function public_project_folders(array $project): array
{
    $type = (string) $project['type'];
    $folders = get_project_folders($project);

    if ($type !== 'webpage') {
        return $folders;
    }

    $rootPrefix = project_webpage_root_prefix(get_project_files($project));
    if ($rootPrefix === '') {
        return $folders;
    }

    $publicFolders = [];
    $rootPrefixLower = strtolower($rootPrefix);
    foreach ($folders as $folderPath) {
        if (strtolower($folderPath) === $rootPrefixLower) {
            continue;
        }

        $publicPath = normalize_project_folder_path(project_webpage_public_path($folderPath, $rootPrefix));
        if ($publicPath !== '') {
            $publicFolders[$publicPath] = $publicPath;
        }
    }

    ksort($publicFolders, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values($publicFolders);
}

function project_webpage_root_prefix(array $files): string
{
    foreach ($files as $file) {
        $relativePath = strtolower((string) ($file['relative_path'] ?? ''));
        if ($relativePath === 'index.html') {
            return '';
        }

        if (substr($relativePath, -11) === '/index.html') {
            return substr($relativePath, 0, -11);
        }
    }

    return '';
}

function project_webpage_public_path(string $relativePath, string $rootPrefix): string
{
    $relativePath = str_replace('\\', '/', $relativePath);
    $rootPrefix = trim(str_replace('\\', '/', $rootPrefix), '/');

    if ($rootPrefix === '') {
        return $relativePath;
    }

    $prefix = strtolower($rootPrefix) . '/';
    $lowerPath = strtolower($relativePath);
    if (strncmp($lowerPath, $prefix, strlen($prefix)) === 0) {
        return substr($relativePath, strlen($prefix));
    }

    return $relativePath;
}

function project_url_path_encode(string $path): string
{
    $parts = array_filter(explode('/', str_replace('\\', '/', $path)), static fn (string $part): bool => $part !== '');
    return implode('/', array_map('rawurlencode', $parts));
}

function count_project_files(array $project): int
{
    $pdo = get_db_connection();
    $statement = $pdo->prepare('SELECT COUNT(*) AS file_count FROM project_files WHERE project_id = :project_id');
    $statement->execute([':project_id' => (int) $project['id']]);
    $row = $statement->fetch();
    $count = is_array($row) ? (int) ($row['file_count'] ?? 0) : 0;

    if ($count === 0 && !empty($project['stored_filename'])) {
        return 1;
    }

    return $count;
}

function get_project_files(array $project): array
{
    $pdo = get_db_connection();
    $statement = $pdo->prepare(
        'SELECT id, project_id, stored_filename, original_filename, relative_path, mime_type, file_size
         FROM project_files
         WHERE project_id = :project_id
         ORDER BY relative_path ASC, id ASC'
    );
    $statement->execute([':project_id' => (int) $project['id']]);
    $files = $statement->fetchAll();

    if ($files === [] && !empty($project['stored_filename'])) {
        return [[
            'id' => 0,
            'project_id' => (int) $project['id'],
            'stored_filename' => (string) $project['stored_filename'],
            'original_filename' => (string) ($project['original_filename'] ?? 'download.bin'),
            'relative_path' => (string) ($project['original_filename'] ?? 'download.bin'),
            'mime_type' => (string) ($project['mime_type'] ?? 'application/octet-stream'),
            'file_size' => (int) ($project['file_size'] ?? 0),
        ]];
    }

    return $files;
}

function find_project_file(array $project, int $fileId): ?array
{
    if ($fileId === 0) {
        $files = get_project_files($project);
        return $files[0] ?? null;
    }

    $pdo = get_db_connection();
    $statement = $pdo->prepare(
        'SELECT id, project_id, stored_filename, original_filename, relative_path, mime_type, file_size
         FROM project_files
         WHERE id = :id AND project_id = :project_id
         LIMIT 1'
    );
    $statement->execute([
        ':id' => $fileId,
        ':project_id' => (int) $project['id'],
    ]);
    $file = $statement->fetch();

    return is_array($file) ? $file : null;
}

function project_file_storage_path(array $file): string
{
    $storedFilename = (string) ($file['stored_filename'] ?? '');
    if ($storedFilename === '') {
        throw new RuntimeException('Projektdatei wurde nicht gefunden.');
    }

    $uploadDirectory = realpath(get_project_upload_directory());
    $filePath = realpath(get_project_upload_directory() . '/' . $storedFilename);

    if ($uploadDirectory === false || $filePath === false || strncmp($filePath, $uploadDirectory . DIRECTORY_SEPARATOR, strlen($uploadDirectory) + 1) !== 0) {
        throw new RuntimeException('Projektdatei wurde nicht gefunden.');
    }

    if (!is_file($filePath) || !is_readable($filePath)) {
        throw new RuntimeException('Projektdatei ist nicht lesbar.');
    }

    return $filePath;
}

function project_mime_type_for_path(string $path, ?string $diskPath = null): string
{
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $map = [
        'html' => 'text/html; charset=utf-8',
        'htm' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'svg' => 'image/svg+xml',
        'xml' => 'application/xml; charset=utf-8',
        'md' => 'text/markdown; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];

    if (isset($map[$extension])) {
        return $map[$extension];
    }

    if ($diskPath !== null && is_file($diskPath) && function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $detectedMime = finfo_file($finfo, $diskPath);
            finfo_close($finfo);
            if (is_string($detectedMime) && $detectedMime !== '') {
                return $detectedMime;
            }
        }
    }

    return 'application/octet-stream';
}

function smb_safe_relative_path(string $path, bool $isFile = true): string
{
    $path = str_replace('\\', '/', $path);
    $parts = [];

    foreach (explode('/', $path) as $part) {
        $part = trim($part);
        if ($part === '' || $part === '.' || $part === '..') {
            continue;
        }

        $part = preg_replace('/[^a-zA-Z0-9._ -]/', '_', $part) ?: ($isFile ? 'datei' : 'ordner');
        $part = trim($part, '. ');
        if ($part !== '') {
            $parts[] = $part;
        }
    }

    if ($parts === []) {
        return $isFile ? 'index.html' : '';
    }

    return implode('/', $parts);
}

function smb_filesystem_path(string $baseDirectory, string $relativePath): string
{
    $relativePath = smb_safe_relative_path($relativePath);
    return rtrim($baseDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
}

function smb_safe_project_title(string $title): string
{
    $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
    $cleanTitle = preg_replace('/[\/\\\\:*?"<>|\x00-\x1F]/u', '_', $title);
    if (!is_string($cleanTitle)) {
        $cleanTitle = preg_replace('/[^a-zA-Z0-9._ -]/', '_', $title) ?: '';
    }

    $cleanTitle = trim($cleanTitle, ". \t\n\r\0\x0B");
    if ($cleanTitle === '') {
        return '';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($cleanTitle) > 80) {
            return trim(mb_substr($cleanTitle, 0, 80), ". \t\n\r\0\x0B");
        }
        return $cleanTitle;
    }

    if (strlen($cleanTitle) > 80) {
        return trim(substr($cleanTitle, 0, 80), ". \t\n\r\0\x0B");
    }

    return $cleanTitle;
}

function smb_project_base_name(array $project): string
{
    return 'project-' . (int) $project['id'];
}

function smb_project_folder_name(array $project): string
{
    $baseName = smb_project_base_name($project);
    $title = smb_safe_project_title((string) ($project['title'] ?? ''));

    return $title !== '' ? $baseName . ' (' . $title . ')' : $baseName;
}

function smb_project_directory(array $project): string
{
    return rtrim(get_smb_workspace_directory(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . smb_project_folder_name($project);
}

function smb_legacy_project_directory(array $project): string
{
    return rtrim(get_smb_workspace_directory(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . smb_project_base_name($project);
}

function smb_project_lock_path(array $project): string
{
    return rtrim(get_smb_workspace_directory(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.project-' . (int) $project['id'] . '.lock';
}

function smb_write_denied_htaccess(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $htaccess = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\nDeny from all\nOptions -Indexes\n");
    }
}

function ensure_smb_workspace_directory(): void
{
    $directory = get_smb_workspace_directory();

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('SMB-Workspace konnte nicht erstellt werden.');
    }

    if (!is_writable($directory)) {
        throw new RuntimeException('SMB-Workspace ist nicht beschreibbar.');
    }

    smb_write_denied_htaccess($directory);

    $projectSmbDirectory = project_root() . DIRECTORY_SEPARATOR . 'smb';
    if (strncmp($directory, $projectSmbDirectory . DIRECTORY_SEPARATOR, strlen($projectSmbDirectory) + 1) === 0 || $directory === $projectSmbDirectory) {
        smb_write_denied_htaccess($projectSmbDirectory);
    }
}

function smb_project_metadata_path_for_directory(string $directory): string
{
    return rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.liveserver-smb.json';
}

function smb_project_metadata_path(array $project): string
{
    return smb_project_metadata_path_for_directory(smb_project_directory($project));
}

function smb_project_directory_metadata_matches(string $directory, int $projectId): bool
{
    $metadataPath = smb_project_metadata_path_for_directory($directory);
    if (!is_file($metadataPath)) {
        return false;
    }

    $metadata = json_decode((string) file_get_contents($metadataPath), true);
    return is_array($metadata) && (int) ($metadata['projectId'] ?? 0) === $projectId;
}

function smb_matching_project_directories(array $project): array
{
    $workspaceDirectory = get_smb_workspace_directory();
    if (!is_dir($workspaceDirectory)) {
        return [];
    }

    $projectId = (int) $project['id'];
    $baseName = smb_project_base_name($project);
    $prefix = $baseName . ' (';
    $directories = [];

    foreach (scandir($workspaceDirectory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = rtrim($workspaceDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $entry;
        if (!is_dir($path)) {
            continue;
        }

        if ($entry === smb_project_folder_name($project) || $entry === $baseName) {
            $directories[$path] = $path;
            continue;
        }

        if (strncmp($entry, $prefix, strlen($prefix)) === 0 && substr($entry, -1) === ')') {
            if (smb_project_directory_metadata_matches($path, $projectId)) {
                $directories[$path] = $path;
            }
        }
    }

    return array_values($directories);
}

function smb_find_existing_project_directory(array $project): ?string
{
    $desiredDirectory = smb_project_directory($project);
    if (is_dir($desiredDirectory)) {
        return $desiredDirectory;
    }

    $legacyDirectory = smb_legacy_project_directory($project);
    if (is_dir($legacyDirectory)) {
        return $legacyDirectory;
    }

    foreach (smb_matching_project_directories($project) as $directory) {
        return $directory;
    }

    return null;
}

function smb_prepare_project_directory(array $project, bool $create): string
{
    $desiredDirectory = smb_project_directory($project);
    $existingDirectory = smb_find_existing_project_directory($project);

    if ($existingDirectory !== null && $existingDirectory !== $desiredDirectory && !is_dir($desiredDirectory)) {
        if (!rename($existingDirectory, $desiredDirectory)) {
            throw new RuntimeException('SMB-Projektordner konnte nicht umbenannt werden.');
        }
        @chmod($desiredDirectory, 0775);
    }

    if ($create && !is_dir($desiredDirectory) && !mkdir($desiredDirectory, 0775, true) && !is_dir($desiredDirectory)) {
        throw new RuntimeException('SMB-Projektordner konnte nicht erstellt werden.');
    }

    return $desiredDirectory;
}

function smb_project_has_workspace(array $project): bool
{
    $projectDirectory = smb_find_existing_project_directory($project);
    return $projectDirectory !== null && is_file(smb_project_metadata_path_for_directory($projectDirectory));
}

function smb_with_project_lock(array $project, callable $callback)
{
    ensure_smb_workspace_directory();
    $lockPath = smb_project_lock_path($project);
    $lockHandle = fopen($lockPath, 'c');

    if ($lockHandle === false) {
        throw new RuntimeException('SMB-Sync-Lock konnte nicht erstellt werden.');
    }
    @chmod($lockPath, 0600);

    try {
        if (!flock($lockHandle, LOCK_EX)) {
            throw new RuntimeException('SMB-Sync-Lock konnte nicht gesetzt werden.');
        }

        return $callback();
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

function smb_delete_directory_contents(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $items = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);
    foreach ($items as $item) {
        $path = $item->getPathname();

        if ($item->isLink() || $item->isFile()) {
            @unlink($path);
            continue;
        }

        if ($item->isDir()) {
            smb_delete_directory_tree($path);
        }
    }
}

function smb_delete_directory_tree(string $directory): void
{
    smb_delete_directory_contents($directory);
    if (is_dir($directory)) {
        @rmdir($directory);
    }
}

function smb_write_project_metadata(array $project, string $lastAction): void
{
    $projectDirectory = smb_prepare_project_directory($project, true);
    $metadata = [
        'version' => 1,
        'projectId' => (int) $project['id'],
        'title' => (string) $project['title'],
        'folderName' => smb_project_folder_name($project),
        'lastAction' => $lastAction,
        'updatedAt' => date('c'),
        'note' => 'Dieser Ordner wird automatisch mit Liveserver synchronisiert.',
    ];

    @file_put_contents(
        smb_project_metadata_path_for_directory($projectDirectory),
        json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function smb_acl_state_path(string $projectDirectory): string
{
    return rtrim($projectDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.liveserver-smb-acl.json';
}

function smb_system_user_exists(string $username): bool
{
    if (!is_smb_username_supported($username)) {
        return false;
    }

    if (function_exists('posix_getpwnam')) {
        return is_array(posix_getpwnam($username));
    }

    $process = proc_open(['id', '-u', $username], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        return false;
    }

    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return proc_close($process) === 0;
}

function smb_editable_acl_entries(array $project): array
{
    $entries = [];
    $ownerUsername = (string) ($project['owner_username'] ?? '');
    if (smb_system_user_exists($ownerUsername)) {
        $entries[$ownerUsername] = 'write';
    }

    if ((string) ($project['visibility'] ?? '') === 'shared') {
        foreach (get_project_user_share_permissions((int) $project['id']) as $username => $permission) {
            $username = (string) $username;
            if (normalize_project_permission((string) $permission) === 'write' && smb_system_user_exists($username)) {
                $entries[$username] = 'write';
            }
        }

        foreach (get_project_class_member_permissions((int) $project['id']) as $username => $permission) {
            $username = (string) $username;
            if (normalize_project_permission((string) $permission) === 'write' && smb_system_user_exists($username)) {
                $entries[$username] = 'write';
            }
        }
    }

    ksort($entries, SORT_NATURAL | SORT_FLAG_CASE);
    return $entries;
}

function smb_editable_group_permission(array $project): string
{
    if ((string) ($project['visibility'] ?? '') !== 'public') {
        return 'none';
    }

    return normalize_project_permission((string) ($project['public_permission'] ?? 'read')) === 'write' ? 'write' : 'none';
}

function smb_project_acl_state(array $project, string $projectDirectory): array
{
    return [
        'version' => 2,
        'projectId' => (int) $project['id'],
        'directory' => basename($projectDirectory),
        'entries' => smb_editable_acl_entries($project),
        'groupPermission' => smb_editable_group_permission($project),
    ];
}

function smb_acl_state_is_current(string $projectDirectory, array $state): bool
{
    $statePath = smb_acl_state_path($projectDirectory);
    if (!is_file($statePath)) {
        return false;
    }

    $existingState = json_decode((string) file_get_contents($statePath), true);
    return is_array($existingState) && $existingState == $state;
}

function smb_write_acl_state(string $projectDirectory, array $state): void
{
    @file_put_contents(
        smb_acl_state_path($projectDirectory),
        json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function smb_apply_project_access_control(array $project, bool $force = false): void
{
    if (!is_smb_sync_enabled() || (string) ($project['type'] ?? '') !== 'webpage') {
        return;
    }

    $helperPath = get_smb_acl_helper_path();
    if (!is_file($helperPath) || !is_executable($helperPath)) {
        return;
    }

    $projectDirectory = smb_find_existing_project_directory($project);
    if ($projectDirectory === null) {
        return;
    }

    $state = smb_project_acl_state($project, $projectDirectory);
    if (!$force && smb_acl_state_is_current($projectDirectory, $state)) {
        return;
    }

    $payload = [
        'directory' => $projectDirectory,
        'entries' => $state['entries'],
        'groupPermission' => $state['groupPermission'],
    ];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open(['sudo', '-n', $helperPath], $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Samba-ACL-Helfer konnte nicht gestartet werden.');
    }

    fwrite($pipes[0], json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        $detail = trim((string) ($stderr !== '' ? $stderr : $stdout));
        throw new RuntimeException('Samba-Projektberechtigungen konnten nicht gesetzt werden.' . ($detail !== '' ? ' ' . $detail : ''));
    }

    smb_write_acl_state($projectDirectory, $state);
}

function smb_apply_editable_project_permissions_for_username(string $username): void
{
    if (!is_smb_sync_enabled() || !is_smb_username_supported($username)) {
        return;
    }

    $pdo = get_db_connection();
    $statement = $pdo->prepare(
        "SELECT DISTINCT p.*, u.username AS owner_username
         FROM projects p
         INNER JOIN users u ON u.id = p.owner_id
         LEFT JOIN project_shares s ON s.project_id = p.id
         LEFT JOIN project_class_shares pcs ON pcs.project_id = p.id
         LEFT JOIN school_class_members scm ON scm.class_id = pcs.class_id
         WHERE p.type = 'webpage'
           AND (
               u.username = :username
               OR (p.visibility = 'shared' AND s.username = :username AND s.permission = 'write')
               OR (p.visibility = 'shared' AND scm.user_id = (SELECT id FROM users WHERE username = :username LIMIT 1) AND pcs.permission = 'write')
               OR (p.visibility = 'public' AND p.public_permission = 'write')
           )
         ORDER BY p.id ASC"
    );
    $statement->execute([':username' => $username]);

    foreach ($statement->fetchAll() as $project) {
        try {
            smb_apply_project_access_control($project, true);
        } catch (Throwable $exception) {
            log_api_exception($exception);
        }
    }
}

function smb_should_ignore_name(string $name): bool
{
    $lower = strtolower($name);
    return $name === ''
        || $name[0] === '.'
        || in_array($lower, ['thumbs.db', 'desktop.ini', '__macosx'], true);
}

function smb_collect_workspace_tree(string $baseDirectory, string $currentDirectory, array &$files, array &$folders): void
{
    $entries = scandir($currentDirectory);
    if ($entries === false) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || smb_should_ignore_name($entry)) {
            continue;
        }

        $path = $currentDirectory . DIRECTORY_SEPARATOR . $entry;
        if (is_link($path)) {
            continue;
        }

        $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen(rtrim($baseDirectory, DIRECTORY_SEPARATOR)) + 1));
        if (is_dir($path)) {
            $folderPath = smb_safe_relative_path($relativePath, false);
            if ($folderPath !== '') {
                $folders[$folderPath] = $folderPath;
            }
            smb_collect_workspace_tree($baseDirectory, $path, $files, $folders);
            continue;
        }

        if (is_file($path) && is_readable($path)) {
            $filePath = smb_safe_relative_path($relativePath);
            if ($filePath !== '') {
                $files[$filePath] = $path;
            }
        }
    }
}

function smb_workspace_files_and_folders(array $project): array
{
    $projectDirectory = smb_prepare_project_directory($project, false);
    $files = [];
    $folders = [];

    if (is_dir($projectDirectory)) {
        smb_collect_workspace_tree($projectDirectory, $projectDirectory, $files, $folders);
    }

    ksort($files, SORT_NATURAL | SORT_FLAG_CASE);
    ksort($folders, SORT_NATURAL | SORT_FLAG_CASE);

    return [
        'files' => $files,
        'folders' => array_values($folders),
    ];
}

function smb_existing_file_map(array $project): array
{
    $files = get_project_files($project);
    $rootPrefix = project_webpage_root_prefix($files);
    $map = [];

    foreach ($files as $file) {
        $relativePath = (string) ($file['relative_path'] ?? $file['original_filename'] ?? '');
        if ($relativePath === '') {
            continue;
        }

        $publicPath = smb_safe_relative_path(project_webpage_public_path($relativePath, $rootPrefix));
        $map[strtolower($publicPath)] = $file;
    }

    return $map;
}

function smb_file_content_hash(string $path): string
{
    return is_file($path) ? (string) hash_file('sha256', $path) : '';
}

function smb_export_project_to_workspace(array $project): void
{
    if (!is_smb_auto_export_enabled() || (string) $project['type'] !== 'webpage') {
        return;
    }

    smb_with_project_lock($project, function () use ($project): void {
        $projectDirectory = smb_prepare_project_directory($project, true);

        smb_delete_directory_contents($projectDirectory);

        $files = get_project_files($project);
        $rootPrefix = project_webpage_root_prefix($files);

        foreach (public_project_folders($project) as $folderPath) {
            $targetFolder = rtrim($projectDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, smb_safe_relative_path((string) $folderPath, false));
            if (!is_dir($targetFolder) && !mkdir($targetFolder, 0775, true) && !is_dir($targetFolder)) {
                throw new RuntimeException('SMB-Projektordner konnte nicht erstellt werden.');
            }
        }

        foreach ($files as $file) {
            $relativePath = smb_safe_relative_path(project_webpage_public_path((string) ($file['relative_path'] ?? ''), $rootPrefix));
            $sourcePath = project_file_storage_path($file);
            $targetPath = smb_filesystem_path($projectDirectory, $relativePath);
            $targetDirectory = dirname($targetPath);

            if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
                throw new RuntimeException('SMB-Projektunterordner konnte nicht erstellt werden.');
            }

            if (!copy($sourcePath, $targetPath)) {
                throw new RuntimeException('SMB-Projektdatei konnte nicht geschrieben werden.');
            }

            @chmod($targetPath, 0664);
        }

        smb_write_project_metadata($project, 'export');
        smb_apply_project_access_control($project, true);
    });
}

function smb_import_project_from_workspace(array $project): array
{
    if (!is_smb_auto_import_enabled() || (string) $project['type'] !== 'webpage') {
        return $project;
    }

    if (!smb_project_has_workspace($project)) {
        smb_export_project_to_workspace($project);
        return find_project_by_id((int) $project['id']) ?: $project;
    }

    return smb_with_project_lock($project, function () use ($project): array {
        $tree = smb_workspace_files_and_folders($project);
        $workspaceFiles = $tree['files'];
        $workspaceFolders = $tree['folders'];
        $existingFiles = smb_existing_file_map($project);
        $seenFileIds = [];
        $createdUploadPaths = [];
        $finalFileInfos = [];
        $totalSize = 0;
        $changed = false;

        ensure_project_upload_directory();
        $pdo = get_db_connection();
        $pdo->beginTransaction();

        try {
            foreach ($workspaceFiles as $relativePath => $sourcePath) {
                $relativePath = smb_safe_relative_path($relativePath);
                $fileSize = (int) filesize($sourcePath);
                $totalSize += $fileSize;

                if ($totalSize > get_max_upload_bytes()) {
                    throw new RuntimeException('SMB-Projekt ist insgesamt zu groß.');
                }

                $mimeType = project_mime_type_for_path($relativePath, $sourcePath);
                $key = strtolower($relativePath);
                $existingFile = $existingFiles[$key] ?? null;

                if ($existingFile !== null) {
                    $targetPath = project_file_storage_path($existingFile);
                    $sourceHash = smb_file_content_hash($sourcePath);
                    $targetHash = smb_file_content_hash($targetPath);
                    $fileId = (int) $existingFile['id'];
                    $seenFileIds[$fileId] = true;

                    if ($sourceHash !== $targetHash) {
                        if (!copy($sourcePath, $targetPath)) {
                            throw new RuntimeException('SMB-Datei konnte nicht importiert werden.');
                        }
                        @chmod($targetPath, 0664);
                        $changed = true;
                    }

                    $needsMetadataUpdate = (string) ($existingFile['relative_path'] ?? '') !== $relativePath
                        || (string) ($existingFile['original_filename'] ?? '') !== basename($relativePath)
                        || (string) ($existingFile['mime_type'] ?? '') !== $mimeType
                        || (int) ($existingFile['file_size'] ?? 0) !== $fileSize;

                    if ($needsMetadataUpdate) {
                        $statement = $pdo->prepare(
                            'UPDATE project_files
                             SET original_filename = :original_filename,
                                 relative_path = :relative_path,
                                 mime_type = :mime_type,
                                 file_size = :file_size
                             WHERE id = :id'
                        );
                        $statement->execute([
                            ':original_filename' => basename($relativePath),
                            ':relative_path' => $relativePath,
                            ':mime_type' => $mimeType,
                            ':file_size' => $fileSize,
                            ':id' => $fileId,
                        ]);
                        $changed = true;
                    }
                } else {
                    $extension = pathinfo($relativePath, PATHINFO_EXTENSION);
                    $storedFilename = bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . strtolower($extension) : '.bin');
                    $targetPath = get_project_upload_directory() . '/' . $storedFilename;

                    if (!copy($sourcePath, $targetPath)) {
                        throw new RuntimeException('Neue SMB-Datei konnte nicht importiert werden.');
                    }

                    @chmod($targetPath, 0664);
                    $createdUploadPaths[] = $targetPath;

                    $statement = $pdo->prepare(
                        'INSERT INTO project_files (project_id, stored_filename, original_filename, relative_path, mime_type, file_size)
                         VALUES (:project_id, :stored_filename, :original_filename, :relative_path, :mime_type, :file_size)'
                    );
                    $statement->execute([
                        ':project_id' => (int) $project['id'],
                        ':stored_filename' => $storedFilename,
                        ':original_filename' => basename($relativePath),
                        ':relative_path' => $relativePath,
                        ':mime_type' => $mimeType,
                        ':file_size' => $fileSize,
                    ]);
                    $seenFileIds[(int) $pdo->lastInsertId()] = true;
                    $changed = true;
                }

                $finalFileInfos[] = [
                    'relative_path' => $relativePath,
                    'file_size' => $fileSize,
                    'mime_type' => $mimeType,
                    'original_filename' => basename($relativePath),
                ];
            }

            foreach (get_project_files($project) as $file) {
                $fileId = (int) ($file['id'] ?? 0);
                if ($fileId <= 0 || isset($seenFileIds[$fileId])) {
                    continue;
                }

                try {
                    $filePath = project_file_storage_path($file);
                    @unlink($filePath);
                } catch (Throwable $exception) {
                    log_api_exception($exception);
                }

                $statement = $pdo->prepare('DELETE FROM project_files WHERE id = :id AND project_id = :project_id');
                $statement->execute([
                    ':id' => $fileId,
                    ':project_id' => (int) $project['id'],
                ]);
                $changed = true;
            }

            $nextFolders = collect_project_folder_paths($finalFileInfos, $workspaceFolders);
            $currentFolders = get_project_folders($project);
            sort($nextFolders);
            sort($currentFolders);
            if ($nextFolders !== $currentFolders) {
                replace_project_folders($pdo, (int) $project['id'], $nextFolders);
                $changed = true;
            }

            if ($changed) {
                $statement = $pdo->prepare(
                    'UPDATE projects
                     SET file_size = (
                         SELECT COALESCE(SUM(file_size), 0)
                         FROM project_files
                         WHERE project_id = :project_id
                     ),
                     upload_kind = :upload_kind,
                     updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                );
                $statement->execute([
                    ':project_id' => (int) $project['id'],
                    ':upload_kind' => count($workspaceFiles) > 1 || $workspaceFolders !== [] ? 'folder' : 'single',
                    ':id' => (int) $project['id'],
                ]);
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            foreach ($createdUploadPaths as $createdUploadPath) {
                @unlink($createdUploadPath);
            }
            throw $exception;
        }

        if ($changed) {
            $freshProject = find_project_by_id((int) $project['id']) ?: $project;
            smb_write_project_metadata($freshProject, 'import');
            smb_apply_project_access_control($freshProject, true);
            return $freshProject;
        }

        $freshProject = find_project_by_id((int) $project['id']) ?: $project;
        smb_apply_project_access_control($freshProject);
        return $freshProject;
    });
}

function smb_sync_project_for_read(array $project): array
{
    if (!is_smb_sync_enabled() || (string) $project['type'] !== 'webpage') {
        return $project;
    }

    try {
        return smb_import_project_from_workspace($project);
    } catch (Throwable $exception) {
        log_api_exception($exception);
        return $project;
    }
}

function smb_sync_project_after_save(array $project): array
{
    if (!is_smb_sync_enabled() || (string) $project['type'] !== 'webpage') {
        return $project;
    }

    try {
        smb_export_project_to_workspace($project);
        return find_project_by_id((int) $project['id']) ?: $project;
    } catch (Throwable $exception) {
        log_api_exception($exception);
        return $project;
    }
}

function list_smb_syncable_projects(): array
{
    if (!is_smb_sync_enabled()) {
        return [];
    }

    $pdo = get_db_connection();
    $statement = $pdo->query(
        "SELECT p.*, u.username AS owner_username
         FROM projects p
         INNER JOIN users u ON u.id = p.owner_id
         WHERE p.type = 'webpage'
         ORDER BY p.id ASC"
    );

    return $statement !== false ? $statement->fetchAll() : [];
}

function sync_all_smb_projects(): array
{
    $projects = list_smb_syncable_projects();
    $result = [
        'checked' => 0,
        'failed' => 0,
    ];

    foreach ($projects as $project) {
        $result['checked']++;

        try {
            $syncedProject = smb_import_project_from_workspace($project);
            smb_apply_project_access_control($syncedProject, true);
        } catch (Throwable $exception) {
            $result['failed']++;
            log_api_exception($exception);
        }
    }

    return $result;
}

function smb_delete_project_workspace(array $project): void
{
    if (!is_smb_sync_enabled() || (string) $project['type'] !== 'webpage') {
        return;
    }

    try {
        smb_with_project_lock($project, function () use ($project): void {
            foreach (smb_matching_project_directories($project) as $projectDirectory) {
                smb_delete_directory_tree($projectDirectory);
            }
        });
    } catch (Throwable $exception) {
        log_api_exception($exception);
    }
}

function smb_sync_health(): array
{
    $enabled = is_smb_sync_enabled();
    $directory = get_smb_workspace_directory();
    $health = [
        'enabled' => $enabled,
        'autoImport' => is_smb_auto_import_enabled(),
        'autoExport' => is_smb_auto_export_enabled(),
        'writable' => false,
    ];

    if ($enabled) {
        try {
            ensure_smb_workspace_directory();
            $health['writable'] = is_writable($directory);
        } catch (Throwable $exception) {
            $health['error'] = $exception->getMessage();
        }
    }

    if (is_detailed_errors_enabled()) {
        $health['path'] = $directory;
    }

    return $health;
}

function runtime_health(): array
{
    $enabled = are_project_runtimes_enabled();
    $directory = get_runtime_workspace_directory();
    $health = [
        'enabled' => $enabled,
        'timeoutSeconds' => get_runtime_timeout_seconds(),
        'maxOutputBytes' => get_runtime_max_output_bytes(),
        'maxMemoryKb' => get_runtime_max_memory_kb(),
        'maxTasks' => get_runtime_max_tasks(),
        'javaMaxHeapMb' => get_runtime_java_max_heap_mb(),
        'javaMaxMemoryKb' => get_runtime_java_max_memory_kb(),
        'sandboxEnabled' => is_runtime_sandbox_enabled(),
        'writable' => false,
    ];

    if ($enabled) {
        try {
            ensure_runtime_workspace_directory();
            $health['writable'] = is_writable($directory);
        } catch (Throwable $exception) {
            $health['error'] = $exception->getMessage();
        }
    }

    if (is_detailed_errors_enabled()) {
        $health['path'] = $directory;
    }

    return $health;
}

function list_accessible_projects(array $user): array
{
    if (!user_can_code($user)) {
        return [];
    }

    $pdo = get_db_connection();
    $statement = $pdo->prepare(
        "SELECT DISTINCT p.*, u.username AS owner_username, po.sort_order AS viewer_sort_order
         FROM projects p
         INNER JOIN users u ON u.id = p.owner_id
         LEFT JOIN project_shares s ON s.project_id = p.id
         LEFT JOIN project_class_shares pcs ON pcs.project_id = p.id
         LEFT JOIN school_class_members scm ON scm.class_id = pcs.class_id AND scm.user_id = :viewer_id
         LEFT JOIN project_orders po ON po.project_id = p.id AND po.user_id = :order_user_id
         WHERE p.visibility = 'public'
            OR p.owner_id = :owner_id
            OR s.username = :username
            OR (p.visibility = 'shared' AND scm.user_id = :viewer_id)
         ORDER BY COALESCE(po.sort_order, 1000000000) ASC, p.created_at DESC, p.id DESC"
    );
    $statement->execute([
        ':viewer_id' => (int) $user['id'],
        ':order_user_id' => (int) $user['id'],
        ':owner_id' => (int) $user['id'],
        ':username' => (string) $user['username'],
    ]);

    $projects = array_map(
        static fn (array $project): array => smb_sync_project_for_read($project),
        $statement->fetchAll()
    );

    return array_map(
        static fn (array $project): array => public_project($project, $user),
        $projects
    );
}
