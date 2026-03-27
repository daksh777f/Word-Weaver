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

    // Fetch both player records
    $stmt = $pdo->prepare("
        SELECT dp.*, u.username
        FROM duel_players dp
        JOIN users u ON u.id = dp.user_id
        WHERE dp.room_id = ?
        ORDER BY dp.player_number ASC
    ");
    $stmt->execute([$room['id']]);
    $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Find my player and opponent
    $myPlayer = null;
    $opponentPlayer = null;
    foreach ($players as $p) {
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

    // Build opponent progress indicator (abstracted)
    $opponentProgress = null;
    if ($opponentPlayer) {
        $opponentProgress = [
            'username' => (string)$opponentPlayer['username'],
            'has_edited' => (int)$opponentPlayer['edit_count'] > 0,
            'edit_count' => (int)$opponentPlayer['edit_count'],
            'has_submitted' => !is_null($opponentPlayer['submitted_at']),
            'submitted_at' => (string)($opponentPlayer['submitted_at'] ?? ''),
            'result' => (string)$opponentPlayer['result']
        ];
    }

    ob_end_clean();
    echo json_encode([
        'status' => $room['status'],
        'countdown_remaining' => $countdownRemaining,
        'duel_elapsed' => $duelElapsed,
        'winner_id' => $room['winner_id'],
        'my_result' => $myPlayer['result'] ?? 'pending',
        'my_submitted' => !is_null($myPlayer['submitted_at'] ?? null),
        'opponent' => $opponentProgress,
        'challenge_id' => (int)$room['challenge_id'],
        'player_count' => $room['player2_id'] ? 2 : 1
    ]);

} catch (Exception $e) {
    error_log('duel_poll error: ' . $e->getMessage());
    ob_end_clean();
    echo json_encode(['error' => 'Server error']);
}
