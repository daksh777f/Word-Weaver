<?php
// ════════════════════════════════════════
// FILE: saboteur_chat.php
// PURPOSE: Send and receive chat messages in room
// NEW TABLES USED: saboteur_chat
// DEPENDS ON: config.php
// REALTIME: polling
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
$action = trim($request_data['action'] ?? 'send');
$message = trim($request_data['message'] ?? '');

if (empty($room_code)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Room code required']);
    exit;
}

try {
    // ── Find room ──────────────────────────
    $stmt = $pdo->prepare("SELECT id FROM saboteur_rooms WHERE room_code = ?");
    $stmt->execute([$room_code]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$room) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Room not found']);
        exit;
    }

    $room_id = (int)$room['id'];

    // ── Get player ──────────────────────────
    $stmt = $pdo->prepare("SELECT id, username, color FROM saboteur_players WHERE room_id = ? AND user_id = ?");
    $stmt->execute([$room_id, $user_id]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$player) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Player not in room']);
        exit;
    }

    if ($action === 'send') {
        if (empty($message) || strlen($message) > 500) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Invalid message']);
            exit;
        }

        // ── Save message ──────────────────────────
        $stmt = $pdo->prepare("
            INSERT INTO saboteur_chat
            (room_id, user_id, username, color, message, is_system)
            VALUES (?, ?, ?, ?, ?, 0)
        ");
        $stmt->execute([
            $room_id,
            $user_id,
            $player['username'],
            $player['color'],
            $message
        ]);

        ob_end_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Message sent'
        ]);

    } elseif ($action === 'get') {
        $since = (int)($request_data['since_id'] ?? 0);

        $stmt = $pdo->prepare("
            SELECT id, user_id, username, color, message, is_system, sent_at
            FROM saboteur_chat
            WHERE room_id = ? AND id > ?
            ORDER BY sent_at ASC
            LIMIT 100
        ");
        $stmt->execute([$room_id, $since]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ob_end_clean();
        echo json_encode([
            'success' => true,
            'messages' => $messages
        ]);

    } else {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }

} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
