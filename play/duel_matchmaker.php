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

if (!isLoggedIn()) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$request_data = json_decode(file_get_contents('php://input'), true);
if (!is_array($request_data)) {
    $request_data = [];
}

$requestedLanguage = strtolower(trim((string)($request_data['language'] ?? '')));
$allowedLanguageStmt = $pdo->query("SELECT DISTINCT LOWER(language) AS language FROM bug_challenges WHERE challenge_type = 'bug_fix' AND language IS NOT NULL AND language <> ''");
$allowedLanguages = array_values(array_filter(array_map(static function ($row) {
    return strtolower(trim((string)($row['language'] ?? '')));
}, $allowedLanguageStmt->fetchAll(PDO::FETCH_ASSOC))));

if ($requestedLanguage !== '' && !in_array($requestedLanguage, $allowedLanguages, true)) {
    $requestedLanguage = '';
}

try {
    $pdo->exec("DELETE FROM duel_rooms WHERE status = 'waiting' AND created_at < NOW() - INTERVAL 5 MINUTE");

    $stmt = $pdo->prepare("\n        SELECT dr.* FROM duel_rooms dr
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
            'success' => true,
            'matched' => false,
            'room_code' => $activeRoom['room_code'],
            'message' => 'Already in a room'
        ]);
        exit;
    }

    if ($requestedLanguage !== '') {
        $stmt = $pdo->prepare("\n            SELECT dr.*
            FROM duel_rooms dr
            JOIN bug_challenges bc ON bc.id = dr.challenge_id
            WHERE dr.status = 'waiting'
            AND dr.player2_id IS NULL
            AND dr.player1_id != ?
            AND LOWER(bc.language) = ?
            ORDER BY dr.created_at ASC
            LIMIT 1
        ");
        $stmt->execute([$user_id, $requestedLanguage]);
    } else {
        $stmt = $pdo->prepare("\n            SELECT * FROM duel_rooms
            WHERE status = 'waiting'
            AND player2_id IS NULL
            AND player1_id != ?
            ORDER BY created_at ASC
            LIMIT 1
        ");
        $stmt->execute([$user_id]);
    }
    $openRoom = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($openRoom) {
        if ($requestedLanguage !== '') {
            $stmt = $pdo->prepare("\n                SELECT id, title, difficulty, concept_tags, broken_code, language
                FROM bug_challenges
                WHERE challenge_type = 'bug_fix'
                AND LOWER(language) = ?
                ORDER BY RAND()
                LIMIT 1
            ");
            $stmt->execute([$requestedLanguage]);
            $challenge = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmt = $pdo->query("\n                SELECT id, title, difficulty, concept_tags, broken_code, language
                FROM bug_challenges
                WHERE challenge_type = 'bug_fix'
                ORDER BY RAND()
                LIMIT 1
            ");
            $challenge = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$challenge) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'No challenges available']);
            exit;
        }

        $stmt = $pdo->prepare("\n            UPDATE duel_rooms SET
                player2_id = ?,
                challenge_id = ?,
                status = 'countdown',
                countdown_started_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$user_id, $challenge['id'], $openRoom['id']]);

        $stmt = $pdo->prepare("\n            INSERT INTO duel_players (room_id, user_id, player_number)
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

    function generateRoomCode($pdo) {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $stmt = $pdo->prepare("SELECT id FROM duel_rooms WHERE room_code = ?");
            $stmt->execute([$code]);
            if (!$stmt->fetch()) {
                return $code;
            }
        }
        exit('Could not generate unique room code');
    }

    $roomCode = generateRoomCode($pdo);

    if ($requestedLanguage !== '') {
        $stmt = $pdo->prepare("\n            SELECT id FROM bug_challenges
            WHERE challenge_type = 'bug_fix'
            AND LOWER(language) = ?
            ORDER BY RAND()
            LIMIT 1
        ");
        $stmt->execute([$requestedLanguage]);
        $challenge = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->query("\n            SELECT id FROM bug_challenges
            WHERE challenge_type = 'bug_fix'
            ORDER BY RAND()
            LIMIT 1
        ");
        $challenge = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$challenge) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'No challenges available']);
        exit;
    }

    $stmt = $pdo->prepare("\n        INSERT INTO duel_rooms (room_code, challenge_id, challenge_type, status, player1_id)
        VALUES (?, ?, 'bug_fix', 'waiting', ?)
    ");
    $stmt->execute([$roomCode, $challenge['id'], $user_id]);
    $roomId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("\n        INSERT INTO duel_players (room_id, user_id, player_number)
        VALUES (?, ?, 1)
    ");
    $stmt->execute([$roomId, $user_id]);

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'matched' => false,
        'room_code' => $roomCode,
        'message' => 'Waiting for opponent',
        'language' => $requestedLanguage
    ]);
} catch (Exception $e) {
    error_log('duel_matchmaker error: ' . $e->getMessage());
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
