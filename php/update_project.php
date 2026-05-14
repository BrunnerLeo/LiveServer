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
    $visibility = (string) ($input['visibility'] ?? '');
    $sharedRaw = (string) ($input['sharedUsernames'] ?? '');
    $publicPermission = normalize_project_permission((string) ($input['publicPermission'] ?? 'read'));

    if ($projectId <= 0) {
        json_response([
            'success' => false,
            'message' => 'Projekt wurde nicht gefunden.',
        ], 404);
    }

    if (!in_array($visibility, ['public', 'private', 'shared'], true)) {
        json_response([
            'success' => false,
            'message' => 'Ungültige Sichtbarkeit.',
        ], 400);
    }

    $project = require_project_owner($projectId, $user);
    $sharedUsernames = $visibility === 'shared' ? normalize_shared_usernames($sharedRaw) : [];
    $sharedPermissions = $visibility === 'shared'
        ? normalize_shared_permissions($input['sharedPermissions'] ?? [], $sharedUsernames)
        : [];
    if ($visibility === 'shared' && $sharedUsernames === []) {
        json_response([
            'success' => false,
            'message' => 'Bitte gib mindestens einen Shared-Benutzernamen ein.',
        ], 400);
    }

    $updatedProject = update_project_visibility(
        (int) $project['id'],
        (int) $user['id'],
        $visibility,
        $sharedUsernames,
        $publicPermission,
        $sharedPermissions
    );

    json_response([
        'success' => true,
        'message' => 'Projekt wurde aktualisiert.',
        'project' => public_project($updatedProject, $user),
        'csrfToken' => ensure_csrf_token(),
    ]);
} catch (InvalidArgumentException $exception) {
    json_response([
        'success' => false,
        'message' => $exception->getMessage(),
    ], 400);
} catch (Throwable $exception) {
    api_exception_response($exception, 'Projekt konnte nicht aktualisiert werden.', 500);
}
