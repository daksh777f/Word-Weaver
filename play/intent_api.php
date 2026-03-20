<?php
ob_start();
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/cerebras_errors.log');
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// ════════════════════════════════════════
// FILE: intent_api.php
// PURPOSE: Provide real-time mentor hints when the student's approach is fundamentally wrong.
// ANALYSES USED: script.js, menu.js, MainGame/grammarheroes/script.js, onboarding/config.php
// NEW TABLES USED: bug_challenges
// CEREBRAS CALLS: yes
// ════════════════════════════════════════

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

if ($challengeId <= 0 || ($language !== 'javascript' && $language !== 'python')) {
    intentFallback();
}

if (mb_strlen($partialCode) > 5000) {
    $partialCode = mb_substr($partialCode, 0, 5000);
}

try {
    $challengeStmt = $pdo->prepare("SELECT broken_code FROM bug_challenges WHERE id = ? LIMIT 1");
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

    $messages = [
        [
            'role' => 'system',
            'content' => 'You are a coding mentor watching a student write code in real time. You will receive their partial code and the original broken challenge code. Your job is to detect if their current approach is fundamentally wrong — not just incomplete. If their approach is correct or simply unfinished, you MUST respond with should_show=false. Only respond with should_show=true if you can clearly see they are going down a wrong path that will not lead to a correct solution. Keep hints to one sentence. Never give away the answer. Respond ONLY in this exact JSON format with no extra text: {"should_show": true/false, "hint": "..."}',
        ],
        [
            'role' => 'user',
            'content' => "Challenge broken code: {$challenge['broken_code']}\nStudent's current code: {$partialCode}\nLanguage: {$language}\nIs the student's approach fundamentally wrong?",
        ],
    ];

    $rawContent = callCerebras($messages, 100);
    if ($rawContent === null) {
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
