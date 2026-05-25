<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/ai_capacity.php';

function ai_text_limit(): int
{
    $settings = get_ai_settings_config();
    return min(max((int) ($settings['maxContextChars'] ?? 24000), 4000), 80000);
}

function ai_prompt_limit(): int
{
    $settings = get_ai_settings_config();
    return min(max((int) ($settings['maxPromptChars'] ?? 2000), 20), 8000);
}

function ai_substr(string $value, int $offset, int $length): string
{
    return function_exists('mb_substr') ? mb_substr($value, $offset, $length) : substr($value, $offset, $length);
}

function ai_strcut(string $value, int $length): string
{
    if ($length <= 0) {
        return '';
    }

    return function_exists('mb_strcut') ? mb_strcut($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
}

function ai_local_context_limit(): int
{
    return min(ai_text_limit(), 4200);
}

function ai_context_language(string $path): string
{
    $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    return match ($extension) {
        'c', 'h' => 'c',
        'java' => 'java',
        'html' => 'html',
        'css' => 'css',
        'js' => 'javascript',
        'json' => 'json',
        default => 'text',
    };
}

function ai_number_lines(string $content): string
{
    $lines = preg_split('/\R/u', $content);
    if (!is_array($lines)) {
        $lines = explode("\n", $content);
    }

    $numbered = [];
    foreach ($lines as $index => $line) {
        $numbered[] = str_pad((string) ($index + 1), 4, ' ', STR_PAD_LEFT) . ' | ' . $line;
    }

    return implode("\n", $numbered);
}

function ai_http_json(string $url, array $headers, array $payload, int $timeout = 35): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP-cURL ist nicht installiert.');
    }

    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($body)) {
        throw new RuntimeException('KI-Anfrage konnte nicht serialisiert werden: ' . json_last_error_msg());
    }

    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('KI-Verbindung konnte nicht vorbereitet werden.');
    }

    $requestHeaders = array_merge(['Content-Type: application/json'], $headers);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
    ]);

    $responseBody = curl_exec($curl);
    $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if (!is_string($responseBody)) {
        throw new RuntimeException('KI-Anfrage fehlgeschlagen: ' . ($error !== '' ? $error : 'keine Antwort'));
    }

    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('KI-Anbieter lieferte kein gültiges JSON.');
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        $message = $decoded['error']['message'] ?? $decoded['error']['type'] ?? 'KI-Anbieter hat die Anfrage abgelehnt.';
        throw new RuntimeException((string) $message);
    }

    return $decoded;
}

function ai_collect_context(array $files, string $activeFile, ?int $maxChars = null, bool $numberLines = true): string
{
    $limit = $maxChars === null ? ai_text_limit() : min(ai_text_limit(), max(1200, $maxChars));
    $used = 0;
    $parts = [];

    if ($activeFile !== '' && isset($files[$activeFile])) {
        $ordered = [$activeFile => $files[$activeFile]] + $files;
    } else {
        $ordered = $files;
    }

    foreach ($ordered as $path => $content) {
        $path = normalize_project_folder_path((string) $path);
        if ($path === '' || !is_string($content)) {
            continue;
        }

        $remaining = $limit - $used;
        if ($remaining <= 160) {
            break;
        }

        $language = ai_context_language($path);
        $header = "Datei: {$path}\n```{$language}\n";
        $footer = "\n```";
        $available = $remaining - strlen($header) - strlen($footer);
        if ($available <= 80) {
            break;
        }

        $snippet = ai_strcut(str_replace("\0", '', $content), $available);
        $body = $numberLines ? ai_number_lines($snippet) : $snippet;
        if (strlen($body) > $available) {
            $body = $numberLines
                ? ai_number_lines(ai_strcut($snippet, (int) floor($available * 0.65)))
                : ai_strcut($body, $available);
        }
        $body = rtrim(ai_strcut($body, $available));
        if ($body === '') {
            continue;
        }

        $part = $header . $body . $footer;
        $parts[] = $part;
        $used += strlen($part) + 2;
    }

    return implode("\n\n", $parts);
}

