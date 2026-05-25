<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

function code_editor_safe_relative_path(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $parts = [];

    foreach (explode('/', $path) as $part) {
        $part = trim($part);
        if ($part === '' || $part === '.' || $part === '..') {
            continue;
        }
        $part = preg_replace('/[^a-zA-Z0-9._ -]/', '_', $part) ?: 'datei';
        $part = trim($part, '. ');
        if ($part !== '') {
            $parts[] = $part;
        }
    }

    return $parts === [] ? 'index.html' : implode('/', $parts);
}

function code_editor_mime_type(string $path): string
{
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $map = [
        'html' => 'text/html; charset=utf-8',
        'htm' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'svg' => 'image/svg+xml',
        'xml' => 'application/xml; charset=utf-8',
        'md' => 'text/markdown; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
        'java' => 'text/x-java-source; charset=utf-8',
        'c' => 'text/x-csrc; charset=utf-8',
        'h' => 'text/x-chdr; charset=utf-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
    ];

    return $map[$extension] ?? 'text/plain; charset=utf-8';
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

function code_editor_binary_content($payload): ?string
{
    if (!is_array($payload) || !is_string($payload['data'] ?? null)) {
        return null;
    }

    $data = (string) $payload['data'];
    if (strpos($data, ',') !== false) {
        $data = substr($data, strpos($data, ',') + 1);
    }

    $content = base64_decode($data, true);
    return $content === false ? null : $content;
}

function code_editor_existing_file_map(array $project): array
{
    $map = [];
    $files = get_project_files($project);
    $rootPrefix = project_webpage_root_prefix($files);

    foreach ($files as $file) {
        $relativePath = code_editor_safe_relative_path((string) ($file['relative_path'] ?? ''));
        $map[strtolower($relativePath)] = $file;

        $publicPath = code_editor_safe_relative_path(project_webpage_public_path($relativePath, $rootPrefix));
        $map[strtolower($publicPath)] = $file;
    }

    return $map;
}

function code_editor_write_existing_file(array $file, string $content): void
{
    $uploadDirectory = realpath(get_project_upload_directory());
    $filePath = realpath(get_project_upload_directory() . '/' . (string) $file['stored_filename']);

    if ($uploadDirectory === false || $filePath === false || strncmp($filePath, $uploadDirectory . DIRECTORY_SEPARATOR, strlen($uploadDirectory) + 1) !== 0) {
        throw new RuntimeException('Projektdatei wurde nicht gefunden.');
    }

    if (file_put_contents($filePath, $content) === false) {
        throw new RuntimeException('Projektdatei konnte nicht gespeichert werden.');
    }
}

function code_editor_delete_existing_file(array $file): void
{
    $fileId = (int) ($file['id'] ?? 0);
    $projectId = (int) ($file['project_id'] ?? 0);

    if ($fileId <= 0 || $projectId <= 0) {
        return;
    }

    $uploadDirectory = realpath(get_project_upload_directory());
    $storedFilename = (string) ($file['stored_filename'] ?? '');
    $filePath = $storedFilename !== '' ? realpath(get_project_upload_directory() . '/' . $storedFilename) : false;

    if ($uploadDirectory !== false && $filePath !== false && strncmp($filePath, $uploadDirectory . DIRECTORY_SEPARATOR, strlen($uploadDirectory) + 1) === 0 && is_file($filePath)) {
        @unlink($filePath);
    }

    $pdo = get_db_connection();
    $statement = $pdo->prepare('DELETE FROM project_files WHERE id = :id AND project_id = :project_id');
    $statement->execute([
        ':id' => $fileId,
        ':project_id' => $projectId,
    ]);
}

function code_editor_insert_file(int $projectId, string $relativePath, string $content): void
{
    $extension = pathinfo($relativePath, PATHINFO_EXTENSION);
    $storedFilename = bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . strtolower($extension) : '.txt');
    $targetPath = get_project_upload_directory() . '/' . $storedFilename;

    if (file_put_contents($targetPath, $content) === false) {
        throw new RuntimeException('Neue Projektdatei konnte nicht gespeichert werden.');
    }

    @chmod($targetPath, 0664);

    $pdo = get_db_connection();
    $statement = $pdo->prepare(
        'INSERT INTO project_files (project_id, stored_filename, original_filename, relative_path, mime_type, file_size)
         VALUES (:project_id, :stored_filename, :original_filename, :relative_path, :mime_type, :file_size)'
    );
    $statement->execute([
        ':project_id' => $projectId,
        ':stored_filename' => $storedFilename,
        ':original_filename' => basename($relativePath),
        ':relative_path' => $relativePath,
        ':mime_type' => code_editor_mime_type($relativePath),
        ':file_size' => strlen($content),
    ]);
}

