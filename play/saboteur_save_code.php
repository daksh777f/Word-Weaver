<?php
// ════════════════════════════════════════
// FILE: saboteur_save_code.php
// PURPOSE: Save player code snapshot and update room code
// NEW TABLES USED: saboteur_code_snapshots, saboteur_rooms
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
$code = (string)($request_data['code'] ?? '');

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

    // ── Check player in room ──────────────────────────
    $stmt = $pdo->prepare("SELECT id FROM saboteur_players WHERE room_id = ? AND user_id = ?");
    $stmt->execute([$room_id, $user_id]);
    if (!$stmt->fetch()) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Player not in room']);
        exit;
    }

    // ── Save code snapshot ──────────────────────────
    $stmt = $pdo->prepare("
        INSERT INTO saboteur_code_snapshots
        (room_id, user_id, code)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$room_id, $user_id, $code]);

    // ── Update room's current_code ──────────────────────────
    $stmt = $pdo->prepare("UPDATE saboteur_rooms SET current_code = ? WHERE id = ?");
    $stmt->execute([$code, $room_id]);

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Code saved'
    ]);

} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