function ai_system_prompt(array $project, string $route = '', string $action = '', string $runtimeOutput = ''): string
{
    $type = (string) ($project['type'] ?? 'webpage');
    if ($route === 'local_coder') {
        $runtimeLower = strtolower($runtimeOutput);
        if ($action === 'find_errors' && (str_contains($runtimeLower, 'compile-fehler') || str_contains($runtimeLower, 'error:'))) {
            return "You are a compiler diagnostic formatter for Webpage, C and Java projects. Output German only. "
                . "The compiler output is the source of truth. Explain the exact diagnostic and the concrete fix. "
                . "Format max 3 short lines: Fehler, Ursache, Korrektur. No extra sections, no repetition, no long intro. Projekt-Typ: {$type}.";
        }

        $task = match ($action) {
            'summary' => 'Summarize the project.',
            'explain' => 'Explain the active file.',
            'find_errors' => 'Find only directly provable errors.',
            default => 'Answer the code question.',
        };
        $errorRule = $action === 'find_errors'
            ? "If there is no compiler error and no directly visible code issue, start exactly: Keine offensichtlichen Fehler gefunden. "
            : "Do not perform unsolicited error analysis unless the action asks for it. ";

        return "You are the strict local qwen2.5-coder assistant in the Liveserver editor for Webpage, C and Java projects. {$task} "
            . "Answer in German only. Use only the provided source code and compiler/runtime output. "
            . "For summary and explanation actions, do not output full source code or code fences. "
            . "Mention only files, links, images, scripts, stylesheets, functions and dependencies that are directly visible in the provided context. "
            . "If compiler/runtime output is provided and contains errors, explain those exact diagnostics first. "
            . $errorRule
            . "Do not invent resources, missing includes or problems. Max 5 short bullets, no repetition, no long intro. Projekt-Typ: {$type}.";
    }

    return "Du bist ein Coding-Assistent im Liveserver-Editor. Antworte auf Deutsch, konkret und knapp. "
        . "Projekt-Typ: {$type}. Gib bei Codeänderungen klare Vorschläge, aber überschreibe keine Dateien automatisch.";
}

function aris_system_prompt_path(): string
{
    return dirname(__DIR__) . '/liveserver-ki/prompts/aris-system.md';
}

function append_aris_admin_context(string $prompt): string
{
    $context = trim(get_system_aris_context());
    if ($context === '') {
        return $prompt;
    }

    return $prompt . "\n\nVom Admin gesetzter ARIS-Kontext:\n" . $context;
}

