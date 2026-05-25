<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

try {
    start_secure_session();
    require_post_request();
    $input = get_json_input();
    require_csrf_token($input);
    $user = require_teacher_user();

    $class = create_school_class((int) $user['id'], (string) ($input['name'] ?? ''));

    json_response([
        'success' => true,
        'message' => 'Klasse wurde erstellt.',
        'csrfToken' => ensure_csrf_token(),
        'class' => $class,
        'teachingClasses' => list_teacher_school_classes((int) $user['id']),
    ]);
} catch (InvalidArgumentException $exception) {
    json_response([
        'success' => false,
        'message' => $exception->getMessage(),
        'csrfToken' => ensure_csrf_token(),
    ], 400);
} catch (Throwable $exception) {
    api_exception_response($exception, 'Klasse konnte nicht erstellt werden.', 500);
}
