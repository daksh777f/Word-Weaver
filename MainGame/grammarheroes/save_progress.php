<?php
header('Content-Type: application/json');

require_once '../../onboarding/config.php';
require_once '../../includes/gwa_updater.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'User not logged in']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid payload']);
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$game_type = 'grammar-heroes';

$score = max(0, (int)($input['score'] ?? 0));
$totalQuestions = max(0, (int)($input['totalQuestions'] ?? 0));
$correctAnswers = max(0, (int)($input['correctAnswers'] ?? 0));
$maxStreak = max(0, (int)($input['maxStreak'] ?? 0));
$totalTime = max(0, (int)($input['totalTime'] ?? 0));
$xpEarned = max(0, (int)($input['xpEarned'] ?? 0));
$wavesCompleted = max(1, (int)($input['wavesCompleted'] ?? 1));
$enemiesDefeated = max(0, (int)($input['enemiesDefeated'] ?? 0));
$bossesDefeated = max(0, (int)($input['bossesDefeated'] ?? 0));

$accuracy = $totalQuestions > 0 ? (int)round(($correctAnswers / $totalQuestions) * 100) : 0;
$sessionLevelByScore = max(1, min(20, (int)floor($score / 80) + 1));
$sessionLevel = max($sessionLevelByScore, min(20, $wavesCompleted));

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "INSERT INTO game_scores (user_id, game_type, score, level, time_spent)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->execute([$user_id, $game_type, $score, $sessionLevel, $totalTime]);

    $stmt = $pdo->prepare("SELECT achievements, unlocked_levels FROM game_progress WHERE user_id = ? AND game_type = ? LIMIT 1");
    $stmt->execute([$user_id, $game_type]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    $achievements = [
        'best_score' => $score,
        'best_accuracy' => $accuracy,
        'best_streak' => $maxStreak,
        'best_wave' => $wavesCompleted,
        'best_enemies' => $enemiesDefeated,
        'best_bosses' => $bossesDefeated,
        'total_sessions' => 1,
        'total_waves' => $wavesCompleted,
        'total_enemies' => $enemiesDefeated,
        'total_bosses' => $bossesDefeated,
        'total_correct' => $correctAnswers,
        'total_questions' => $totalQuestions
    ];

    $unlockedLevels = ['last_session_level' => $sessionLevel];

    if ($existing) {
        $prevAchievements = json_decode($existing['achievements'] ?? '{}', true);
        if (is_array($prevAchievements)) {
            $achievements['best_score'] = max((int)($prevAchievements['best_score'] ?? 0), $score);
            $achievements['best_accuracy'] = max((int)($prevAchievements['best_accuracy'] ?? 0), $accuracy);
            $achievements['best_streak'] = max((int)($prevAchievements['best_streak'] ?? 0), $maxStreak);
            $achievements['best_wave'] = max((int)($prevAchievements['best_wave'] ?? 0), $wavesCompleted);
            $achievements['best_enemies'] = max((int)($prevAchievements['best_enemies'] ?? 0), $enemiesDefeated);
            $achievements['best_bosses'] = max((int)($prevAchievements['best_bosses'] ?? 0), $bossesDefeated);
            $achievements['total_sessions'] = (int)($prevAchievements['total_sessions'] ?? 0) + 1;
            $achievements['total_waves'] = (int)($prevAchievements['total_waves'] ?? 0) + $wavesCompleted;
            $achievements['total_enemies'] = (int)($prevAchievements['total_enemies'] ?? 0) + $enemiesDefeated;
            $achievements['total_bosses'] = (int)($prevAchievements['total_bosses'] ?? 0) + $bossesDefeated;
            $achievements['total_correct'] = (int)($prevAchievements['total_correct'] ?? 0) + $correctAnswers;
            $achievements['total_questions'] = (int)($prevAchievements['total_questions'] ?? 0) + $totalQuestions;
        }

        $prevUnlocks = json_decode($existing['unlocked_levels'] ?? '{}', true);
        if (is_array($prevUnlocks)) {
            $unlockedLevels = array_merge($prevUnlocks, $unlockedLevels);
        }
    }

    $stmt = $pdo->prepare(
        "INSERT INTO game_progress
            (user_id, game_type, unlocked_levels, achievements, total_play_time, player_level, experience_points, total_experience_earned, total_monsters_defeated, last_played)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            unlocked_levels = VALUES(unlocked_levels),
            achievements = VALUES(achievements),
            total_play_time = total_play_time + VALUES(total_play_time),
            player_level = GREATEST(player_level, VALUES(player_level)),
            experience_points = experience_points + VALUES(experience_points),
            total_experience_earned = total_experience_earned + VALUES(total_experience_earned),
            total_monsters_defeated = total_monsters_defeated + VALUES(total_monsters_defeated),
            last_played = NOW(),
            updated_at = NOW()"
    );

    $stmt->execute([
        $user_id,
        $game_type,
        json_encode($unlockedLevels),
        json_encode($achievements),
        $totalTime,
        $sessionLevel,
        $xpEarned,
        $xpEarned,
        $correctAnswers
    ]);

    updateUserGWA($pdo, $user_id, $game_type);

    $stmt = $pdo->prepare("SELECT gwa FROM user_gwa WHERE user_id = ? AND game_type = ? LIMIT 1");
    $stmt->execute([$user_id, $game_type]);
    $gwaRow = $stmt->fetch(PDO::FETCH_ASSOC);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Progress saved',
        'data' => [
            'level' => $sessionLevel,
            'accuracy' => $accuracy,
            'new_gwa' => $gwaRow ? (float)$gwaRow['gwa'] : 0,
            'totals' => $achievements
        ]
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to save progress',
        'details' => $e->getMessage()
    ]);
}
