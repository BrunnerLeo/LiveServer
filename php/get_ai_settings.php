<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

try {
    start_secure_session();
    $user = require_logged_in_user();
    $settings = get_user_ai_settings((int) $user['id']);

    json_response([
        'success' => true,
        'csrfToken' => ensure_csrf_token(),
        'ai' => public_ai_settings($settings),
    ]);
} catch (Throwable $exception) {
    api_exception_response($exception, 'KI-Einstellungen konnten nicht geladen werden.', 500);
}
