<?php
// ════════════════════════════════════════
// FILE: duel_cancel.php
// PURPOSE: Cancel waiting room search
// NEW TABLES USED: duel_rooms, duel_players
// REALTIME: none
// CEREBRAS CALLS: no
// ════════════════════════════════════════

ob_start();
require_once '../onboarding/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    ob_end_clean();
    echo json_encode(['success' => false]);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

try {
    // Find waiting rooms for this user
    $stmt = $pdo->prepare("
        SELECT dr.id FROM duel_rooms dr
        JOIN duel_players dp ON dp.room_id = dr.id
        WHERE dp.user_id = ?
        AND dr.status = 'waiting'
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $waitingRoom = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($waitingRoom) {
        // Delete duel players for this room
        $stmt = $pdo->prepare("DELETE FROM duel_players WHERE room_id = ?");
        $stmt->execute([$waitingRoom['id']]);

        // Delete the room
        $stmt = $pdo->prepare("DELETE FROM duel_rooms WHERE id = ? AND status = 'waiting'");
        $stmt->execute([$waitingRoom['id']]);
    }

    ob_end_clean();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log('duel_cancel error: ' . $e->getMessage());
    ob_end_clean();
    echo json_encode(['success' => false]);
}
