<?php
require_once '../onboarding/config.php';
requireLogin();

$user_id = (int)$_SESSION['user_id'];
$room_code = isset($_GET['room']) ? trim($_GET['room']) : null;

if (!$room_code) {
    header('Location: index.php');
    exit;
}

// Get room and challenge
$stmt = $pdo->prepare("
    SELECT sr.id, sr.challenge_id, sr.current_round, sr.max_rounds, sr.round_duration
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

// Get challenge if set
$challenge = null;
if ($room['challenge_id']) {
    $stmt = $pdo->prepare("
        SELECT id, title, base_code, test_cases, category
        FROM saboteur_challenges
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$room['challenge_id']]);
    $challenge = $stmt->fetch();
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
    <title>Code Mafia - Game</title>
    <link rel="stylesheet" href="retro-game.css">
    <style>
        body {
            display: grid;
            grid-template-columns: 200px 1fr 200px;
            grid-template-rows: 1fr 40px;
            gap: 0;
            height: 100vh;
            overflow: hidden;
            background: var(--card-bg);
        }

        .game-panel {
            background: var(--card-bg);
            border-right: 3px solid var(--card-border);
            padding: 0.75rem;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }

        .left-panel {
            border-right: 3px solid var(--card-border);
            grid-column: 1;
            grid-row: 1;
        }

        .center-panel {
            display: flex;
            flex-direction: column;
            border-right: 3px solid var(--card-border);
            padding: 0;
            grid-column: 2;
            grid-row: 1;
            overflow: hidden;
        }

        .right-panel {
            border-left: 3px solid var(--card-border);
            display: flex;
            flex-direction: column;
            grid-column: 3;
            grid-row: 1;
        }

        .panel-header {
            font-size: 2.1rem;
            color: var(--text-primary);
            margin-bottom: 0.6rem;
            text-transform: uppercase;
            border-bottom: 3px solid var(--card-border);
            padding-bottom: 0.5rem;
            font-weight: bold;
        }

        .panel-section {
            margin-bottom: 1rem;
        }

        .player-item {
            display: flex;
            align-items: center;
            padding: 0.35rem 0.35rem;
            margin-bottom: 0.25rem;
            background: rgba(255, 255, 255, 0.05);
            border-left: 4px solid #ccc;
            font-size: 1.5rem;
            border: 2px solid var(--card-border);
            box-shadow: inset -1px -1px 0 rgba(0,0,0,0.1);
        }

        .player-color {
            width: 10px;
            height: 10px;
            margin-right: 0.4rem;
            border: 1px solid rgba(0, 0, 0, 0.3);
        }

        .player-name {
            flex: 1;
            text-transform: uppercase;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .test-case {
            background: rgba(255, 255, 255, 0.05);
            border: 3px solid var(--card-border);
            padding: 0.4rem 0.3rem;
            margin-bottom: 0.35rem;
            font-size: 1.3rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            transition: all 0.1s;
            box-shadow: inset -2px -2px 0 rgba(0,0,0,0.1);
        }

        .test-case:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .test-case input[type="radio"] {
            margin-right: 0.3rem;
            width: 14px;
            height: 14px;
            flex-shrink: 0;
        }

        .test-case.passed {
            background: rgba(76, 175, 80, 0.2);
            border-color: var(--btn-action);
        }

        .test-case.passed::after {
            content: ' ✓';
            color: var(--btn-action);
            font-weight: bold;
        }

        .top-bar {
            background: linear-gradient(180deg, rgba(255,255,255,0.15) 0%, rgba(255,255,255,0.08) 100%);
            border-bottom: 4px solid var(--card-border);
            padding: 0.6rem 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 1.4rem;
            gap: 1rem;
            flex-shrink: 0;
            min-height: 75px;
            box-sizing: border-box;
        }

        .round-badge {
            background: var(--btn-primary);
            color: white;
            padding: 0.5rem 0.8rem;
            border-radius: 20px;
            text-transform: uppercase;
            font-weight: bold;
            font-size: 1.3rem;
            white-space: nowrap;
            flex-shrink: 0;
            border: 2px solid #B8620E;
            box-shadow: inset -1px -1px 0 rgba(0,0,0,0.3);
        }

        #challengeName {
            flex: 1;
            text-align: center;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-weight: bold;
            font-size: 1.6rem;
            color: var(--text-primary);
            padding: 0 1rem;
        }

        #aliveCount {
            white-space: nowrap;
            flex-shrink: 0;
        }

        .timer {
            background: var(--code-dark);
            border: 3px solid #0f0;
            padding: 0.4rem 0.8rem;
            font-family: 'Courier New', monospace;
            font-size: 1.7rem;
            text-align: center;
            color: #0f0;
            flex-shrink: 0;
            min-width: 90px;
            font-weight: bold;
            box-shadow: inset 0 0 10px rgba(0,255,0,0.2);
        }

        .editor-area {
            flex: 1;
            padding: 0.5rem;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .code-editor {
            flex: 1;
            background: var(--code-dark);
            border: 3px solid var(--card-border);
            padding: 0.75rem;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            color: #0f0;
            overflow: auto;
            resize: none;
        }

        .code-editor::selection {
            background: rgba(0, 255, 0, 0.3);
        }

        .action-buttons {
            display: flex;
            gap: 0.4rem;
            margin-top: 0.5rem;
            justify-content: center;
        }

        .emergency-btn {
            background: var(--btn-danger);
            border: 3px solid #B71C1C;
            width: 100%;
            padding: 0.7rem 0.5rem;
            font-size: 1.7rem;
            color: white;
            flex-shrink: 0;
            font-weight: bold;
            cursor: pointer;
            text-transform: uppercase;
            transition: all 0.1s;
            box-shadow: inset -2px -2px 0 rgba(0,0,0,0.3);
        }

        .emergency-btn:hover {
            background: #B71C1C;
        }

        .emergency-btn:active {
            box-shadow: inset 2px 2px 0 rgba(0,0,0,0.3);
        }

        .chat-messages {
            flex: 1;
            list-style: none;
            overflow-y: auto;
            margin-bottom: 0.6rem;
            padding: 0.5rem;
            font-size: 1.3rem;
            border: 2px solid var(--card-border);
            background: rgba(255, 255, 255, 0.03);
        }

        .chat-message {
            margin-bottom: 0.4rem;
            line-height: 1.3;
            word-break: break-word;
            padding: 0.2rem 0.3rem;
        }

        .chat-message strong {
            text-transform: uppercase;
            font-size: 1.2rem;
            font-weight: bold;
        }

        .chat-input-area {
            display: flex;
            gap: 0.3rem;
            padding: 0.4rem;
            flex-shrink: 0;
            border-top: 2px solid var(--card-border);
        }

        .chat-input-area input {
            flex: 1;
            font-size: 1.2rem;
            padding: 0.35rem 0.4rem;
            min-width: 0;
            border: 2px solid var(--card-border);
            background: rgba(255,255,255,0.8);
            box-shadow: inset -1px -1px 0 rgba(0,0,0,0.1);
        }

        .chat-input-area button {
            padding: 0.35rem 0.5rem;
            font-size: 1.2rem;
            background: var(--btn-primary);
            color: white;
            flex-shrink: 0;
            border: 2px solid #B8620E;
            cursor: pointer;
            font-weight: bold;
            box-shadow: inset -1px -1px 0 rgba(0,0,0,0.2);
        }

        .chat-input-area button:hover {
            background: #C47215;
        }

        .test-note {
            font-size: 1.1rem;
            color: var(--text-muted);
            margin-top: 0.4rem;
        }

        .ground {
            grid-column: 1 / -1;
            grid-row: 2;
            background: var(--ground-green);
            border-top: 3px solid var(--card-border);
        }

        @media (max-width: 1200px) {
            body {
                grid-template-columns: 1fr;
            }

            .left-panel {
                grid-column: 1;
                grid-row: 1;
            }

            .center-panel {
                grid-column: 1;
                grid-row: 1;
            }

            .right-panel {
                grid-column: 1;
                grid-row: 1;
            }

            .game-panel {
                display: none;
            }

            .right-panel {
                display: flex;
            }
        }
    </style>
</head>
<body>
    <!-- LEFT PANEL -->
    <div class="game-panel left-panel">
        <!-- Players -->
        <div class="panel-section">
            <div class="panel-header">👥 Players</div>
            <div id="playersList"></div>
        </div>

        <!-- Test Cases -->
        <div class="panel-section">
            <div class="panel-header">✅ Test Cases (0/3)</div>
            <div id="testCasesList"></div>
            <div class="test-note">Tests lock once passed ✓</div>
        </div>
    </div>

    <!-- CENTER PANEL -->
    <div class="center-panel">
        <!-- Top Bar -->
        <div class="top-bar">
            <span class="round-badge" id="roundBadge">Round 1/4</span>
            <span id="challengeName" style="flex: 1; margin: 0 1rem;">Challenge</span>
            <span id="aliveCount">4 Alive</span>
            <div class="timer" id="timer">32s</div>
        </div>

        <!-- Editor Area -->
        <div class="editor-area">
            <textarea id="codeEditor" class="code-editor" spellcheck="false"><?php echo isset($challenge) ? htmlspecialchars($challenge['base_code']) : '// No challenge loaded'; ?></textarea>

            <!-- Action Buttons -->
            <div class="action-buttons">
                <button class="emergency-btn" onclick="callEmergency()">
                    ⚠ EMERGENCY
                </button>
            </div>
        </div>
    </div>

    <!-- RIGHT PANEL -->
    <div class="game-panel right-panel">
        <div class="panel-header">💬 Chat</div>
        <ul class="chat-messages" id="chatMessages">
            <li class="chat-message" style="color: var(--text-muted);">No messages yet...</li>
        </ul>

        <div class="chat-input-area">
            <input 
                type="text" 
                id="chatInput" 
                placeholder="Type..."
                onkeypress="if(event.key==='Enter') sendChat();"
            >
            <button onclick="sendChat()">📤</button>
        </div>
    </div>

    <!-- Ground -->
    <div class="ground"></div>

    <script>
        const roomCode = <?php echo json_encode($room_code); ?>;
        const userId = <?php echo $user_id; ?>;
        let lastChatId = 0;

        async function pollGame() {
            try {
                const response = await fetch('saboteur_poll.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ room_code: roomCode })
                });
                const data = await response.json();

                if (data.success) {
                    // Update displays
                    document.getElementById('roundBadge').textContent = 
                        `Round ${data.room.current_round}/${data.room.max_rounds}`;
                    
                    if (data.challenge) {
                        document.getElementById('challengeName').textContent = data.challenge.title;
                    }

                    // Update timer
                    const mins = Math.floor(data.room.round_remaining / 60);
                    const secs = data.room.round_remaining % 60;
                    document.getElementById('timer').textContent = 
                        String(mins).padStart(1, '0') + ':' + String(secs).padStart(2, '0');

                    // Update players
                    updatePlayersList(data.players);

                    // Update chat
                    updateChat(data.chat_messages);

                    // Update alive count
                    const aliveCount = data.players.filter(p => !p.is_eliminated).length;
                    document.getElementById('aliveCount').textContent = aliveCount + ' Alive';

                    // Check for game state changes
                    if (data.room.status === 'voting') {
                        window.location.href = 'vote.php?room=' + encodeURIComponent(roomCode);
                    }
                    
                    if (data.room.status === 'finished') {
                        window.location.href = 'game_over.php?room=' + encodeURIComponent(roomCode) + '&result=' + data.room.winner;
                    }
                }
            } catch (err) {
                console.error(err);
            }
        }

        function updatePlayersList(players) {
            const list = document.getElementById('playersList');
            list.innerHTML = players.map(p => `
                <div class="player-item" style="${p.is_eliminated ? 'opacity: 0.5;' : ''}">
                    <div class="player-color" style="background: ${getPlayerColor(p.color)};"></div>
                    <div class="player-name">${p.username}${p.is_you ? ' (YOU)' : ''}</div>
                </div>
            `).join('');
        }

        function updateChat(messages) {
            const list = document.getElementById('chatMessages');
            messages.slice(-5).forEach(msg => {
                if (msg.id > lastChatId) {
                    lastChatId = msg.id;
                    const li = document.createElement('li');
                    li.className = 'chat-message';
                    const color = getPlayerColor(msg.color);
                    li.innerHTML = `<strong style="color: ${color};">${msg.username}:</strong> ${msg.message}`;
                    list.appendChild(li);
                }
            });
            list.scrollTop = list.scrollHeight;
        }

        function getPlayerColor(color) {
            const colorMap = {
                red: '#E53935',
                blue: '#1E88E5',
                green: '#43A047',
                orange: '#FB8C00',
                purple: '#8E24AA'
            };
            return colorMap[color] || '#999';
        }

        async function sendChat() {
            const input = document.getElementById('chatInput');
            const message = input.value.trim();
            if (!message) return;

            try {
                const response = await fetch('saboteur_chat.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        room_code: roomCode,
                        message: message
                    })
                });
                
                if (response.ok) {
                    input.value = '';
                    pollGame();
                }
            } catch (err) {
                console.error(err);
            }
        }

        async function callEmergency() {
            alert('Emergency meeting called!');
            try {
                await fetch('saboteur_emergency.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ room_code: roomCode })
                });
                pollGame();
            } catch (err) {
                console.error(err);
            }
        }

        // Poll every 1 second
        pollGame();
        setInterval(pollGame, 1000);
    </script>
</body>
</html>
