<?php
// ════════════════════════════════════════
// FILE: saboteur_award_xp.php
// PURPOSE: Award XP and update leaderboard at game end
// NEW TABLES USED: user_game_sessions, users, codedungeon_activity_feed
// DEPENDS ON: config.php
// REALTIME: on demand
// CEREBRAS CALLS: no
// ════════════════════════════════════════

ob_start();
require_once '../onboarding/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$request_data = json_decode(file_get_contents('php://input'), true);
$room_code = trim($request_data['room_code'] ?? '');

if (empty($room_code)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Room code required']);
    exit;
}

try {
    // ── Find room ──────────────────────────
    $stmt = $pdo->prepare("
        SELECT sr.id, sr.status, sr.winner, sp.id AS player_id, sp.role, sp.xp_awarded, sr.challenge_id
        FROM saboteur_rooms sr
        INNER JOIN saboteur_players sp ON sp.room_id = sr.id
        WHERE sr.room_code = ? AND sp.user_id = ?
    ");
    $stmt->execute([$room_code, $user_id]);
    $room_player = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$room_player) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Room or player not found']);
        exit;
    }

    if ($room_player['status'] !== 'finished') {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Game not finished']);
        exit;
    }

    $room_id = (int)$room_player['id'];
    $player_id = (int)$room_player['player_id'];
    $player_role = $room_player['role'];
    $winner = $room_player['winner'];

    if ((int)$room_player['xp_awarded'] === 1) {
        ob_end_clean();
        echo json_encode([
            'success' => true,
            'xp_awarded' => 0,
            'player_role' => $player_role,
            'game_result' => $winner,
            'winner_bonus' => 0,
            'message' => 'XP already awarded'
        ]);
        exit;
    }

    // ── Calculate XP ──────────────────────────
    $base_xp = 50;
    $winner_bonus = 0;

    if ($winner === 'fixers' && $player_role === 'fixer') {
        $winner_bonus = 50;
    } elseif ($winner === 'saboteur' && $player_role === 'saboteur') {
        $winner_bonus = 75;
    }

    $total_xp = $base_xp + $winner_bonus;

    $pdo->beginTransaction();

    // ── Award XP to user ──────────────────────────
    $stmt = $pdo->prepare("UPDATE users SET total_xp = total_xp + ? WHERE id = ?");
    $stmt->execute([$total_xp, $user_id]);

    $stmt = $pdo->prepare("UPDATE saboteur_players SET xp_awarded = 1 WHERE id = ?");
    $stmt->execute([$player_id]);

    // ── Get user details ──────────────────────────
    $stmt = $pdo->prepare("SELECT username, total_xp FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // ── Create activity feed entry ──────────────────────────
    $result_text = ($winner === 'fixers' && $player_role === 'fixer') ? '🎉 Fixers Won' :
                   (($winner === 'saboteur' && $player_role === 'saboteur') ? '🎯 Saboteur Won' : '❌ Lost');

    $activity_title = '🕵️ Bug Saboteur: ' . $result_text;
    $activity_subtitle = 'Multiplayer · +' . $total_xp . ' XP';

    $stmt = $pdo->prepare("
        INSERT INTO codedungeon_activity_feed
            (user_id, activity_type, title, subtitle, xp_earned, score, game_type, challenge_id, short_hash)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $shortHash = substr(md5(time() . $user_id), 0, 10);
    $stmt->execute([
        $user_id,
        'game_completed',
        $activity_title,
        $activity_subtitle,
        $total_xp,
        0,
        'saboteur',
        (int)$room_player['challenge_id'],
        $shortHash
    ]);

    $pdo->commit();

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'xp_awarded' => $total_xp,
        'player_role' => $player_role,
        'game_result' => $winner,
        'winner_bonus' => $winner_bonus,
        'total_user_xp' => (int)$user['total_xp'],
        'message' => 'XP awarded'
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
