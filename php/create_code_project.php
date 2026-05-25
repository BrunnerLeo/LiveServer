<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

function code_project_safe_relative_path(string $path, string $fallback): string
{
    $path = normalize_project_folder_path($path);
    return $path !== '' ? $path : $fallback;
}

function code_project_mime_type(string $filename): string
{
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $map = [
        'html' => 'text/html; charset=utf-8',
        'htm' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'java' => 'text/x-java-source; charset=utf-8',
        'c' => 'text/x-csrc; charset=utf-8',
        'h' => 'text/x-chdr; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
        'md' => 'text/markdown; charset=utf-8',
    ];

    return $map[$extension] ?? 'text/plain; charset=utf-8';
}

function normalize_code_file_content($value): string
{
    if (!is_string($value)) {
        return '';
    }

    return str_replace("\r\n", "\n", $value);
}

function first_source_file(array $files, string $extension, string $fallback): string
{
    if (isset($files[$fallback]) && trim((string) $files[$fallback]) !== '') {
        return $fallback;
    }

    foreach ($files as $path => $content) {
        if (strtolower(pathinfo((string) $path, PATHINFO_EXTENSION)) === $extension && trim((string) $content) !== '') {
            return (string) $path;
        }
    }

    return $fallback;
}

function validate_code_project_files(string $type, array $files, string $entryFile): void
{
    if ($type === 'webpage') {
        if (trim((string) ($files['index.html'] ?? '')) === '') {
            throw new InvalidArgumentException('index.html darf nicht leer sein.');
        }
        return;
    }

    if ($type === 'java') {
        if (!isset($files[$entryFile]) || trim((string) $files[$entryFile]) === '') {
            throw new InvalidArgumentException('Die Java Entry-Datei darf nicht leer sein.');
        }

        foreach ($files as $path => $content) {
            if (strtolower(pathinfo((string) $path, PATHINFO_EXTENSION)) === 'java' && trim((string) $content) !== '') {
                return;
            }
        }

        throw new InvalidArgumentException('Java-Projekte brauchen mindestens eine .java Datei.');
    }

    if ($type === 'c') {
        if (!isset($files[$entryFile]) || trim((string) $files[$entryFile]) === '') {
            throw new InvalidArgumentException('Die C Entry-Datei darf nicht leer sein.');
        }

        foreach ($files as $path => $content) {
            if (strtolower(pathinfo((string) $path, PATHINFO_EXTENSION)) === 'c' && trim((string) $content) !== '') {
                return;
            }
        }

        throw new InvalidArgumentException('C-Projekte brauchen mindestens eine .c Datei.');
    }
}

