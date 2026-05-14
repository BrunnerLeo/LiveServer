<?php
declare(strict_types=1);

class DuplicateUsernameException extends RuntimeException
{
}

function project_root(): string
{
    return dirname(__DIR__);
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
        'secure' => (bool) ($security['secureCookie'] ?? false),
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
        "CREATE TABLE IF NOT EXISTS projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            type TEXT NOT NULL CHECK (type IN ('file', 'webpage')),
            visibility TEXT NOT NULL CHECK (visibility IN ('public', 'private', 'shared')),
            public_permission TEXT NOT NULL DEFAULT 'read',
            upload_kind TEXT NOT NULL DEFAULT 'single' CHECK (upload_kind IN ('single', 'folder')),
            stored_filename TEXT,
            original_filename TEXT,
            mime_type TEXT,
            file_size INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE
        )"
    );

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_projects_owner_id ON projects (owner_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_projects_visibility ON projects (visibility)');
    ensure_sqlite_column($pdo, 'projects', 'upload_kind', "TEXT NOT NULL DEFAULT 'single'");
    ensure_sqlite_column($pdo, 'projects', 'public_permission', "TEXT NOT NULL DEFAULT 'read'");

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
    $pdo->exec("UPDATE projects SET public_permission = 'read' WHERE public_permission NOT IN ('read', 'write')");
    $pdo->exec("UPDATE project_shares SET permission = 'read' WHERE permission NOT IN ('read', 'write')");
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

function get_theme_keys(): array
{
    return ['primary', 'secondary', 'accent', 'background', 'surface', 'text'];
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
        'theme' => $theme !== [] ? $theme : new stdClass(),
    ];
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

function normalize_project_permission(string $permission): string
{
    return $permission === 'write' ? 'write' : 'read';
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
    $statement = $pdo->prepare('SELECT username FROM project_shares WHERE project_id = :project_id ORDER BY username ASC');
    $statement->execute([':project_id' => $projectId]);

    return array_map(static fn (array $row): string => (string) $row['username'], $statement->fetchAll());
}

function get_project_share_permissions(int $projectId): array
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
    string $uploadKind,
    array $fileInfos,
    array $sharedUsernames,
    string $publicPermission = 'read',
    array $sharedPermissions = [],
    array $folderPaths = []
): array {
    $pdo = get_db_connection();
    $pdo->beginTransaction();

    try {
        $statement = $pdo->prepare(
            'INSERT INTO projects (
                owner_id, title, type, visibility, public_permission, upload_kind, stored_filename, original_filename, mime_type, file_size
            ) VALUES (
                :owner_id, :title, :type, :visibility, :public_permission, :upload_kind, :stored_filename, :original_filename, :mime_type, :file_size
            )'
        );
        $firstFile = $fileInfos[0] ?? [];
        $statement->execute([
            ':owner_id' => $ownerId,
            ':title' => $title,
            ':type' => $type,
            ':visibility' => $visibility,
            ':public_permission' => normalize_project_permission($publicPermission),
            ':upload_kind' => $uploadKind,
            ':stored_filename' => $firstFile['stored_filename'] ?? null,
            ':original_filename' => $firstFile['original_filename'] ?? null,
            ':mime_type' => $firstFile['mime_type'] ?? null,
            ':file_size' => array_sum(array_map(static fn (array $file): int => (int) ($file['file_size'] ?? 0), $fileInfos)) ?: null,
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

function update_project_visibility(int $projectId, int $ownerId, string $visibility, array $sharedUsernames, string $publicPermission = 'read', array $sharedPermissions = []): array
{
    $pdo = get_db_connection();
    $pdo->beginTransaction();

    try {
        $statement = $pdo->prepare(
            'UPDATE projects
             SET visibility = :visibility, public_permission = :public_permission, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND owner_id = :owner_id'
        );
        $statement->execute([
            ':visibility' => $visibility,
            ':public_permission' => normalize_project_permission($publicPermission),
            ':id' => $projectId,
            ':owner_id' => $ownerId,
        ]);

        if ($statement->rowCount() < 1) {
            throw new RuntimeException('Projekt konnte nicht aktualisiert werden.');
        }

        $deleteShares = $pdo->prepare('DELETE FROM project_shares WHERE project_id = :project_id');
        $deleteShares->execute([':project_id' => $projectId]);

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

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    $project = find_project_by_id($projectId);
    if ($project === null) {
        throw new RuntimeException('Projekt konnte nach dem Aktualisieren nicht geladen werden.');
    }

    return $project;
}

function delete_project_record(array $project): void
{
    $files = get_project_files($project);
    $pdo = get_db_connection();

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

    return $statement->fetchColumn() !== false;
}

function user_can_edit_project(array $project, ?array $user): bool
{
    if ($user === null || (string) $project['type'] !== 'webpage') {
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

    return normalize_project_permission((string) ($statement->fetchColumn() ?: 'read')) === 'write';
}

function require_project_editor_access(int $projectId, array $user): array
{
    $project = require_project_access($projectId);
    if ((string) $project['type'] !== 'webpage') {
        json_response([
            'success' => false,
            'message' => 'Nur Webpage-Projekte können im Editor geöffnet werden.',
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
        'downloadUrl' => $type === 'file' ? 'download_project.php?id=' . $id : null,
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

function list_accessible_projects(array $user): array
{
    $pdo = get_db_connection();
    $statement = $pdo->prepare(
        "SELECT DISTINCT p.*, u.username AS owner_username, po.sort_order AS viewer_sort_order
         FROM projects p
         INNER JOIN users u ON u.id = p.owner_id
         LEFT JOIN project_shares s ON s.project_id = p.id
         LEFT JOIN project_orders po ON po.project_id = p.id AND po.user_id = :order_user_id
         WHERE p.visibility = 'public'
            OR p.owner_id = :owner_id
            OR s.username = :username
         ORDER BY COALESCE(po.sort_order, 1000000000) ASC, p.created_at DESC, p.id DESC"
    );
    $statement->execute([
        ':order_user_id' => (int) $user['id'],
        ':owner_id' => (int) $user['id'],
        ':username' => (string) $user['username'],
    ]);

    return array_map(
        static fn (array $project): array => public_project($project, $user),
        $statement->fetchAll()
    );
}
