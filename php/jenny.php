<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/ai_capacity.php';

function jenny_substr(string $value, int $offset, int $length): string
{
    return function_exists('mb_substr') ? mb_substr($value, $offset, $length) : substr($value, $offset, $length);
}

function jenny_http_json(string $url, array $headers, array $payload, int $timeout = 180): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP-cURL ist nicht installiert.');
    }

    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) {
        throw new RuntimeException('Jenny-Anfrage konnte nicht serialisiert werden.');
    }

    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Jenny-Verbindung konnte nicht vorbereitet werden.');
    }

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
    ]);

    $responseBody = curl_exec($curl);
    $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if (!is_string($responseBody)) {
        throw new RuntimeException('Jenny-Anfrage fehlgeschlagen: ' . ($error !== '' ? $error : 'keine Antwort'));
    }

    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Jenny-Modell lieferte kein gültiges JSON.');
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        $message = $decoded['error']['message'] ?? $decoded['error']['type'] ?? 'Jenny-Modell hat die Anfrage abgelehnt.';
        throw new RuntimeException((string) $message);
    }

    return $decoded;
}

function jenny_require_teacher(array $user): void
{
    if (!is_teacher_user($user)) {
        json_response([
            'success' => false,
            'message' => 'Diese Jenny-Funktion ist nur für Lehrkräfte verfügbar.',
            'csrfToken' => ensure_csrf_token(),
        ], 403);
    }
}

function jenny_settings(): array
{
    $settings = get_ai_settings_config();
    $jenny = is_array($settings['jenny'] ?? null) ? $settings['jenny'] : [];
    $aiSmall = is_array($settings['aiSmall'] ?? null) ? $settings['aiSmall'] : [];
    $model = trim((string) ($jenny['model'] ?? 'jenny'));
    if ($model === '' || preg_match('/^[a-zA-Z0-9._:-]+$/', $model) !== 1) {
        throw new InvalidArgumentException('Bitte konfiguriere ein gültiges Jenny-Modell.');
    }

    return [
        'enabled' => (bool) ($jenny['enabled'] ?? true),
        'baseUrl' => normalize_ai_base_url((string) ($jenny['baseUrl'] ?? $aiSmall['baseUrl'] ?? 'http://localhost:8080/v1'), false),
        'model' => $model,
        'apiKey' => (string) ($jenny['apiKey'] ?? ''),
        'maxPromptChars' => min(max((int) ($jenny['maxPromptChars'] ?? 4000), 500), 12000),
        'maxTextChars' => min(max((int) ($jenny['maxTextChars'] ?? 12000), 1000), 30000),
    ];
}

function jenny_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS jenny_tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            teacher_id INTEGER NOT NULL,
            class_id INTEGER,
            topic TEXT NOT NULL,
            task_text TEXT NOT NULL,
            is_recommended INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'sent', 'archived')),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at TEXT,
            FOREIGN KEY (teacher_id) REFERENCES users (id) ON DELETE CASCADE
        )"
    );
    ensure_sqlite_column($pdo, 'jenny_tasks', 'class_id', 'INTEGER');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_jenny_tasks_teacher_id ON jenny_tasks (teacher_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_jenny_tasks_class_id ON jenny_tasks (class_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_jenny_tasks_status ON jenny_tasks (status)');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS jenny_submissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL,
            student_id INTEGER NOT NULL,
            text TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (task_id) REFERENCES jenny_tasks (id) ON DELETE CASCADE,
            FOREIGN KEY (student_id) REFERENCES users (id) ON DELETE CASCADE,
            UNIQUE (task_id, student_id)
        )"
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_jenny_submissions_task_id ON jenny_submissions (task_id)');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS jenny_evaluations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            submission_id INTEGER NOT NULL,
            teacher_id INTEGER,
            score INTEGER,
            feedback TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (submission_id) REFERENCES jenny_submissions (id) ON DELETE CASCADE,
            FOREIGN KEY (teacher_id) REFERENCES users (id) ON DELETE SET NULL
        )"
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_jenny_evaluations_submission_id ON jenny_evaluations (submission_id)');
}

function jenny_require_teacher_class(PDO $pdo, array $user, int $classId): array
{
    if ($classId <= 0) {
        throw new InvalidArgumentException('Bitte wähle eine Klasse aus.');
    }

    $statement = $pdo->prepare(
        "SELECT sc.*, u.username AS teacher_username, u.realname AS teacher_realname, COUNT(scm.user_id) AS member_count
         FROM school_classes sc
         INNER JOIN users u ON u.id = sc.teacher_id
         LEFT JOIN school_class_members scm ON scm.class_id = sc.id
         WHERE sc.id = :id AND sc.teacher_id = :teacher_id
         GROUP BY sc.id, sc.teacher_id, sc.name, sc.code, sc.created_at, sc.updated_at, u.username, u.realname
         LIMIT 1"
    );
    $statement->execute([
        ':id' => $classId,
        ':teacher_id' => (int) $user['id'],
    ]);
    $class = $statement->fetch();
    if (!is_array($class)) {
        throw new InvalidArgumentException('Bitte wähle eine gültige Klasse aus.');
    }

    return public_school_class($class);
}

