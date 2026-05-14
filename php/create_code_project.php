<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

function code_project_mime_type(string $filename): string
{
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $map = [
        'html' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
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

try {
    start_secure_session();
    require_post_request();
    $input = get_json_input();
    require_csrf_token($input);
    $user = require_logged_in_user();

    $title = trim((string) ($input['title'] ?? ''));
    $visibility = (string) ($input['visibility'] ?? '');
    $sharedRaw = (string) ($input['sharedUsernames'] ?? '');
    $publicPermission = normalize_project_permission((string) ($input['publicPermission'] ?? 'read'));
    $files = is_array($input['files'] ?? null) ? $input['files'] : [];
    $titleLength = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);

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

    $allowedFiles = ['index.html', 'style.css', 'script.js'];
    $html = normalize_code_file_content($files['index.html'] ?? '');
    if (trim($html) === '') {
        json_response([
            'success' => false,
            'message' => 'index.html darf nicht leer sein.',
        ], 400);
    }

    ensure_project_upload_directory();
    $fileInfos = [];
    $createdFilePaths = [];
    $totalSize = 0;

    foreach ($allowedFiles as $filename) {
        $content = normalize_code_file_content($files[$filename] ?? '');
        if ($filename !== 'index.html' && trim($content) === '') {
            continue;
        }

        $bytes = strlen($content);
        $totalSize += $bytes;
        if ($bytes <= 0 || $totalSize > get_max_upload_bytes()) {
            json_response([
                'success' => false,
                'message' => 'Der Code ist leer oder insgesamt zu groß.',
            ], 400);
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $storedFilename = bin2hex(random_bytes(16)) . '.' . strtolower($extension);
        $targetPath = get_project_upload_directory() . '/' . $storedFilename;

        if (file_put_contents($targetPath, $content) === false) {
            throw new RuntimeException('Code-Datei konnte nicht gespeichert werden.');
        }

        $createdFilePaths[] = $targetPath;
        @chmod($targetPath, 0664);

        $fileInfos[] = [
            'stored_filename' => $storedFilename,
            'original_filename' => $filename,
            'relative_path' => $filename,
            'mime_type' => code_project_mime_type($filename),
            'file_size' => $bytes,
        ];
    }

    $project = create_project_record(
        (int) $user['id'],
        $title,
        'webpage',
        $visibility,
        'folder',
        $fileInfos,
        $sharedUsernames,
        $publicPermission,
        $sharedPermissions
    );

    json_response([
        'success' => true,
        'message' => 'Webpage-Projekt wurde aus dem Code erstellt.',
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
