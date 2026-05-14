<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

try {
    start_secure_session();
    $csrfToken = ensure_csrf_token();
    $user = current_user_record();

    json_response([
        'success' => true,
        'loggedIn' => $user !== null,
        'csrfToken' => $csrfToken,
        'user' => $user !== null ? public_user($user) : null,
    ]);
} catch (Throwable $exception) {
    json_response([
        'success' => false,
        'message' => 'Session konnte nicht geprüft werden.',
    ], 500);
}
