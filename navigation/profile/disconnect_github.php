<?php
// ════════════════════════════════════════
// FILE: disconnect_github.php
// PURPOSE: POST endpoint to disconnect GitHub account
// DEPENDS ON: config.php
// EXTERNAL API: No
// ════════════════════════════════════════

ob_start();
require_once '../onboarding/config.php';

requireLogin();

header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];

try {
    $stmt = $pdo->prepare(
        "UPDATE users SET 
            github_username = NULL,
            github_connected_at = NULL,
            github_avatar_url = NULL,
            github_data = NULL,
            github_cache_updated = NULL
        WHERE id = ?"
    );
    
    $stmt->execute([$user_id]);
    
    ob_end_clean();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    error_log('GitHub disconnect error: ' . $e->getMessage());
    ob_end_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Failed to disconnect GitHub'
    ]);
}
