<?php
// ════════════════════════════════════════
// FILE: saboteur_create.php
// PURPOSE: Create a saboteur room and assign host
// NEW TABLES USED: saboteur_rooms, saboteur_players, saboteur_challenges
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
$category = trim($request_data['category'] ?? '');

if ($category === '') {
    $category = 'Object Oriented';
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

    // ── Check if user already in active room ──────────────────────────
    $stmt = $pdo->prepare("
        SELECT sr.room_code FROM saboteur_rooms sr
        INNER JOIN saboteur_players sp ON sr.id = sp.room_id
        WHERE sp.user_id = ? AND sr.status IN ('lobby', 'role_reveal', 'playing', 'voting')
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    if ($stmt->fetch()) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Already in active room']);
        exit;
    }

    // ── Generate unique room code ──────────────────────────
    $room_code = strtoupper(substr(md5(uniqid($user_id, true)), 0, 6));
    $max_attempts = 10;
    $attempt = 0;
    while ($attempt < $max_attempts) {
        $stmt = $pdo->prepare("SELECT id FROM saboteur_rooms WHERE room_code = ?");
        $stmt->execute([$room_code]);
        if (!$stmt->fetch()) {
            break;
        }
        $room_code = strtoupper(substr(md5(uniqid($user_id, true) . $attempt), 0, 6));
        $attempt++;
    }

    if ($attempt >= $max_attempts) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Failed to generate room code']);
        exit;
    }

    // ── Create room ──────────────────────────
    $stmt = $pdo->prepare("
        INSERT INTO saboteur_rooms
        (room_code, host_id, challenge_id, category, status)
        VALUES (?, ?, 0, ?, 'lobby')
    ");
    $stmt->execute([$room_code, $user_id, $category]);
    $room_id = $pdo->lastInsertId();

    // ── Add host as player ──────────────────────────
    $colors = ['red', 'blue', 'green', 'orange', 'purple'];
    $host_color = $colors[0];

    // Host does NOT need to be ready - they just start the game
    $stmt = $pdo->prepare("
        INSERT INTO saboteur_players
        (room_id, user_id, username, color, is_host, is_ready)
        VALUES (?, ?, ?, ?, 1, 0)
    ");
    $stmt->execute([$room_id, $user_id, $user['username'], $host_color]);

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'room_code' => $room_code,
        'room_id' => $room_id,
        'message' => 'Room created'
    ]);

} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
