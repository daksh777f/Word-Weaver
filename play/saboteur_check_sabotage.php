<?php
// ════════════════════════════════════════
// FILE: saboteur_check_sabotage.php
// PURPOSE: Check code for sabotage patterns (for fixers to investigate)
// NEW TABLES USED: saboteur_rooms, saboteur_challenges, saboteur_players
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
    $stmt = $pdo->prepare("SELECT id, challenge_id FROM saboteur_rooms WHERE room_code = ?");
    $stmt->execute([$room_code]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$room) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Room not found']);
        exit;
    }

    $room_id = (int)$room['id'];

    // ── Check if player is saboteur ──────────────────────────
    $stmt = $pdo->prepare("SELECT role FROM saboteur_players WHERE room_id = ? AND user_id = ?");
    $stmt->execute([$room_id, $user_id]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$player || $player['role'] !== 'fixer') {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Only fixers can check for sabotage']);
        exit;
    }

    // ── Get challenge sabotage tasks ──────────────────────────
    $stmt = $pdo->prepare("SELECT sabotage_tasks FROM saboteur_challenges WHERE id = ?");
    $stmt->execute([(int)$room['challenge_id']]);
    $challenge = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$challenge) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Challenge not found']);
        exit;
    }

    $sabotage_tasks = json_decode($challenge['sabotage_tasks'], true);

    // ── Check code for sabotage patterns ──────────────────────────
    $detected_sabotages = [];

    foreach ($sabotage_tasks as $task) {
        $task_id = (int)$task['id'];
        $pattern = $task['detection_pattern'] ?? '';
        $negate = (bool)($task['negate'] ?? false);

        $is_detected = preg_match('/' . preg_quote($pattern) . '/i', $code) || 
                      preg_match($pattern, $code);

        if ($negate) {
            $is_detected = !$is_detected;
        }

        if ($is_detected) {
            $detected_sabotages[] = [
                'id' => $task_id,
                'description' => $task['description'] ?? '',
                'hint' => $task['hint'] ?? '',
                'target_function' => $task['target_function'] ?? ''
            ];
        }
    }

    $suspicion_level = count($detected_sabotages) > 0 ? min(100, count($detected_sabotages) * 20) : 0;

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'sabotages_detected' => count($detected_sabotages),
        'detected_sabotages' => $detected_sabotages,
        'suspicion_level' => $suspicion_level
    ]);

} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
