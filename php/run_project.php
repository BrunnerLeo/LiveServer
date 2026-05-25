<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

function runtime_error_response(string $message, int $statusCode = 400): void
{
    json_response([
        'success' => false,
        'message' => $message,
        'csrfToken' => ensure_csrf_token(),
    ], $statusCode);
}

function runtime_safe_relative_path(string $path): string
{
    return normalize_project_folder_path($path);
}

function runtime_binary(string $settingsKey, string $fallback): string
{
    $settings = get_runtime_settings();
    $configured = trim((string) ($settings[$settingsKey] ?? ''));
    $candidate = $configured !== '' ? $configured : $fallback;

    if (strpos($candidate, '/') !== false) {
        if (is_executable($candidate)) {
            return $candidate;
        }

        throw new RuntimeException('Runtime-Binary ist nicht ausführbar: ' . $candidate);
    }

    $process = proc_open(
        ['/bin/sh', '-c', 'command -v ' . escapeshellarg($candidate)],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Runtime-Binary konnte nicht gesucht werden.');
    }

    fclose($pipes[0]);
    $path = trim((string) stream_get_contents($pipes[1]));
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0 || $path === '' || !is_executable($path)) {
        throw new RuntimeException('Runtime-Binary wurde nicht gefunden: ' . $candidate);
    }

    return $path;
}

function runtime_remove_directory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $items = scandir($directory);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path) && !is_link($path)) {
            runtime_remove_directory($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($directory);
}

function runtime_resolve_uploaded_file(array $file): string
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

function runtime_prepare_workspace(array $project): array
{
    ensure_runtime_workspace_directory();
    $workspaceRoot = realpath(get_runtime_workspace_directory());
    if ($workspaceRoot === false) {
        throw new RuntimeException('Runtime-Arbeitsordner wurde nicht gefunden.');
    }

    $jobDirectory = $workspaceRoot . DIRECTORY_SEPARATOR . 'job-' . (int) $project['id'] . '-' . bin2hex(random_bytes(8));
    $sourceDirectory = $jobDirectory . DIRECTORY_SEPARATOR . 'src';
    if (!mkdir($sourceDirectory, 0770, true) && !is_dir($sourceDirectory)) {
        throw new RuntimeException('Runtime-Job konnte nicht vorbereitet werden.');
    }

    $maxSourceBytes = get_runtime_max_source_bytes();
    $totalBytes = 0;
    $writtenFiles = [];

    foreach (get_project_files($project) as $file) {
        $relativePath = runtime_safe_relative_path((string) ($file['relative_path'] ?? $file['original_filename'] ?? ''));
        if ($relativePath === '') {
            continue;
        }

        $sourcePath = runtime_resolve_uploaded_file($file);
        $fileSize = filesize($sourcePath);
        $fileSize = $fileSize === false ? 0 : (int) $fileSize;
        $totalBytes += $fileSize;
        if ($fileSize < 0 || $totalBytes > $maxSourceBytes) {
            throw new RuntimeException('Projektquellen sind für einen Runtime-Lauf zu groß.');
        }

        $targetPath = $sourceDirectory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $targetDirectory = dirname($targetPath);
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0770, true) && !is_dir($targetDirectory)) {
            throw new RuntimeException('Runtime-Dateiordner konnte nicht erstellt werden.');
        }

        if (!copy($sourcePath, $targetPath)) {
            throw new RuntimeException('Runtime-Datei konnte nicht kopiert werden.');
        }

        @chmod($targetPath, 0660);
        $writtenFiles[$relativePath] = $targetPath;
    }

    return [
        'jobDirectory' => $jobDirectory,
        'sourceDirectory' => $sourceDirectory,
        'files' => $writtenFiles,
    ];
}

