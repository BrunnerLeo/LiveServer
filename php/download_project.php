<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

function download_error(string $message, int $statusCode): void
{
    http_response_code($statusCode);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function safe_content_disposition_filename(string $filename): string
{
    $filename = basename($filename);
    $filename = preg_replace('/[^a-zA-Z0-9._ -]/', '_', $filename) ?: 'download.bin';
    return trim($filename, '. ') ?: 'download.bin';
}

function safe_zip_entry_name(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $parts = [];

    foreach (explode('/', $path) as $part) {
        $part = trim($part);
        if ($part === '' || $part === '.' || $part === '..') {
            continue;
        }
        $parts[] = $part;
    }

    return $parts === [] ? 'download.bin' : implode('/', $parts);
}

function resolve_uploaded_file_path(string $storedFilename): string
{
    $uploadDirectory = realpath(get_project_upload_directory());
    $filePath = realpath(get_project_upload_directory() . '/' . $storedFilename);

    if ($uploadDirectory === false || $filePath === false || strncmp($filePath, $uploadDirectory . DIRECTORY_SEPARATOR, strlen($uploadDirectory) + 1) !== 0) {
        download_error('Datei wurde nicht gefunden.', 404);
    }

    if (!is_file($filePath) || !is_readable($filePath)) {
        download_error('Datei wurde nicht gefunden.', 404);
    }

    return $filePath;
}

function stream_single_project_file(array $file): void
{
    $filePath = resolve_uploaded_file_path((string) $file['stored_filename']);
    $downloadName = safe_content_disposition_filename((string) ($file['original_filename'] ?? 'download.bin'));
    $mimeType = (string) ($file['mime_type'] ?? 'application/octet-stream');

    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . (string) filesize($filePath));
    header('Content-Disposition: attachment; filename="' . addcslashes($downloadName, "\\\"") . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($filePath);
    exit;
}

function stream_project_zip(array $project, array $files): void
{
    if (!class_exists('ZipArchive')) {
        download_error('ZIP-Downloads sind auf diesem Server nicht verfügbar. Bitte installiere php-zip.', 500);
    }

    $zipPath = tempnam(sys_get_temp_dir(), 'project_zip_');
    if ($zipPath === false) {
        download_error('ZIP-Datei konnte nicht vorbereitet werden.', 500);
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($zipPath);
        download_error('ZIP-Datei konnte nicht erstellt werden.', 500);
    }

    foreach ($files as $file) {
        $filePath = resolve_uploaded_file_path((string) $file['stored_filename']);
        $entryName = safe_zip_entry_name((string) ($file['relative_path'] ?? $file['original_filename'] ?? 'download.bin'));
        $zip->addFile($filePath, $entryName);
    }

    $zip->close();
    $zipName = safe_content_disposition_filename((string) $project['title']) . '.zip';

    header('Content-Type: application/zip');
    header('Content-Length: ' . (string) filesize($zipPath));
    header('Content-Disposition: attachment; filename="' . addcslashes($zipName, "\\\"") . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($zipPath);
    @unlink($zipPath);
    exit;
}

try {
    start_secure_session();
    $projectId = (int) ($_GET['id'] ?? 0);
    if ($projectId <= 0) {
        download_error('Projekt wurde nicht gefunden.', 404);
    }

    $project = find_project_by_id($projectId);
    if ($project === null) {
        download_error('Projekt wurde nicht gefunden.', 404);
    }

    $user = current_user_record();
    if (!user_can_access_project($project, $user)) {
        download_error($user === null ? 'Login erforderlich.' : 'Kein Zugriff auf dieses Projekt.', $user === null ? 401 : 403);
    }

    $files = get_project_files($project);
    if ($files === []) {
        download_error('Datei wurde nicht gefunden.', 404);
    }

    $fileId = isset($_GET['file_id']) ? (int) $_GET['file_id'] : null;
    if ($fileId !== null) {
        $file = find_project_file($project, $fileId);
        if ($file === null) {
            download_error('Datei wurde nicht gefunden.', 404);
        }
        stream_single_project_file($file);
    }

    if (count($files) === 1) {
        stream_single_project_file($files[0]);
    }

    stream_project_zip($project, $files);
} catch (Throwable $exception) {
    log_api_exception($exception);
    download_error('Download konnte nicht verarbeitet werden.', 500);
}
