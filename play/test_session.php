<?php
require_once '../onboarding/config.php';

header('Content-Type: application/json');

$response = [
    'session_id' => session_id(),
    'is_logged_in' => isLoggedIn(),
    'user_id' => $_SESSION['user_id'] ?? null,
    'username' => $_SESSION['username'] ?? null,
    '_session_keys' => array_keys($_SESSION)
];

echo json_encode($response);
?>