function jenny_clean_text(string $value, int $maxLength): string
{
    $value = trim(preg_replace('/[ \t]+/u', ' ', $value) ?? $value);
    if ($value === '') {
        return '';
    }

    return jenny_substr($value, 0, $maxLength);
}

function jenny_json_from_answer(string $answer): array
{
    $answer = trim($answer);
    if (preg_match('/```(?:json)?\s*(.*?)\s*```/su', $answer, $matches) === 1) {
        $answer = trim((string) $matches[1]);
    }

    $decoded = json_decode($answer, true);
    return is_array($decoded) ? $decoded : [];
}

function jenny_call(string $systemPrompt, string $userPrompt, int $maxTokens = 1200): string
{
    $settings = jenny_settings();
    if (!$settings['enabled']) {
        throw new RuntimeException('Jenny ist in den KI-Einstellungen deaktiviert.');
    }

    $headers = [];
    if ((string) $settings['apiKey'] !== '') {
        $headers[] = 'Authorization: Bearer ' . (string) $settings['apiKey'];
    }

    $response = with_ai_small_capacity('low', static fn (): array => jenny_http_json(
        rtrim((string) $settings['baseUrl'], '/') . '/chat/completions',
        $headers,
        [
            'model' => (string) $settings['model'],
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => 0.15,
            'top_p' => 0.9,
            'repeat_penalty' => 1.12,
            'max_tokens' => $maxTokens,
            'stop' => ['<|im_end|>'],
        ],
        180
    ));

    return trim((string) ($response['choices'][0]['message']['content'] ?? ''));
}