try {
    start_secure_session();
    require_post_request();
    $input = get_json_input();
    require_csrf_token($input);
    $user = require_logged_in_user();
    require_coding_user($user);

    $title = trim((string) ($input['title'] ?? ''));
    $type = normalize_project_type((string) ($input['type'] ?? 'webpage'));
    $visibility = (string) ($input['visibility'] ?? '');
    $sharedRaw = (string) ($input['sharedUsernames'] ?? '');
    $publicPermission = normalize_project_permission((string) ($input['publicPermission'] ?? 'read'));
    $siteRefreshMode = normalize_site_refresh_mode((string) ($input['siteRefreshMode'] ?? 'manual'));
    $siteRefreshSeconds = normalize_site_refresh_seconds($input['siteRefreshSeconds'] ?? 5);
    $rawFiles = is_array($input['files'] ?? null) ? $input['files'] : [];
    $titleLength = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);

    if (!is_editor_project_type($type)) {
        json_response([
            'success' => false,
            'message' => 'Nur Webpage-, Java- und C-Projekte können direkt aus Code erstellt werden.',
        ], 400);
    }

    if ($title === '' || $titleLength > 150) {
        json_response([
            'success' => false,
            'message' => 'Bitte gib einen Titel mit maximal 150 Zeichen ein.',
        ], 400);
    }

    if (!in_array($visibility, ['public', 'private', 'shared'], true)) {
        json_response([
            'success' => false,
            'message' => 'Ungültige Sichtbarkeit.',
        ], 400);
    }

    $sharedTargets = $visibility === 'shared' ? normalize_shared_targets($sharedRaw, $user, $type) : ['usernames' => [], 'classes' => []];
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

    $fallbackEntry = default_runtime_entry_file($type);
    $entryCandidate = (string) ($input['entryFile'] ?? '');
    if ($entryCandidate === '' && $type === 'java') {
        $entryCandidate = first_source_file($rawFiles, 'java', $fallbackEntry);
    } elseif ($entryCandidate === '' && $type === 'c') {
        $entryCandidate = first_source_file($rawFiles, 'c', $fallbackEntry);
    }
    $entryFile = is_runtime_project_type($type) ? normalize_runtime_entry_file($type, $entryCandidate) : '';

    ensure_project_upload_directory();
    $fileInfos = [];
    $createdFilePaths = [];
    $normalizedFiles = [];
    $totalSize = 0;

    foreach ($rawFiles as $path => $value) {
        $relativePath = code_project_safe_relative_path((string) $path, $type === 'webpage' ? 'index.html' : default_runtime_entry_file($type));
        $content = normalize_code_file_content($value);
        if (trim($content) === '' && !($type === 'webpage' && $relativePath === 'index.html')) {
            continue;
        }

        $normalizedFiles[$relativePath] = $content;
    }

    validate_code_project_files($type, $normalizedFiles, $entryFile);

    foreach ($normalizedFiles as $relativePath => $content) {
        $bytes = strlen($content);
        $totalSize += $bytes;
        if ($bytes <= 0 || $totalSize > get_max_upload_bytes()) {
            json_response([
                'success' => false,
                'message' => 'Der Code ist leer oder insgesamt zu groß.',
            ], 400);
        }

        $extension = pathinfo($relativePath, PATHINFO_EXTENSION);
        $storedFilename = bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . strtolower($extension) : '.txt');
        $targetPath = get_project_upload_directory() . '/' . $storedFilename;

        if (file_put_contents($targetPath, $content) === false) {
            throw new RuntimeException('Code-Datei konnte nicht gespeichert werden.');
        }

        $createdFilePaths[] = $targetPath;
        @chmod($targetPath, 0664);

        $fileInfos[] = [
            'stored_filename' => $storedFilename,
            'original_filename' => basename($relativePath),
            'relative_path' => $relativePath,
            'mime_type' => code_project_mime_type($relativePath),
            'file_size' => $bytes,
        ];
    }

    $project = create_project_record(
        (int) $user['id'],
        $title,
        $type,
        $visibility,
        null,
        'folder',
        $fileInfos,
        $sharedUsernames,
        $publicPermission,
        $sharedPermissions['usernames'],
        [],
        $type === 'webpage' ? $siteRefreshMode : 'manual',
        $siteRefreshSeconds,
        is_runtime_project_type($type) ? ['entryFile' => $entryFile] : [],
        $sharedClassIds,
        $sharedPermissions['classes']
    );
    $project = smb_sync_project_after_save($project);

    json_response([
        'success' => true,
        'message' => $type === 'webpage' ? 'Webpage-Projekt wurde aus dem Code erstellt.' : 'Code-Projekt wurde erstellt.',
        'csrfToken' => ensure_csrf_token(),
        'project' => public_project($project, $user),
    ]);
} catch (InvalidArgumentException $exception) {
    if (isset($createdFilePaths) && is_array($createdFilePaths)) {
        foreach ($createdFilePaths as $createdFilePath) {
            @unlink($createdFilePath);
        }
    }

    json_response([
        'success' => false,
        'message' => $exception->getMessage(),
    ], 400);
} catch (Throwable $exception) {
    if (isset($createdFilePaths) && is_array($createdFilePaths)) {
        foreach ($createdFilePaths as $createdFilePath) {
            @unlink($createdFilePath);
        }
    }

    api_exception_response($exception, 'Code-Projekt konnte nicht erstellt werden.', 500);
}
