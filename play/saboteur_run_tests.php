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
    $stmt = $pdo->prepare("SELECT challenge_id FROM saboteur_rooms WHERE room_code = ?");
    $stmt->execute([$room_code]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$room) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Room not found']);
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
            $is_match = preg_match('/' . preg_quote($pattern) . '/i', $code) || 
                       preg_match($pattern, $code);
        } elseif ($check_type === 'has_function') {
            $is_match = preg_match('/\b' . preg_quote($pattern) . '\s*\(/i', $code);
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