function load_aris_system_prompt(array $project): string
{
    $type = (string) ($project['type'] ?? 'webpage');
    $path = aris_system_prompt_path();
    if (is_readable($path)) {
        $raw = file_get_contents($path);
        if (is_string($raw) && trim($raw) !== '') {
            $prompt = '';
            if (preg_match('/```text\s*(.*?)\s*```/s', $raw, $matches) === 1) {
                $prompt = trim((string) $matches[1]);
            } else {
                $prompt = trim($raw);
            }

            return append_aris_admin_context($prompt . "\n\nLiveserver-Editor-Regeln: Antworte direkt und lesbar. "
                . "Verwende im Editor niemals Marker wie [RESULT], [NOTES], [SYSTEM PROMPT] oder Pfeil-Zeilen wie '->'. "
                . "Projekt-Typ: {$type}. "
                . "Wenn du Code gibst, nutze normale Markdown-Codeblöcke mit Dateinamen davor. "
                . "Wenn der Nutzer Code fuer mehrere Dateien verlangt, antworte mit getrennten Abschnitten pro Datei und genau einem Codeblock je Datei. Mische niemals mehrere Dateien in denselben Codeblock. "
                . "Wenn der Nutzer neuen Code oder neue Ausgaben verlangt, ist die Nutzeranforderung wichtiger als der alte Projektkontext. Uebernimm keine alten printf-/System.out-Strings, wenn der Nutzer andere Texte verlangt. "
                . "Behalte vorhandene Dateigrenzen exakt bei: Code aus main2.c gehoert nicht in main.c, ausser der Nutzer fordert das ausdrücklich. "
                . "Wenn der Nutzer bei C nach main und main2 fragt, verwende main.c fuer main() und main2.c fuer die zweite Funktion oder Datei-Logik; main.c darf die Funktion aus main2.c nur deklarieren und aufrufen. "
                . "Bei C-Projekten mit mehreren Dateien ist eine Funktionsdeklaration in main.c und die Definition in main2.c korrekt, wenn der Compile-Befehl beide .c-Dateien enthält. Sage dann, dass nichts kopiert werden muss. "
                . "Wenn die Runtime-Ausgabe Status: OK meldet, behandle das Projekt als lauffähig und schlage keinen Fix vor, solange nicht ausdrücklich nach einer Änderung gefragt wird.");
        }
    }

    return append_aris_admin_context("Du bist ARIS, der Liveserver-Chatbot im Editor. Antworte auf Deutsch, präzise und praktisch. "
        . "Hilf beim Verstehen, Planen und Verbessern des Projekts. Projekt-Typ: {$type}. "
        . "Wenn Codekontext vorhanden ist, nutze nur diesen Kontext und erfinde keine Dateien. "
        . "Wenn der Nutzer Code fuer mehrere Dateien verlangt, antworte mit getrennten Abschnitten pro Datei und genau einem Codeblock je Datei. "
        . "Wenn der Nutzer neue Ausgabetexte oder neuen Code verlangt, uebernimm exakt diese Anforderung und nicht alte Strings aus dem Projektkontext. "
        . "Bei C-Projekten mit mehreren Dateien ist eine Deklaration in einer Datei und die Definition in einer anderen Datei korrekt, wenn beide Dateien gemeinsam kompiliert werden. "
        . "Behalte vorhandene Dateigrenzen exakt bei und schreibe funktionierenden Code nicht als Fix um, wenn die Runtime Status: OK meldet.");
}

function clean_aris_answer(string $answer): string
{
    $answer = trim($answer);
    if ($answer === '') {
        return '';
    }

    $answer = preg_replace('/^\s*\[RESULT\]\s*/iu', '', $answer) ?? $answer;
    $answer = preg_replace('/^\s*->\s*(direkte\s+lösung|direkte\s+loesung|code\s*\/\s*fix\s*\/\s*architektur).*?\R+/iu', '', $answer) ?? $answer;
    $answer = preg_replace('/\R+\s*\[NOTES\][\s\S]*$/iu', '', $answer) ?? $answer;
    $answer = preg_replace('/^\s*->\s*/mu', '', $answer) ?? $answer;

    return trim($answer);
}

function clean_aris_action_answer(string $answer, string $action, array $project, array $files, string $activeFile): string
{
    $answer = clean_aris_answer($answer);
    if (!in_array($action, ['summary', 'explain', 'find_errors'], true)) {
        return $answer;
    }

    $withoutCode = preg_replace('/```[a-zA-Z0-9_+.-]*\s*\n[\s\S]*?\n```/u', '', $answer);
    $withoutCode = is_string($withoutCode) ? trim($withoutCode) : $answer;
    if ($withoutCode !== '') {
        return $withoutCode;
    }

    return aris_action_fallback_answer($project, $files, $activeFile, $action);
}

