<?php

// ════════════════════════════════════════════════════════════════
// FILE: buy_freeze.php
// PURPOSE: XP shop endpoint for purchasing streak freezes
// NEW TABLES USED: xp_shop_transactions
// DEPENDS ON: config.php (for $pdo, session)
// ════════════════════════════════════════════════════════════════

ob_start();
require_once '../onboarding/config.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    ob_end_clean();
    echo json_encode([
        'success' => false,
        'message' => 'You must be logged in to purchase items'
    ]);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$item = $input['item'] ?? '';
$cost = (int)($input['cost'] ?? 0);
$user_id = (int)($_SESSION['user_id'] ?? 0);

// Validate input
if ($item !== 'streak_freeze' || $cost !== 500) {
    ob_end_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Invalid item'
    ]);
    exit;
}

if ($user_id <= 0) {
    ob_end_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Session error, please login again'
    ]);
    exit;
}

try {
    // Fetch current XP and freeze count
    $userStmt = $pdo->prepare(
        "SELECT total_xp, streak_freezes FROM users WHERE id = ?"
    );
    $userStmt->execute([$user_id]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        ob_end_clean();
        echo json_encode([
            'success' => false,
            'message' => 'User not found'
        ]);
        exit;
    }

    $totalXp = (int)$user['total_xp'];
    $freezeCount = (int)$user['streak_freezes'];

    // Check if user has enough XP
    if ($totalXp < 500) {
        ob_end_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Not enough XP. You need 500 XP to buy a freeze.'
        ]);
        exit;
    }

    // Check if user already has max freezes
    if ($freezeCount >= 3) {
        ob_end_clean();
        echo json_encode([
            'success' => false,
            'message' => 'You already have the maximum 3 freezes.'
        ]);
        exit;
    }

    // Begin transaction for safe XP deduction and freeze purchase
    $pdo->beginTransaction();

    // Deduct XP and add freeze
    $updateStmt = $pdo->prepare(
        "UPDATE users SET 
            total_xp = total_xp - 500,
            streak_freezes = streak_freezes + 1
         WHERE id = ? AND total_xp >= 500"
    );
    $updateStmt->execute([$user_id]);

    if ($updateStmt->rowCount() !== 1) {
        $pdo->rollBack();
        ob_end_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Purchase failed. Please try again.'
        ]);
        exit;
    }

    // Record transaction
    $transStmt = $pdo->prepare(
        "INSERT INTO xp_shop_transactions (user_id, item_name, xp_cost)
         VALUES (?, 'streak_freeze', 500)"
    );
    $transStmt->execute([$user_id]);

    // Commit transaction
    $pdo->commit();

    // Log the purchase
    error_log("CodeDungeon: user $user_id purchased streak_freeze for 500 XP");

    $newTotalXp = $totalXp - 500;
    $newFreezeCount = $freezeCount + 1;

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Streak Freeze purchased successfully!',
        'new_xp' => $newTotalXp,
        'new_freezes' => $newFreezeCount
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("buy_freeze.php error: " . $e->getMessage());
    ob_end_clean();
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred. Please try again.'
    ]);
}

?>
