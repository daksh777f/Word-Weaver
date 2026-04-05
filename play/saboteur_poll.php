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

function purgeAbandonedSaboteurRooms(PDO $pdo): void {
    $staleRoomStmt = $pdo->query("\n        SELECT sr.id
        FROM saboteur_rooms sr
        LEFT JOIN (
            SELECT room_id, COUNT(*) AS player_count, MAX(last_seen) AS max_last_seen
            FROM saboteur_players
            GROUP BY room_id
        ) sp ON sp.room_id = sr.id
        WHERE
            sp.player_count IS NULL
            OR (
                sr.status = 'lobby'
                AND COALESCE(sp.max_last_seen, sr.created_at) < NOW() - INTERVAL 10 MINUTE
            )
            OR (
                sr.status IN ('role_reveal', 'playing', 'voting')
                AND COALESCE(sp.max_last_seen, sr.round_started_at, sr.game_started_at, sr.created_at) < NOW() - INTERVAL 5 MINUTE
            )
            OR (
                sr.status = 'finished'
                AND COALESCE(sr.game_ended_at, sr.created_at) < NOW() - INTERVAL 15 MINUTE
            )
    ");

    $staleRoomIds = $staleRoomStmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($staleRoomIds)) {
        return;
    }

    $deleteSnapshots = $pdo->prepare('DELETE FROM saboteur_code_snapshots WHERE room_id = ?');
    $deleteChat = $pdo->prepare('DELETE FROM saboteur_chat WHERE room_id = ?');
    $deletePlayers = $pdo->prepare('DELETE FROM saboteur_players WHERE room_id = ?');
    $deleteRoom = $pdo->prepare('DELETE FROM saboteur_rooms WHERE id = ?');

    foreach ($staleRoomIds as $staleRoomId) {
        $roomId = (int)$staleRoomId;
        $deleteSnapshots->execute([$roomId]);
        $deleteChat->execute([$roomId]);
        $deletePlayers->execute([$roomId]);
        $deleteRoom->execute([$roomId]);
    }
}

