<?php
// ════════════════════════════════════════
// FILE: duel_poll.php
// PURPOSE: Heartbeat endpoint — return duel state for real-time display
// NEW TABLES USED: duel_rooms, duel_players
// REALTIME: polling (called every 1.5 seconds)
// CEREBRAS CALLS: no
// ════════════════════════════════════════

ob_start();
require_once '../onboarding/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (!isLoggedIn()) {
    ob_end_clean();
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$room_code = trim($_GET['room'] ?? '');
$phase = trim($_GET['phase'] ?? 'duel');

if (empty($room_code)) {
    ob_end_clean();
    echo json_encode(['error' => 'No room code']);
    exit;
}

try {
    // Fetch room with both players
    $stmt = $pdo->prepare("
        SELECT dr.*,
            u1.username AS p1_username,
            u2.username AS p2_username
        FROM duel_rooms dr
        LEFT JOIN users u1 ON u1.id = dr.player1_id
        LEFT JOIN users u2 ON u2.id = dr.player2_id
        WHERE dr.room_code = ?
        LIMIT 1
    ");
    $stmt->execute([$room_code]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$room) {
        ob_end_clean();
        echo json_encode(['error' => 'Room not found']);
        exit;
    }

    // Fetch both player records (bot may not exist in users table)
    $stmt = $pdo->prepare("
        SELECT dp.*, COALESCE(u.username, CASE WHEN dp.user_id = -1 THEN 'Code Bot' ELSE NULL END) AS username
        FROM duel_players dp
        LEFT JOIN users u ON u.id = dp.user_id
        WHERE dp.room_id = ?
        ORDER BY dp.player_number ASC
    ");
    $stmt->execute([$room['id']]);
    $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Find my player and opponent
    $myPlayer = null;
    $opponentPlayer = null;
    $p1 = null;
    $p2 = null;
    foreach ($players as $p) {
        if ((int)$p['player_number'] === 1) {
            $p1 = $p;
        } elseif ((int)$p['player_number'] === 2) {
            $p2 = $p;
        }

        if ((int)$p['user_id'] === $user_id) {
            $myPlayer = $p;
        } else {
            $opponentPlayer = $p;
        }
    }

    // ─── LOBBY PHASE ────────────────────────────────────────────────────
    if ($phase === 'lobby') {
        ob_end_clean();
        echo json_encode([
            'status' => $room['status'],
            'player_count' => $room['player2_id'] ? 2 : 1
        ]);
        exit;
    }

    // ─── DUEL PHASE ─────────────────────────────────────────────────────

    // Calculate countdown seconds remaining
    $countdownRemaining = 0;
    if ($room['status'] === 'countdown' && $room['countdown_started_at']) {
        $elapsed = time() - strtotime($room['countdown_started_at']);
        $countdownRemaining = max(0, 3 - $elapsed);
        
        // If countdown finished, automatically transition to active
        if ($countdownRemaining <= 0 && $room['status'] === 'countdown') {
            $stmt = $pdo->prepare("
                UPDATE duel_rooms SET
                    status = 'active',
                    duel_started_at = NOW()
                WHERE id = ? AND status = 'countdown'
            ");
            $stmt->execute([$room['id']]);
            
            // Refresh room status
            $stmt = $pdo->prepare("SELECT status FROM duel_rooms WHERE id = ?");
            $stmt->execute([$room['id']]);
            $room['status'] = $stmt->fetchColumn();
        }
    }

    // Calculate duel time elapsed
    $duelElapsed = 0;
    if ($room['duel_started_at']) {
        $duelElapsed = time() - strtotime($room['duel_started_at']);
    }

    $playerStatusFromRow = static function ($row, string $roomStatus): string {
        if (!is_array($row)) {
            return 'waiting';
        }
        if (!empty($row['submitted_at'])) {
            return 'submitted';
        }
        if ($roomStatus === 'active') {
            return 'editing';
        }
        if ((int)($row['edit_count'] ?? 0) > 0) {
            return 'editing';
        }
        return 'waiting';
    };

    $p1Status = $playerStatusFromRow($p1, (string)$room['status']);
    $p2Status = $playerStatusFromRow($p2, (string)$room['status']);

    if ($phase === 'result' && $myPlayer) {
        $feedback = [];
        if (!empty($myPlayer['ai_feedback'])) {
            $decoded = json_decode((string)$myPlayer['ai_feedback'], true);
            if (is_array($decoded)) {
                $feedback = $decoded;
            }
        }

        ob_end_clean();
        echo json_encode([
            'status' => $room['status'],
            'result' => (string)($myPlayer['result'] ?? 'pending'),
            'score' => (int)($myPlayer['score'] ?? 0),
            'xp' => (int)($myPlayer['xp_earned'] ?? 0),
            'roast' => (string)($feedback['roast'] ?? ''),
            'time_complexity' => (string)($feedback['time_complexity'] ?? ''),
            'space_complexity' => (string)($feedback['space_complexity'] ?? ''),
            'edge_cases' => isset($feedback['edge_cases_missed'])
                ? (is_array($feedback['edge_cases_missed']) ? implode(', ', $feedback['edge_cases_missed']) : (string)$feedback['edge_cases_missed'])
                : '',
            'cleaner' => (string)($feedback['cleaner_alternative'] ?? ''),
            'senior_advice' => (string)($feedback['senior_dev_comment'] ?? ''),
            'duel_comment' => (string)($feedback['duel_comment'] ?? '')
        ]);
        exit;
    }

    ob_end_clean();
    echo json_encode([
        'status' => $room['status'],
        'countdown_remaining' => $countdownRemaining,
        'duel_elapsed' => $duelElapsed,
        'winner_id' => $room['winner_id'],
        'player1_id' => isset($p1['user_id']) ? (int)$p1['user_id'] : null,
        'player2_id' => isset($p2['user_id']) ? (int)$p2['user_id'] : null,
        'p1_status' => $p1Status,
        'p2_status' => $p2Status,
        'p1_submitted_at' => $p1['submitted_at'] ?? null,
        'p2_submitted_at' => $p2['submitted_at'] ?? null,
        'my_result' => $myPlayer['result'] ?? 'pending',
        'my_submitted' => !is_null($myPlayer['submitted_at'] ?? null),
        'challenge_id' => (int)$room['challenge_id'],
        'player_count' => $room['player2_id'] ? 2 : 1
    ]);

} catch (Exception $e) {
    error_log('duel_poll error: ' . $e->getMessage());
    ob_end_clean();
    echo json_encode(['error' => 'Server error']);
}