function jenny_generate_tasks(PDO $pdo, array $user, string $topic, int $classId): array
{
    $settings = jenny_settings();
    $class = jenny_require_teacher_class($pdo, $user, $classId);
    $topic = jenny_clean_text($topic, (int) $settings['maxPromptChars']);
    if ($topic === '') {
        throw new InvalidArgumentException('Bitte gib ein Thema ein.');
    }

    $system = 'Du bist Jenny, eine deutschsprachige KI für Deutschunterricht. Antworte ausschließlich auf Deutsch und ausschließlich als JSON.';
    $className = (string) ($class['name'] ?? '');
    $prompt = "Klasse: {$className}\nThema: {$topic}\n\nErstelle genau 6 Aufgaben für den Deutschunterricht. Markiere genau 3 davon als beste Auswahl. JSON-Format: {\"aufgaben\":[{\"text\":\"...\",\"besteAuswahl\":true}]}";
    $answer = jenny_call($system, $prompt);
    $decoded = jenny_json_from_answer($answer);
    $items = is_array($decoded['aufgaben'] ?? null) ? $decoded['aufgaben'] : [];

    if (count($items) < 6) {
        throw new RuntimeException('Jenny hat keine vollständige Aufgabenliste geliefert.');
    }

    $pdo->beginTransaction();
    try {
        $created = [];
        $recommended = 0;
        foreach (array_slice($items, 0, 6) as $index => $item) {
            $text = jenny_clean_text((string) ($item['text'] ?? ''), 2400);
            if ($text === '') {
                continue;
            }

            $isRecommended = !empty($item['besteAuswahl']) && $recommended < 3;
            if ($isRecommended) {
                $recommended++;
            }

            $statement = $pdo->prepare(
                'INSERT INTO jenny_tasks (teacher_id, class_id, topic, task_text, is_recommended) VALUES (:teacher_id, :class_id, :topic, :task_text, :is_recommended)'
            );
            $statement->execute([
                ':teacher_id' => (int) $user['id'],
                ':class_id' => (int) $class['id'],
                ':topic' => $topic,
                ':task_text' => $text,
                ':is_recommended' => $isRecommended ? 1 : 0,
            ]);
            $created[] = [
                'id' => (int) $pdo->lastInsertId(),
                'topic' => $topic,
                'classId' => (int) $class['id'],
                'className' => (string) $class['name'],
                'taskText' => $text,
                'isRecommended' => $isRecommended,
                'status' => 'draft',
            ];
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    return $created;
}

function jenny_public_task(array $row): array
{
    $classId = $row['class_id'] ?? null;

    return [
        'id' => (int) $row['id'],
        'topic' => (string) $row['topic'],
        'classId' => $classId === null ? 0 : (int) $classId,
        'className' => $classId === null ? 'Alle Schüler' : (string) ($row['class_name'] ?? ''),
        'classCode' => (string) ($row['class_code'] ?? ''),
        'taskText' => (string) $row['task_text'],
        'isRecommended' => (int) ($row['is_recommended'] ?? 0) === 1,
        'status' => (string) $row['status'],
        'createdAt' => (string) $row['created_at'],
        'sentAt' => (string) ($row['sent_at'] ?? ''),
    ];
}

function jenny_list_teacher(PDO $pdo, array $user): array
{
    $statement = $pdo->prepare(
        "SELECT t.*, sc.name AS class_name, sc.code AS class_code
         FROM jenny_tasks t
         LEFT JOIN school_classes sc ON sc.id = t.class_id
         WHERE t.teacher_id = :teacher_id
         ORDER BY t.id DESC
         LIMIT 60"
    );
    $statement->execute([':teacher_id' => (int) $user['id']]);
    $tasks = array_map('jenny_public_task', $statement->fetchAll());

    $submissionStatement = $pdo->prepare(
        "SELECT s.id, s.task_id, s.text, s.created_at, u.username, u.realname, sc.name AS class_name,
                e.score, e.feedback, e.created_at AS evaluated_at
         FROM jenny_submissions s
         JOIN jenny_tasks t ON t.id = s.task_id
         JOIN users u ON u.id = s.student_id
         LEFT JOIN school_classes sc ON sc.id = t.class_id
         LEFT JOIN jenny_evaluations e ON e.id = (
            SELECT id FROM jenny_evaluations WHERE submission_id = s.id ORDER BY id DESC LIMIT 1
         )
         WHERE t.teacher_id = :teacher_id
         ORDER BY s.updated_at DESC"
    );
    $submissionStatement->execute([':teacher_id' => (int) $user['id']]);

    return [
        'classes' => list_teacher_school_classes((int) $user['id']),
        'tasks' => $tasks,
        'submissions' => $submissionStatement->fetchAll(),
    ];
}

function jenny_list_student(PDO $pdo, array $user): array
{
    $statement = $pdo->prepare(
        "SELECT t.*, sc.name AS class_name, sc.code AS class_code,
                s.id AS submission_id, s.text AS submission_text, e.score, e.feedback
         FROM jenny_tasks t
         LEFT JOIN school_classes sc ON sc.id = t.class_id
         LEFT JOIN jenny_submissions s ON s.task_id = t.id AND s.student_id = :student_id
         LEFT JOIN jenny_evaluations e ON e.id = (
            SELECT id FROM jenny_evaluations WHERE submission_id = s.id ORDER BY id DESC LIMIT 1
         )
         WHERE t.status = 'sent'
           AND (
                t.class_id IS NULL
                OR
                EXISTS (
                    SELECT 1 FROM school_class_members scm
                    WHERE scm.class_id = t.class_id AND scm.user_id = :student_id
                )
                OR t.teacher_id = :student_id
           )
         ORDER BY t.sent_at DESC, t.id DESC"
    );
    $statement->execute([':student_id' => (int) $user['id']]);
    return $statement->fetchAll();
}

function jenny_send_task(PDO $pdo, array $user, int $taskId): void
{
    $statement = $pdo->prepare("UPDATE jenny_tasks SET status = 'sent', sent_at = CURRENT_TIMESTAMP WHERE id = :id AND teacher_id = :teacher_id");
    $statement->execute([':id' => $taskId, ':teacher_id' => (int) $user['id']]);
    if ($statement->rowCount() < 1) {
        throw new InvalidArgumentException('Aufgabe wurde nicht gefunden.');
    }
}

function jenny_submit(PDO $pdo, array $user, int $taskId, string $text): array
{
    $settings = jenny_settings();
    $text = jenny_clean_text($text, (int) $settings['maxTextChars']);
    if ($text === '') {
        throw new InvalidArgumentException('Bitte gib deinen Text ein.');
    }

    $taskStatement = $pdo->prepare(
        "SELECT id FROM jenny_tasks
         WHERE id = :id
           AND status = 'sent'
           AND (
                class_id IS NULL
                OR
                EXISTS (
                    SELECT 1 FROM school_class_members scm
                    WHERE scm.class_id = jenny_tasks.class_id AND scm.user_id = :student_id
                )
                OR teacher_id = :student_id
           )"
    );
    $taskStatement->execute([
        ':id' => $taskId,
        ':student_id' => (int) $user['id'],
    ]);
    if (!$taskStatement->fetch()) {
        throw new InvalidArgumentException('Diese Aufgabe ist nicht verfügbar.');
    }

    $statement = $pdo->prepare(
        "INSERT INTO jenny_submissions (task_id, student_id, text, updated_at)
         VALUES (:task_id, :student_id, :text, CURRENT_TIMESTAMP)
         ON CONFLICT(task_id, student_id) DO UPDATE SET text = excluded.text, updated_at = CURRENT_TIMESTAMP"
    );
    $statement->execute([
        ':task_id' => $taskId,
        ':student_id' => (int) $user['id'],
        ':text' => $text,
    ]);

    return ['taskId' => $taskId, 'text' => $text];
}

function jenny_evaluate(PDO $pdo, array $user, int $submissionId): array
{
    jenny_require_teacher($user);
    $statement = $pdo->prepare(
        "SELECT s.id, s.text, t.task_text, t.topic
         FROM jenny_submissions s
         JOIN jenny_tasks t ON t.id = s.task_id
         WHERE s.id = :id AND t.teacher_id = :teacher_id"
    );
    $statement->execute([':id' => $submissionId, ':teacher_id' => (int) $user['id']]);
    $submission = $statement->fetch();
    if (!is_array($submission)) {
        throw new InvalidArgumentException('Abgabe wurde nicht gefunden.');
    }

    $system = 'Du bist Jenny, eine deutschsprachige KI für Deutschunterricht. Bewerte fair, knapp und konstruktiv. Antworte nur als JSON.';
    $prompt = "Thema: {$submission['topic']}\nAufgabe: {$submission['task_text']}\nSchülertext:\n{$submission['text']}\n\nBewerte mit 0 bis 100 Punkten und erstelle konkretes Feedback. JSON: {\"punkte\":75,\"feedback\":\"...\"}";
    $answer = jenny_call($system, $prompt, 900);
    $decoded = jenny_json_from_answer($answer);
    $score = max(0, min(100, (int) ($decoded['punkte'] ?? 0)));
    $feedback = jenny_clean_text((string) ($decoded['feedback'] ?? $answer), 3000);

    $insert = $pdo->prepare('INSERT INTO jenny_evaluations (submission_id, teacher_id, score, feedback) VALUES (:submission_id, :teacher_id, :score, :feedback)');
    $insert->execute([
        ':submission_id' => $submissionId,
        ':teacher_id' => (int) $user['id'],
        ':score' => $score,
        ':feedback' => $feedback,
    ]);

    return ['submissionId' => $submissionId, 'score' => $score, 'feedback' => $feedback];
}

try {
    start_secure_session();
    $pdo = get_db_connection();
    jenny_ensure_schema($pdo);
    $user = require_logged_in_user();
    $action = (string) ($_GET['action'] ?? '');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $input = get_json_input();
        require_csrf_token($input);
        $action = (string) ($input['action'] ?? $action);
    }

    if ($action === 'bootstrap') {
        $isTeacher = is_teacher_user($user);
        $isStudent = is_student_user($user);
        json_response([
            'success' => true,
            'csrfToken' => ensure_csrf_token(),
            'mode' => $isTeacher ? 'teacher' : 'student',
            'capabilities' => [
                'teacher' => $isTeacher,
                'student' => $isStudent,
            ],
            'teacher' => $isTeacher ? jenny_list_teacher($pdo, $user) : null,
            'student' => $isStudent ? jenny_list_student($pdo, $user) : null,
        ]);
    }

    require_post_request();

    if ($action === 'generate') {
        jenny_require_teacher($user);
        json_response([
            'success' => true,
            'csrfToken' => ensure_csrf_token(),
            'tasks' => jenny_generate_tasks($pdo, $user, (string) ($input['topic'] ?? ''), (int) ($input['classId'] ?? 0)),
        ]);
    }

    if ($action === 'send') {
        jenny_require_teacher($user);
        jenny_send_task($pdo, $user, (int) ($input['taskId'] ?? 0));
        json_response(['success' => true, 'csrfToken' => ensure_csrf_token()]);
    }

    if ($action === 'submit') {
        json_response([
            'success' => true,
            'csrfToken' => ensure_csrf_token(),
            'submission' => jenny_submit($pdo, $user, (int) ($input['taskId'] ?? 0), (string) ($input['text'] ?? '')),
        ]);
    }

    if ($action === 'evaluate') {
        json_response([
            'success' => true,
            'csrfToken' => ensure_csrf_token(),
            'evaluation' => jenny_evaluate($pdo, $user, (int) ($input['submissionId'] ?? 0)),
        ]);
    }

    json_response([
        'success' => false,
        'message' => 'Unbekannte Jenny-Aktion.',
        'csrfToken' => ensure_csrf_token(),
    ], 400);
} catch (InvalidArgumentException $exception) {
    json_response([
        'success' => false,
        'message' => $exception->getMessage(),
        'csrfToken' => ensure_csrf_token(),
    ], 400);
} catch (AiCapacityBusyException $exception) {
    json_response([
        'success' => false,
        'message' => $exception->getMessage(),
        'csrfToken' => ensure_csrf_token(),
    ], 429);
} catch (Throwable $exception) {
    api_exception_response($exception, 'Jenny-Anfrage konnte nicht verarbeitet werden.', 500);
}
