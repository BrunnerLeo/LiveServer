<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

try {
    start_secure_session();
    $settings = load_settings();
    ensure_project_upload_directory();
    $uploadDirectory = get_project_upload_directory();
    $uploadHealth = [
        'writable' => is_writable($uploadDirectory),
    ];

    if (is_detailed_errors_enabled()) {
        $uploadHealth['path'] = $uploadDirectory;
    }

    json_response([
        'success' => true,
        'message' => 'PHP-API ist erreichbar.',
        'phpVersion' => PHP_VERSION,
        'pdoLoaded' => extension_loaded('pdo'),
        'sqliteLoaded' => extension_loaded('pdo_sqlite') || extension_loaded('sqlite3'),
        'zipLoaded' => class_exists('ZipArchive'),
        'curlLoaded' => function_exists('curl_init'),
        'database' => database_health(),
        'uploads' => $uploadHealth,
        'smb' => smb_sync_health(),
        'runtimes' => runtime_health(),
        'ai' => [
            'enabled' => true,
            'curlLoaded' => function_exists('curl_init'),
            'providers' => array_keys(get_ai_provider_models()),
            'localCoder' => [
                'enabled' => get_ai_local_coder_settings()['enabled'],
                'model' => get_ai_local_coder_settings()['model'],
            ],
        ],
        'schoolName' => (string) (($settings['school'] ?? [])['name'] ?? ''),
        'csrfToken' => ensure_csrf_token(),
    ]);
} catch (Throwable $exception) {
    api_exception_response($exception, 'Health-Check konnte nicht ausgeführt werden.', 500);
}
