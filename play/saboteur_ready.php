<?php
// ════════════════════════════════════════
// FILE: saboteur_ready.php
// PURPOSE: Mark player as ready to start game
// NEW TABLES USED: saboteur_players
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
$is_ready = (bool)($request_data['is_ready'] ?? false);

if (empty($room_code)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Room code required']);
    exit;
}

try {
    // ── Find room ──────────────────────────
    $stmt = $pdo->prepare("SELECT id, status FROM saboteur_rooms WHERE room_code = ?");
    $stmt->execute([$room_code]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$room) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Room not found']);
        exit;
    }

    $room_id = (int)$room['id'];

    // ── Find player ──────────────────────────
    $stmt = $pdo->prepare("SELECT id, is_host FROM saboteur_players WHERE room_id = ? AND user_id = ?");
    $stmt->execute([$room_id, $user_id]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$player) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Player not in room']);
        exit;
    }

    // ── Update ready status ──────────────────────────
    $stmt = $pdo->prepare("UPDATE saboteur_players SET is_ready = ? WHERE id = ?");
    $stmt->execute([$is_ready ? 1 : 0, $player['id']]);

    // ── Check if all players ready ──────────────────────────
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total, SUM(is_ready) as ready
        FROM saboteur_players WHERE room_id = ?
    ");
    $stmt->execute([$room_id]);
    $counts = $stmt->fetch(PDO::FETCH_ASSOC);
    $all_ready = ((int)$counts['total'] > 0 && (int)$counts['total'] === (int)$counts['ready']);

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'is_ready' => $is_ready,
        'all_ready' => $all_ready,
        'ready_count' => (int)$counts['ready'],
        'total_count' => (int)$counts['total']
    ]);

} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