function aris_action_fallback_answer(array $project, array $files, string $activeFile, string $action): string
{
    if ($action === 'find_errors') {
        return 'Keine offensichtlichen Fehler gefunden.';
    }

    $path = $activeFile !== '' ? $activeFile : (array_key_first($files) ?? '');
    $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    $typeLabel = match ($extension) {
        'html', 'htm' => 'HTML-Datei',
        'css' => 'CSS-Datei',
        'js', 'mjs' => 'JavaScript-Datei',
        'json' => 'JSON-Datei',
        default => 'Textdatei',
    };
    $content = is_string($files[$path] ?? null) ? (string) $files[$path] : '';
    $details = [];

    if (preg_match('/<title>\s*(.*?)\s*<\/title>/is', $content, $matches) === 1) {
        $details[] = 'Titel: ' . trim(strip_tags((string) $matches[1]));
    }
    if (preg_match_all('/<link\b[^>]*rel=["\']stylesheet["\'][^>]*href=["\']([^"\']+)["\']/i', $content, $matches) > 0) {
        $details[] = 'CSS: ' . implode(', ', array_slice($matches[1], 0, 4));
    }
    if (preg_match_all('/<a\b[^>]*href=["\']([^"\']+)["\']/i', $content, $matches) > 0) {
        $details[] = 'Links: ' . count($matches[1]);
    }

    $detailText = $details === [] ? '' : ' ' . implode('. ', $details) . '.';
    if ($action === 'summary') {
        return "Das Projekt enthält als aktive Datei {$path}. {$typeLabel} mit zentraler Struktur für die Webseite.{$detailText}";
    }

    return "Die aktive Datei {$path} ist eine {$typeLabel}. Sie legt die sichtbare Seitenstruktur fest und bindet die dazugehörigen Ressourcen ein.{$detailText}";
}

function ai_action_instruction(string $action): string
{
    return match ($action) {
        'summary' => "Fasse das Projekt kurz auf Deutsch zusammen. Nenne nur Dateien und Ressourcen, die im Kontext sichtbar sind. Schreibe Prosa oder kurze Stichpunkte, keine Codeblöcke und keinen vollständigen Quellcode. Maximal 120 Wörter.",
        'explain' => "Erkläre die aktive Datei kurz und sachlich auf Deutsch. Nenne Zweck, groben Aufbau und wichtige eingebundene Ressourcen. Erfinde keine Ressourcen, Bilder, Links, Funktionen oder Probleme. Gib keinen Quellcode aus und verwende keine Codeblöcke. Maximal 120 Wörter.",
        'find_errors' => "Prüfe nur direkt sichtbare echte Fehler. Erfinde keine Probleme. Wenn kein klarer Fehler erkennbar ist, antworte exakt: Keine offensichtlichen Fehler gefunden. Gib keinen vollständigen Quellcode aus.",
        default => '',
    };
}

function ai_user_prompt(array $project, string $prompt, array $files, string $activeFile, string $action = '', string $runtimeOutput = '', ?int $contextLimit = null, bool $numberLines = true): string
{
    $context = ai_collect_context($files, $activeFile, $contextLimit, $numberLines);
    $title = (string) ($project['title'] ?? 'Projekt');
    $instruction = ai_action_instruction($action);
    $task = $instruction !== '' ? $instruction : $prompt;
    $runtimeBlock = '';

    if ($runtimeOutput !== '') {
        $runtimeBlock = "\n\nCompiler-/Runtime-Ausgabe:\n```text\n" . ai_substr($runtimeOutput, 0, 6000) . "\n```";
    }

    return "Projekt: {$title}\nAktive Datei: {$activeFile}\n\nAufgabe:\n{$task}\n\n"
        . "Prioritaet: Die Aufgabe und die originale Nutzerfrage haben Vorrang vor alten Codeinhalten. "
        . "Wenn die Nutzerfrage neue Textausgaben verlangt, verwende genau diese neuen Texte.\n\n"
        . "Originale Nutzerfrage:\n{$prompt}{$runtimeBlock}\n\nProjektkontext:\n{$context}";
}

function ai_is_code_generation_prompt(string $prompt): bool
{
    return preg_match('/\b(schreibe|erstelle|generiere|baue|mache|implementiere)\b.*\b(code|programm|datei|funktion)\b/iu', $prompt) === 1
        || preg_match('/\bcode\s+der\b/iu', $prompt) === 1;
}

