<?php
// ════════════════════════════════════════
// FILE: duel_submit.php
// PURPOSE: Handle duel submission — determine winner, scores, AI feedback
// NEW TABLES USED: duel_rooms, duel_players, duel_history, bug_challenges
// CEREBRAS CALLS: yes
// REALTIME: none
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
$input = json_decode(file_get_contents('php://input'), true);

$room_code = $input['room_code'] ?? '';
$submitted_code = trim($input['submitted_code'] ?? '');
$time_taken = max(0, (int)($input['time_taken'] ?? 0));

// Validate inputs
if (empty($room_code) || empty($submitted_code)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid submission']);
    exit;
}

try {
    // Fetch room
    $stmt = $pdo->prepare("SELECT * FROM duel_rooms WHERE room_code = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$room_code]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$room) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Duel not active']);
        exit;
    }

    // Fetch my player record
    $stmt = $pdo->prepare("SELECT * FROM duel_players WHERE room_id = ? AND user_id = ?");
    $stmt->execute([$room['id'], $user_id]);
    $myPlayer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$myPlayer) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Player not in room']);
        exit;
    }

    // Check not already submitted
    if (!is_null($myPlayer['submitted_at'])) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Already submitted']);
        exit;
    }

    // Fetch challenge
    $stmt = $pdo->prepare("SELECT * FROM bug_challenges WHERE id = ?");
    $stmt->execute([$room['challenge_id']]);
    $challenge = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$challenge) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Challenge not found']);
        exit;
    }

    // Calculate score
    $base_score = 1000;
    $time_penalty = $time_taken * 2;
    $score = max(0, $base_score - $time_penalty);
    $xp = (int)($score / 10);

    // Update my duel_players record
    $stmt = $pdo->prepare("
        UPDATE duel_players SET
            submitted_code = ?,
            submitted_at = NOW(),
            score = ?,
            xp_earned = ?
        WHERE room_id = ? AND user_id = ?
    ");
    $stmt->execute([$submitted_code, $score, $xp, $room['id'], $user_id]);

    // Fetch opponent to check if they already submitted
    $stmt = $pdo->prepare("
        SELECT * FROM duel_players
        WHERE room_id = ? AND user_id != ?
        LIMIT 1
    ");
    $stmt->execute([$room['id'], $user_id]);
    $opponentPlayer = $stmt->fetch(PDO::FETCH_ASSOC);

    $isFirstToSubmit = is_null($opponentPlayer['submitted_at']);

    if ($isFirstToSubmit) {
        // ─── I WIN ───────────────────────────────────────────────────────
        
        // Mark this player as won
        $stmt = $pdo->prepare("
            UPDATE duel_players SET result = 'won'
            WHERE room_id = ? AND user_id = ?
        ");
        $stmt->execute([$room['id'], $user_id]);

        // Mark opponent as lost
        $stmt = $pdo->prepare("
            UPDATE duel_players SET result = 'lost'
            WHERE room_id = ? AND user_id != ?
        ");
        $stmt->execute([$room['id'], $user_id]);

        // Mark room as finished with winner
        $stmt = $pdo->prepare("
            UPDATE duel_rooms SET
                status = 'finished',
                winner_id = ?,
                duel_ended_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$user_id, $room['id']]);

        // Update user stats: duel_wins, duel_win_streak
        $stmt = $pdo->prepare("
            UPDATE users SET
                duel_wins = duel_wins + 1,
                duel_win_streak = duel_win_streak + 1,
                total_xp = total_xp + ?
            WHERE id = ?
        ");
        $stmt->execute([$xp + 200, $user_id]); // +200 winner bonus

        // Update opponent stats: duel_losses, reset streak
        $stmt = $pdo->prepare("
            UPDATE users SET
                duel_losses = duel_losses + 1,
                duel_win_streak = 0,
                total_xp = total_xp + ?
            WHERE id = ?
        ");
        $stmt->execute([$xp, $opponentPlayer['user_id']]);

        // Record in duel_history
        $duelDuration = $time_taken;
        $stmt = $pdo->prepare("
            INSERT INTO duel_history
                (room_id, winner_id, loser_id, challenge_id, challenge_type,
                 challenge_title, winner_score, loser_score, duel_duration)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $room['id'],
            $user_id,
            $opponentPlayer['user_id'],
            $room['challenge_id'],
            $room['challenge_type'],
            $challenge['title'],
            $score,
            0,
            $duelDuration
        ]);

        $result = 'won';
        $xpEarned = $xp + 200; // Winner bonus

    } else {
        // ─── I LOSE ──────────────────────────────────────────────────────
        // Opponent already submitted first
        $result = 'lost';
        $xpEarned = $xp;
    }

    // ─── GENERATE AI FEEDBACK ───────────────────────────────────────────

    $aiPayload = [
        ['role' => 'system',
         'content' => 
            'You are a senior developer doing a code review after a competitive duel. ' .
            ($result === 'won' 
                ? 'This player WON the duel. Be congratulatory but still critical. '
                : 'This player LOST the duel. Be encouraging but honest. ') .
            'Respond ONLY in this exact JSON with no extra text: ' .
            '{"roast": "2 sentences", "time_complexity": "...", "space_complexity": "...", ' .
            '"edge_cases_missed": [], "cleaner_alternative": "...", ' .
            '"senior_dev_comment": "...", ' .
            '"duel_comment": "one sentence specifically about this duel result"}'],
        ['role' => 'user',
         'content' =>
            'Challenge: ' . $challenge['title'] .
            "\nLanguage: " . $challenge['language'] .
            "\nTime taken: " . $time_taken . ' seconds' .
            "\nDuel result: " . $result .
            "\nSubmitted code:\n" . $submitted_code]
    ];

    $rawFeedback = callCerebras($aiPayload, 500);
    $feedback = null;

    if ($rawFeedback) {
        $cleaned = trim($rawFeedback);
        $cleaned = preg_replace('/^```json\s*/i', '', $cleaned);
        $cleaned = preg_replace('/^```\s*/i', '', $cleaned);
        $cleaned = preg_replace('/```\s*$/i', '', $cleaned);
        $feedback = json_decode(trim($cleaned), true);
    }

    // Fallback feedback
    if (!is_array($feedback)) {
        $feedback = [
            'roast' => $result === 'won'
                ? 'You got there first. The code works.'
                : 'Second place. The bugs waited for you.',
            'time_complexity' => 'Unable to analyze',
            'space_complexity' => 'Unable to analyze',
            'edge_cases_missed' => [],
            'cleaner_alternative' => '',
            'senior_dev_comment' => 
                $result === 'won'
                ? 'Two-hand victory. Ship it.'
                : 'Next time, faster.',
            'duel_comment' => 
                $result === 'won'
                ? 'You fixed it first. Victory is yours.'
                : 'Your opponent was quicker today. Challenge them to a rematch.'
        ];
    }

    // Save feedback to duel_players
    $stmt = $pdo->prepare("
        UPDATE duel_players SET
            ai_feedback = ?
        WHERE room_id = ? AND user_id = ?
    ");
    $stmt->execute([json_encode($feedback, JSON_UNESCAPED_UNICODE), $room['id'], $user_id]);

    // Log activity
    $activity_type = $result === 'won' ? 'bug_fixed' : 'challenge_solved';
    $title = ($result === 'won' ? '🏆 Won Duel: ' : '⚔️ Lost Duel: ') . $challenge['title'];
    $subtitle = 'Bug Duel · ' . ($result === 'won' ? '+' . $xpEarned . ' XP (winner bonus)' : '+' . $xpEarned . ' XP');

    $stmt = $pdo->prepare("
        INSERT INTO codedungeon_activity_feed
            (user_id, activity_type, title, subtitle, xp_earned, score, game_type, challenge_id, short_hash)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $shortHash = substr(md5(time() . $user_id), 0, 10);
    $stmt->execute([
        $user_id,
        $activity_type,
        $title,
        $subtitle,
        $xpEarned,
        $score,
        'bug_duel',
        $room['challenge_id'],
        $shortHash
    ]);

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'result' => $result,
        'score' => $score,
        'xp_earned' => $xpEarned,
        'feedback' => $feedback,
        'is_first_to_submit' => $isFirstToSubmit
    ]);

} catch (Exception $e) {
    error_log('duel_submit error: ' . $e->getMessage());
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
