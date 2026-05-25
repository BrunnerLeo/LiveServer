<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $result = sync_all_smb_projects();
    echo sprintf(
        "[%s] SMB sync checked=%d failed=%d\n",
        date('c'),
        (int) $result['checked'],
        (int) $result['failed']
    );
    exit($result['failed'] > 0 ? 1 : 0);
} catch (Throwable $exception) {
    log_api_exception($exception);
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
