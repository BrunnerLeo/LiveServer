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
    $role = normalize_user_role((string) ($input['role'] ?? ''));

    if ($userId <= 0 || $role !== 'teacher') {
        json_response([
            'success' => false,
            'message' => 'Ungültige Rollenänderung.',
            'csrfToken' => ensure_csrf_token(),
        ], 400);
    }

    $account = upgrade_school_account_to_teacher($userId);

    json_response([
        'success' => true,
        'message' => 'Account wurde zu Lehrer hochgestuft.',
        'csrfToken' => ensure_csrf_token(),
        'account' => [
            'id' => (int) $account['id'],
            'username' => (string) $account['username'],
            'realname' => (string) ($account['realname'] ?? ''),
            'role' => (string) ($account['role'] ?? 'teacher'),
        ],
        'accounts' => list_student_users(),
    ]);
} catch (InvalidArgumentException $exception) {
    json_response([
        'success' => false,
        'message' => $exception->getMessage(),
        'csrfToken' => ensure_csrf_token(),
    ], 400);
} catch (Throwable $exception) {
    api_exception_response($exception, 'Rolle konnte nicht geändert werden.', 500);
}