function aris_generation_user_prompt(array $project, string $prompt, array $files, string $activeFile): string
{
    $title = (string) ($project['title'] ?? 'Projekt');
    $fileNames = array_keys($files);
    sort($fileNames, SORT_NATURAL | SORT_FLAG_CASE);
    $fileList = $fileNames === []
        ? '- keine Dateien uebergeben'
        : implode("\n", array_map(static fn (string $path): string => '- ' . normalize_project_folder_path($path), $fileNames));

    return "Projekt: {$title}\nAktive Datei: {$activeFile}\n\n"
        . "Aufgabe:\n{$prompt}\n\n"
        . "Modus: Neuer Code gemaess Nutzerfrage. Die vorhandenen Dateien dienen nur als Dateinamen, nicht als Inhalt-Vorlage. "
        . "Uebernimm keine alten printf-/System.out-Strings aus frueherem Code oder Runtime-Ausgaben. "
        . "Wenn die Aufgabe mehrere Dateien nennt oder mehrere vorhandene Dateien passend sind, gib getrennte Abschnitte pro Datei aus. "
        . "Format:\nDatei: name.ext\n```sprache\ncode\n```\n\n"
        . "Vorhandene Dateien:\n{$fileList}";
}

function c_string_literal(string $value): string
{
    return addcslashes($value, "\\\"\n\r\t");
}

function aris_direct_c_main_main2_answer(string $prompt, array $files): ?string
{
    $hasMain = array_key_exists('main.c', $files);
    $hasMain2 = array_key_exists('main2.c', $files);
    if (!$hasMain || !$hasMain2 || stripos($prompt, 'main2') === false) {
        return null;
    }

    if (preg_match('/in\s+main(?:\.c)?\s+(?:ausgibt|gibt\s+aus)\s+(.+?)\s+und\s+in\s+main2(?:\.c)?\s+(?:ausgibt\s+|gibt\s+aus\s+)?(.+?)(?:[.;]|$)/iu', $prompt, $matches) !== 1) {
        return null;
    }

    $mainText = trim((string) $matches[1], " \t\n\r\0\x0B\"'");
    $main2Text = trim((string) $matches[2], " \t\n\r\0\x0B\"'");
    if ($mainText === '' || $main2Text === '') {
        return null;
    }

    $mainText = c_string_literal($mainText);
    $main2Text = c_string_literal($main2Text);

    return "Datei: main.c\n```c\n#include <stdio.h>\n\nvoid print_from_main2(void);\n\nint main(void) {\n    printf(\"{$mainText}\\n\");\n    print_from_main2();\n    return 0;\n}\n```\n\n"
        . "Datei: main2.c\n```c\n#include <stdio.h>\n\nvoid print_from_main2(void) {\n    printf(\"{$main2Text}\\n\");\n}\n```";
}

function call_openai_compatible_ai(string $baseUrl, string $apiKey, string $model, string $systemPrompt, string $userPrompt, int $timeout = 35, array $options = []): string
{
    $headers = [];
    if ($apiKey !== '') {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }

    $payload = array_merge([
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ],
        'temperature' => 0.2,
        'max_tokens' => 1200,
    ], $options);

    $response = with_ai_small_capacity('high', static fn (): array => ai_http_json(
        rtrim($baseUrl, '/') . '/chat/completions',
        $headers,
        $payload,
        $timeout
    ));

    return trim((string) ($response['choices'][0]['message']['content'] ?? ''));
}

function call_aris_ai(array $project, string $prompt, array $files, string $activeFile, string $action = '', string $runtimeOutput = ''): array
{
    $localCoder = get_ai_local_coder_settings();
    if (!$localCoder['enabled']) {
        throw new RuntimeException('ARIS ist deaktiviert, weil der lokale llama.cpp-Assistent deaktiviert ist.');
    }

    $model = (string) $localCoder['model'];
    $directAnswer = aris_direct_c_main_main2_answer($prompt, $files);
    if ($directAnswer !== null) {
        return [
            'provider' => 'aris',
            'model' => $model,
            'answer' => $directAnswer,
        ];
    }

    $systemPrompt = load_aris_system_prompt($project);
    $isGenerationPrompt = ai_is_code_generation_prompt($prompt);
    $userPrompt = $isGenerationPrompt
        ? aris_generation_user_prompt($project, $prompt, $files, $activeFile)
        : ai_user_prompt($project, $prompt, $files, $activeFile, $action, $runtimeOutput, ai_local_context_limit(), false);
    $answer = call_openai_compatible_ai(
        (string) $localCoder['baseUrl'],
        (string) $localCoder['apiKey'],
        $model,
        $systemPrompt,
        $userPrompt,
        180,
        [
            'temperature' => $isGenerationPrompt ? 0 : 0.2,
            'top_p' => 0.9,
            'repeat_penalty' => 1.12,
            'max_tokens' => $isGenerationPrompt ? 900 : 520,
            'stop' => ['<|im_end|>'],
        ]
    );
    $answer = clean_aris_action_answer($answer, $action, $project, $files, $activeFile);

    return [
        'provider' => 'aris',
        'model' => $model,
        'answer' => $answer,
    ];
}

