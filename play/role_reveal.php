<?php
require_once '../onboarding/config.php';
requireLogin();

$user_id = (int)$_SESSION['user_id'];
$room_code = isset($_GET['room']) ? trim($_GET['room']) : null;

if (!$room_code) {
    header('Location: index.php');
    exit;
}

// Get room and player role
$stmt = $pdo->prepare("
    SELECT sp.role 
    FROM saboteur_rooms sr
    INNER JOIN saboteur_players sp ON sr.id = sp.room_id
    WHERE sr.room_code = ? AND sp.user_id = ?
    LIMIT 1
");
$stmt->execute([$room_code, $user_id]);
$playerData = $stmt->fetch();

if (!$playerData) {
    header('Location: index.php');
    exit;
}

$role = $playerData['role'] ?? 'fixer';
$isImpostor = ($role === 'saboteur');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Code Mafia - Role Reveal</title>
    <link rel="stylesheet" href="retro-game.css">
    <style>
        body {
            margin: 0;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            width: 100%;
            overflow: hidden;
            background: #111111;
            color: white;
        }

        .role-container {
            text-align: center;
            z-index: 10;
        }

        .role-title {
            font-size: 4rem;
            font-family: 'Press Start 2P', cursive;
            letter-spacing: 3px;
            margin-bottom: 2rem;
            font-weight: bold;
        }

        .impostor .role-title {
            color: var(--btn-danger);
        }

        .civilian .role-title {
            color: var(--btn-action);
        }

        .role-subtitle {
            font-family: 'Press Start 2P', cursive;
            font-size: 2.5rem;
            margin-bottom: 3rem;
            line-height: 1.8;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        .role-status {
            font-family: 'Press Start 2P', cursive;
            font-size: 2rem;
            color: rgba(255, 255, 255, 0.7);
            animation: blink 1s infinite;
        }
    </style>
</head>
<body>
    <div class="role-container <?php echo $isImpostor ? 'impostor' : 'civilian'; ?>">
        <div class="role-title">
            <?php echo $isImpostor ? '🕵️ IMPOSTOR' : '🔧 CIVILIAN'; ?>
        </div>

        <div class="role-subtitle">
            <?php if ($isImpostor): ?>
                Sabotage the code without<br>
                getting caught!! Make the code<br>
                fail by round 4.
            <?php else: ?>
                Fix the code and find<br>
                the Impostor before<br>
                it's too late!
            <?php endif; ?>
        </div>

        <div class="role-status">
            Game starting soon...
        </div>
    </div>

    <script>
        // Auto-redirect after 3 seconds
        setTimeout(() => {
            window.location.href = 'game.php?room=' + encodeURIComponent(<?php echo json_encode($room_code); ?>);
        }, 3000);
    </script>
</body>
</html>
