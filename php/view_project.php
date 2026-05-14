<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

function render_project_error(string $message, int $statusCode): void
{
    http_response_code($statusCode);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Projekt</title><style>body{font-family:Arial,sans-serif;margin:40px;color:#172033}a{color:#155eef}</style></head><body>';
    echo '<h1>Projekt nicht verfügbar</h1><p>' . $safeMessage . '</p><p><a href="../public/html/login.html">Zum Login</a></p>';
    echo '</body></html>';
    exit;
}

function parse_webpage_request(): array
{
    $pathInfo = trim((string) ($_SERVER['PATH_INFO'] ?? ''), '/');
    if ($pathInfo !== '') {
        $parts = explode('/', $pathInfo, 2);
        return [
            'project_id' => (int) ($parts[0] ?? 0),
            'path' => clean_webpage_path((string) ($parts[1] ?? 'index.html')),
        ];
    }

    return [
        'project_id' => (int) ($_GET['id'] ?? 0),
        'path' => clean_webpage_path((string) ($_GET['path'] ?? 'index.html')),
    ];
}

function uses_webpage_path_route(): bool
{
    return trim((string) ($_SERVER['PATH_INFO'] ?? ''), '/') !== '';
}

function clean_webpage_path(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('/[?#].*$/', '', $path) ?: '';
    $path = rawurldecode($path);
    $parts = [];

    foreach (explode('/', $path) as $part) {
        $part = trim($part);
        if ($part === '' || $part === '.' || $part === '..') {
            continue;
        }
        $parts[] = $part;
    }

    return $parts === [] ? 'index.html' : implode('/', $parts);
}

function find_webpage_file(array $project, string $path): ?array
{
    $files = get_project_files($project);
    $path = strtolower($path);
    $rootPrefix = get_webpage_root_prefix($files);
    $prefixedPath = $rootPrefix !== '' ? $rootPrefix . '/' . $path : $path;

    foreach ($files as $file) {
        $relativePath = strtolower((string) ($file['relative_path'] ?? ''));
        if ($relativePath === $path || $relativePath === $prefixedPath) {
            return $file;
        }
    }

    if ($path !== 'index.html') {
        $directoryIndexPath = rtrim($path, '/') . '/index.html';
        $prefixedDirectoryIndexPath = $rootPrefix !== '' ? $rootPrefix . '/' . $directoryIndexPath : $directoryIndexPath;

        foreach ($files as $file) {
            $relativePath = strtolower((string) ($file['relative_path'] ?? ''));
            if ($relativePath === $directoryIndexPath || $relativePath === $prefixedDirectoryIndexPath) {
                return $file;
            }
        }
    }

    if ($path === 'index.html') {
        foreach ($files as $file) {
            $relativePath = strtolower((string) ($file['relative_path'] ?? ''));
            if ($relativePath === 'index.html' || substr($relativePath, -11) === '/index.html') {
                return $file;
            }
        }
    }

    return null;
}

function build_webpage_route(int $projectId, string $path): string
{
    $encodedPath = project_url_path_encode($path);
    $basePath = '/site.php/' . $projectId . '/';

    if ($encodedPath === '' || strtolower($encodedPath) === 'index.html') {
        return $basePath;
    }

    return $basePath . $encodedPath;
}

function build_webpage_directory_route(int $projectId, string $path): string
{
    $path = trim(str_replace('\\', '/', $path), '/');
    if ($path === '' || strtolower($path) === 'index.html' || strpos($path, '/') === false) {
        return build_webpage_route($projectId, '');
    }

    return build_webpage_route($projectId, substr($path, 0, strrpos($path, '/'))) . '/';
}

function get_webpage_public_file_path(array $file, array $project): string
{
    $relativePath = (string) ($file['relative_path'] ?? $file['original_filename'] ?? 'index.html');
    return project_webpage_public_path($relativePath, get_webpage_root_prefix(get_project_files($project)));
}

function get_webpage_root_prefix(array $files): string
{
    foreach ($files as $file) {
        $relativePath = strtolower((string) ($file['relative_path'] ?? ''));
        if ($relativePath === 'index.html') {
            return '';
        }

        if (substr($relativePath, -11) === '/index.html') {
            return substr($relativePath, 0, -11);
        }
    }

    return '';
}

function resolve_project_file_path(array $file): string
{
    $uploadDirectory = realpath(get_project_upload_directory());
    $filePath = realpath(get_project_upload_directory() . '/' . (string) $file['stored_filename']);

    if ($uploadDirectory === false || $filePath === false || strncmp($filePath, $uploadDirectory . DIRECTORY_SEPARATOR, strlen($uploadDirectory) + 1) !== 0) {
        render_project_error('Datei wurde nicht gefunden.', 404);
    }

    if (!is_file($filePath) || !is_readable($filePath)) {
        render_project_error('Datei wurde nicht gefunden.', 404);
    }

    return $filePath;
}

function webpage_content_type(array $file): string
{
    $path = strtolower((string) ($file['relative_path'] ?? $file['original_filename'] ?? ''));
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
        'txt' => 'text/plain; charset=utf-8',
        'md' => 'text/markdown; charset=utf-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];

    if (isset($map[$extension])) {
        return $map[$extension];
    }

    $mimeType = trim((string) ($file['mime_type'] ?? ''));
    return $mimeType !== '' ? $mimeType : 'application/octet-stream';
}

function inject_webpage_base(string $html, int $projectId, string $currentPath): string
{
    if (stripos($html, '<base ') !== false) {
        return rewrite_hosted_site_html_urls($html, $projectId);
    }

    $baseHref = htmlspecialchars(build_webpage_directory_route($projectId, $currentPath), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $baseTag = '<base href="' . $baseHref . '">';

    if (preg_match('/<head\b[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE) === 1) {
        $position = $match[0][1] + strlen($match[0][0]);
        return rewrite_hosted_site_html_urls(substr($html, 0, $position) . $baseTag . substr($html, $position), $projectId);
    }

    return rewrite_hosted_site_html_urls($baseTag . $html, $projectId);
}

function rewrite_hosted_site_absolute_url(string $url, int $projectId): string
{
    $url = trim($url);
    if ($url === '' || $url[0] !== '/' || strncmp($url, '//', 2) === 0) {
        return $url;
    }

    $sitePrefix = build_webpage_route($projectId, '');
    if (strncmp($url, $sitePrefix, strlen($sitePrefix)) === 0) {
        return $url;
    }

    return rtrim($sitePrefix, '/') . $url;
}

function rewrite_hosted_site_html_urls(string $html, int $projectId): string
{
    $html = preg_replace_callback(
        '/\b(href|src|action|poster)\s*=\s*(["\'])(\/(?!\/)[^"\']*)\2/i',
        static function (array $match) use ($projectId): string {
            return $match[1] . '=' . $match[2] . rewrite_hosted_site_absolute_url($match[3], $projectId) . $match[2];
        },
        $html
    ) ?? $html;

    return preg_replace_callback(
        '/\bsrcset\s*=\s*(["\'])([^"\']*)\1/i',
        static function (array $match) use ($projectId): string {
            $items = array_map(
                static function (string $item) use ($projectId): string {
                    $parts = preg_split('/\s+/', trim($item), 2);
                    if ($parts === false || $parts === [] || $parts[0] === '') {
                        return $item;
                    }

                    $parts[0] = rewrite_hosted_site_absolute_url($parts[0], $projectId);
                    return implode(' ', $parts);
                },
                explode(',', $match[2])
            );

            return 'srcset=' . $match[1] . implode(', ', $items) . $match[1];
        },
        $html
    ) ?? $html;
}

function rewrite_hosted_site_css_urls(string $css, int $projectId): string
{
    $css = preg_replace_callback(
        '/url\(\s*(["\']?)(\/(?!\/)[^"\')]+)\1\s*\)/i',
        static function (array $match) use ($projectId): string {
            return 'url(' . $match[1] . rewrite_hosted_site_absolute_url($match[2], $projectId) . $match[1] . ')';
        },
        $css
    ) ?? $css;

    return preg_replace_callback(
        '/@import\s+(["\'])(\/(?!\/)[^"\']+)\1/i',
        static function (array $match) use ($projectId): string {
            return '@import ' . $match[1] . rewrite_hosted_site_absolute_url($match[2], $projectId) . $match[1];
        },
        $css
    ) ?? $css;
}

function stream_webpage_file(array $file, array $project): void
{
    $filePath = resolve_project_file_path($file);
    $mimeType = webpage_content_type($file);

    header('Content-Type: ' . $mimeType);
    header('X-Content-Type-Options: nosniff');

    if (strncmp($mimeType, 'text/html', 9) === 0) {
        $html = (string) file_get_contents($filePath);
        $html = inject_webpage_base($html, (int) $project['id'], get_webpage_public_file_path($file, $project));
        header('Content-Length: ' . (string) strlen($html));
        echo $html;
        exit;
    }

    if (strncmp($mimeType, 'text/css', 8) === 0) {
        $css = rewrite_hosted_site_css_urls((string) file_get_contents($filePath), (int) $project['id']);
        header('Content-Length: ' . (string) strlen($css));
        echo $css;
        exit;
    }

    header('Content-Length: ' . (string) filesize($filePath));
    readfile($filePath);
    exit;
}

try {
    start_secure_session();
    $request = parse_webpage_request();
    $projectId = (int) $request['project_id'];
    if ($projectId <= 0) {
        render_project_error('Projekt wurde nicht gefunden.', 404);
    }

    $project = find_project_by_id($projectId);
    if ($project === null) {
        render_project_error('Projekt wurde nicht gefunden.', 404);
    }

    $user = current_user_record();
    if (!user_can_access_project($project, $user)) {
        render_project_error($user === null ? 'Bitte melde dich an, um dieses Projekt zu öffnen.' : 'Du hast keinen Zugriff auf dieses Projekt.', $user === null ? 401 : 403);
    }

    if ((string) $project['type'] === 'webpage') {
        if (!uses_webpage_path_route()) {
            header('Location: ' . build_webpage_route($projectId, (string) $request['path']), true, 302);
            exit;
        }

        $file = find_webpage_file($project, (string) $request['path']);
        if ($file === null) {
            render_project_error('Die Webpage-Datei wurde nicht gefunden.', 404);
        }
        stream_webpage_file($file, $project);
    }

    $title = htmlspecialchars((string) $project['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $files = get_project_files($project);
    $isFolder = count($files) > 1 || (string) ($project['upload_kind'] ?? 'single') === 'folder';
    $downloadUrl = 'download_project.php?id=' . (int) $project['id'];

    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . $title . '</title><style>body{font-family:Arial,sans-serif;margin:40px;color:#172033}.button{display:inline-block;padding:10px 16px;background:#155eef;color:#fff;text-decoration:none;border-radius:6px}li{margin:8px 0}code{background:#eef3f8;padding:2px 5px;border-radius:4px}</style></head><body>';
    echo '<h1>' . $title . '</h1>';
    echo '<p><a class="button" href="' . htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8') . '">' . ($isFolder ? 'Ordner als ZIP herunterladen' : 'Datei herunterladen') . '</a></p>';

    if ($files !== []) {
        echo '<h2>' . ($isFolder ? 'Dateien im Ordner' : 'Datei') . '</h2><ul>';
        foreach ($files as $file) {
            $fileName = htmlspecialchars((string) ($file['relative_path'] ?? $file['original_filename'] ?? 'Download'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $fileId = (int) ($file['id'] ?? 0);
            $fileUrl = 'download_project.php?id=' . (int) $project['id'] . '&file_id=' . $fileId;
            echo '<li><code>' . $fileName . '</code> <a href="' . htmlspecialchars($fileUrl, ENT_QUOTES, 'UTF-8') . '">einzeln herunterladen</a></li>';
        }
        echo '</ul>';
    }

    echo '</body></html>';
    exit;
} catch (Throwable $exception) {
    log_api_exception($exception);
    render_project_error('Projekt konnte nicht geladen werden.', 500);
}
