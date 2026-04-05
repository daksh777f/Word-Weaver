<?php
// ════════════════════════════════════════
// FILE: saboteur_run_tests.php
// PURPOSE: Run test cases against current code
// NEW TABLES USED: saboteur_rooms, saboteur_challenges
// DEPENDS ON: config.php
// REALTIME: on demand
// CEREBRAS CALLS: no
// ════════════════════════════════════════

ob_start();
require_once '../onboarding/config.php';

header('Content-Type: application/json');

function safePatternMatch(string $pattern, string $code): bool {
    if ($pattern === '') {
        return false;
    }

    $quotedResult = @preg_match('/' . preg_quote($pattern, '/') . '/i', $code);
    if ($quotedResult === 1) {
        return true;
    }

    $rawResult = @preg_match($pattern, $code);
    if ($rawResult === false) {
        return stripos($code, $pattern) !== false;
    }

    return $rawResult === 1;
}

if (!isLoggedIn()) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$request_data = json_decode(file_get_contents('php://input'), true);
$room_code = trim($request_data['room_code'] ?? '');
$code = (string)($request_data['code'] ?? '');

if (empty($room_code)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Room code required']);
    exit;
}

try {
    // ── Find room ──────────────────────────
    $stmt = $pdo->prepare("SELECT id, challenge_id FROM saboteur_rooms WHERE room_code = ?");
    $stmt->execute([$room_code]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$room) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Room not found']);
        exit;
    }

    $room_id = (int)$room['id'];

    $stmt = $pdo->prepare("SELECT id FROM saboteur_players WHERE room_id = ? AND user_id = ?");
    $stmt->execute([$room_id, $user_id]);
    if (!$stmt->fetch()) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Player not in room']);
        exit;
    }

    // ── Get challenge ──────────────────────────
    $stmt = $pdo->prepare("SELECT test_cases FROM saboteur_challenges WHERE id = ?");
    $stmt->execute([(int)$room['challenge_id']]);
    $challenge = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$challenge) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Challenge not found']);
        exit;
    }

    $test_cases = json_decode($challenge['test_cases'], true);

    // ── Run pattern-based tests ──────────────────────────
    $test_results = [];
    $passed = 0;

    foreach ($test_cases as $test) {
        $test_id = (int)$test['id'];
        $pattern = $test['pattern'] ?? '';
        $negate = (bool)($test['negate'] ?? false);
        $check_type = $test['check_type'] ?? 'contains';

        $is_match = false;

        if ($check_type === 'contains') {
            $is_match = safePatternMatch($pattern, $code);
        } elseif ($check_type === 'has_function') {
            $is_match = (@preg_match('/\b' . preg_quote($pattern, '/') . '\s*\(/i', $code) === 1);
        }

        if ($negate) {
            $is_match = !$is_match;
        }

        if ($is_match) {
            $passed++;
        }

        $test_results[] = [
            'id' => $test_id,
            'description' => $test['description'] ?? '',
            'passed' => $is_match
        ];
    }

    $all_passed = ($passed === count($test_cases));

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'test_results' => $test_results,
        'passed' => $passed,
        'total' => count($test_cases),
        'all_passed' => $all_passed
    ]);

} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
