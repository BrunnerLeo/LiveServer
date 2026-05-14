<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

try {
    start_secure_session();
    require_post_request();
    $input = get_json_input();
    require_csrf_token($input);
    $user = require_logged_in_user();

    if (!array_key_exists('theme', $input) || $input['theme'] === null) {
        $updatedUser = update_user_theme((int) $user['id'], null);
    } elseif (is_array($input['theme'])) {
        $updatedUser = update_user_theme((int) $user['id'], $input['theme']);
    } else {
        json_response([
            'success' => false,
            'message' => 'Die Farbdaten sind ungültig.',
        ], 400);
    }

    json_response([
        'success' => true,
        'message' => 'Farben gespeichert.',
        'csrfToken' => ensure_csrf_token(),
        'user' => $updatedUser !== null ? public_user($updatedUser) : public_user($user),
    ]);
} catch (InvalidArgumentException $exception) {
    json_response([
        'success' => false,
        'message' => $exception->getMessage(),
        'csrfToken' => ensure_csrf_token(),
    ], 400);
} catch (Throwable $exception) {
    api_exception_response($exception, 'Farben konnten nicht gespeichert werden.', 500);
}
