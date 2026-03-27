<?php
// ════════════════════════════════════════
// FILE: duel_matchmaker.php
// PURPOSE: Handle 1v1 duel matchmaking — find/create waiting rooms
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

try {
    // ── STEP 1: Clean up stale waiting rooms ────────────────────────────
    // Delete rooms waiting longer than 5 minutes
    $pdo->exec("DELETE FROM duel_rooms 
        WHERE status = 'waiting' 
        AND created_at < NOW() - INTERVAL 5 MINUTE");

    // ── STEP 2: Check if user already in active room ────────────────────
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
        // User already in room
        ob_end_clean();
        echo json_encode([
            'success' => true,
            'matched' => false,
            'room_code' => $activeRoom['room_code'],
            'message' => 'Already in a room'
        ]);
        exit;
    }

    // ── STEP 3: Look for open waiting room ──────────────────────────────
    $stmt = $pdo->prepare("
        SELECT * FROM duel_rooms
        WHERE status = 'waiting'
        AND player2_id IS NULL
        AND player1_id != ?
        ORDER BY created_at ASC
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $openRoom = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($openRoom) {
        // ── JOIN existing room ──────────────────────────────────────────
        
        // Pick a random challenge
        $stmt = $pdo->query("
            SELECT id, title, difficulty, concept_tags, broken_code, language
            FROM bug_challenges
            WHERE challenge_type = 'bug_fix'
            ORDER BY RAND()
            LIMIT 1
        ");
        $challenge = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$challenge) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'No challenges available']);
            exit;
        }

        // Update room: set player2, challenge, and status
        $stmt = $pdo->prepare("
            UPDATE duel_rooms SET
                player2_id = ?,
                challenge_id = ?,
                status = 'countdown',
                countdown_started_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$user_id, $challenge['id'], $openRoom['id']]);

        // Insert player2 record
        $stmt = $pdo->prepare("
            INSERT INTO duel_players (room_id, user_id, player_number)
            VALUES (?, ?, 2)
        ");
        $stmt->execute([$openRoom['id'], $user_id]);

        ob_end_clean();
        echo json_encode([
            'success' => true,
            'matched' => true,
            'room_code' => $openRoom['room_code']
        ]);
        exit;
    }

    // ── STEP 4: No open room — create new one ──────────────────────────

    // Generate unique room code (8 alphanumeric)
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

    // Pick a random challenge for this room
    $stmt = $pdo->query("
        SELECT id FROM bug_challenges
        WHERE challenge_type = 'bug_fix'
        ORDER BY RAND()
        LIMIT 1
    ");
    $challenge = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$challenge) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'No challenges available']);
        exit;
    }

    // Create new room
    $stmt = $pdo->prepare("
        INSERT INTO duel_rooms (room_code, challenge_id, challenge_type, status, player1_id)
        VALUES (?, ?, 'bug_fix', 'waiting', ?)
    ");
    $stmt->execute([$roomCode, $challenge['id'], $user_id]);
    $roomId = (int)$pdo->lastInsertId();

    // Insert player1 record
    $stmt = $pdo->prepare("
        INSERT INTO duel_players (room_id, user_id, player_number)
        VALUES (?, ?, 1)
    ");
    $stmt->execute([$roomId, $user_id]);

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'matched' => false,
        'room_code' => $roomCode,
        'message' => 'Waiting for opponent'
    ]);

} catch (Exception $e) {
    error_log('duel_matchmaker error: ' . $e->getMessage());
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
