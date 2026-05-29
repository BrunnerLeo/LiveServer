<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

try {
    start_secure_session();
    require_post_request();
    $input = get_json_input();
    require_csrf_token($input);
    $user = require_logged_in_user();

    $realname = trim((string) ($input['realname'] ?? ''));
    $currentPassword = (string) ($input['currentPassword'] ?? '');
    $newPassword = (string) ($input['newPassword'] ?? '');

    $realnameLength = function_exists('mb_strlen') ? mb_strlen($realname) : strlen($realname);

    if ($realname === '' || $realnameLength > 150) {
        json_response([
            'success' => false,
            'message' => 'Bitte gib einen gültigen Namen mit maximal 150 Zeichen ein.',
        ], 400);
    }

    if ($newPassword !== '') {
        $newPasswordLength = function_exists('mb_strlen') ? mb_strlen($newPassword) : strlen($newPassword);

        if ($newPasswordLength < 8) {
            json_response([
                'success' => false,
                'message' => 'Das neue Passwort muss mindestens 8 Zeichen lang sein.',
            ], 400);
        }

        if (!password_verify($currentPassword, (string) $user['password_hash'])) {
            json_response([
                'success' => false,
                'message' => 'Das aktuelle Passwort ist falsch.',
            ], 401);
        }

        require_smb_supported_credentials((string) $user['username'], $newPassword);
        provision_smb_user_credentials((string) $user['username'], $newPassword);

        $updatedUser = update_user_profile((int) $user['id'], $realname, hash_plain_password($newPassword));
    } else {
        $updatedUser = update_user_profile((int) $user['id'], $realname);
    }

    json_response([
        'success' => true,
        'message' => 'Profil gespeichert.',
        'csrfToken' => ensure_csrf_token(),
        'user' => $updatedUser !== null ? public_user($updatedUser) : public_user($user),
    ]);
} catch (Throwable $exception) {
    api_exception_response($exception, 'Benutzerdaten konnten nicht gespeichert werden.', 500);
}
