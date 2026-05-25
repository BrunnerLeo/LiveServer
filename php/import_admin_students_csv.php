<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

function admin_csv_delimiter(string $line): string
{
    $delimiters = [',', ';', "\t"];
    $best = ',';
    $bestCount = -1;
    foreach ($delimiters as $delimiter) {
        $count = substr_count($line, $delimiter);
        if ($count > $bestCount) {
            $best = $delimiter;
            $bestCount = $count;
        }
    }

    return $best;
}

function admin_csv_key(string $value): string
{
    $value = strtolower(trim($value));
    return preg_replace('/[^a-z0-9]/', '', $value) ?: '';
}

function admin_csv_header_map(array $row): array
{
    $aliases = [
        'username' => ['username', 'benutzername', 'user', 'login'],
        'password' => ['password', 'passwort', 'kennwort'],
        'realname' => ['realname', 'name', 'vollername', 'anzeigename', 'schuelername', 'schülername'],
    ];
    $map = [];
    foreach ($row as $index => $cell) {
        $key = admin_csv_key((string) $cell);
        foreach ($aliases as $target => $names) {
            if (in_array($key, array_map('admin_csv_key', $names), true)) {
                $map[$target] = $index;
            }
        }
    }

    return $map;
}

function admin_csv_row_value(array $row, array $map, string $key, int $fallbackIndex): string
{
    $index = array_key_exists($key, $map) ? (int) $map[$key] : $fallbackIndex;
    return trim((string) ($row[$index] ?? ''));
}

try {
    start_secure_session();
    require_post_request();
    require_csrf_token($_POST);
    require_admin_user();

    $upload = $_FILES['studentsCsv'] ?? null;
    if (!is_array($upload) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_response([
            'success' => false,
            'message' => 'Bitte wähle eine CSV-Datei aus.',
            'csrfToken' => ensure_csrf_token(),
        ], 400);
    }

    $tmpName = (string) ($upload['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        json_response([
            'success' => false,
            'message' => 'CSV-Datei konnte nicht gelesen werden.',
            'csrfToken' => ensure_csrf_token(),
        ], 400);
    }

    $firstLine = (string) (file($tmpName, FILE_IGNORE_NEW_LINES)[0] ?? '');
    $delimiter = admin_csv_delimiter($firstLine);
    $handle = fopen($tmpName, 'rb');
    if ($handle === false) {
        throw new RuntimeException('CSV-Datei konnte nicht geöffnet werden.');
    }

    $created = 0;
    $skipped = 0;
    $errors = [];
    $headerMap = [];
    $rowNumber = 0;

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        $rowNumber++;
        if ($row === [null] || implode('', array_map('trim', $row)) === '') {
            continue;
        }

        if ($rowNumber === 1) {
            $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) ($row[0] ?? '')) ?? (string) ($row[0] ?? '');
            $candidateMap = admin_csv_header_map($row);
            if (isset($candidateMap['username']) || isset($candidateMap['password'])) {
                $headerMap = $candidateMap;
                continue;
            }
        }

        $username = admin_csv_row_value($row, $headerMap, 'username', 0);
        $password = admin_csv_row_value($row, $headerMap, 'password', 1);
        $realname = admin_csv_row_value($row, $headerMap, 'realname', 2);
        if ($realname === '') {
            $realname = $username;
        }

        try {
            $realnameLength = function_exists('mb_strlen') ? mb_strlen($realname) : strlen($realname);
            if ($realname === '' || $realnameLength > 150) {
                throw new InvalidArgumentException('Name fehlt oder ist zu lang.');
            }
            if (preg_match('/^[a-zA-Z0-9._-]{3,100}$/', $username) !== 1 || strtolower($username) === 'admin') {
                throw new InvalidArgumentException('Benutzername ist ungültig.');
            }
            validate_student_password($password);
            if (find_user_by_username($username) !== null) {
                $skipped++;
                continue;
            }

            require_smb_supported_credentials($username, $password);
            provision_smb_user_credentials($username, $password);
            create_user($username, hash_plain_password($password), $realname, 'student');
            $created++;
        } catch (Throwable $exception) {
            $errors[] = 'Zeile ' . $rowNumber . ': ' . $exception->getMessage();
        }
    }

    fclose($handle);

    $accounts = list_student_users();
    json_response([
        'success' => true,
        'message' => 'CSV-Import abgeschlossen.',
        'csrfToken' => ensure_csrf_token(),
        'result' => [
            'created' => $created,
            'skipped' => $skipped,
            'failed' => count($errors),
            'errors' => array_slice($errors, 0, 20),
        ],
        'students' => $accounts,
        'accounts' => $accounts,
    ]);
} catch (Throwable $exception) {
    if (isset($handle) && is_resource($handle)) {
        fclose($handle);
    }
    api_exception_response($exception, 'CSV-Import konnte nicht verarbeitet werden.', 500);
}
