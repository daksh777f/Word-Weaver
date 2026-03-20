<?php
ob_start();
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/cerebras_errors.log');
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
// ════════════════════════════════════════
// FILE: obituary.php
// PURPOSE: Analyze final submissions, persist session results, and return Bug Hunt obituary feedback.
// ANALYSES USED: MainGame/grammarheroes/save_progress.php, onboarding/config.php
// NEW TABLES USED: bug_challenges, user_game_sessions, concept_graph
// CEREBRAS CALLS: yes
// ════════════════════════════════════════

require_once '../onboarding/config.php';

requireLogin();

function obituaryRespond(array $payload): void {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode($payload);
    exit;
}

function obituaryFallbackPayload(): array {
    return [
        'roast' => "Our AI mentor is taking a coffee break. Here's what we know: your code was submitted.",
        'time_complexity' => 'Unable to analyze right now.',
        'space_complexity' => 'Unable to analyze right now.',
        'edge_cases_missed' => [],
        'cleaner_alternative' => '',
        'senior_dev_comment' => 'Ship it. (Just kidding.)',
        'score' => 500,
        'xp_awarded' => 50,
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    obituaryRespond(obituaryFallbackPayload());
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    obituaryRespond(obituaryFallbackPayload());
}

$sessionUserId = (int)($_SESSION['user_id'] ?? 0);
$userId = (int)($input['user_id'] ?? 0);
if ($sessionUserId > 0) {
    $userId = $sessionUserId;
}

$submittedCode = trim((string)($input['submitted_code'] ?? ''));
$challengeId = (int)($input['challenge_id'] ?? 0);
$timeTaken = max(0, (int)($input['time_taken'] ?? 0));
$language = strtolower(trim((string)($input['language'] ?? 'javascript')));
$gameType = strtolower(trim((string)($input['game_type'] ?? 'bug_hunt')));
$hintPenalty = max(0, (int)($input['hint_penalty'] ?? 0));
$hintsUsed = max(0, (int)($input['hints_used'] ?? 0));
$arenaSessionId = max(0, (int)($input['arena_session_id'] ?? 0));

if (!in_array($gameType, ['bug_hunt', 'daily_sprint', 'live_coding'], true)) {
    $gameType = 'bug_hunt';
}

if ($challengeId <= 0 || $submittedCode === '') {
    obituaryRespond(obituaryFallbackPayload());
}

try {
    $challengeStmt = $pdo->prepare("SELECT title, concept_tags FROM bug_challenges WHERE id = ? LIMIT 1");
    $challengeStmt->execute([$challengeId]);
    $challenge = $challengeStmt->fetch();

    if (!$challenge) {
        obituaryRespond(obituaryFallbackPayload());
    }

    $analysis = null;

    $messages = [
        [
            'role' => 'system',
            'content' => 'You are a senior developer doing a code review. You are sharp, a little dry, and occasionally funny — but never cruel. You always explain clearly. Respond ONLY in this exact JSON format with no extra text, no markdown, no code fences: {"roast": "2 sentences max, personality-driven observation about the code style or approach", "time_complexity": "Big-O notation + one sentence plain English explanation", "space_complexity": "Big-O notation + one sentence", "edge_cases_missed": ["edge case 1 description", "edge case 2 description"], "cleaner_alternative": "a shorter or more idiomatic version of the solution as a code string (escape newlines as \\n)", "senior_dev_comment": "one sentence, dry wit, the kind of thing a senior dev mutters in a code review"}',
        ],
        [
            'role' => 'user',
            'content' => "Language: {$language}\nChallenge: {$challenge['title']}\nTime taken: {$timeTaken} seconds\nStudent's submitted code:\n{$submittedCode}\n\nGive this code a full senior dev obituary review.",
        ],
    ];

    $rawContent = callCerebras($messages, 600);
    if ($rawContent !== null) {
        $cleaned = trim($rawContent);
        $cleaned = preg_replace('/^```json\s*/i', '', $cleaned);
        $cleaned = preg_replace('/^```\s*/i', '', $cleaned);
        $cleaned = preg_replace('/```\s*$/i', '', $cleaned);
        $cleaned = trim((string)$cleaned);

        $analysis = json_decode($cleaned, true);
        if (!is_array($analysis)) {
            if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $cleaned, $match)) {
                $analysis = json_decode($match[0], true);
            }
        }
    }

    $fallbackMode = false;
    if (!is_array($analysis)) {
        if ($rawContent !== null) {
            error_log('CodeDungeon obituary could not parse model response: ' . $rawContent);
        }
        $analysis = obituaryFallbackPayload();
        $fallbackMode = true;
    }

    $analysis = [
        'roast' => (string)($analysis['roast'] ?? ''),
        'time_complexity' => (string)($analysis['time_complexity'] ?? ''),
        'space_complexity' => (string)($analysis['space_complexity'] ?? ''),
        'edge_cases_missed' => is_array($analysis['edge_cases_missed'] ?? null) ? array_values(array_map('strval', $analysis['edge_cases_missed'])) : [],
        'cleaner_alternative' => (string)($analysis['cleaner_alternative'] ?? ''),
        'senior_dev_comment' => (string)($analysis['senior_dev_comment'] ?? ''),
    ];

    if ($fallbackMode) {
        $finalPayload = obituaryFallbackPayload();

        $pdo->beginTransaction();

        $saveStmt = $pdo->prepare(
            "INSERT INTO user_game_sessions (user_id, game_type, challenge_id, submitted_code, score, time_taken, ai_feedback)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $saveStmt->execute([
            $userId,
            $gameType,
            $challengeId,
            $submittedCode,
            $finalPayload['score'],
            $timeTaken,
            json_encode($finalPayload, JSON_UNESCAPED_UNICODE),
        ]);

        $tags = array_filter(array_map('trim', explode(',', (string)$challenge['concept_tags'])));
        foreach ($tags as $concept) {
            $upsert = $pdo->prepare(
                "INSERT INTO concept_graph (user_id, concept_name, times_encountered, times_solved)
                 VALUES (?, ?, 1, 1)
                 ON DUPLICATE KEY UPDATE
                    times_encountered = times_encountered + 1,
                    times_solved = times_solved + 1,
                    last_seen = CURRENT_TIMESTAMP"
            );
            $upsert->execute([$userId, $concept]);
        }

        if ($gameType === 'live_coding' && $arenaSessionId > 0) {
            $arenaUpdateStmt = $pdo->prepare(
                'UPDATE arena_sessions SET submitted_code = ?, score = ?, time_taken = ?, hints_used = ?, ai_feedback = ?, completed = 1, completed_at = NOW() WHERE id = ? AND user_id = ?'
            );
            $arenaUpdateStmt->execute([
                $submittedCode,
                $finalPayload['score'],
                $timeTaken,
                $hintsUsed,
                json_encode($finalPayload, JSON_UNESCAPED_UNICODE),
                $arenaSessionId,
                $userId,
            ]);
        }

        $pdo->commit();
        obituaryRespond($finalPayload);
    }

    $edgeCaseCount = count($analysis['edge_cases_missed']);
    $baseScore = 1000;
    $timePenalty = $timeTaken * 2;
    $edgeCasePenalty = $edgeCaseCount * 50;
    $finalScore = max(0, $baseScore - $timePenalty - $edgeCasePenalty - $hintPenalty);
    $xpAwarded = (int)floor($finalScore / 10);

    $responsePayload = [
        'roast' => $analysis['roast'],
        'time_complexity' => $analysis['time_complexity'],
        'space_complexity' => $analysis['space_complexity'],
        'edge_cases_missed' => $analysis['edge_cases_missed'],
        'cleaner_alternative' => $analysis['cleaner_alternative'],
        'senior_dev_comment' => $analysis['senior_dev_comment'],
        'score' => $finalScore,
        'xp_awarded' => $xpAwarded,
    ];

    $pdo->beginTransaction();

    $saveStmt = $pdo->prepare(
        "INSERT INTO user_game_sessions (user_id, game_type, challenge_id, submitted_code, score, time_taken, ai_feedback)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );

    $saveStmt->execute([
        $userId,
        $gameType,
        $challengeId,
        $submittedCode,
        $finalScore,
        $timeTaken,
        json_encode($responsePayload, JSON_UNESCAPED_UNICODE),
    ]);

    $tags = array_filter(array_map('trim', explode(',', (string)$challenge['concept_tags'])));
    $solvedIncrement = $edgeCaseCount === 0 ? 1 : 0;

    foreach ($tags as $concept) {
        $upsert = $pdo->prepare(
            "INSERT INTO concept_graph (user_id, concept_name, times_encountered, times_solved)
             VALUES (?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE
                times_encountered = times_encountered + 1,
                times_solved = times_solved + VALUES(times_solved),
                last_seen = CURRENT_TIMESTAMP"
        );
        $upsert->execute([$userId, $concept, $solvedIncrement]);
    }

    if ($gameType === 'live_coding' && $arenaSessionId > 0) {
        $arenaUpdateStmt = $pdo->prepare(
            'UPDATE arena_sessions SET submitted_code = ?, score = ?, time_taken = ?, hints_used = ?, ai_feedback = ?, completed = 1, completed_at = NOW() WHERE id = ? AND user_id = ?'
        );
        $arenaUpdateStmt->execute([
            $submittedCode,
            $finalScore,
            $timeTaken,
            $hintsUsed,
            json_encode($responsePayload, JSON_UNESCAPED_UNICODE),
            $arenaSessionId,
            $userId,
        ]);
    }

    $pdo->commit();
    obituaryRespond($responsePayload);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('CodeDungeon obituary fatal: ' . $e->getMessage());
    obituaryRespond(obituaryFallbackPayload());
}
