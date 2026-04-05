<?php
require_once '../onboarding/config.php';
requireLogin();

$user_id = (int)$_SESSION['user_id'];
$room_code = isset($_GET['room']) ? trim($_GET['room']) : null;

if (!$room_code) {
    header('Location: index.php');
    exit;
}

// Get room and players
$stmt = $pdo->prepare("
    SELECT sr.id 
    FROM saboteur_rooms sr
    WHERE sr.room_code = ?
    LIMIT 1
");
$stmt->execute([$room_code]);
$room = $stmt->fetch();

if (!$room) {
    header('Location: index.php');
    exit;
}

$room_id = (int)$room['id'];

// Get all players
$stmt = $pdo->prepare("
    SELECT id, user_id, username, color, is_eliminated, vote_target_id
    FROM saboteur_players
    WHERE room_id = ? AND NOT is_eliminated
    ORDER BY username
");
$stmt->execute([$room_id]);
$players = $stmt->fetchAll();

// Get current player
$currentPlayer = null;
foreach ($players as $p) {
    if ($p['user_id'] == $user_id) {
        $currentPlayer = $p;
        break;
    }
}

// Player colors
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
    <title>Code Mafia - Emergency Vote</title>
    <link rel="stylesheet" href="retro-game.css">
    <style>
        body {
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
            padding: 0;
            overflow: hidden;
        }

        .vote-container {
            display: grid;
            grid-template-areas: "chat modal";
            gap: 2rem;
            width: 90%;
            max-width: 1000px;
        }

        .modal-center {
            background: var(--card-bg);
            border: 3px solid var(--card-border);
            padding: 1.5rem;
            box-shadow: inset -2px -2px 0 rgba(0, 0, 0, 0.15), 0 0 20px rgba(0, 0, 0, 0.5);
            grid-area: modal;
            max-width: 400px;
            margin: 0 auto;
        }

        .modal-header {
            background: #333;
            color: white;
            font-family: 'Press Start 2P', cursive;
            font-size: 2rem;
            padding: 1rem;
            margin: -1.5rem -1.5rem 1rem -1.5rem;
            letter-spacing: 1px;
            text-align: center;
            border-bottom: 3px solid var(--card-border);
        }

        .modal-subtitle {
            font-family: 'Press Start 2P', cursive;
            font-size: 2rem;
            color: var(--text-muted);
            margin-bottom: 1rem;
            text-align: center;
        }

        .vote-options {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            max-height: 400px;
            overflow-y: auto;
            margin-bottom: 1rem;
        }

        .vote-option {
            background: rgba(255, 255, 255, 0.05);
            border: 3px solid var(--card-border);
            padding: 0.75rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: all 0.1s;
            font-size: 2rem;
        }

        .vote-option:hover {
            background: #F0D090;
        }

        .vote-option.selected {
            background: #F0D090;
            border-color: var(--btn-primary);
            border-left-width: 4px;
        }

        .vote-player-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex: 1;
        }

        .vote-color {
            width: 16px;
            height: 16px;
            border: 1px solid rgba(0, 0, 0, 0.3);
        }

        .vote-name {
            text-transform: uppercase;
        }

        .vote-badge {
            background: var(--btn-primary);
            color: white;
            padding: 0.2rem 0.4rem;
            font-size: 1.5rem;
            margin-left: 0.25rem;
        }

        .vote-indicator {
            display: flex;
            gap: 0.25rem;
            margin-left: 0.5rem;
        }

        .vote-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            border: 1px solid rgba(0, 0, 0, 0.3);
        }

        .skip-btn {
            width: 100%;
            background: #D4821A;
            color: white;
            padding: 0.75rem;
            border: 3px solid var(--text-primary);
            font-family: 'Press Start 2P', cursive;
            font-size: 2rem;
            text-transform: uppercase;
            cursor: pointer;
            margin-top: 0.5rem;
        }

        .skip-btn:hover {
            background: #F0D090;
            color: var(--text-primary);
        }

        .chat-panel {
            background: var(--card-bg);
            border: 3px solid var(--card-border);
            padding: 1rem;
            box-shadow: inset -2px -2px 0 rgba(0, 0, 0, 0.15), 0 0 15px rgba(0, 0, 0, 0.5);
            grid-area: chat;
            max-width: 250px;
            max-height: 500px;
            display: flex;
            flex-direction: column;
        }

        .chat-header {
            font-family: 'Press Start 2P', cursive;
            font-size: 2rem;
            color: var(--text-primary);
            margin-bottom: 0.75rem;
            border-bottom: 2px solid var(--card-border);
            padding-bottom: 0.5rem;
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            margin-bottom: 0.75rem;
            max-height: 350px;
        }

        .chat-message {
            font-size: 1.8rem;
            margin-bottom: 0.4rem;
            line-height: 1.2;
            word-break: break-word;
        }

        @media (max-width: 800px) {
            .vote-container {
                grid-template-areas: "modal" "chat";
                max-width: 100%;
                gap: 1rem;
            }

            .chat-panel {
                max-width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="vote-container">
        <!-- Center Modal -->
        <div class="modal-center">
            <div class="modal-header">
                WHO IS THE IMPOSTOR?
            </div>

            <div class="modal-subtitle">
                Vote to eliminate a player or skip
            </div>

            <!-- Vote Options -->
            <div class="vote-options" id="voteOptions">
                <?php foreach ($players as $player): if ($player['user_id'] == $user_id) continue; ?>
                    <div class="vote-option" onclick="selectVote(this, <?php echo $player['id']; ?>)">
                        <div class="vote-player-info">
                            <div class="vote-color" style="background: <?php echo $colorMap[$player['color']] ?? '#999'; ?>;"></div>
                            <span class="vote-name"><?php echo htmlspecialchars($player['username']); ?></span>
                            <?php if ($player['user_id'] == $user_id): ?>
                                <span class="vote-badge">(YOU)</span>
                            <?php endif; ?>
                        </div>
                        <div class="vote-indicator" id="votes-<?php echo $player['id']; ?>">
                            <!-- Vote dots will be added here -->
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <button class="skip-btn" onclick="voteSkip()">
                ⏭ SKIP VOTE
            </button>
        </div>

        <!-- Chat Panel -->
        <div class="chat-panel">
            <div class="chat-header">💬 Chat</div>
            <div class="chat-messages" id="chatMessages">
                <div class="chat-message" style="color: var(--text-muted);">No messages yet...</div>
            </div>
        </div>
    </div>

    <script>
        const roomCode = <?php echo json_encode($room_code); ?>;
        const userId = <?php echo $user_id; ?>;
        let selectedVoteId = null;

        function selectVote(element, playerId) {
            // Remove previous selection
            document.querySelectorAll('.vote-option').forEach(el => {
                el.classList.remove('selected');
            });
            
            // Highlight this option
            element.classList.add('selected');
            selectedVoteId = playerId;

            // Send vote
            fetch('saboteur_vote.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    room_code: roomCode,
                    target_user_id: playerId
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    console.log('Vote recorded');
                }
            })
            .catch(err => console.error(err));
        }

        function voteSkip() {
            fetch('saboteur_vote.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    room_code: roomCode,
                    target_user_id: null  // Null means skip
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    console.log('Skip vote recorded');
                }
            })
            .catch(err => console.error(err));
        }

        // Poll for voting completion
        setInterval(() => {
            fetch('saboteur_poll.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ room_code: roomCode })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    // Update chat
                    const chatArea = document.getElementById('chatMessages');
                    chatArea.innerHTML = (data.chat_messages || []).slice(-3).map(msg => `
                        <div class="chat-message">
                            <strong style="color: #${['E53935','1E88E5','43A047','FB8C00','8E24AA'][Math.random()*5|0]};">${msg.username}:</strong> ${msg.message}
                        </div>
                    `).join('');

                    // Check if voting is done
                    if (data.room.status === 'playing') {
                        window.location.href = 'game.php?room=' + encodeURIComponent(roomCode);
                    }
                }
            })
            .catch(err => console.error(err));
        }, 1500);
    </script>
</body>
</html>