function clean_local_coder_answer(string $answer, string $action = ''): string
{
    $answer = trim($answer);
    if ($answer === '') {
        return '';
    }

    if ($action === 'find_errors' && preg_match('/^Keine offensichtlichen Fehler gefunden\.?/u', $answer) === 1) {
        return 'Keine offensichtlichen Fehler gefunden.';
    }

    $lines = preg_split('/\R/u', $answer);
    if (!is_array($lines)) {
        return ai_substr($answer, 0, 1800);
    }

    $cleaned = [];
    $seen = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            if ($cleaned !== [] && end($cleaned) !== '') {
                $cleaned[] = '';
            }
            continue;
        }

        if ($action === 'find_errors' && preg_match('/^(Zusatzinformation|Explanation|Corrected code|This change|No real errors|```)/i', $trimmed) === 1) {
            break;
        }

        $signature = preg_replace('/^\s*(?:[-*]|\d+[.)])\s*/u', '', $trimmed);
        $signature = preg_replace('/[`*_#>\[\]().,;:!?0-9\s]+/u', ' ', strtolower((string) $signature));
        $signature = trim((string) $signature);
        if ($signature !== '' && isset($seen[$signature])) {
            continue;
        }

        if ($signature !== '') {
            $seen[$signature] = true;
        }
        $cleaned[] = $line;

        $maxLines = $action === 'find_errors' ? 8 : 24;
        $maxChars = $action === 'find_errors' ? 900 : 1800;
        if (count($cleaned) >= $maxLines || strlen(implode("\n", $cleaned)) >= $maxChars) {
            break;
        }
    }

    return trim(ai_substr(implode("\n", $cleaned), 0, $action === 'find_errors' ? 900 : 1800));
}

function local_runtime_error_answer(string $runtimeOutput, array $files, string $activeFile): ?string
{
    if ($runtimeOutput === '') {
        return null;
    }

    if (preg_match('/^Status:\s*OK\b/mi', $runtimeOutput) === 1) {
        return 'Keine offensichtlichen Fehler gefunden.';
    }

    if (preg_match('/^Status:\s*Compile-Fehler\b/mi', $runtimeOutput) !== 1) {
        return null;
    }

    $diagnostic = '';
    $diagnosticFile = $activeFile;
    $diagnosticLine = 0;
    $diagnosticMessage = '';
    $lines = preg_split('/\R/u', $runtimeOutput);
    if (!is_array($lines)) {
        $lines = explode("\n", $runtimeOutput);
    }

    foreach ($lines as $line) {
        if (preg_match('/^(.+?):(\d+):(\d+):\s*error:\s*(.+)$/i', trim($line), $matches) === 1) {
            $diagnostic = trim($line);
            $diagnosticFile = normalize_project_folder_path((string) $matches[1]);
            $diagnosticLine = (int) $matches[2];
            $diagnosticMessage = trim((string) $matches[4]);
            break;
        }
    }

    if ($diagnostic === '') {
        foreach ($lines as $line) {
            if (stripos($line, 'error:') !== false) {
                $diagnostic = trim($line);
                break;
            }
        }
    }

    if ($diagnostic === '') {
        return "Fehler: Das Projekt kompiliert nicht.\nKorrektur: Pruefe die Compiler-Ausgabe im Run-Fenster und behebe die erste gemeldete Stelle.";
    }

    $answer = 'Fehler: Compiler meldet `' . $diagnostic . '`.';
    $messageLower = strtolower($diagnosticMessage);
    if ($diagnosticLine > 1 && str_contains($messageLower, "expected ';' before")) {
        $sourcePath = $diagnosticFile !== '' && isset($files[$diagnosticFile]) ? $diagnosticFile : $activeFile;
        $sourceLines = isset($files[$sourcePath]) && is_string($files[$sourcePath]) ? preg_split('/\R/u', $files[$sourcePath]) : [];
        $previousLine = is_array($sourceLines) ? trim((string) ($sourceLines[$diagnosticLine - 2] ?? '')) : '';
        $answer .= "\nUrsache: Vor Zeile {$diagnosticLine} fehlt sehr wahrscheinlich ein Semikolon.";
        if ($previousLine !== '' && !str_ends_with($previousLine, ';')) {
            $answer .= "\nKorrektur: `" . $previousLine . ";`";
        }
        return $answer;
    }

    if ($diagnosticLine > 0) {
        $answer .= "\nKorrektur: Behebe die gemeldete Stelle bei {$diagnosticFile}:{$diagnosticLine} und kompiliere erneut.";
    } else {
        $answer .= "\nKorrektur: Behebe die erste Compiler-Meldung und kompiliere erneut.";
    }

    return $answer;
}

