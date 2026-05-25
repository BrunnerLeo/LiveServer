<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

try {
    start_secure_session();
    require_post_request();
    $input = get_json_input();
    require_csrf_token($input);
    $user = require_student_user();

    $class = join_school_class_by_code((int) $user['id'], (string) ($input['code'] ?? ''));

    json_response([
        'success' => true,
        'message' => 'Du bist der Klasse beigetreten.',
        'csrfToken' => ensure_csrf_token(),
        'class' => $class,
        'joinedClasses' => list_student_school_classes((int) $user['id']),
    ]);
} catch (InvalidArgumentException $exception) {
    json_response([
        'success' => false,
        'message' => $exception->getMessage(),
        'csrfToken' => ensure_csrf_token(),
    ], 400);
} catch (Throwable $exception) {
    api_exception_response($exception, 'Klassencode konnte nicht verarbeitet werden.', 500);
}