function runtime_shell_command(array $command, int $timeoutSeconds, ?int $memoryKb = null): string
{
    $timeoutBinary = runtime_binary('timeoutBinary', 'timeout');
    $safeCommand = implode(' ', array_map('escapeshellarg', $command));
    $cpuSeconds = max(1, $timeoutSeconds + 1);
    $memoryKb = $memoryKb ?? get_runtime_max_memory_kb();
    $fileKb = 32768;

    return 'ulimit -t ' . $cpuSeconds
        . '; ulimit -v ' . $memoryKb
        . '; ulimit -f ' . $fileKb
        . '; ulimit -u ' . get_runtime_max_tasks()
        . '; exec ' . escapeshellarg($timeoutBinary)
        . ' --kill-after=2s ' . escapeshellarg($timeoutSeconds . 's')
        . ' ' . $safeCommand;
}

function runtime_sandbox_command(string $innerShellCommand, string $workspaceDirectory, bool $unshareNetwork = true): array
{
    if (!is_runtime_sandbox_enabled()) {
        return ['/bin/bash', '-lc', $innerShellCommand];
    }

    $bubblewrap = runtime_binary('bubblewrapBinary', 'bwrap');
    $arguments = [
        $bubblewrap,
        '--die-with-parent',
        '--new-session',
        '--unshare-pid',
        '--unshare-ipc',
        '--unshare-uts',
        '--unshare-cgroup',
        '--proc', '/proc',
        '--dev', '/dev',
        '--tmpfs', '/tmp',
        '--tmpfs', '/run',
        '--bind', $workspaceDirectory, '/workspace',
        '--chdir', '/workspace',
        '--setenv', 'HOME', '/tmp',
        '--setenv', 'TMPDIR', '/tmp',
        '--setenv', 'PATH', '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
    ];

    if ($unshareNetwork) {
        $arguments[] = '--unshare-net';
    }

    foreach (get_runtime_sandbox_readonly_paths() as $path) {
        if (is_dir($path) || is_file($path)) {
            $arguments[] = '--ro-bind';
            $arguments[] = $path;
            $arguments[] = $path;
        }
    }

    $arguments[] = '--';
    $arguments[] = '/bin/bash';
    $arguments[] = '-lc';
    $arguments[] = $innerShellCommand;

    return $arguments;
}

function runtime_append_limited(string &$target, string $chunk, int $limit, bool &$truncated): void
{
    if ($chunk === '' || strlen($target) >= $limit) {
        if ($chunk !== '') {
            $truncated = true;
        }
        return;
    }

    $remaining = $limit - strlen($target);
    if (strlen($chunk) > $remaining) {
        $target .= substr($chunk, 0, $remaining);
        $truncated = true;
        return;
    }

    $target .= $chunk;
}

function runtime_execute(array $command, string $cwd, string $stdin = '', ?int $memoryKb = null): array
{
    $result = runtime_execute_with_sandbox_mode($command, $cwd, $stdin, true, $memoryKb);
    if (
        $result['exitCode'] !== 0
        && is_runtime_sandbox_enabled()
        && str_contains($result['stderr'], 'Failed RTM_NEWADDR')
    ) {
        $fallback = runtime_execute_with_sandbox_mode($command, $cwd, $stdin, false, $memoryKb);
        $fallback['stderr'] = trim(
            "Hinweis: Netzwerk-Isolation wird von diesem Host nicht unterstuetzt; Runtime lief ohne --unshare-net.\n"
            . $fallback['stderr']
        );
        return $fallback;
    }

    return $result;
}

