<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

function clean_original_filename(string $filename): string
{
    $filename = basename($filename);
    $filename = preg_replace('/[^a-zA-Z0-9._ -]/', '_', $filename) ?: 'download.bin';
    $filename = trim($filename, '. ');

    return $filename !== '' ? $filename : 'download.bin';
}

function clean_relative_upload_path(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $parts = explode('/', $path);
    $cleanParts = [];

    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '' || $part === '.' || $part === '..') {
            continue;
        }

        $part = preg_replace('/[^a-zA-Z0-9._ -]/', '_', $part) ?: 'datei';
        $part = trim($part, '. ');
        if ($part !== '') {
            $cleanParts[] = $part;
        }
    }

    if ($cleanParts === []) {
        return 'download.bin';
    }

    return implode('/', $cleanParts);
}

function normalize_posted_path_list($value): array
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        $value = is_array($decoded) ? $decoded : [$value];
    }

    if (!is_array($value)) {
        return [];
    }

    $paths = [];
    foreach ($value as $path) {
        $path = clean_relative_upload_path((string) $path);
        if ($path !== '' && $path !== 'download.bin') {
            $paths[] = $path;
        }
    }

    return $paths;
}

function normalize_posted_folder_list($value): array
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        $value = is_array($decoded) ? $decoded : [$value];
    }

    if (!is_array($value)) {
        return [];
    }

    $folders = [];
    foreach ($value as $folderPath) {
        $folderPath = normalize_project_folder_path((string) $folderPath);
        if ($folderPath !== '') {
            $folders[$folderPath] = $folderPath;
        }
    }

    return array_values($folders);
}

