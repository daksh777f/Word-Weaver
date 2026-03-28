<?php
// ════════════════════════════════════════
// FILE: saboteur_start.php
// PURPOSE: Start game, select challenge, and assign roles
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
$room_code = trim($request_data['room_code'] ?? '');

if (empty($room_code)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Room code required']);
    exit;
}

try {
    // ── Find room ──────────────────────────
    $stmt = $pdo->prepare("SELECT id, host_id, category, challenge_id FROM saboteur_rooms WHERE room_code = ?");
    $stmt->execute([$room_code]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$room) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Room not found']);
        exit;
    }

    // ── Check if caller is host ──────────────────────────
    if ((int)$room['host_id'] !== $user_id) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Only host can start game']);
        exit;
    }

    $room_id = (int)$room['id'];

    // ── Get random challenge by category ──────────────────────────
    if (empty($room['challenge_id']) || $room['challenge_id'] == 0) {
        $category = $room['category'];
        if (empty($category)) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Category not selected']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT id FROM saboteur_challenges
            WHERE category = ?
            ORDER BY RAND()
            LIMIT 1
        ");
        $stmt->execute([$category]);
        $challenge = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$challenge) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'No challenges in category']);
            exit;
        }

        $challenge_id = (int)$challenge['id'];

        // ── Update room with challenge ──────────────────────────
        $stmt = $pdo->prepare("UPDATE saboteur_rooms SET challenge_id = ? WHERE id = ?");
        $stmt->execute([$challenge_id, $room_id]);
    } else {
        $challenge_id = (int)$room['challenge_id'];
    }

    // ── Get all players in room ──────────────────────────
    $stmt = $pdo->prepare("SELECT id, user_id FROM saboteur_players WHERE room_id = ? ORDER BY joined_at");
    $stmt->execute([$room_id]);
    $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($players) < 2) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Need at least 2 players']);
        exit;
    }

    // ── Assign roles: 1 saboteur, rest fixers ──────────────────────────
    $saboteur_index = rand(0, count($players) - 1);

    foreach ($players as $idx => $player) {
        $role = ($idx === $saboteur_index) ? 'saboteur' : 'fixer';
        $stmt = $pdo->prepare("UPDATE saboteur_players SET role = ? WHERE id = ?");
        $stmt->execute([$role, $player['id']]);
    }

    // ── Update room status ──────────────────────────
    $stmt = $pdo->prepare("UPDATE saboteur_rooms SET status = 'role_reveal', game_started_at = NOW(), round_started_at = NOW() WHERE id = ?");
    $stmt->execute([$room_id]);

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'challenge_id' => $challenge_id,
        'player_count' => count($players),
        'message' => 'Game started'
    ]);

} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
