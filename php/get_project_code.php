<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

function code_editor_is_text_file(array $file): bool
{
    $path = strtolower((string) ($file['relative_path'] ?? $file['original_filename'] ?? ''));
    $mime = strtolower((string) ($file['mime_type'] ?? ''));
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    return strncmp($mime, 'text/', 5) === 0
        || in_array($extension, ['html', 'htm', 'css', 'js', 'mjs', 'json', 'txt', 'md', 'svg', 'xml', 'java', 'c', 'h'], true);
}

function code_editor_resolve_file_path(array $file): string
{
    $uploadDirectory = realpath(get_project_upload_directory());
    $filePath = realpath(get_project_upload_directory() . '/' . (string) $file['stored_filename']);

    if ($uploadDirectory === false || $filePath === false || strncmp($filePath, $uploadDirectory . DIRECTORY_SEPARATOR, strlen($uploadDirectory) + 1) !== 0) {
        throw new RuntimeException('Projektdatei wurde nicht gefunden.');
    }

    if (!is_file($filePath) || !is_readable($filePath)) {
        throw new RuntimeException('Projektdatei ist nicht lesbar.');
    }

    return $filePath;
}

function code_editor_public_assets(array $publicProject, array $editableFiles): array
{
    $assets = [];
    foreach (($publicProject['files'] ?? []) as $file) {
        $path = (string) ($file['openPath'] ?? $file['path'] ?? '');
        if ($path === '' || array_key_exists($path, $editableFiles)) {
            continue;
        }

        $assets[] = [
            'path' => $path,
            'mimeType' => (string) ($file['mimeType'] ?? 'application/octet-stream'),
            'fileSize' => (int) ($file['fileSize'] ?? 0),
            'openUrl' => (string) ($file['openUrl'] ?? ''),
        ];
    }

    return $assets;
}

try {
    start_secure_session();
    $user = require_logged_in_user();
    $projectId = (int) ($_GET['id'] ?? 0);

    if ($projectId <= 0) {
        json_response([
            'success' => false,
            'message' => 'Projekt wurde nicht gefunden.',
        ], 404);
    }

    $project = require_project_editor_access($projectId, $user);
    $project = smb_sync_project_for_read($project);

    $projectFiles = get_project_files($project);
    $rootPrefix = project_webpage_root_prefix($projectFiles);
    $files = [];
    foreach ($projectFiles as $file) {
        if (!code_editor_is_text_file($file) || (int) ($file['file_size'] ?? 0) > 1048576) {
            continue;
        }

        $path = (string) ($file['relative_path'] ?? $file['original_filename'] ?? '');
        if ($path === '') {
            continue;
        }

        $editorPath = project_webpage_public_path($path, $rootPrefix);
        $files[$editorPath] = (string) file_get_contents(code_editor_resolve_file_path($file));
    }

    $publicProject = public_project($project, $user);

    $runtimeConfig = project_runtime_config($project);
    $entryFile = (string) ($runtimeConfig['entryFile'] ?? '');
    if ($entryFile === '' || !isset($files[$entryFile])) {
        $entryFile = isset($files['index.html']) ? 'index.html' : (array_key_first($files) ?: '');
    }

    json_response([
        'success' => true,
        'project' => $publicProject,
        'files' => $files,
        'assets' => code_editor_public_assets($publicProject, $files),
        'folders' => public_project_folders($project),
        'entryFile' => $entryFile,
        'csrfToken' => ensure_csrf_token(),
    ]);
} catch (Throwable $exception) {
    api_exception_response($exception, 'Projekt konnte nicht im Editor geladen werden.', 500);
}
