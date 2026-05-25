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
    $publicPermission = normalize_project_permission((string) ($input['publicPermission'] ?? ($project['public_permission'] ?? 'read')));
    $siteRefreshMode = normalize_site_refresh_mode((string) ($input['siteRefreshMode'] ?? ($project['site_refresh_mode'] ?? 'manual')));
    $siteRefreshSeconds = normalize_site_refresh_seconds($input['siteRefreshSeconds'] ?? ($project['site_refresh_seconds'] ?? 5));
    $sharedTargets = $visibility === 'shared' ? normalize_shared_targets($sharedRaw, $user, (string) $project['type']) : ['usernames' => [], 'classes' => []];
    $sharedUsernames = $sharedTargets['usernames'];
    $sharedClassIds = array_map(static fn (array $class): int => (int) $class['id'], $sharedTargets['classes']);
    $sharedPermissions = $visibility === 'shared'
        ? normalize_shared_target_permissions($input['sharedPermissions'] ?? [], $sharedTargets)
        : ['usernames' => [], 'classes' => []];
    if ($visibility === 'shared' && $sharedUsernames === [] && $sharedClassIds === []) {
        json_response([
            'success' => false,
            'message' => 'Bitte gib mindestens einen Benutzernamen oder eine Klasse ein.',
        ], 400);
    }

    $updatedProject = update_project_visibility(
        (int) $project['id'],
        (int) $user['id'],
        $visibility,
        $sharedUsernames,
        $publicPermission,
        $sharedPermissions['usernames'],
        (string) $project['type'] === 'webpage' ? $siteRefreshMode : 'manual',
        $siteRefreshSeconds,
        $sharedClassIds,
        $sharedPermissions['classes']
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
