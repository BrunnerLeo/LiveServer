<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

try {
    start_secure_session();
    require_admin_user();

    $accounts = list_student_users();
    json_response([
        'success' => true,
        'csrfToken' => ensure_csrf_token(),
        'students' => $accounts,
        'accounts' => $accounts,
    ]);
} catch (Throwable $exception) {
    api_exception_response($exception, 'Accountliste konnte nicht geladen werden.', 500);
}
