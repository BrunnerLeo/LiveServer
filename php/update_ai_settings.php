<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

try {
    start_secure_session();
    require_post_request();
    $input = get_json_input();
    require_csrf_token($input);
    $user = require_logged_in_user();

    $provider = (string) ($input['provider'] ?? 'openai');
    $model = (string) ($input['model'] ?? '');
    $baseUrl = (string) ($input['baseUrl'] ?? '');
    $apiKey = array_key_exists('apiKey', $input) ? (string) $input['apiKey'] : null;
    $clearApiKey = (bool) ($input['clearApiKey'] ?? false);

    $settings = save_user_ai_settings((int) $user['id'], $provider, $model, $apiKey, $clearApiKey, $baseUrl);

    json_response([
        'success' => true,
        'message' => 'KI-Einstellungen gespeichert.',
        'csrfToken' => ensure_csrf_token(),
        'ai' => public_ai_settings($settings),
    ]);
} catch (InvalidArgumentException $exception) {
    json_response([
        'success' => false,
        'message' => $exception->getMessage(),
        'csrfToken' => ensure_csrf_token(),
    ], 400);
} catch (Throwable $exception) {
    api_exception_response($exception, 'KI-Einstellungen konnten nicht gespeichert werden.', 500);
}
