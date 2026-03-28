<?php
// ════════════════════════════════════════
// FILE: saboteur_emergency.php
// PURPOSE: Call emergency meeting to start voting early
// NEW TABLES USED: saboteur_rooms, saboteur_players
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
    $stmt = $pdo->prepare("SELECT id, emergency_used, status FROM saboteur_rooms WHERE room_code = ?");
    $stmt->execute([$room_code]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$room) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Room not found']);
        exit;
    }

    $room_id = (int)$room['id'];

    if ($room['status'] !== 'playing') {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Game not in playing state']);
        exit;
    }

    if ($room['emergency_used']) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Emergency already used this round']);
        exit;
    }

    // ── Check player in room ──────────────────────────
    $stmt = $pdo->prepare("SELECT id FROM saboteur_players WHERE room_id = ? AND user_id = ?");
    $stmt->execute([$room_id, $user_id]);
    if (!$stmt->fetch()) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Player not in room']);
        exit;
    }

    // ── Update room to voting state ──────────────────────────
    $stmt = $pdo->prepare("
        UPDATE saboteur_rooms
        SET status = 'voting', emergency_used = 1, voting_started_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$room_id]);

    // ── Reset vote targets ──────────────────────────
    $stmt = $pdo->prepare("UPDATE saboteur_players SET vote_target_id = NULL WHERE room_id = ?");
    $stmt->execute([$room_id]);

    // ── Add system message ──────────────────────────
    $stmt = $pdo->prepare("INSERT INTO saboteur_chat (room_id, user_id, username, color, message, is_system) VALUES (?, ?, ?, ?, ?, 1)");
    $stmt->execute([$room_id, 0, 'SYSTEM', 'system', '🚨 EMERGENCY MEETING CALLED']);

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Emergency meeting started'
    ]);

} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
