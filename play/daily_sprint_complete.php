<?php
// ════════════════════════════════════════
// FILE: daily_sprint_complete.php
// PURPOSE: Finalize a Daily Sprint run, persist results, update lock/status, and return summary leaderboard data.
// ANALYSES USED: play/obituary.php, onboarding/config.php, navigation/leaderboards/leaderboards.php, MainGame/grammarheroes/save_progress.php
// NEW TABLES USED: daily_sprint_locks, daily_sprint_results
// DEPENDS ON: onboarding/config.php
// CEREBRAS CALLS: no
// ════════════════════════════════════════

require_once '../onboarding/config.php';

requireLogin();
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    echo json_encode(['success' => false, 'message' => 'Invalid payload']);
    exit();
}

$sessionUserId = (int)($_SESSION['user_id'] ?? 0);
$userId = $sessionUserId;

$sprintLockId = (int)($input['sprint_lock_id'] ?? 0);
$scores = is_array($input['scores'] ?? null) ? array_values($input['scores']) : [];
$feedbacks = is_array($input['feedbacks'] ?? null) ? array_values($input['feedbacks']) : [];
$bugsFixed = max(0, min(3, (int)($input['bugs_fixed'] ?? 0)));
$totalScore = max(0, (int)($input['total_score'] ?? 0));
$totalTime = max(0, (int)($input['total_time'] ?? 0));
$challengeIds = is_array($input['challenge_ids'] ?? null) ? array_values($input['challenge_ids']) : [];
$timePerBug = is_array($input['time_per_bug'] ?? null) ? array_values($input['time_per_bug']) : [];
$submittedCode = is_array($input['submitted_code'] ?? null) ? array_values($input['submitted_code']) : [];

if ($sprintLockId <= 0 || count($challengeIds) !== 3) {
    echo json_encode(['success' => false, 'message' => 'Invalid sprint input']);
    exit();
}

for ($i = 0; $i < 3; $i += 1) {
    if (!isset($scores[$i])) $scores[$i] = 0;
    if (!isset($feedbacks[$i])) $feedbacks[$i] = null;
    if (!isset($timePerBug[$i])) $timePerBug[$i] = 0;
    if (!isset($submittedCode[$i])) $submittedCode[$i] = '';
}

$xpAwarded = (int)floor($totalScore / 10);

