<?php
// ════════════════════════════════════════
// FILE: duel_edit_ping.php
// PURPOSE: Track player edits for opponent activity indicator
// NEW TABLES USED: duel_rooms, duel_players
// REALTIME: none
// CEREBRAS CALLS: no
// ════════════════════════════════════════

ob_start();
require_once '../onboarding/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    ob_end_clean();
    echo json_encode(['ok' => false]);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);
$room_code = $input['room_code'] ?? '';

if (empty($room_code)) {
    ob_end_clean();
    echo json_encode(['ok' => false]);
    exit;
}

try {
    // Update player edit count and timestamp
    $stmt = $pdo->prepare("
        UPDATE duel_players dp
        JOIN duel_rooms dr ON dr.id = dp.room_id
        SET dp.edit_count = dp.edit_count + 1,
            dp.last_edit_at = NOW()
        WHERE dr.room_code = ?
        AND dp.user_id = ?
        AND dr.status = 'active'
    ");
    $stmt->execute([$room_code, $user_id]);

    ob_end_clean();
    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    error_log('duel_edit_ping error: ' . $e->getMessage());
    ob_end_clean();
    echo json_encode(['ok' => false]);
}
