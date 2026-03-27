<?php
// ════════════════════════════════════════
// FILE: duel_bot_matchmaker.php
// PURPOSE: Create bot duel rooms for demo/practice
// NEW TABLES USED: duel_rooms, duel_players, bug_challenges
// REALTIME: short poll
// CEREBRAS CALLS: no
// ════════════════════════════════════════

ob_start();
require_once '../onboarding/config.php';

header('Content-Type: application/json');

// requireLogin check
if (!isLoggedIn()) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$request_data = json_decode(file_get_contents('php://input'), true);
$mode = $request_data['mode'] ?? 'easy'; // easy, medium, hard

try {
    // ── Check if user already in active room ────────────────────────
    $stmt = $pdo->prepare("
        SELECT dr.* FROM duel_rooms dr
        JOIN duel_players dp ON dp.room_id = dr.id
        WHERE dp.user_id = ?
        AND dr.status IN ('waiting','countdown','active')
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $activeRoom = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($activeRoom) {
        ob_end_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Already in a room'
        ]);
        exit;
    }

    // ── Pick a random challenge ──────────────────────────────────────
    $difficulty = ['easy' => 'beginner', 'medium' => 'intermediate', 'hard' => 'advanced'][$mode] ?? 'beginner';
    
    $stmt = $pdo->prepare("
        SELECT id, title, difficulty, concept_tags, broken_code, language
        FROM bug_challenges
        WHERE challenge_type = 'bug_fix'
        AND difficulty = ?
        ORDER BY RAND()
        LIMIT 1
    ");
    $stmt->execute([$difficulty]);
    $challenge = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$challenge) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'No challenges available']);
        exit;
    }

    // ── Generate unique room code ────────────────────────────────────
    function generateRoomCode($pdo) {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
            
            // Check uniqueness
            $stmt = $pdo->prepare("SELECT id FROM duel_rooms WHERE room_code = ?");
            $stmt->execute([$code]);
            if (!$stmt->fetch()) {
                return $code;
            }
        }
        exit('Could not generate unique room code');
    }

    $roomCode = generateRoomCode($pdo);

    // ── Create bot player in database ────────────────────────────────
    // Use a special bot user ID (negative or special marker)
    $botUserId = -1; // Special ID for bot player

    // ── Create new room ──────────────────────────────────────────────
    $stmt = $pdo->prepare("
        INSERT INTO duel_rooms (room_code, challenge_id, challenge_type, status, player1_id, player2_id, countdown_started_at)
        VALUES (?, ?, 'bug_fix', 'countdown', ?, ?, NOW())
    ");
    $stmt->execute([$roomCode, $challenge['id'], $user_id, $botUserId]);
    $roomId = (int)$pdo->lastInsertId();

    // ── Insert player1 record (human) ────────────────────────────────
    $stmt = $pdo->prepare("
        INSERT INTO duel_players (room_id, user_id, player_number)
        VALUES (?, ?, 1)
    ");
    $stmt->execute([$roomId, $user_id]);

    // ── Insert player2 record (bot) ──────────────────────────────────
    $stmt = $pdo->prepare("
        INSERT INTO duel_players (room_id, user_id, player_number)
        VALUES (?, ?, 2)
    ");
    $stmt->execute([$roomId, $botUserId]);

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'matched' => true,
        'room_code' => $roomCode,
        'is_bot' => true,
        'message' => 'Bot duel ready!'
    ]);

} catch (Exception $e) {
    error_log('duel_bot_matchmaker error: ' . $e->getMessage());
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
?>