try {
    $pdo->beginTransaction();

    $lockStmt = $pdo->prepare("SELECT id, user_id, completed FROM daily_sprint_locks WHERE id = ? LIMIT 1");
    $lockStmt->execute([$sprintLockId]);
    $lock = $lockStmt->fetch(PDO::FETCH_ASSOC);

    if (!$lock || (int)$lock['user_id'] !== $userId) {
        throw new RuntimeException('Sprint lock not found for user');
    }

    if ((int)$lock['completed'] === 1) {
        $topStmt = $pdo->prepare("SELECT u.username, dsl.total_score, dsl.bugs_fixed FROM daily_sprint_locks dsl JOIN users u ON u.id = dsl.user_id WHERE dsl.sprint_date = CURDATE() AND dsl.completed = 1 ORDER BY dsl.total_score DESC LIMIT 5");
        $topStmt->execute();
        $top5 = $topStmt->fetchAll(PDO::FETCH_ASSOC);

        $rankStmt = $pdo->prepare("SELECT COUNT(*) + 1 AS rank_pos FROM daily_sprint_locks WHERE sprint_date = CURDATE() AND completed = 1 AND total_score > (SELECT total_score FROM daily_sprint_locks WHERE id = ?)");
        $rankStmt->execute([$sprintLockId]);
        $rank = (int)$rankStmt->fetchColumn();

        $pdo->commit();
        echo json_encode([
            'success' => true,
            'total_score' => (int)($lock['total_score'] ?? 0),
            'xp_awarded' => (int)floor(((int)($lock['total_score'] ?? 0)) / 10),
            'leaderboard_rank' => $rank,
            'top5' => $top5,
        ]);
        exit();
    }

    $insertResultStmt = $pdo->prepare(
        "INSERT INTO daily_sprint_results
            (sprint_id, user_id, challenge_id, submitted_code, score, time_taken, ai_feedback, bug_fixed, sequence_number)
         VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $challengeTagStmt = $pdo->prepare("SELECT concept_tags FROM bug_challenges WHERE id = ? LIMIT 1");

    for ($i = 0; $i < 3; $i += 1) {
        $challengeId = (int)$challengeIds[$i];
        $bugFixed = (int)($scores[$i] > 0 ? 1 : 0);
        $feedbackJson = null;

        if (is_array($feedbacks[$i])) {
            $feedbackJson = json_encode($feedbacks[$i], JSON_UNESCAPED_UNICODE);
        } elseif (is_string($feedbacks[$i]) && trim($feedbacks[$i]) !== '') {
            $feedbackJson = $feedbacks[$i];
        }

        $insertResultStmt->execute([
            $sprintLockId,
            $userId,
            $challengeId,
            (string)$submittedCode[$i],
            max(0, (int)$scores[$i]),
            max(0, (int)$timePerBug[$i]),
            $feedbackJson,
            $bugFixed,
            $i + 1,
        ]);

        if ($bugFixed === 1) {
            $challengeTagStmt->execute([$challengeId]);
            $challengeRow = $challengeTagStmt->fetch(PDO::FETCH_ASSOC);
            if ($challengeRow) {
                $tags = array_filter(array_map('trim', explode(',', (string)$challengeRow['concept_tags'])));
                $edgeCases = is_array($feedbacks[$i]['edge_cases_missed'] ?? null) ? $feedbacks[$i]['edge_cases_missed'] : [];
                $solvedIncrement = count($edgeCases) === 0 ? 1 : 0;

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
            }
        }
    }

    $updateLockStmt = $pdo->prepare(
        "UPDATE daily_sprint_locks
         SET completed = 1,
             total_score = ?,
             bugs_fixed = ?,
             total_time = ?,
             completed_at = NOW()
         WHERE id = ?"
    );
    $updateLockStmt->execute([$totalScore, $bugsFixed, $totalTime, $sprintLockId]);

    // Leaderboard insert pattern (same family as existing game save flows)
    $insertGameScore = $pdo->prepare(
        "INSERT INTO game_scores (user_id, game_type, score, level, time_spent)
         VALUES (?, ?, ?, ?, ?)"
    );
    $insertGameScore->execute([$userId, 'daily_sprint', $totalScore, max(1, $bugsFixed), $totalTime]);

    // XP award/update pattern adapted from existing upsert flow
    $achievementPayload = json_encode([
        'best_score' => $totalScore,
        'best_bugs_fixed' => $bugsFixed,
        'total_sessions' => 1,
    ]);

    $progressStmt = $pdo->prepare(
        "INSERT INTO game_progress
            (user_id, game_type, unlocked_levels, achievements, total_play_time, player_level, experience_points, total_experience_earned, total_monsters_defeated, last_played)
         VALUES
            (?, 'daily_sprint', ?, ?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            total_play_time = total_play_time + VALUES(total_play_time),
            player_level = GREATEST(player_level, VALUES(player_level)),
            experience_points = experience_points + VALUES(experience_points),
            total_experience_earned = total_experience_earned + VALUES(total_experience_earned),
            last_played = NOW(),
            updated_at = NOW()"
    );

    $progressStmt->execute([
        $userId,
        json_encode(['daily_sprint_best' => $bugsFixed]),
        $achievementPayload,
        $totalTime,
        max(1, $bugsFixed),
        $xpAwarded,
        $xpAwarded,
        $bugsFixed,
    ]);

    $topStmt = $pdo->prepare("SELECT u.username, dsl.total_score, dsl.bugs_fixed FROM daily_sprint_locks dsl JOIN users u ON u.id = dsl.user_id WHERE dsl.sprint_date = CURDATE() AND dsl.completed = 1 ORDER BY dsl.total_score DESC LIMIT 5");
    $topStmt->execute();
    $top5 = $topStmt->fetchAll(PDO::FETCH_ASSOC);

    $rankStmt = $pdo->prepare("SELECT COUNT(*) + 1 AS rank_pos FROM daily_sprint_locks WHERE sprint_date = CURDATE() AND completed = 1 AND total_score > ?");
    $rankStmt->execute([$totalScore]);
    $rank = (int)$rankStmt->fetchColumn();

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'total_score' => $totalScore,
        'xp_awarded' => $xpAwarded,
        'leaderboard_rank' => $rank,
        'top5' => $top5,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
