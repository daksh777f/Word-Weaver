<?php
// ════════════════════════════════════════
// FILE: duel_bot_submit.php
// PURPOSE: Handle bot code submissions in duels
// NEW TABLES USED: duel_players, duel_rooms
// REALTIME: immediate
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
$data = json_decode(file_get_contents('php://input'), true);
$room_code = trim($data['room_code'] ?? '');
$code = $data['code'] ?? '';
$challenge_id = (int)($data['challenge_id'] ?? 0);

try {
    // ── Validate room ──────────────────────────────────────────────────
    $stmt = $pdo->prepare("SELECT * FROM duel_rooms WHERE room_code = ?");
    $stmt->execute([$room_code]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$room) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Room not found']);
        exit;
    }

    // ── Find bot player record ─────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT * FROM duel_players
        WHERE room_id = ? AND user_id = -1
    ");
    $stmt->execute([(int)$room['id']]);
    $botPlayer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$botPlayer) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Bot player not found']);
        exit;
    }

    // ── Update bot player status to submitted ──────────────────────────
    $stmt = $pdo->prepare("
        UPDATE duel_players
            SET submitted_code = ?,
                submitted_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$code, (int)$botPlayer['id']]);

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Bot submission recorded',
        'submitted_at' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    error_log('duel_bot_submit error: ' . $e->getMessage());
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
?>
