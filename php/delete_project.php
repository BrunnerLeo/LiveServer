<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

try {
    start_secure_session();
    require_post_request();
    $input = get_json_input();
    require_csrf_token($input);
    $user = require_logged_in_user();

    $projectId = (int) ($input['id'] ?? 0);
    if ($projectId <= 0) {
        json_response([
            'success' => false,
            'message' => 'Projekt wurde nicht gefunden.',
        ], 404);
    }

    $project = require_project_owner($projectId, $user);
    delete_project_record($project);

    json_response([
        'success' => true,
        'message' => 'Projekt wurde gelöscht.',
        'csrfToken' => ensure_csrf_token(),
    ]);
} catch (Throwable $exception) {
    api_exception_response($exception, 'Projekt konnte nicht gelöscht werden.', 500);
}
