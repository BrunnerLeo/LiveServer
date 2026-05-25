<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

try {
    start_secure_session();
    require_admin_user();

    json_response([
        'success' => true,
        'csrfToken' => ensure_csrf_token(),
        'settings' => public_system_ai_settings(),
    ]);
} catch (Throwable $exception) {
    api_exception_response($exception, 'Admin-KI-Einstellungen konnten nicht geladen werden.', 500);
}
