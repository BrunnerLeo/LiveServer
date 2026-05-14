<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

try {
    start_secure_session();
    require_post_request();
    $input = get_json_input();
    require_csrf_token($input);
    $user = require_logged_in_user();

    $projectIds = $input['projectIds'] ?? [];
    if (!is_array($projectIds)) {
        json_response([
            'success' => false,
            'message' => 'Ungültige Projektreihenfolge.',
        ], 400);
    }

    update_project_order_for_user($user, $projectIds);

    json_response([
        'success' => true,
        'message' => 'Projektreihenfolge wurde gespeichert.',
        'csrfToken' => ensure_csrf_token(),
    ]);
} catch (Throwable $exception) {
    api_exception_response($exception, 'Projektreihenfolge konnte nicht gespeichert werden.', 500);
}