function collect_uploaded_project_files(array $files, array $postedRelativePaths = []): array
{
    $normalized = [];
    $names = $files['name'] ?? [];
    $tmpNames = $files['tmp_name'] ?? [];
    $errors = $files['error'] ?? [];
    $sizes = $files['size'] ?? [];
    $fullPaths = $files['full_path'] ?? [];

    if (!is_array($names)) {
        $names = [$names];
        $tmpNames = [$tmpNames];
        $errors = [$errors];
        $sizes = [$sizes];
        $fullPaths = [$fullPaths ?: $names[0]];
    }

    foreach ($names as $index => $name) {
        $error = (int) ($errors[$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $explicitRelativePath = (string) ($postedRelativePaths[$index] ?? '');
        $relativePath = $explicitRelativePath !== '' ? $explicitRelativePath : (string) ($fullPaths[$index] ?? $name);
        $normalized[] = [
            'name' => (string) $name,
            'tmp_name' => (string) ($tmpNames[$index] ?? ''),
            'error' => $error,
            'size' => (int) ($sizes[$index] ?? 0),
            'relative_path' => clean_relative_upload_path($relativePath !== '' ? $relativePath : (string) $name),
        ];
    }

    return $normalized;
}

function detect_mime_type(string $path): string
{
    if (!function_exists('finfo_open')) {
        return 'application/octet-stream';
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        return 'application/octet-stream';
    }

    $detectedMime = finfo_file($finfo, $path);
    finfo_close($finfo);

    return is_string($detectedMime) && $detectedMime !== '' ? $detectedMime : 'application/octet-stream';
}

try {
    start_secure_session();
    require_post_request();
    require_csrf_token($_POST);
    $user = require_logged_in_user();
    require_coding_user($user);

    $title = trim((string) ($_POST['title'] ?? ''));
    $type = normalize_project_type((string) ($_POST['type'] ?? ''));
    $uploadKind = (string) ($_POST['uploadKind'] ?? 'single');
    $visibility = (string) ($_POST['visibility'] ?? '');
    $htmlContent = null;
    $sharedRaw = (string) ($_POST['sharedUsernames'] ?? '');
    $publicPermission = normalize_project_permission((string) ($_POST['publicPermission'] ?? 'read'));
    $siteRefreshMode = normalize_site_refresh_mode((string) ($_POST['siteRefreshMode'] ?? 'manual'));
    $siteRefreshSeconds = normalize_site_refresh_seconds($_POST['siteRefreshSeconds'] ?? 5);
    $titleLength = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);

    if ($title === '' || $titleLength > 150) {
        json_response([
            'success' => false,
            'message' => 'Bitte gib einen Titel mit maximal 150 Zeichen ein.',
        ], 400);
    }

    if (!in_array($uploadKind, ['single', 'folder'], true)) {
        $uploadKind = 'single';
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
        ? normalize_shared_target_permissions($_POST['sharedPermissions'] ?? [], $sharedTargets)
        : ['usernames' => [], 'classes' => []];
    if ($visibility === 'shared' && $sharedUsernames === [] && $sharedClassIds === []) {
        json_response([
            'success' => false,
            'message' => 'Bitte gib mindestens einen Benutzernamen oder eine Klasse ein.',
        ], 400);
    }
    $fileInfos = [];
    $movedFilePaths = [];

    if ($type === 'webpage') {
        $uploadKind = 'folder';
    }

    if (is_runtime_project_type($type)) {
        $uploadKind = 'folder';
    }

    $filesSource = $_FILES['projectFiles'] ?? ($_FILES['projectFile'] ?? null);
    if (!is_array($filesSource)) {
        json_response([
            'success' => false,
            'message' => $type === 'webpage' ? 'Bitte wähle index.html und weitere Top-Level-Dateien aus.' : ($uploadKind === 'folder' ? 'Bitte wähle einen Ordner aus.' : 'Bitte wähle eine Datei aus.'),
        ], 400);
    }

    $postedRelativePaths = normalize_posted_path_list($_POST['projectRelativePaths'] ?? []);
    $postedFolderPaths = normalize_posted_folder_list($_POST['projectFolderPaths'] ?? []);
    $uploadedFiles = collect_uploaded_project_files($filesSource, $postedRelativePaths);
    if ($uploadedFiles === []) {
        json_response([
            'success' => false,
            'message' => $type === 'webpage' ? 'Bitte wähle Webpage-Dateien mit index.html aus.' : ($uploadKind === 'folder' ? 'Bitte wähle einen Ordner mit Dateien aus.' : 'Bitte wähle eine Datei aus.'),
        ], 400);
    }

    if ($uploadKind === 'single' && count($uploadedFiles) > 1) {
        json_response([
            'success' => false,
            'message' => 'Bitte wähle für einen Einzeldatei-Upload nur eine Datei aus.',
        ], 400);
    }

    if ($type === 'webpage') {
        $hasIndexHtml = false;
        foreach ($uploadedFiles as $uploadedFile) {
            $relativePath = strtolower((string) $uploadedFile['relative_path']);
            if ($relativePath === 'index.html' || substr($relativePath, -11) === '/index.html') {
                $hasIndexHtml = true;
                break;
            }
        }

        if (!$hasIndexHtml) {
        json_response([
            'success' => false,
            'message' => 'Die Webpage-Dateien müssen eine index.html enthalten.',
        ], 400);
        }
    }

    $runtimeEntryFile = '';
    if (is_runtime_project_type($type)) {
        $sourceExtension = $type === 'java' ? 'java' : 'c';
        $fallbackEntry = default_runtime_entry_file($type);
        $firstSource = '';

        foreach ($uploadedFiles as $uploadedFile) {
            $relativePath = clean_relative_upload_path((string) $uploadedFile['relative_path']);
            if (strtolower(pathinfo($relativePath, PATHINFO_EXTENSION)) !== $sourceExtension) {
                continue;
            }

            if (strcasecmp($relativePath, $fallbackEntry) === 0) {
                $firstSource = $relativePath;
                break;
            }

            if ($firstSource === '') {
                $firstSource = $relativePath;
            }
        }

        if ($firstSource === '') {
            json_response([
                'success' => false,
                'message' => $type === 'java' ? 'Java-Projekte brauchen mindestens eine .java Datei.' : 'C-Projekte brauchen mindestens eine .c Datei.',
            ], 400);
        }

        $runtimeEntryFile = normalize_runtime_entry_file($type, (string) ($_POST['entryFile'] ?? $firstSource));
    }

    ensure_project_upload_directory();
    $totalSize = 0;

    foreach ($uploadedFiles as $uploadedFile) {
        if ((int) $uploadedFile['error'] !== UPLOAD_ERR_OK) {
            json_response([
                'success' => false,
                'message' => 'Mindestens ein Upload ist fehlgeschlagen.',
            ], 400);
        }

        $fileSize = (int) $uploadedFile['size'];
        $totalSize += $fileSize;
        if ($fileSize <= 0 || $totalSize > get_max_upload_bytes()) {
            json_response([
                'success' => false,
                'message' => 'Der Upload ist leer oder insgesamt zu groß.',
            ], 400);
        }

        $originalFilename = clean_original_filename((string) $uploadedFile['name']);
        $relativePath = $uploadKind === 'folder'
            ? clean_relative_upload_path((string) $uploadedFile['relative_path'])
            : $originalFilename;
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
        $storedFilename = bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . strtolower($extension) : '');
        $targetPath = get_project_upload_directory() . '/' . $storedFilename;

        if (!move_uploaded_file((string) $uploadedFile['tmp_name'], $targetPath)) {
            throw new RuntimeException('Datei konnte nicht gespeichert werden.');
        }

        $movedFilePaths[] = $targetPath;
        @chmod($targetPath, 0664);

        $fileInfos[] = [
            'stored_filename' => $storedFilename,
            'original_filename' => $originalFilename,
            'relative_path' => $relativePath,
            'mime_type' => detect_mime_type($targetPath),
            'file_size' => $fileSize,
        ];
    }

    $project = create_project_record(
        (int) $user['id'],
        $title,
        $type,
        $visibility,
        $htmlContent,
        $uploadKind,
        $fileInfos,
        $sharedUsernames,
        $publicPermission,
        $sharedPermissions['usernames'],
        $postedFolderPaths,
        $type === 'webpage' ? $siteRefreshMode : 'manual',
        $siteRefreshSeconds,
        is_runtime_project_type($type) ? ['entryFile' => $runtimeEntryFile] : [],
        $sharedClassIds,
        $sharedPermissions['classes']
    );
    $project = smb_sync_project_after_save($project);

    json_response([
        'success' => true,
        'message' => 'Projekt wurde erstellt.',
        'csrfToken' => ensure_csrf_token(),
        'project' => public_project($project, $user),
    ]);
} catch (InvalidArgumentException $exception) {
    if (isset($movedFilePaths) && is_array($movedFilePaths)) {
        foreach ($movedFilePaths as $movedFilePath) {
            @unlink($movedFilePath);
        }
    }

    json_response([
        'success' => false,
        'message' => $exception->getMessage(),
    ], 400);
} catch (Throwable $exception) {
    if (isset($movedFilePaths) && is_array($movedFilePaths)) {
        foreach ($movedFilePaths as $movedFilePath) {
            @unlink($movedFilePath);
        }
    }

    api_exception_response($exception, 'Projekt konnte nicht erstellt werden.', 500);
}
