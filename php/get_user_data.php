<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

try {
    start_secure_session();
    $user = require_logged_in_user();

    json_response([
        'success' => true,
        'user' => public_user($user),
        'content' => get_allowed_content_for_user($user),
    ]);
} catch (Throwable $exception) {
    json_response([
        'success' => false,
        'message' => 'Benutzerdaten konnten nicht geladen werden.',
    ], 500);
}
