<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

try {
    start_secure_session();
    require_post_request();
    $input = get_json_input();
    require_csrf_token($input);
    require_admin_user();

    $enabled = (bool) ($input['enabled'] ?? false);
    $arisContext = (string) ($input['arisContext'] ?? '');
    $settings = save_system_ai_settings($enabled, $arisContext);

    json_response([
        'success' => true,
        'message' => 'Admin-KI-Einstellungen gespeichert.',
        'csrfToken' => ensure_csrf_token(),
        'settings' => $settings,
    ]);
} catch (InvalidArgumentException $exception) {
    json_response([
        'success' => false,
        'message' => $exception->getMessage(),
        'csrfToken' => ensure_csrf_token(),
    ], 400);
} catch (Throwable $exception) {
    api_exception_response($exception, 'Admin-KI-Einstellungen konnten nicht gespeichert werden.', 500);
}
