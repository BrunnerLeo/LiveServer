<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

try {
    start_secure_session();
    $user = require_logged_in_user();

    json_response([
        'success' => true,
        'projects' => list_accessible_projects($user),
        'csrfToken' => ensure_csrf_token(),
    ]);
} catch (Throwable $exception) {
    api_exception_response($exception, 'Projekte konnten nicht geladen werden.', 500);
}
