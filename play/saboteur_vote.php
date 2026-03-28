<?php
// ════════════════════════════════════════
// FILE: saboteur_vote.php
// PURPOSE: Cast vote to eliminate suspected saboteur
// NEW TABLES USED: saboteur_players, saboteur_rooms
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
$target_user_id = (int)($request_data['target_user_id'] ?? 0);

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

    if ($room['status'] !== 'voting') {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Not in voting state']);
        exit;
    }

    // ── Get voter ──────────────────────────
    $stmt = $pdo->prepare("SELECT id FROM saboteur_players WHERE room_id = ? AND user_id = ?");
    $stmt->execute([$room_id, $user_id]);
    $voter = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$voter) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Player not in room']);
        exit;
    }

    // ── Get target if specified ──────────────────────────
    if ($target_user_id > 0) {
        $stmt = $pdo->prepare("SELECT id FROM saboteur_players WHERE room_id = ? AND user_id = ? AND is_eliminated = 0");
        $stmt->execute([$room_id, $target_user_id]);
        $target_player = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$target_player) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Target player not found or eliminated']);
            exit;
        }

        $target_id = (int)$target_player['id'];
    } else {
        $target_id = null;
    }

    // ── Record vote ──────────────────────────
    $stmt = $pdo->prepare("UPDATE saboteur_players SET vote_target_id = ? WHERE id = ?");
    $stmt->execute([$target_id, $voter['id']]);

    // ── Check if all players have voted ──────────────────────────
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total, 
               SUM(CASE WHEN vote_target_id IS NOT NULL THEN 1 ELSE 0 END) as voted
        FROM saboteur_players WHERE room_id = ? AND is_eliminated = 0
    ");
    $stmt->execute([$room_id]);
    $vote_counts = $stmt->fetch(PDO::FETCH_ASSOC);
    $all_voted = ((int)$vote_counts['total'] > 0 && (int)$vote_counts['total'] === (int)$vote_counts['voted']);

    // ── Calculate vote results if all voted ──────────────────────────
    $eliminated_user_id = null;
    if ($all_voted) {
        $stmt = $pdo->prepare("
            SELECT vote_target_id, COUNT(*) as count
            FROM saboteur_players
            WHERE room_id = ? AND is_eliminated = 0 AND vote_target_id IS NOT NULL
            GROUP BY vote_target_id
            ORDER BY count DESC
            LIMIT 1
        ");
        $stmt->execute([$room_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result && $result['vote_target_id']) {
            $stmt = $pdo->prepare("
                UPDATE saboteur_players SET is_eliminated = 1
                WHERE id = ?
            ");
            $stmt->execute([(int)$result['vote_target_id']]);

            $stmt = $pdo->prepare("SELECT user_id, role FROM saboteur_players WHERE id = ?");
            $stmt->execute([(int)$result['vote_target_id']]);
            $eliminated = $stmt->fetch(PDO::FETCH_ASSOC);
            $eliminated_user_id = (int)$eliminated['user_id'];
            $eliminated_role = $eliminated['role'];
        }
    }

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'vote_recorded' => true,
        'all_voted' => $all_voted,
        'voted_count' => (int)$vote_counts['voted'],
        'total_count' => (int)$vote_counts['total'],
        'eliminated_user_id' => $eliminated_user_id
    ]);

} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
