<?php
// ════════════════════════════════════════
// FILE: saboteur_poll.php
// PURPOSE: Poll game state, players, current code, and chat
// NEW TABLES USED: saboteur_rooms, saboteur_players, saboteur_challenges, saboteur_chat, saboteur_code_snapshots
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

if (empty($room_code)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Room code required']);
    exit;
}

try {
    // ── Find room ──────────────────────────
    $stmt = $pdo->prepare("SELECT * FROM saboteur_rooms WHERE room_code = ?");
    $stmt->execute([$room_code]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$room) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Room not found']);
        exit;
    }

    $room_id = (int)$room['id'];

    // ── Get current player in room ──────────────────────────
    $stmt = $pdo->prepare("SELECT * FROM saboteur_players WHERE room_id = ? AND user_id = ?");
    $stmt->execute([$room_id, $user_id]);
    $current_player = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$current_player) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Player not in room']);
        exit;
    }

    // ── Get all players ──────────────────────────
    $stmt = $pdo->prepare("SELECT id, user_id, username, color, role, is_ready, is_host, is_eliminated, vote_target_id FROM saboteur_players WHERE room_id = ? ORDER BY joined_at");
    $stmt->execute([$room_id]);
    $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $players_data = [];
    foreach ($players as $p) {
        $players_data[] = [
            'id' => (int)$p['id'],
            'user_id' => (int)$p['user_id'],
            'username' => $p['username'],
            'color' => $p['color'],
            'role' => $room['status'] !== 'lobby' ? $p['role'] : null,
            'is_ready' => (bool)$p['is_ready'],
            'is_host' => (bool)$p['is_host'],
            'is_eliminated' => (bool)$p['is_eliminated'],
            'is_you' => ($p['user_id'] == $user_id)
        ];
    }

    // ── Get challenge details ──────────────────────────
    $challenge = null;
    if (!empty($room['challenge_id'])) {
        $stmt = $pdo->prepare("SELECT id, title, base_code, test_cases, sabotage_tasks, todo_descriptions FROM saboteur_challenges WHERE id = ?");
        $stmt->execute([(int)$room['challenge_id']]);
        $ch = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($ch) {
            $challenge = [
                'id' => (int)$ch['id'],
                'title' => $ch['title'],
                'base_code' => $ch['base_code'],
                'test_cases' => json_decode($ch['test_cases'], true),
                'todo_descriptions' => json_decode($ch['todo_descriptions'], true),
                'sabotage_tasks' => json_decode($ch['sabotage_tasks'], true)
            ];
        }
    }

    // ── Get recent chat messages ──────────────────────────
    $stmt = $pdo->prepare("
        SELECT user_id, username, color, message, is_system, sent_at
        FROM saboteur_chat
        WHERE room_id = ?
        ORDER BY sent_at DESC
        LIMIT 50
    ");
    $stmt->execute([$room_id]);
    $chat_messages = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

    // ── Get current code ──────────────────────────
    $current_code = $room['current_code'] ?? '';

    // ── Calculate round timer ──────────────────────────
    $round_elapsed = 0;
    if (!empty($room['round_started_at'])) {
        $round_start = new DateTime($room['round_started_at']);
        $round_elapsed = (new DateTime())->getTimestamp() - $round_start->getTimestamp();
    }
    $round_remaining = max(0, (int)$room['round_duration'] - $round_elapsed);

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'room' => [
            'id' => (int)$room['id'],
            'room_code' => $room['room_code'],
            'status' => $room['status'],
            'current_round' => (int)$room['current_round'],
            'max_rounds' => (int)$room['max_rounds'],
            'round_duration' => (int)$room['round_duration'],
            'round_remaining' => $round_remaining,
            'winner' => $room['winner'],
            'emergency_used' => (bool)$room['emergency_used']
        ],
        'players' => $players_data,
        'current_player' => [
            'id' => (int)$current_player['id'],
            'is_host' => (bool)$current_player['is_host'],
            'role' => $current_player['role']
        ],
        'challenge' => $challenge,
        'current_code' => $current_code,
        'chat_messages' => $chat_messages
    ]);

} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
