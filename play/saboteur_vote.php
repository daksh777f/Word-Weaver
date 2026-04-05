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

function finishVoting(PDO $pdo, int $room_id): array {
    $stmt = $pdo->prepare("SELECT current_round, max_rounds FROM saboteur_rooms WHERE id = ? LIMIT 1");
    $stmt->execute([$room_id]);
    $room_meta = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT vote_target_id, COUNT(*) AS votes FROM saboteur_players WHERE room_id = ? AND is_eliminated = 0 AND vote_target_id IS NOT NULL GROUP BY vote_target_id ORDER BY votes DESC");
    $stmt->execute([$room_id]);
    $vote_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $eliminated_user_id = null;
    $winner = null;

    if (!empty($vote_rows)) {
        $top_votes = (int)$vote_rows[0]['votes'];
        $is_tie = isset($vote_rows[1]) && (int)$vote_rows[1]['votes'] === $top_votes;
        if (!$is_tie && !empty($vote_rows[0]['vote_target_id'])) {
            $eliminated_player_id = (int)$vote_rows[0]['vote_target_id'];

            $stmt = $pdo->prepare("UPDATE saboteur_players SET is_eliminated = 1 WHERE id = ? AND room_id = ?");
            $stmt->execute([$eliminated_player_id, $room_id]);

            $stmt = $pdo->prepare("SELECT user_id, role, username FROM saboteur_players WHERE id = ? LIMIT 1");
            $stmt->execute([$eliminated_player_id]);
            $eliminated = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($eliminated) {
                $eliminated_user_id = (int)$eliminated['user_id'];
                if ($eliminated['role'] === 'saboteur') {
                    $winner = 'fixers';
                }

                $stmt = $pdo->prepare("INSERT INTO saboteur_chat (room_id, user_id, username, color, message, is_system) VALUES (?, 0, 'SYSTEM', 'system', ?, 1)");
                $stmt->execute([$room_id, 'Vote result: ' . $eliminated['username'] . ' was eliminated.']);
            }
        }
    }

    if ($eliminated_user_id === null) {
        $stmt = $pdo->prepare("INSERT INTO saboteur_chat (room_id, user_id, username, color, message, is_system) VALUES (?, 0, 'SYSTEM', 'system', 'Vote tied. No player was eliminated.', 1)");
        $stmt->execute([$room_id]);
    }

    if ($winner === null) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM saboteur_players WHERE room_id = ? AND role = 'saboteur' AND is_eliminated = 0");
        $stmt->execute([$room_id]);
        if ((int)$stmt->fetchColumn() <= 0) {
            $winner = 'fixers';
        }
    }

    if ($winner === null && $room_meta && (int)$room_meta['current_round'] >= (int)$room_meta['max_rounds']) {
        $winner = 'saboteur';
    }

    if ($winner !== null) {
        $stmt = $pdo->prepare("UPDATE saboteur_rooms SET status = 'finished', winner = ?, game_ended_at = NOW(), voting_started_at = NULL WHERE id = ?");
        $stmt->execute([$winner, $room_id]);
    } else {
        $stmt = $pdo->prepare("UPDATE saboteur_rooms SET status = 'playing', current_round = current_round + 1, emergency_used = 0, round_started_at = NOW(), voting_started_at = NULL WHERE id = ?");
        $stmt->execute([$room_id]);
        $stmt = $pdo->prepare("UPDATE saboteur_players SET vote_target_id = NULL WHERE room_id = ?");
        $stmt->execute([$room_id]);
    }

    return [
        'eliminated_user_id' => $eliminated_user_id,
        'winner' => $winner
    ];
}

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
    $stmt = $pdo->prepare("SELECT id, is_eliminated FROM saboteur_players WHERE room_id = ? AND user_id = ?");
    $stmt->execute([$room_id, $user_id]);
    $voter = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$voter) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Player not in room']);
        exit;
    }

    if ((int)$voter['is_eliminated'] === 1) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Eliminated players cannot vote']);
        exit;
    }

    // ── Validate target ──────────────────────────
    if ($target_user_id <= 0 || $target_user_id === $user_id) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Select a valid player to vote']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM saboteur_players WHERE room_id = ? AND user_id = ? AND is_eliminated = 0");
    $stmt->execute([$room_id, $target_user_id]);
    $target_player = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$target_player) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Target player not found or eliminated']);
        exit;
    }

    $target_id = (int)$target_player['id'];

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
    $winner = null;
    if ($all_voted) {
        $result = finishVoting($pdo, $room_id);
        $eliminated_user_id = $result['eliminated_user_id'];
        $winner = $result['winner'];
    }

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'vote_recorded' => true,
        'all_voted' => $all_voted,
        'voted_count' => (int)$vote_counts['voted'],
        'total_count' => (int)$vote_counts['total'],
        'eliminated_user_id' => $eliminated_user_id,
        'winner' => $winner
    ]);

} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
