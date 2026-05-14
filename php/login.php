<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

try {
    start_secure_session();
    require_post_request();
    $input = get_json_input();
    require_csrf_token($input);

    $username = trim((string) ($input['username'] ?? ''));
    $password = (string) ($input['password'] ?? '');

    if ($username === '' || $password === '') {
        json_response([
            'success' => false,
            'message' => 'Benutzername und Passwort sind erforderlich.',
        ], 400);
    }

    $user = find_user_by_username($username);
    if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
        json_response([
            'success' => false,
            'message' => 'Benutzername oder Passwort ist falsch.',
        ], 401);
    }

    update_password_hash_if_needed($user, $password);
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = (string) $user['username'];
    $csrfToken = rotate_csrf_token();

    json_response([
        'success' => true,
        'message' => 'Login erfolgreich.',
        'csrfToken' => $csrfToken,
        'user' => public_user($user),
    ]);
} catch (Throwable $exception) {
    api_exception_response($exception, 'Login konnte nicht verarbeitet werden.', 500);
}