function runtime_execute_with_sandbox_mode(array $command, string $cwd, string $stdin = '', bool $unshareNetwork = true, ?int $memoryKb = null): array
{
    $timeoutSeconds = get_runtime_timeout_seconds();
    $maxOutputBytes = get_runtime_max_output_bytes();
    $startedAt = microtime(true);
    $truncated = false;
    $timedOut = false;
    $stdout = '';
    $stderr = '';

    $process = proc_open(
        runtime_sandbox_command(runtime_shell_command($command, $timeoutSeconds, $memoryKb), $cwd, $unshareNetwork),
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $cwd
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Runtime-Prozess konnte nicht gestartet werden.');
    }

    $stdinLimit = get_runtime_max_input_bytes();
    fwrite($pipes[0], substr($stdin, 0, $stdinLimit));
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $exitCode = null;
    while (true) {
        foreach ([1 => 'stdout', 2 => 'stderr'] as $index => $streamName) {
            while (!feof($pipes[$index])) {
                $chunk = fread($pipes[$index], 8192);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                if ($streamName === 'stdout') {
                    runtime_append_limited($stdout, $chunk, $maxOutputBytes, $truncated);
                } else {
                    runtime_append_limited($stderr, $chunk, $maxOutputBytes, $truncated);
                }
            }
        }

        $status = proc_get_status($process);
        if (!$status['running']) {
            $exitCode = (int) $status['exitcode'];
            break;
        }

        if ((microtime(true) - $startedAt) > ($timeoutSeconds + 3)) {
            $timedOut = true;
            proc_terminate($process);
            break;
        }

        usleep(20000);
    }

    foreach ([1, 2] as $index) {
        while (!feof($pipes[$index])) {
            $chunk = fread($pipes[$index], 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            if ($index === 1) {
                runtime_append_limited($stdout, $chunk, $maxOutputBytes, $truncated);
            } else {
                runtime_append_limited($stderr, $chunk, $maxOutputBytes, $truncated);
            }
        }
        fclose($pipes[$index]);
    }

    $closedExitCode = proc_close($process);
    if ($exitCode === null || $exitCode < 0) {
        $exitCode = $closedExitCode;
    }

    if ($exitCode === 124 || $exitCode === 137 || $exitCode === 143) {
        $timedOut = true;
    }

    return [
        'command' => implode(' ', array_map(static fn (string $part): string => basename($part) === $part ? $part : basename($part), $command)),
        'exitCode' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'timedOut' => $timedOut,
        'truncated' => $truncated,
        'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
    ];
}

function runtime_java_main_class(string $entryFile, string $entryPath): string
{
    $className = pathinfo($entryFile, PATHINFO_FILENAME);
    $content = is_file($entryPath) ? (string) file_get_contents($entryPath) : '';

    if (preg_match('/^\s*package\s+([a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)*)\s*;/m', $content, $match) === 1) {
        return $match[1] . '.' . $className;
    }

    return $className;
}

function runtime_source_files(array $files, string $extension): array
{
    $matches = [];
    foreach ($files as $relativePath => $absolutePath) {
        if (strtolower(pathinfo((string) $relativePath, PATHINFO_EXTENSION)) === $extension) {
            $matches[] = (string) $relativePath;
        }
    }

    sort($matches, SORT_NATURAL | SORT_FLAG_CASE);
    return $matches;
}

function runtime_run_java(array $workspace, string $entryFile, string $stdin): array
{
    $javaFiles = runtime_source_files($workspace['files'], 'java');
    if ($javaFiles === []) {
        throw new RuntimeException('Keine .java Dateien im Projekt gefunden.');
    }

    if (!isset($workspace['files'][$entryFile])) {
        throw new RuntimeException('Entry-Datei wurde nicht gefunden: ' . $entryFile);
    }

    $buildDirectory = $workspace['sourceDirectory'] . DIRECTORY_SEPARATOR . 'build';
    if (!mkdir($buildDirectory, 0770, true) && !is_dir($buildDirectory)) {
        throw new RuntimeException('Java-Buildordner konnte nicht erstellt werden.');
    }

    $javac = runtime_binary('javaCompiler', 'javac');
    $java = runtime_binary('javaBinary', 'java');
    $javaHeap = get_runtime_java_max_heap_mb() . 'm';
    $javaMemoryKb = get_runtime_java_max_memory_kb();
    $javaVmOptions = [
        '-Xms16m',
        '-Xmx' . $javaHeap,
        '-XX:MaxMetaspaceSize=64m',
        '-XX:ReservedCodeCacheSize=16m',
        '-XX:CompressedClassSpaceSize=32m',
    ];

    $compileCommand = [$javac];
    foreach ($javaVmOptions as $option) {
        $compileCommand[] = '-J' . $option;
    }
    array_push($compileCommand, '-encoding', 'UTF-8', '-d', 'build');
    foreach ($javaFiles as $javaFile) {
        $compileCommand[] = $javaFile;
    }

    $compile = runtime_execute($compileCommand, $workspace['sourceDirectory'], '', $javaMemoryKb);
    if ((int) $compile['exitCode'] !== 0 || $compile['timedOut']) {
        return [
            'status' => $compile['timedOut'] ? 'timeout' : 'compile_error',
            'entryFile' => $entryFile,
            'compile' => $compile,
            'run' => null,
        ];
    }

    $mainClass = runtime_java_main_class($entryFile, $workspace['files'][$entryFile]);
    $runCommand = array_merge([$java], $javaVmOptions, ['-cp', 'build', $mainClass]);
    $run = runtime_execute($runCommand, $workspace['sourceDirectory'], $stdin, $javaMemoryKb);

    return [
        'status' => ((int) $run['exitCode'] === 0 && !$run['timedOut']) ? 'ok' : ($run['timedOut'] ? 'timeout' : 'runtime_error'),
        'entryFile' => $entryFile,
        'compile' => $compile,
        'run' => $run,
    ];
}

function runtime_run_c(array $workspace, string $entryFile, string $stdin): array
{
    $cFiles = runtime_source_files($workspace['files'], 'c');
    if ($cFiles === []) {
        throw new RuntimeException('Keine .c Dateien im Projekt gefunden.');
    }

    if (!isset($workspace['files'][$entryFile])) {
        throw new RuntimeException('Entry-Datei wurde nicht gefunden: ' . $entryFile);
    }

    $gcc = runtime_binary('cCompiler', 'gcc');
    $compileCommand = [$gcc, '-std=c17', '-Wall', '-Wextra', '-O0', '-o', 'program'];
    foreach ($cFiles as $cFile) {
        $compileCommand[] = $cFile;
    }

    $compile = runtime_execute($compileCommand, $workspace['sourceDirectory']);
    if ((int) $compile['exitCode'] !== 0 || $compile['timedOut']) {
        return [
            'status' => $compile['timedOut'] ? 'timeout' : 'compile_error',
            'entryFile' => $entryFile,
            'compile' => $compile,
            'run' => null,
        ];
    }

    $run = runtime_execute(['./program'], $workspace['sourceDirectory'], $stdin);

    return [
        'status' => ((int) $run['exitCode'] === 0 && !$run['timedOut']) ? 'ok' : ($run['timedOut'] ? 'timeout' : 'runtime_error'),
        'entryFile' => $entryFile,
        'compile' => $compile,
        'run' => $run,
    ];
}

try {
    start_secure_session();
    require_post_request();
    $input = get_json_input();
    require_csrf_token($input);
    $user = require_logged_in_user();

    if (!are_project_runtimes_enabled()) {
        runtime_error_response('Java/C Runtime ist in der Konfiguration deaktiviert.', 403);
    }

    $projectId = (int) ($input['id'] ?? 0);
    if ($projectId <= 0) {
        runtime_error_response('Projekt wurde nicht gefunden.', 404);
    }

    $project = require_project_editor_access($projectId, $user);
    $type = (string) $project['type'];
    if (!is_runtime_project_type($type)) {
        runtime_error_response('Nur Java- und C-Projekte können ausgeführt werden.', 400);
    }

    $runtimeConfig = project_runtime_config($project);
    $entryFile = normalize_runtime_entry_file($type, (string) ($input['entryFile'] ?? $runtimeConfig['entryFile'] ?? default_runtime_entry_file($type)));
    $stdin = str_replace("\r\n", "\n", (string) ($input['stdin'] ?? ''));
    $workspace = runtime_prepare_workspace($project);

    try {
        $result = $type === 'java'
            ? runtime_run_java($workspace, $entryFile, $stdin)
            : runtime_run_c($workspace, $entryFile, $stdin);
    } finally {
        runtime_remove_directory((string) $workspace['jobDirectory']);
    }

    json_response([
        'success' => true,
        'projectId' => $projectId,
        'type' => $type,
        'result' => $result,
        'csrfToken' => ensure_csrf_token(),
    ]);
} catch (Throwable $exception) {
    api_exception_response($exception, 'Projekt konnte nicht ausgeführt werden.', 500);
}