try {
    start_secure_session();
    require_post_request();
    $input = get_json_input();
    require_csrf_token($input);
    $user = require_logged_in_user();

    $projectId = (int) ($input['id'] ?? 0);
    $files = is_array($input['files'] ?? null) ? $input['files'] : [];
    $binaryFiles = is_array($input['binaryFiles'] ?? null) ? $input['binaryFiles'] : [];
    $folders = is_array($input['folders'] ?? null) ? $input['folders'] : [];
    $deletedPaths = is_array($input['deletedPaths'] ?? null) ? $input['deletedPaths'] : [];
    $entryFileInput = (string) ($input['entryFile'] ?? '');

    if ($projectId <= 0) {
        json_response([
            'success' => false,
            'message' => 'Projekt wurde nicht gefunden.',
        ], 404);
    }

    if ($files === [] && $binaryFiles === []) {
        json_response([
            'success' => false,
            'message' => 'Keine Dateien zum Speichern erhalten.',
        ], 400);
    }

    $project = require_project_edit_access($projectId, $user);
    $project = smb_sync_project_for_read($project);
    $projectType = (string) $project['type'];

    $existingFiles = code_editor_existing_file_map($project);
    $totalSize = 0;
    $editableFiles = [];
    $finalPaths = [];

    foreach (array_keys($files) as $path) {
        $finalPaths[strtolower(code_editor_safe_relative_path((string) $path))] = true;
    }

    foreach (array_keys($binaryFiles) as $path) {
        $finalPaths[strtolower(code_editor_safe_relative_path((string) $path))] = true;
    }

    $deletedFileIds = [];
    foreach ($deletedPaths as $path) {
        if (!is_string($path)) {
            continue;
        }

        $relativePath = code_editor_safe_relative_path($path);
        $key = strtolower($relativePath);

        if (isset($finalPaths[$key]) || !isset($existingFiles[$key])) {
            continue;
        }

        $fileId = (int) ($existingFiles[$key]['id'] ?? 0);
        if ($fileId > 0 && isset($deletedFileIds[$fileId])) {
            continue;
        }

        code_editor_delete_existing_file($existingFiles[$key]);
        if ($fileId > 0) {
            $deletedFileIds[$fileId] = true;
        }
    }

    foreach ($files as $path => $content) {
        if (!is_string($content)) {
            continue;
        }

        $relativePath = code_editor_safe_relative_path((string) $path);
        $editableFiles[$relativePath] = true;
        $content = str_replace("\r\n", "\n", $content);
        $totalSize += strlen($content);

        if ($totalSize > get_max_upload_bytes()) {
            json_response([
                'success' => false,
                'message' => 'Der Code ist insgesamt zu groß.',
            ], 400);
        }

        $key = strtolower($relativePath);
        if (isset($existingFiles[$key])) {
            code_editor_write_existing_file($existingFiles[$key], $content);
            $pdo = get_db_connection();
            $statement = $pdo->prepare('UPDATE project_files SET file_size = :file_size, mime_type = :mime_type WHERE id = :id');
            $statement->execute([
                ':file_size' => strlen($content),
                ':mime_type' => code_editor_mime_type($relativePath),
                ':id' => (int) $existingFiles[$key]['id'],
            ]);
        } else {
            code_editor_insert_file((int) $project['id'], $relativePath, $content);
        }
    }

    foreach ($binaryFiles as $path => $payload) {
        $content = code_editor_binary_content($payload);
        if ($content === null) {
            continue;
        }

        $relativePath = code_editor_safe_relative_path((string) $path);
        $totalSize += strlen($content);

        if ($totalSize > get_max_upload_bytes()) {
            json_response([
                'success' => false,
                'message' => 'Der Upload ist insgesamt zu groß.',
            ], 400);
        }

        $key = strtolower($relativePath);
        if (isset($existingFiles[$key])) {
            code_editor_write_existing_file($existingFiles[$key], $content);
            $pdo = get_db_connection();
            $statement = $pdo->prepare('UPDATE project_files SET file_size = :file_size, mime_type = :mime_type WHERE id = :id');
            $statement->execute([
                ':file_size' => strlen($content),
                ':mime_type' => code_editor_mime_type($relativePath),
                ':id' => (int) $existingFiles[$key]['id'],
            ]);
        } else {
            code_editor_insert_file((int) $project['id'], $relativePath, $content);
        }
    }

    $pdo = get_db_connection();
    $statement = $pdo->prepare(
        'UPDATE projects
         SET file_size = (
             SELECT COALESCE(SUM(file_size), 0)
             FROM project_files
             WHERE project_id = :project_id
         ),
         updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $statement->execute([
        ':project_id' => (int) $project['id'],
        ':id' => (int) $project['id'],
    ]);
    replace_project_folders($pdo, (int) $project['id'], collect_project_folder_paths(get_project_files($project), $folders));

    if (is_runtime_project_type($projectType)) {
        $currentRuntimeConfig = project_runtime_config($project);
        $entryFile = normalize_runtime_entry_file(
            $projectType,
            $entryFileInput !== '' ? $entryFileInput : (string) ($currentRuntimeConfig['entryFile'] ?? default_runtime_entry_file($projectType))
        );
        update_project_runtime_config((int) $project['id'], $projectType, ['entryFile' => $entryFile]);
    }

    $updatedProject = find_project_by_id((int) $project['id']);
    $updatedProject = smb_sync_project_after_save($updatedProject ?: $project);
    $publicProject = public_project($updatedProject, $user);
    json_response([
        'success' => true,
        'message' => 'Projekt wurde im Editor gespeichert.',
        'project' => $publicProject,
        'assets' => code_editor_public_assets($publicProject, $editableFiles),
        'csrfToken' => ensure_csrf_token(),
    ]);
} catch (Throwable $exception) {
    api_exception_response($exception, 'Projekt konnte im Editor nicht gespeichert werden.', 500);
}
