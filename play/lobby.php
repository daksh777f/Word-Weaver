<?php
require_once '../onboarding/config.php';
requireLogin();

$user_id = (int)$_SESSION['user_id'];
$room_code = isset($_GET['room']) ? trim($_GET['room']) : null;

if (!$room_code) {
    header('Location: index.php');
    exit;
}

// Fetch room and players
$stmt = $pdo->prepare("SELECT id, room_code FROM saboteur_rooms WHERE room_code = ? LIMIT 1");
$stmt->execute([$room_code]);
$room = $stmt->fetch();

if (!$room) {
    header('Location: index.php');
    exit;
}

$room_id = (int)$room['id'];

// Fetch players
$stmt = $pdo->prepare("
    SELECT user_id, username, color, is_host, is_ready 
    FROM saboteur_players 
    WHERE room_id = ? 
    ORDER BY joined_at
");
$stmt->execute([$room_id]);
$players = $stmt->fetchAll();

// Player colors mapping
$colorMap = [
    'red' => '#E53935',
    'blue' => '#1E88E5',
    'green' => '#43A047',
    'orange' => '#FB8C00',
    'purple' => '#8E24AA'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Code Mafia - Lobby</title>
    <link rel="stylesheet" href="retro-game.css">
    <style>
        body {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: white;
            position: relative;
        }

        .clouds-container {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 50%;
            pointer-events: none;
        }

        .content {
            position: relative;
            z-index: 10;
            text-align: center;
            max-width: 450px;
            margin-bottom: 60px;
        }

        .lobby-heading {
            font-size: 2rem;
            color: var(--btn-primary);
            margin-bottom: 2rem;
            letter-spacing: 2px;
        }

        .code-card {
            background: var(--card-bg);
            border: 3px solid var(--card-border);
            padding: 1rem;
            margin-bottom: 1.5rem;
            box-shadow: inset -2px -2px 0 rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .code-label {
            font-size: 2rem;
            color: var(--text-muted);
            text-align: left;
            flex: 1;
        }

        .code-value {
            font-size: 1.5rem;
            color: var(--text-primary);
            letter-spacing: 3px;
            font-weight: bold;
            flex: 2;
        }

        .code-copy-btn {
            background: var(--btn-primary);
            border: 2px solid var(--text-primary);
            padding: 0.5rem 0.75rem;
            cursor: pointer;
            font-size: 1.8rem;
            color: white;
        }

        .players-card {
            background: var(--card-bg);
            border: 3px solid var(--card-border);
            padding: 1rem;
            margin-bottom: 1.5rem;
            box-shadow: inset -2px -2px 0 rgba(0, 0, 0, 0.15);
            text-align: left;
        }

        .players-header {
            font-size: 2.5rem;
            color: var(--text-primary);
            margin-bottom: 0.75rem;
            text-transform: uppercase;
        }

        .player-row {
            display: flex;
            align-items: center;
            padding: 0.5rem;
            margin-bottom: 0.3rem;
            background: rgba(255, 255, 255, 0.05);
            border-left: 3px solid white;
        }

        .player-color {
            width: 16px;
            height: 16px;
            margin-right: 0.75rem;
            border: 1px solid rgba(0, 0, 0, 0.3);
        }

        .player-name {
            font-size: 2rem;
            color: var(--text-primary);
            flex: 1;
            text-transform: uppercase;
        }

        .player-badge {
            font-size: 1.8rem;
            background: var(--btn-action);
            color: white;
            padding: 0.2rem 0.4rem;
            margin-left: 0.3rem;
        }

        .player-host {
            background: var(--btn-primary);
        }

        .ready-btn {
            width: 100%;
            min-height: 2.5rem;
            background: var(--btn-action);
            border-color: #2E7D32;
            font-size: 2rem;
            margin-bottom: 1rem;
        }

        .waiting-text {
            font-size: 2rem;
            color: white;
        }

        .back-link {
            position: fixed;
            top: 20px;
            left: 20px;
            font-size: 2rem;
            color: white;
            cursor: pointer;
            z-index: 20;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <!-- Clouds -->
    <div class="clouds-container">
        <div class="cloud" style="left: 5%; top: 15%;"></div>
        <div class="cloud" style="left: 25%; top: 5%;"></div>
        <div class="cloud" style="left: 45%; top: 20%;"></div>
        <div class="cloud" style="left: 65%; top: 8%;"></div>
        <div class="cloud" style="left: 80%; top: 18%;"></div>
        <div class="cloud" style="left: 15%; top: 40%;"></div>
        <div class="cloud" style="left: 75%; top: 35%;"></div>
    </div>

    <a href="index.php" class="back-link">← BACK</a>

    <!-- Main Content -->
    <div class="content">
        <h1 class="lobby-heading">LOBBY</h1>

        <!-- Code Display Card -->
        <div class="code-card">
            <div style="text-align: center; flex: 1;">
                <div class="code-label">Lobby Code:</div>
                <div class="code-value"><?php echo htmlspecialchars($room_code); ?></div>
            </div>
            <button class="code-copy-btn" onclick="copy('<?php echo htmlspecialchars($room_code); ?>')">
                📋
            </button>
        </div>

        <!-- Players Card -->
        <div class="players-card">
            <div class="players-header">
                ⚙ Players (<?php echo count($players); ?>/5)
            </div>

            <?php foreach ($players as $player): ?>
                <div class="player-row">
                    <div class="player-color" style="background: <?php echo $colorMap[$player['color']] ?? '#999'; ?>;"></div>
                    <div class="player-name">
                        <?php echo htmlspecialchars($player['username']); ?>
                        <?php if ($player['user_id'] == $user_id): ?>
                            <span class="player-badge" style="color: var(--text-primary); background: rgba(255,255,255,0.3);">YOU</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($player['is_host']): ?>
                        <span class="player-badge player-host">HOST</span>
                    <?php endif; ?>
                    <?php if ($player['is_ready']): ?>
                        <span class="player-badge">✓</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php if (count($players) < 5): ?>
                <p class="waiting-text" style="margin-top: 0.75rem;">
                    Waiting for more players...
                </p>
            <?php endif; ?>
        </div>

        <!-- Ready Button -->
        <button id="readyBtn" class="ready-btn" onclick="markReady()">
            ✓ READY!
        </button>

        <p class="waiting-text" id="statusText">
            Waiting for host to start...
        </p>
    </div>

    <!-- Ground Strip -->
    <div class="ground"></div>

    <script>
        function copy(text) {
            navigator.clipboard.writeText(text);
            alert('Code copied to clipboard!');
        }

        function markReady() {
            fetch('saboteur_ready.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    room_code: <?php echo json_encode($room_code); ?>,
                    is_ready: true
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('readyBtn').style.display = 'none';
                    document.getElementById('statusText').textContent = 'You are ready! Waiting for others...';
                }
            })
            .catch(err => console.error(err));
        }

        // Poll for game start
        setInterval(() => {
            fetch('saboteur_poll.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    room_code: <?php echo json_encode($room_code); ?>
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success && data.room.status !== 'lobby') {
                    window.location.href = 'game.php?room=' + encodeURIComponent(<?php echo json_encode($room_code); ?>);
                }
            })
            .catch(err => console.error(err));
        }, 1000);
    </script>
</body>
</html>
