<?php
// ════════════════════════════════════════════════════════════════════
// FILE: recommend_api.php
// PURPOSE: Endpoint for fetching adaptive recommendations after challenge completion
// NEW TABLES USED: ai_recommendations, performance_snapshots, users
// DEPENDS ON: onboarding/config.php, adaptive_engine.php
// CEREBRAS CALLS: yes (through adaptive_engine.php)
// ════════════════════════════════════════════════════════════════════

ob_start();
require_once '../onboarding/config.php';
require_once 'adaptive_engine.php';

header('Content-Type: application/json');

requireLogin();

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
if ($user_id <= 0) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$last_challenge_id = (int)($input['challenge_id'] ?? 0);
$last_game_type = (string)($input['game_type'] ?? 'bug_hunt');
$last_score = (int)($input['score'] ?? 0);
$last_hints = (int)($input['hints_used'] ?? 0);
$last_time = (int)($input['time_taken'] ?? 0);
$last_difficulty = (string)($input['difficulty'] ?? 'beginner');

try {
    // Update skill level
    $skillUpdate = updateSkillLevel(
        $user_id,
        [
            'score' => $last_score,
            'hints_used' => $last_hints,
            'time_taken' => $last_time,
            'difficulty' => $last_difficulty,
            'game_type' => $last_game_type
        ],
        $pdo
    );
    
    // Build performance profile
    $profile = buildPerformanceProfile($user_id, $pdo);
    
    // Get AI recommendation
    $recommendation = getAIRecommendation($user_id, $profile, $pdo);
    
    // Save performance snapshot
    $snapshotStmt = $pdo->prepare("
        INSERT INTO performance_snapshots
        (user_id, snapshot_data, skill_level_before, skill_level_after)
        VALUES (?, ?, ?, ?)
    ");
    $snapshotStmt->execute([
        $user_id,
        json_encode($profile),
        $skillUpdate['old_level'],
        $skillUpdate['new_level']
    ]);
    
    ob_end_clean();
    
    if (!$recommendation) {
        echo json_encode([
            'success' => false,
            'message' => 'No recommendation available yet'
        ]);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'recommendation' => $recommendation,
        'skill_update' => $skillUpdate
    ]);
    exit;
    
} catch (Throwable $e) {
    error_log('CodeDungeon recommend_api error: ' . $e->getMessage());
    ob_end_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
    exit;
}
?>