<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

try {
    start_secure_session();
    $user = require_logged_in_user();

    json_response([
        'success' => true,
        'csrfToken' => ensure_csrf_token(),
        'teachingClasses' => is_teacher_user($user) ? list_teacher_school_classes((int) $user['id']) : [],
        'joinedClasses' => is_student_user($user) ? list_student_school_classes((int) $user['id']) : [],
    ]);
} catch (Throwable $exception) {
    api_exception_response($exception, 'Klassen konnten nicht geladen werden.', 500);
}
