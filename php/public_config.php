<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

try {
    $settings = load_settings();

    json_response([
        'success' => true,
        'version' => (string) ($settings['version'] ?? ''),
        'school' => [
            'name' => (string) (($settings['school'] ?? [])['name'] ?? 'Liveserver'),
            'logo' => (string) (($settings['school'] ?? [])['logo'] ?? ''),
        ],
        'theme' => is_array($settings['theme'] ?? null) ? $settings['theme'] : [],
        'texts' => is_array($settings['texts'] ?? null) ? $settings['texts'] : [],
        'registration' => is_array($settings['registration'] ?? null) ? $settings['registration'] : [],
    ]);
} catch (Throwable $exception) {
    api_exception_response($exception, 'Öffentliche Konfiguration konnte nicht geladen werden.', 500);
}
