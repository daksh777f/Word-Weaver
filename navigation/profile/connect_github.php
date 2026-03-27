<?php
// ════════════════════════════════════════
// FILE: connect_github.php
// PURPOSE: POST endpoint to validate and connect GitHub account
// DEPENDS ON: config.php, github_api.php
// EXTERNAL API: Yes - GitHub public API
// ════════════════════════════════════════

ob_start();
require_once '../onboarding/config.php';
require_once '../includes/github_api.php';

requireLogin();

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$username = trim($input['username'] ?? '');

if (empty($username)) {
    ob_end_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Please enter a GitHub username'
    ]);
    exit;
}

// Validate username exists on GitHub
$validation = validateGitHubUsername($username);

if (!$validation['valid']) {
    ob_end_clean();
    echo json_encode([
        'success' => false,
        'message' => $validation['message']
    ]);
    exit;
}

$user_id = $_SESSION['user_id'];
$cleanUsername = $validation['login'];
$avatarUrl = $validation['avatar_url'];

// Save to users table
try {
    $stmt = $pdo->prepare(
        "UPDATE users SET 
            github_username = ?,
            github_connected_at = NOW(),
            github_avatar_url = ?
        WHERE id = ?"
    );
    
    $stmt->execute([$cleanUsername, $avatarUrl, $user_id]);
    
    error_log('CodeDungeon: user ' . $user_id 
        . ' connected GitHub: ' . $cleanUsername);
    
    ob_end_clean();
    echo json_encode([
        'success' => true,
        'message' => 'GitHub connected successfully',
        'username' => $cleanUsername,
        'avatar_url' => $avatarUrl
    ]);
} catch (Exception $e) {
    error_log('GitHub connect error: ' . $e->getMessage());
    ob_end_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Failed to connect GitHub'
    ]);
}
