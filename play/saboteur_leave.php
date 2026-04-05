<?php
// ════════════════════════════════════════
// FILE: saboteur_leave.php
// PURPOSE: Remove current player from a saboteur room and clean host/room state
// DEPENDS ON: config.php
// ════════════════════════════════════════

ob_start();
require_once '../onboarding/config.php';

header('Content-Type: application/json');

function deleteSaboteurRoomData(PDO $pdo, int $roomId): void {
    $stmt = $pdo->prepare('DELETE FROM saboteur_code_snapshots WHERE room_id = ?');
    $stmt->execute([$roomId]);

    $stmt = $pdo->prepare('DELETE FROM saboteur_chat WHERE room_id = ?');
    $stmt->execute([$roomId]);

    $stmt = $pdo->prepare('DELETE FROM saboteur_players WHERE room_id = ?');
    $stmt->execute([$roomId]);

    $stmt = $pdo->prepare('DELETE FROM saboteur_rooms WHERE id = ?');
    $stmt->execute([$roomId]);
}

if (!isLoggedIn()) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$request_data = json_decode(file_get_contents('php://input'), true);
$request_data = is_array($request_data) ? $request_data : [];
$room_code = trim((string)($request_data['room_code'] ?? ''));

if ($room_code === '') {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Room code required']);
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT id, host_id, status FROM saboteur_rooms WHERE room_code = ? LIMIT 1');
    $stmt->execute([$room_code]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$room) {
        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'Room already removed']);
        exit;
    }

    $room_id = (int)$room['id'];

    $stmt = $pdo->prepare('SELECT id, is_host, username FROM saboteur_players WHERE room_id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$room_id, $user_id]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$player) {
        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'Player already not in room']);
        exit;
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare('DELETE FROM saboteur_players WHERE id = ?');
    $stmt->execute([(int)$player['id']]);

    $stmt = $pdo->prepare('SELECT id, user_id FROM saboteur_players WHERE room_id = ? ORDER BY joined_at ASC');
    $stmt->execute([$room_id]);
    $remaining_players = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($remaining_players)) {
        deleteSaboteurRoomData($pdo, $room_id);
    } else {
        if ((int)$player['is_host'] === 1) {
            $new_host = $remaining_players[0];
            $stmt = $pdo->prepare('UPDATE saboteur_players SET is_host = CASE WHEN id = ? THEN 1 ELSE 0 END WHERE room_id = ?');
            $stmt->execute([(int)$new_host['id'], $room_id]);
            $stmt = $pdo->prepare('UPDATE saboteur_rooms SET host_id = ? WHERE id = ?');
            $stmt->execute([(int)$new_host['user_id'], $room_id]);
        }

        $stmt = $pdo->prepare("INSERT INTO saboteur_chat (room_id, user_id, username, color, message, is_system) VALUES (?, 0, 'SYSTEM', 'system', ?, 1)");
        $stmt->execute([$room_id, $player['username'] . ' left the room.']);
    }

    $pdo->commit();

    ob_end_clean();
    echo json_encode(['success' => true, 'message' => 'Left room']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