function call_openai_ai(string $apiKey, string $model, string $systemPrompt, string $userPrompt): string
{
    return call_openai_compatible_ai('https://api.openai.com/v1', $apiKey, $model, $systemPrompt, $userPrompt);
}

function call_anthropic_ai(string $apiKey, string $model, string $systemPrompt, string $userPrompt): string
{
    $response = ai_http_json(
        'https://api.anthropic.com/v1/messages',
        [
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        [
            'model' => $model,
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'max_tokens' => 1200,
            'temperature' => 0.2,
        ]
    );

    $parts = [];
    foreach (($response['content'] ?? []) as $part) {
        if (($part['type'] ?? '') === 'text') {
            $parts[] = (string) ($part['text'] ?? '');
        }
    }

    return trim(implode("\n", $parts));
}

try {
    start_secure_session();
    require_post_request();
    $input = get_json_input();
    require_csrf_token($input);
    $user = require_logged_in_user();

    if (!is_system_ai_enabled()) {
        json_response([
            'success' => false,
            'message' => 'KI ist aktuell vom Admin deaktiviert.',
            'csrfToken' => ensure_csrf_token(),
        ], 403);
    }

    $projectId = (int) ($input['projectId'] ?? 0);
    $project = require_project_editor_access($projectId, $user);
    $prompt = trim((string) ($input['prompt'] ?? ''));
    $route = trim((string) ($input['route'] ?? ''));
    $action = trim((string) ($input['action'] ?? ''));
    $activeFile = normalize_project_folder_path((string) ($input['activeFile'] ?? ''));
    $files = is_array($input['files'] ?? null) ? $input['files'] : [];
    $runtimeOutput = trim((string) ($input['runtimeOutput'] ?? ''));

    if ($route !== '' && $route !== 'local_coder' && $route !== 'aris') {
        json_response([
            'success' => false,
            'message' => 'Unbekannte KI-Route.',
            'csrfToken' => ensure_csrf_token(),
        ], 400);
    }

    if ($prompt === '' || strlen($prompt) > ai_prompt_limit()) {
        json_response([
            'success' => false,
            'message' => 'Bitte gib eine KI-Frage ein.',
            'csrfToken' => ensure_csrf_token(),
        ], 400);
    }

    if ($action !== '' && !in_array($action, ['summary', 'explain', 'find_errors'], true)) {
        $action = '';
    }

    if ($action !== '') {
        $route = 'local_coder';
    } elseif ($route === '') {
        $route = 'aris';
    }

    $prompt = ai_substr($prompt, 0, ai_prompt_limit());
    $systemPrompt = ai_system_prompt($project, $route, $action, $route === 'local_coder' ? $runtimeOutput : '');
    $userPrompt = ai_user_prompt(
        $project,
        $prompt,
        $files,
        $activeFile,
        $route === 'local_coder' ? $action : '',
        $route === 'local_coder' ? $runtimeOutput : '',
        $route === 'local_coder' ? ai_local_context_limit() : null,
        true
    );

    if ($route === 'local_coder') {
        $localCoder = get_ai_local_coder_settings();
        if (!$localCoder['enabled']) {
            throw new RuntimeException('Lokaler Code-Assistent ist deaktiviert.');
        }

        $provider = 'local_coder';
        $model = (string) $localCoder['model'];
        $runtimeAnswer = $action === 'find_errors' ? local_runtime_error_answer($runtimeOutput, $files, $activeFile) : null;
        if ($runtimeAnswer !== null) {
            json_response([
                'success' => true,
                'csrfToken' => ensure_csrf_token(),
                'provider' => $provider,
                'model' => $model,
                'answer' => $runtimeAnswer,
            ]);
        }

        $answer = call_openai_compatible_ai(
            (string) $localCoder['baseUrl'],
            (string) $localCoder['apiKey'],
            $model,
            $systemPrompt,
            $userPrompt,
            180,
            [
                'temperature' => 0,
                'top_p' => 1,
                'min_p' => 0.05,
                'repeat_penalty' => 1.25,
                'frequency_penalty' => 0.15,
                'max_tokens' => $action === 'find_errors' ? 140 : 320,
                'stop' => ['<|im_end|>'],
            ]
        );
        $answer = clean_local_coder_answer($answer, $action);
        $answer = clean_aris_action_answer($answer, $action, $project, $files, $activeFile);
    } elseif ($route === 'aris') {
        $arisResult = call_aris_ai($project, $prompt, $files, $activeFile, $action, $runtimeOutput);
        $provider = $arisResult['provider'];
        $model = $arisResult['model'];
        $answer = $arisResult['answer'];
    } else {
        $settings = get_user_ai_settings((int) $user['id']);
        if ($settings === null || ((string) ($settings['provider'] ?? '') !== 'selfhosted' && empty($settings['api_key_encrypted']))) {
            json_response([
                'success' => false,
                'message' => 'Bitte hinterlege zuerst deinen KI-API-Key in den Einstellungen.',
                'csrfToken' => ensure_csrf_token(),
            ], 400);
        }

        $provider = normalize_ai_provider((string) $settings['provider']);
        $model = normalize_ai_model($provider, (string) $settings['model']);
        $apiKey = decrypt_ai_api_key($settings['api_key_encrypted'] ?? null);
        if ($provider !== 'selfhosted' && $apiKey === '') {
            throw new RuntimeException('KI-API-Key konnte nicht gelesen werden.');
        }

        if ($provider === 'anthropic') {
            $answer = call_anthropic_ai($apiKey, $model, $systemPrompt, $userPrompt);
        } elseif ($provider === 'selfhosted') {
            $baseUrl = normalize_ai_base_url((string) ($settings['base_url'] ?? ''));
            $answer = call_openai_compatible_ai($baseUrl, $apiKey, $model, $systemPrompt, $userPrompt, 180);
        } else {
            $answer = call_openai_ai($apiKey, $model, $systemPrompt, $userPrompt);
        }
    }

    json_response([
        'success' => true,
        'csrfToken' => ensure_csrf_token(),
        'provider' => $provider,
        'model' => $model,
        'answer' => $answer !== '' ? $answer : 'Der KI-Anbieter hat keine Antwort geliefert.',
    ]);
} catch (InvalidArgumentException $exception) {
    json_response([
        'success' => false,
        'message' => $exception->getMessage(),
        'csrfToken' => ensure_csrf_token(),
    ], 400);
} catch (Throwable $exception) {
    api_exception_response($exception, 'KI-Anfrage konnte nicht verarbeitet werden.', 500);
}
