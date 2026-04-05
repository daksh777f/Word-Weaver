<?php
// ════════════════════════════════════════
// FILE: saboteur_join.php
// PURPOSE: Join an existing saboteur room
// NEW TABLES USED: saboteur_rooms, saboteur_players
// DEPENDS ON: config.php
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
$room_code = strtoupper(trim((string)($request_data['room_code'] ?? '')));

if (empty($room_code)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Room code required']);
    exit;
}

try {
    // ── Get user details ──────────────────────────────────
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }

    // ── Check if user already in another active room ──────────────────────────
        $stmt = $pdo->prepare("
            SELECT sr.room_code, sr.status FROM saboteur_rooms sr
        INNER JOIN saboteur_players sp ON sr.id = sp.room_id
        WHERE sp.user_id = ? AND sr.status IN ('lobby', 'role_reveal', 'playing', 'voting')
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
        $existingActiveRoom = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existingActiveRoom) {
            $existingCode = (string)$existingActiveRoom['room_code'];
            $existingStatus = (string)$existingActiveRoom['status'];
            $redirectUrl = ($existingStatus === 'lobby')
                ? ('saboteur_lobby.php?room=' . urlencode($existingCode))
                : ('saboteur_game.php?room=' . urlencode($existingCode));

            if (strcasecmp((string)$existingActiveRoom['room_code'], $room_code) === 0) {
                ob_end_clean();
                echo json_encode([
                    'success' => true,
                    'room_code' => $existingCode,
                    'message' => 'Already in this room',
                    'redirect_url' => $redirectUrl
                ]);
                exit;
            }

            ob_end_clean();
            echo json_encode([
                'success' => false,
                'message' => 'Already in active room',
                'room_code' => $existingCode,
                'redirect_url' => $redirectUrl
            ]);
            exit;
        }

    // ── Find room ──────────────────────────
    $stmt = $pdo->prepare("SELECT id, status FROM saboteur_rooms WHERE room_code = ?");
    $stmt->execute([$room_code]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$room) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Room not found']);
        exit;
    }

    if ($room['status'] !== 'lobby') {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Game already started']);
        exit;
    }

    $room_id = (int)$room['id'];

    // ── Check if user already in room ──────────────────────────
    $stmt = $pdo->prepare("SELECT id FROM saboteur_players WHERE room_id = ? AND user_id = ?");
    $stmt->execute([$room_id, $user_id]);
    if ($stmt->fetch()) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Already in this room']);
        exit;
    }

    // ── Check player count ──────────────────────────
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM saboteur_players WHERE room_id = ?");
    $stmt->execute([$room_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $player_count = (int)$result['count'];

    if ($player_count >= 5) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Room is full']);
        exit;
    }

    // ── Get available colors ──────────────────────────
    $colors = ['red', 'blue', 'green', 'orange', 'purple'];
    $stmt = $pdo->prepare("SELECT color FROM saboteur_players WHERE room_id = ?");
    $stmt->execute([$room_id]);
    $used_colors = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'color');
    $available_colors = array_diff($colors, $used_colors);
    $player_color = reset($available_colors);

    // ── Add player to room ──────────────────────────
    $stmt = $pdo->prepare("
        INSERT INTO saboteur_players
        (room_id, user_id, username, color, is_host, is_ready)
        VALUES (?, ?, ?, ?, 0, 0)
    ");
    $stmt->execute([$room_id, $user_id, $user['username'], $player_color]);

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'room_id' => $room_id,
        'room_code' => $room_code,
        'player_count' => $player_count + 1,
        'message' => 'Joined room',
        'redirect_url' => 'saboteur_lobby.php?room=' . urlencode($room_code)
    ]);

} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
