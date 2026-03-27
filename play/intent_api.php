<?php

// ════════════════════════════════════════
// FILE: intent_api.php
// CHANGE: Rewrite Cerebras prompt to force specific code references, add game_type routing, enhanced error handling
// LINES CHANGED: System prompt (new rules-based format), user message (structured sections), challenge query (more columns), game_type routing, error handlers
// LINES PRESERVED: ob_start, headers, requireLogin, intentRespond fallback, debounce timing logic, session-based rate limit
// ════════════════════════════════════════

ob_start();
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/cerebras_errors.log');
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once '../onboarding/config.php';

requireLogin();

function intentRespond(array $payload): void {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode($payload);
    exit;
}

function intentFallback(): void {
    intentRespond(['should_show' => false, 'hint' => '']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    intentFallback();
}

$rawBody = file_get_contents('php://input');
$data = json_decode((string)$rawBody, true);

if (!is_array($data)) {
    intentFallback();
}

$partialCode = trim(strip_tags((string)($data['partial_code'] ?? '')));
$language = strtolower(trim((string)($data['language'] ?? '')));
$challengeId = (int)($data['challenge_id'] ?? 0);
$gameType = strtolower(trim((string)($data['game_type'] ?? 'bug_hunt')));

if ($challengeId <= 0 || ($language !== 'javascript' && $language !== 'python')) {
    intentFallback();
}

if (mb_strlen($partialCode) > 5000) {
    $partialCode = mb_substr($partialCode, 0, 5000);
}

try {
    // Route to correct table based on game_type
    if ($gameType === 'live_coding') {
        $challengeStmt = $pdo->prepare(
            "SELECT id, title, description as bug_description, language, starter_code as broken_code 
             FROM live_coding_challenges WHERE id = ? LIMIT 1"
        );
    } else {
        $challengeStmt = $pdo->prepare(
            "SELECT id, title, bug_description, language, broken_code 
             FROM bug_challenges WHERE id = ? LIMIT 1"
        );
    }
    
    $challengeStmt->execute([$challengeId]);
    $challenge = $challengeStmt->fetch();

    if (!$challenge) {
        intentFallback();
    }

    $now = time();
    $lastHintTime = isset($_SESSION['last_hint_time']) ? (int)$_SESSION['last_hint_time'] : 0;
    if (($now - $lastHintTime) < 10) {
        intentFallback();
    }

    // ════════════════════════════════════════
    // BUILD THE CEREBRAS PROMPT
    // ════════════════════════════════════════

    $systemPrompt = "You are a senior software developer doing live pair programming with a junior student. You can see their code updating in real time as they type.

Your job is to watch what they write and give ONE specific, contextual hint if and only if their current approach contains a concrete mistake that will cause incorrect behavior or a runtime error.

You must follow these rules without exception:

RULE 1 — BE SPECIFIC TO THEIR CODE:
Do not give generic advice.
Reference their actual variable names.
Reference their actual loop conditions.
Reference their actual function names.
If they wrote 'i <= arr.length' say 'i <= arr.length' in your hint.
If they wrote 'let total = null' say 'let total = null' in your hint.
A hint that could apply to any code is a bad hint. Reject it and write a specific one.

RULE 2 — ONLY HINT ON WRONG APPROACHES:
If the student's code is incomplete but heading in the correct direction:
return should_show = false.
Incomplete code is not wrong code.
A function with only 2 lines written is not wrong — it is unfinished.
Only return should_show = true if you can point to a specific line or expression that will produce wrong results.

RULE 3 — NEVER GIVE THE ANSWER:
Your hint must guide, not solve.
Do not write corrected code.
Do not say 'change X to Y'.
Do say 'your condition X will cause Z because of W — think about what value arr.length returns for a 5 item array'.

RULE 4 — ONE SENTENCE MAXIMUM:
Your hint text must be one sentence.
No bullet points. No paragraphs.
No line breaks. One sentence.

RULE 5 — SOUND LIKE A COLLEAGUE:
Write as a developer talking to a colleague, not as a teacher talking to a student. Casual, direct, specific.
Not 'you should consider' — say 'your loop will crash here because'.

RULE 6 — RESPONSE FORMAT:
Respond with ONLY a JSON object.
No text before the JSON.
No text after the JSON.
No markdown code fences.
No explanation.
Only this exact format:
{\"should_show\": true, \"hint\": \"...\"}
or
{\"should_show\": false, \"hint\": \"\"}";

    $userMessage = "CHALLENGE TITLE:\n"
        . $challenge['title'] . "\n\n"
        . "CHALLENGE DESCRIPTION:\n"
        . $challenge['bug_description'] . "\n\n"
        . "ORIGINAL BROKEN CODE (this is the code the student is supposed to fix):\n"
        . "```" . $challenge['language'] . "\n"
        . $challenge['broken_code'] . "\n"
        . "```\n\n"
        . "WHAT THE STUDENT HAS WRITTEN SO FAR (this is their current code in the editor right now):\n"
        . "```" . $challenge['language'] . "\n"
        . $partialCode . "\n"
        . "```\n\n"
        . "LANGUAGE: " . $challenge['language'] . "\n\n"
        . "YOUR TASK:\n"
        . "Compare what the student has written to the original broken code.\n"
        . "Determine if their current specific approach will produce correct results or not.\n"
        . "If their approach is wrong, write ONE specific hint referencing their actual variable names and expressions.\n"
        . "If their approach is incomplete but not wrong, return should_show false.";

    $messages = [
        [
            'role' => 'system',
            'content' => $systemPrompt,
        ],
        [
            'role' => 'user',
            'content' => $userMessage,
        ],
    ];

    $rawContent = callCerebras($messages, 100);
    
    // ════════════════════════════════════════
    // ERROR HANDLING FOR EMPTY RESPONSE
    // ════════════════════════════════════════
    if (empty($rawContent)) {
        error_log('CodeDungeon intent_api: Cerebras returned empty content');
        intentFallback();
    }

    $cleaned = trim($rawContent);
    $cleaned = preg_replace('/^```json\s*/i', '', $cleaned);
    $cleaned = preg_replace('/^```\s*/i', '', $cleaned);
    $cleaned = preg_replace('/```\s*$/i', '', $cleaned);
    $cleaned = trim((string)$cleaned);

    $parsed = json_decode($cleaned, true);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($parsed['should_show'])) {
        error_log('CodeDungeon intent_api could not parse model response: ' . $rawContent);
        intentFallback();
    }

    $shouldShow = (bool)$parsed['should_show'];
    $hint = isset($parsed['hint']) ? strip_tags((string)$parsed['hint']) : '';

    // ════════════════════════════════════════
    // ERROR HANDLING FOR OVERLY LONG HINTS
    // ════════════════════════════════════════
    if (isset($hint) && strlen($hint) > 200) {
        // Truncate at the last complete sentence within 200 characters
        $hint = substr($hint, 0, 200);
        $lastPeriod = strrpos($hint, '.');
        if ($lastPeriod !== false) {
            $hint = substr($hint, 0, $lastPeriod + 1);
        }
    }

    // ════════════════════════════════════════
    // SOFT CHECK: HINT SPECIFICITY WARNING
    // ════════════════════════════════════════
    if ($shouldShow && isset($hint) && isset($partialCode)) {
        // Extract first identifier from student's code
        preg_match('/\b([a-zA-Z_][a-zA-Z0-9_]{2,})\b/', $partialCode, $matches);
        if (!empty($matches[1])) {
            $studentIdentifier = $matches[1];
            if (strpos($hint, $studentIdentifier) === false) {
                error_log(
                    'CodeDungeon intent_api WARNING: hint may be generic — does not contain ' .
                    'student identifier: ' . $studentIdentifier .
                    ' | hint: ' . $hint
                );
            }
        }
    }

    if ($shouldShow) {
        $_SESSION['last_hint_time'] = $now;
    }

    intentRespond([
        'should_show' => $shouldShow,
        'hint' => $shouldShow ? trim($hint) : '',
    ]);
} catch (Throwable $e) {
    error_log('CodeDungeon intent_api fatal: ' . $e->getMessage());
    intentFallback();
}