function resolveVotingRound(PDO $pdo, int $room_id): void {
    $stmt = $pdo->prepare("SELECT current_round, max_rounds FROM saboteur_rooms WHERE id = ? LIMIT 1");
    $stmt->execute([$room_id]);
    $room_meta = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$room_meta) {
        return;
    }

    $stmt = $pdo->prepare("SELECT vote_target_id, COUNT(*) AS votes FROM saboteur_players WHERE room_id = ? AND is_eliminated = 0 AND vote_target_id IS NOT NULL GROUP BY vote_target_id ORDER BY votes DESC");
    $stmt->execute([$room_id]);
    $vote_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $eliminated_player_id = null;
    if (!empty($vote_rows)) {
        $top_votes = (int)$vote_rows[0]['votes'];
        $is_tie = isset($vote_rows[1]) && (int)$vote_rows[1]['votes'] === $top_votes;
        if (!$is_tie && !empty($vote_rows[0]['vote_target_id'])) {
            $eliminated_player_id = (int)$vote_rows[0]['vote_target_id'];
        }
    }

    $winner = null;
    if ($eliminated_player_id) {
        $stmt = $pdo->prepare("UPDATE saboteur_players SET is_eliminated = 1 WHERE id = ? AND room_id = ?");
        $stmt->execute([$eliminated_player_id, $room_id]);

        $stmt = $pdo->prepare("SELECT role, username FROM saboteur_players WHERE id = ? LIMIT 1");
        $stmt->execute([$eliminated_player_id]);
        $eliminated = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($eliminated && $eliminated['role'] === 'saboteur') {
            $winner = 'fixers';
        }

        $message = $eliminated
            ? ('Vote result: ' . $eliminated['username'] . ' was eliminated.')
            : 'Vote result: a player was eliminated.';
        $stmt = $pdo->prepare("INSERT INTO saboteur_chat (room_id, user_id, username, color, message, is_system) VALUES (?, 0, 'SYSTEM', 'system', ?, 1)");
        $stmt->execute([$room_id, $message]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO saboteur_chat (room_id, user_id, username, color, message, is_system) VALUES (?, 0, 'SYSTEM', 'system', 'Vote tied or skipped. No elimination this round.', 1)");
        $stmt->execute([$room_id]);
    }

    if ($winner === null) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM saboteur_players WHERE room_id = ? AND role = 'saboteur' AND is_eliminated = 0");
        $stmt->execute([$room_id]);
        $saboteurs_alive = (int)$stmt->fetchColumn();
        if ($saboteurs_alive <= 0) {
            $winner = 'fixers';
        }
    }

    if ($winner === null && (int)$room_meta['current_round'] >= (int)$room_meta['max_rounds']) {
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

if (empty($room_code)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Room code required']);
    exit;
}

try {
    purgeAbandonedSaboteurRooms($pdo);

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

    // Auto transition role reveal into active play after a short reveal window.
    if ($room['status'] === 'role_reveal' && !empty($room['round_started_at'])) {
        $elapsed = (new DateTime())->getTimestamp() - (new DateTime($room['round_started_at']))->getTimestamp();
        if ($elapsed >= 5) {
            $stmt = $pdo->prepare("UPDATE saboteur_rooms SET status = 'playing', round_started_at = NOW(), emergency_used = 0, voting_started_at = NULL WHERE id = ?");
            $stmt->execute([$room_id]);
            $room['status'] = 'playing';
            $room['round_started_at'] = date('Y-m-d H:i:s');
        }
    }

    // Auto start emergency voting when timer expires.
    if ($room['status'] === 'playing' && !empty($room['round_started_at'])) {
        $elapsed = (new DateTime())->getTimestamp() - (new DateTime($room['round_started_at']))->getTimestamp();
        if ($elapsed >= (int)$room['round_duration']) {
            $stmt = $pdo->prepare("UPDATE saboteur_rooms SET status = 'voting', emergency_used = 1, voting_started_at = NOW() WHERE id = ?");
            $stmt->execute([$room_id]);
            $stmt = $pdo->prepare("UPDATE saboteur_players SET vote_target_id = NULL WHERE room_id = ?");
            $stmt->execute([$room_id]);
            $stmt = $pdo->prepare("INSERT INTO saboteur_chat (room_id, user_id, username, color, message, is_system) VALUES (?, 0, 'SYSTEM', 'system', 'Round timer ended. Emergency voting has started.', 1)");
            $stmt->execute([$room_id]);
            $room['status'] = 'voting';
        }
    }

    // Safety timeout for voting so games cannot deadlock.
    if ($room['status'] === 'voting' && !empty($room['voting_started_at'])) {
        $vote_elapsed = (new DateTime())->getTimestamp() - (new DateTime($room['voting_started_at']))->getTimestamp();
        if ($vote_elapsed >= 20) {
            resolveVotingRound($pdo, $room_id);
            $stmt = $pdo->prepare("SELECT * FROM saboteur_rooms WHERE id = ?");
            $stmt->execute([$room_id]);
            $room = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }

    // ── Get current player in room ──────────────────────────
    $stmt = $pdo->prepare("SELECT * FROM saboteur_players WHERE room_id = ? AND user_id = ?");
    $stmt->execute([$room_id, $user_id]);
    $current_player = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$current_player) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Player not in room']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE saboteur_players SET last_seen = NOW(), is_connected = 1 WHERE id = ?");
    $stmt->execute([(int)$current_player['id']]);

    // ── Get all players ──────────────────────────
    $stmt = $pdo->prepare("SELECT id, user_id, username, color, role, is_ready, is_host, is_eliminated, vote_target_id, cursor_line, cursor_col, cursor_pos FROM saboteur_players WHERE room_id = ? ORDER BY joined_at");
    $stmt->execute([$room_id]);
    $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $players_data = [];
    foreach ($players as $p) {
        $players_data[] = [
            'id' => (int)$p['id'],
            'user_id' => (int)$p['user_id'],
            'username' => $p['username'],
            'color' => $p['color'],
            'role' => ($room['status'] === 'finished' || (int)$p['user_id'] === $user_id) ? $p['role'] : null,
            'is_ready' => (bool)$p['is_ready'],
            'is_host' => (bool)$p['is_host'],
            'is_eliminated' => (bool)$p['is_eliminated'],
            'is_you' => ($p['user_id'] == $user_id),
            'cursor_line' => (int)($p['cursor_line'] ?? 0),
            'cursor_col' => (int)($p['cursor_col'] ?? 0),
            'cursor_pos' => (int)($p['cursor_pos'] ?? 0)
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
        SELECT id, user_id, username, color, message, is_system, sent_at
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
    if ($room['status'] === 'playing' && !empty($room['round_started_at'])) {
        $round_start = new DateTime($room['round_started_at']);
        $round_elapsed = (new DateTime())->getTimestamp() - $round_start->getTimestamp();
    }
    $round_remaining = ($room['status'] === 'playing')
        ? max(0, (int)$room['round_duration'] - $round_elapsed)
        : (int)$room['round_duration'];

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
            'is_ready' => (bool)$current_player['is_ready'],
            'role' => $current_player['role'],
            'sabotage_tasks' => ($current_player['role'] === 'saboteur' && $challenge) ? $challenge['sabotage_tasks'] : []
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
