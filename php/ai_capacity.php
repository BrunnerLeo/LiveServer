<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

class AiCapacityBusyException extends RuntimeException
{
}

function ai_small_capacity_settings(): array
{
    $settings = get_ai_settings_config();
    $aiSmall = is_array($settings['aiSmall'] ?? null) ? $settings['aiSmall'] : [];

    return [
        'maxConcurrent' => min(max((int) ($aiSmall['maxConcurrent'] ?? 1), 1), 8),
        'highPriorityWaitSeconds' => min(max((int) ($aiSmall['highPriorityWaitSeconds'] ?? 180), 0), 600),
        'lowPriorityWaitSeconds' => min(max((int) ($aiSmall['lowPriorityWaitSeconds'] ?? 0), 0), 60),
    ];
}

function ai_small_runtime_dir(): string
{
    $path = project_root() . '/runtime/ai-small';
    if ((is_dir($path) || @mkdir($path, 0770, true)) && is_writable($path)) {
        return $path;
    }

    $fallback = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/liveserver-ai-small';
    if (!is_dir($fallback)) {
        @mkdir($fallback, 0770, true);
    }

    return $fallback;
}

function ai_small_acquire_capacity(string $priority): array
{
    $settings = ai_small_capacity_settings();
    $waitSeconds = $priority === 'low'
        ? (int) $settings['lowPriorityWaitSeconds']
        : (int) $settings['highPriorityWaitSeconds'];
    $deadline = microtime(true) + $waitSeconds;

    do {
        for ($slot = 0; $slot < (int) $settings['maxConcurrent']; $slot++) {
            $path = ai_small_runtime_dir() . "/slot-{$slot}.lock";
            $handle = fopen($path, 'c+');
            if ($handle === false) {
                continue;
            }

            if (flock($handle, LOCK_EX | LOCK_NB)) {
                ftruncate($handle, 0);
                fwrite($handle, json_encode([
                    'priority' => $priority,
                    'pid' => getmypid(),
                    'startedAt' => gmdate('c'),
                ], JSON_UNESCAPED_SLASHES) ?: '');

                return [$handle, $path];
            }

            fclose($handle);
        }

        if ($waitSeconds <= 0) {
            break;
        }

        usleep(250000);
    } while (microtime(true) < $deadline);

    throw new AiCapacityBusyException('AI small ist gerade mit priorisierten ARIS-, Zusammenfassen- oder Erklaeren-Anfragen belegt. Jenny wird nur gestartet, wenn Kapazitaet frei ist.');
}

function ai_small_release_capacity(array $lock): void
{
    $handle = $lock[0] ?? null;
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function with_ai_small_capacity(string $priority, callable $callback): mixed
{
    $lock = ai_small_acquire_capacity($priority);
    try {
        return $callback();
    } finally {
        ai_small_release_capacity($lock);
    }
}
