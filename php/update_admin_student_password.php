<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

try {
    start_secure_session();
    require_post_request();
    $input = get_json_input();
    require_csrf_token($input);
    require_admin_user();

    $userId = (int) ($input['userId'] ?? 0);
    $password = (string) ($input['password'] ?? '');

    if ($userId <= 0) {
        json_response([
            'success' => false,
            'message' => 'Schüler wurde nicht gefunden.',
            'csrfToken' => ensure_csrf_token(),
        ], 404);
    }

    $student = update_student_password_by_admin($userId, $password);

    json_response([
        'success' => true,
        'message' => 'Passwort wurde geändert.',
        'csrfToken' => ensure_csrf_token(),
        'student' => [
            'id' => (int) $student['id'],
            'username' => (string) $student['username'],
            'realname' => (string) ($student['realname'] ?? ''),
            'role' => (string) ($student['role'] ?? 'student'),
        ],
    ]);
} catch (InvalidArgumentException $exception) {
    json_response([
        'success' => false,
        'message' => $exception->getMessage(),
        'csrfToken' => ensure_csrf_token(),
    ], 400);
} catch (Throwable $exception) {
    api_exception_response($exception, 'Passwort konnte nicht geändert werden.', 500);
}
