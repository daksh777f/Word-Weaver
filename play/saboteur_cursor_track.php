<?php
// ════════════════════════════════════════
// FILE: saboteur_cursor_track.php
// PURPOSE: Track player cursor positions in real-time editor
// NEW TABLES USED: saboteur_players (cursor_line, cursor_col, cursor_pos)
// DEPENDS ON: config.php
// REALTIME: cursor tracking
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
$cursor_line = (int)($request_data['cursor_line'] ?? 0);
$cursor_col = (int)($request_data['cursor_col'] ?? 0);
$cursor_pos = (int)($request_data['cursor_pos'] ?? 0);

if (empty($room_code)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Room code required']);
    exit;
}

try {
    // ── Find room and player ──────────────────────────
    $stmt = $pdo->prepare("SELECT id FROM saboteur_rooms WHERE room_code = ?");
    $stmt->execute([$room_code]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$room) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Room not found']);
        exit;
    }

    $room_id = (int)$room['id'];

    // ── Update player cursor position ──────────────────────────
    $stmt = $pdo->prepare("
        UPDATE saboteur_players 
        SET cursor_line = ?, cursor_col = ?, cursor_pos = ? 
        WHERE room_id = ? AND user_id = ?
    ");
    $stmt->execute([$cursor_line, $cursor_col, $cursor_pos, $room_id, $user_id]);
    
    ob_end_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Cursor position updated',
        'position' => [
            'line' => $cursor_line,
            'col' => $cursor_col,
            'pos' => $cursor_pos
        ]
    ]);

} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>

