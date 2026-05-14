<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

try {
    start_secure_session();
    require_post_request();
    $input = get_json_input();
    require_csrf_token($input);

    $settings = load_settings();
    $registration = $settings['registration'] ?? [];

    if (!((bool) ($registration['enabled'] ?? false))) {
        json_response([
            'success' => false,
            'message' => (string) ($registration['message'] ?? 'Registrierung ist aktuell deaktiviert.'),
        ], 403);
    }

    $realname = trim((string) ($input['realname'] ?? ''));
    $username = trim((string) ($input['username'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    $passwordConfirm = (string) ($input['passwordConfirm'] ?? '');
    $realnameLength = function_exists('mb_strlen') ? mb_strlen($realname) : strlen($realname);
    $passwordLength = function_exists('mb_strlen') ? mb_strlen($password) : strlen($password);

    if ($realname === '' || $realnameLength > 150) {
        json_response([
            'success' => false,
            'message' => 'Bitte gib einen gültigen Namen mit maximal 150 Zeichen ein.',
        ], 400);
    }

    if (preg_match('/^[a-zA-Z0-9._-]{3,100}$/', $username) !== 1) {
        json_response([
            'success' => false,
            'message' => 'Der Benutzername muss 3 bis 100 Zeichen lang sein und darf nur Buchstaben, Zahlen, Punkt, Unterstrich und Bindestrich enthalten.',
        ], 400);
    }

    if ($passwordLength < 8) {
        json_response([
            'success' => false,
            'message' => 'Das Passwort muss mindestens 8 Zeichen lang sein.',
        ], 400);
    }

    if (!hash_equals($password, $passwordConfirm)) {
        json_response([
            'success' => false,
            'message' => 'Die Passwörter stimmen nicht überein.',
        ], 400);
    }

    if (find_user_by_username($username) !== null) {
        json_response([
            'success' => false,
            'message' => 'Dieser Benutzername ist bereits vergeben.',
        ], 409);
    }

    $user = create_user($username, hash_plain_password($password), $realname, 'student');

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = (string) $user['username'];
    $csrfToken = rotate_csrf_token();

    json_response([
        'success' => true,
        'message' => 'Registrierung erfolgreich.',
        'csrfToken' => $csrfToken,
        'user' => public_user($user),
    ]);
} catch (DuplicateUsernameException $exception) {
    json_response([
        'success' => false,
        'message' => 'Dieser Benutzername ist bereits vergeben.',
    ], 409);
} catch (Throwable $exception) {
    api_exception_response($exception, 'Registrierung konnte nicht verarbeitet werden.', 500);
}
