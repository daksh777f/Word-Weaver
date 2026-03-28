<?php
// ════════════════════════════════════════
// FILE: saboteur_game.php
// PURPOSE: Main saboteur game page with code editor, players, chat, voting
// NEW TABLES USED: saboteur_rooms, saboteur_players, saboteur_challenges, saboteur_chat
// DEPENDS ON: config.php, greeting.php
// CEREBRAS CALLS: no
// ════════════════════════════════════════

require_once '../onboarding/config.php';
require_once '../includes/greeting.php';

requireLogin();

$user_id = (int)$_SESSION['user_id'];
$room_code = trim($_GET['room'] ?? '');

if (empty($room_code)) {
    header('Location: saboteur_lobby.php');
    exit();
}

// Fetch user
$stmt = $pdo->prepare("SELECT username, email, profile_image FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header('Location: ../onboarding/login.php');
    exit();
}

// Fetch room and verify player belongs
$stmt = $pdo->prepare("
    SELECT sr.* FROM saboteur_rooms sr
    INNER JOIN saboteur_players sp ON sp.room_id = sr.id
    WHERE sr.room_code = ? AND sp.user_id = ?
    LIMIT 1
");
$stmt->execute([$room_code, $user_id]);
$room = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$room) {
    header('Location: saboteur_lobby.php');
    exit();
}

$room_id = (int)$room['id'];

// Fetch challenge
$stmt = $pdo->prepare("SELECT * FROM saboteur_challenges WHERE id = ?");
$stmt->execute([(int)$room['challenge_id']]);
$challenge = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$challenge) {
    header('Location: saboteur_lobby.php');
    exit();
}

$test_cases = json_decode($challenge['test_cases'], true);
$todo_descriptions = json_decode($challenge['todo_descriptions'], true);
$sabotage_tasks = json_decode($challenge['sabotage_tasks'], true);

// Fetch current player
$stmt = $pdo->prepare("SELECT * FROM saboteur_players WHERE room_id = ? AND user_id = ?");
$stmt->execute([$room_id, $user_id]);
$current_player = $stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include '../includes/favicon.php'; ?>
    <title>Bug Saboteur - Game</title>
    <link rel="stylesheet" href="../styles.css?v=<?php echo filemtime('../styles.css'); ?>">
    <link rel="stylesheet" href="game-selection.css?v=<?php echo filemtime('game-selection.css'); ?>">
    <link rel="stylesheet" href="../MainGame/grammarheroes/game.css?v=<?php echo filemtime('../MainGame/grammarheroes/game.css'); ?>">
    <link rel="stylesheet" href="../notif/toast.css?v=<?php echo filemtime('../notif/toast.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        :root {
            --sab-bg: #0b1230;
            --sab-border: rgba(255, 255, 255, 0.16);
            --sab-text: #f3f6ff;
            --sab-muted: #b8c2eb;
            --sab-primary: #8b5cf6;
            --sab-danger: #ff6b6b;
            --sab-success: #51cf66;
        }

        body {
            margin: 0;
            padding: 0;
            background: var(--sab-bg);
            color: var(--sab-text);
        }

        .sab-game-container {
            display: grid;
            grid-template-columns: 300px 1fr 280px;
            height: 100vh;
            gap: 1px;
            background: var(--sab-border);
        }

        .sab-game-header {
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.1), rgba(106, 46, 217, 0.05));
            border-bottom: 1px solid var(--sab-border);
            padding: 0.8rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.9rem;
            grid-column: 1 / -1;
        }

        .timer {
            font-weight: 700;
            color: var(--sab-danger);
            font-size: 1.2rem;
        }

        .round-info {
            text-align: center;
            flex: 1;
        }

        .left-panel, .center-panel, .right-panel {
            background: var(--sab-bg);
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }

        .panel-section {
            border-bottom: 1px solid var(--sab-border);
            padding: 1rem;
        }

        .panel-title {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--sab-primary);
            font-weight: 700;
            margin-bottom: 0.8rem;
        }

        .player-item {
            display: flex;
            align-items: center;
            padding: 0.6rem;
            background: rgba(139, 92, 246, 0.05);
            border-radius: 6px;
            margin-bottom: 0.4rem;
            font-size: 0.85rem;
            border: 1px solid transparent;
            transition: all 0.2s;
        }

        .player-item.eliminated {
            opacity: 0.5;
            text-decoration: line-through;
        }

        .player-color {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 0.6rem;
            flex-shrink: 0;
        }

        .red { background: #ff6b6b; }
        .blue { background: #4c6ef5; }
        .green { background: #51cf66; }
        .orange { background: #ffa94d; }
        .purple { background: #b197fc; }

        .player-name {
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .role-badge {
            font-size: 0.7rem;
            background: var(--sab-danger);
            color: white;
            padding: 0.2rem 0.4rem;
            border-radius: 3px;
            margin-left: 0.4rem;
        }

        .test-case {
            background: rgba(139, 92, 246, 0.05);
            border: 1px solid var(--sab-border);
            border-radius: 6px;
            padding: 0.6rem;
            margin-bottom: 0.4rem;
            font-size: 0.8rem;
        }

        .test-case.passed {
            border-color: var(--sab-success);
            background: rgba(81, 207, 102, 0.05);
        }

        .test-case.failed {
            border-color: var(--sab-danger);
            background: rgba(255, 107, 107, 0.05);
        }

        .test-check {
            display: inline-block;
            width: 16px;
            height: 16px;
            border-radius: 3px;
            margin-right: 0.4rem;
            text-align: center;
            line-height: 16px;
            font-size: 0.7rem;
            font-weight: 700;
        }

        .test-case.passed .test-check {
            background: var(--sab-success);
            color: white;
        }

        .test-case.failed .test-check {
            background: var(--sab-danger);
            color: white;
        }

        .test-desc {
            margin-left: 0;
            margin-top: 0.3rem;
        }

        .editor-shell {
            display: grid;
            grid-template-columns: 40px 1fr;
            border: 1px solid var(--sab-border);
            border-radius: 8px;
            overflow: hidden;
            background: #060b1a;
            min-height: 400px;
            margin: 0 1rem 1rem;
        }

        .line-numbers {
            background: #060b1a;
            color: rgba(255, 255, 255, 0.3);
            border-right: 1px solid rgba(255, 255, 255, 0.1);
            text-align: right;
            padding: 10px 6px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.6;
            user-select: none;
            white-space: pre;
        }

        #sab-code-editor {
            width: 100%;
            height: 100%;
            border: 0;
            outline: none;
            resize: none;
            background: #060b1a;
            color: var(--sab-text);
            font-family: 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.6;
            padding: 10px;
        }

        .action-buttons {
            display: flex;
            gap: 0.6rem;
            padding: 0 1rem 1rem;
            flex-wrap: wrap;
        }

        .sab-btn {
            padding: 0.6rem 1rem;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.2s;
        }

        .sab-btn-primary {
            background: var(--sab-primary);
            color: white;
        }

        .sab-btn-primary:hover {
            background: linear-gradient(135deg, var(--sab-primary), #7c3aed);
            transform: translateY(-2px);
        }

        .sab-btn-secondary {
            background: transparent;
            border: 1px solid var(--sab-border);
            color: var(--sab-muted);
        }

        .sab-btn-secondary:hover {
            border-color: var(--sab-primary);
            color: var(--sab-primary);
        }

        .sab-btn-danger {
            background: transparent;
            border: 1px solid var(--sab-danger);
            color: var(--sab-danger);
        }

        .sab-btn-danger:hover {
            background: rgba(255, 107, 107, 0.1);
        }

        .chat-messages {
            list-style: none;
            margin: 0;
            padding: 0;
            flex: 1;
            overflow-y: auto;
        }

        .chat-message {
            padding: 0.6rem;
            margin-bottom: 0.4rem;
            background: rgba(139, 92, 246, 0.05);
            border-radius: 4px;
            font-size: 0.8rem;
            border-left: 2px solid transparent;
        }

        .chat-message.system {
            background: rgba(255, 107, 107, 0.05);
            border-left-color: var(--sab-danger);
            font-style: italic;
            color: var(--sab-muted);
        }

        .chat-username {
            font-weight: 600;
            margin-right: 0.4rem;
        }

        .chat-input-area {
            display: flex;
            gap: 0.4rem;
            padding: 1rem;
            border-top: 1px solid var(--sab-border);
        }

        #sab-chat-input {
            flex: 1;
            background: #060b1a;
            border: 1px solid var(--sab-border);
            border-radius: 6px;
            color: var(--sab-text);
            padding: 0.6rem;
            font-size: 0.85rem;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 10000;
            align-items: center;
            justify-content: center;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: var(--sab-bg);
            border: 1px solid var(--sab-border);
            border-radius: 12px;
            padding: 2rem;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal-title {
            font-size: 1.3rem;
            margin-bottom: 1rem;
            color: var(--sab-primary);
        }

        .modal-buttons {
            display: flex;
            gap: 0.8rem;
            margin-top: 1.5rem;
            flex-wrap: wrap;
        }

        .player-vote-option {
            padding: 0.8rem;
            background: rgba(139, 92, 246, 0.05);
            border: 1px solid var(--sab-border);
            border-radius: 8px;
            margin-bottom: 0.6rem;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .player-vote-option:hover {
            border-color: var(--sab-primary);
            background: rgba(139, 92, 246, 0.1);
        }

        .player-vote-option.voted {
            background: rgba(139, 92, 246, 0.2);
            border-color: var(--sab-primary);
        }

        .radio-circle {
            width: 18px;
            height: 18px;
            border-radius: 50%;
            border: 2px solid var(--sab-muted);
            flex-shrink: 0;
        }

        .player-vote-option.voted .radio-circle {
            background: var(--sab-primary);
            border-color: var(--sab-primary);
        }
    </style>
</head>
<body>
    <div class="sab-game-container">
        <!-- HEADER -->
        <div class="sab-game-header">
            <div>Round <strong id="roundNum">1</strong> / <?php echo (int)$room['max_rounds']; ?></div>
            <div class="round-info">
                <div class="timer" id="timer">01:00</div>
            </div>
            <button class="ghost-btn" onclick="if(confirm('Leave game?')) window.location.href='saboteur_lobby.php'" style="margin-left: auto;">
                <i class="fas fa-door-open"></i> Quit
            </button>
        </div>

        <!-- LEFT PANEL: PLAYERS & TESTS -->
        <div class="left-panel">
            <div class="panel-section">
                <div class="panel-title">🧑 Players</div>
                <div id="playersList"></div>
                <button id="emergencyBtn" class="sab-btn sab-btn-danger" style="width: 100%; margin-top: 0.8rem; font-size: 0.8rem;">🚨 Emergency Meeting</button>
            </div>

            <div class="panel-section" style="flex: 1;">
                <div class="panel-title">✅ Test Cases</div>
                <div id="testsList"></div>
                <button id="runTestsBtn" class="sab-btn sab-btn-primary" style="width: 100%; margin-top: 0.8rem;">Run Tests</button>
            </div>
        </div>

        <!-- CENTER PANEL: CODE EDITOR -->
        <div class="center-panel">
            <div style="padding: 1rem 0 0;">
                <div style="padding: 0 1rem; margin-bottom: 0.8rem;">
                    <h2 style="margin: 0; color: var(--sab-primary);">📝 <?php echo htmlspecialchars($challenge['title']); ?></h2>
                    <p style="margin: 0.3rem 0; font-size: 0.85rem; color: var(--sab-muted);"><?php echo join(', ', $todo_descriptions ?? []); ?></p>
                </div>

                <div class="editor-shell">
                    <div class="line-numbers" id="lineNumbers">1</div>
                    <textarea id="sab-code-editor" spellcheck="false"><?php echo htmlspecialchars($challenge['base_code']); ?></textarea>
                </div>

                <div class="action-buttons">
                    <button id="checkSabotageBtn" class="sab-btn sab-btn-secondary">🔍 Check for Sabotage</button>
                    <button id="submitBtn" class="sab-btn sab-btn-primary">Submit Code</button>
                </div>
            </div>
        </div>

        <!-- RIGHT PANEL: CHAT -->
        <div class="right-panel">
            <div class="panel-section" style="flex: 1; display: flex; flex-direction: column;">
                <div class="panel-title">💬 Chat</div>
                <ul class="chat-messages" id="chatMessages"></ul>
            </div>

            <div class="chat-input-area">
                <input type="text" id="sab-chat-input" placeholder="Send message..." maxlength="200">
                <button id="sendChatBtn" class="sab-btn sab-btn-primary" style="padding: 0.6rem 0.8rem;"><i class="fas fa-paper-plane"></i></button>
            </div>
        </div>
    </div>

    <!-- ROLE REVEAL MODAL -->
    <div class="modal" id="roleRevealModal">
        <div class="modal-content">
            <div class="modal-title">🎭 Your Role</div>
            <div id="roleDisplay" style="font-size: 2rem; margin: 2rem 0; text-align: center;"></div>
            <p id="roleDescription" style="text-align: center; color: var(--sab-muted); line-height: 1.6;"></p>
            <div class="modal-buttons">
                <button class="sab-btn sab-btn-primary" style="width: 100%;" onclick="document.getElementById('roleRevealModal').classList.remove('show');">Ready to Play</button>
            </div>
        </div>
    </div>

    <!-- VOTING MODAL -->
    <div class="modal" id="votingModal">
        <div class="modal-content">
            <div class="modal-title">🗳️ Emergency Vote</div>
            <p style="color: var(--sab-muted); margin-bottom: 1rem;">Who do you suspect?</p>
            <div id="votingOptions"></div>
            <div class="modal-buttons">
                <button id="confirmVoteBtn" class="sab-btn sab-btn-primary" style="width: 100%;">Submit Vote</button>
            </div>
        </div>
    </div>

    <!-- GAME OVER MODAL -->
    <div class="modal" id="gameOverModal">
        <div class="modal-content">
            <div class="modal-title" id="gameOverTitle">Game Over</div>
            <div id="gameOverContent" style="text-align: center;"></div>
            <div class="modal-buttons">
                <button class="sab-btn sab-btn-primary" style="width: 100%;" onclick="window.location.href='saboteur_lobby.php';">Back to Lobby</button>
            </div>
        </div>
    </div>

    <script>
        const GAME_CONFIG = {
            roomCode: <?php echo json_encode($room_code); ?>,
            challengeId: <?php echo (int)$room['challenge_id']; ?>,
            userId: <?php echo $user_id; ?>,
            username: <?php echo json_encode($user['username']); ?>,
            isHost: <?php echo (int)$current_player['is_host'] ? 'true' : 'false'; ?>,
            playerRole: null,
            currentRound: <?php echo (int)$room['current_round']; ?>,
            maxRounds: <?php echo (int)$room['max_rounds']; ?>,
            roundDuration: <?php echo (int)$room['round_duration']; ?>
        };

        let pollInterval = null;
        let timerInterval = null;
        let roundEndTime = null;
        let selectedVoteTarget = null;
        let lastChatId = 0;

        // ════════════════════════════════
        // INITIALIZATION
        // ════════════════════════════════

        function init() {
            document.getElementById('sendChatBtn').addEventListener('click', sendChat);
            document.getElementById('sab-chat-input').addEventListener('keypress', (e) => {
                if (e.key === 'Enter') sendChat();
            });

            document.getElementById('runTestsBtn').addEventListener('click', runTests);
            document.getElementById('submitBtn').addEventListener('click', submitCode);
            document.getElementById('checkSabotageBtn').addEventListener('click', checkSabotage);
            document.getElementById('emergencyBtn').addEventListener('click', callEmergency);

            // Start game loop
            pollGame();
            pollInterval = setInterval(pollGame, 1000);
        }

        // ════════════════════════════════
        // POLLING & STATE MANAGEMENT
        // ════════════════════════════════

        async function pollGame() {
            try {
                const response = await fetch('saboteur_poll.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ room_code: GAME_CONFIG.roomCode })
                });
                const data = await response.json();

                if (!data.success) {
                    console.error('Poll error:', data.message);
                    return;
                }

                // Update room state
                GAME_CONFIG.playerRole = data.current_player.role;
                GAME_CONFIG.currentRound = data.room.current_round;

                // Update displays
                document.getElementById('roundNum').textContent = data.room.current_round;
                updatePlayersList(data.players);
                updateTimer(data.room.round_remaining);
                updateChat(data.chat_messages);

                // Handle state transitions
                if (data.room.status === 'role_reveal' && !document.getElementById('roleRevealModal').classList.contains('show')) {
                    showRoleReveal(data.current_player.role);
                }

                if (data.room.status === 'voting') {
                    showVotingModal(data.players);
                }

                if (data.room.status === 'finished') {
                    showGameOver(data.room.winner, data.current_player.role);
                }
            } catch (err) {
                console.error('Poll error:', err);
            }
        }

        function updateTimer(remaining) {
            const mins = Math.floor(remaining / 60);
            const secs = remaining % 60;
            document.getElementById('timer').textContent = 
                String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');

            if (remaining <= 10) {
                document.getElementById('timer').style.color = '#ff6b6b';
            } else {
                document.getElementById('timer').style.color = '#51cf66';
            }
        }

        function updatePlayersList(players) {
            const list = document.getElementById('playersList');
            list.innerHTML = players.map(p => `
                <div class="player-item ${p.is_eliminated ? 'eliminated' : ''}">
                    <div class="player-color ${p.color}"></div>
                    <div class="player-name">${htmlEscape(p.username)}</div>
                    ${p.is_you ? '<span style="font-weight:600;color:var(--sab-primary);">(You)</span>' : ''}
                </div>
            `).join('');
        }

        function updateChat(messages) {
            const chatList = document.getElementById('chatMessages');
            messages.filter(m => m.id > lastChatId).forEach(msg => {
                lastChatId = Math.max(lastChatId, msg.id);
                const li = document.createElement('li');
                li.className = 'chat-message' + (msg.is_system ? ' system' : '');
                li.innerHTML = msg.is_system 
                    ? htmlEscape(msg.message)
                    : `<span class="chat-username">${htmlEscape(msg.username)}:</span>${htmlEscape(msg.message)}`;
                chatList.appendChild(li);
                chatList.scrollTop = chatList.scrollHeight;
            });
        }

        // ════════════════════════════════
        // ACTIONS
        // ════════════════════════════════

        async function sendChat() {
            const input = document.getElementById('sab-chat-input');
            const message = input.value.trim();
            if (!message) return;

            try {
                const response = await fetch('saboteur_chat.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        room_code: GAME_CONFIG.roomCode,
                        action: 'send',
                        message: message
                    })
                });
                const data = await response.json();
                if (data.success) {
                    input.value = '';
                    pollGame();
                }
            } catch (err) {
                showToast('Failed to send message', 'error');
            }
        }

        async function runTests() {
            const code = document.getElementById('sab-code-editor').value;

            try {
                const response = await fetch('saboteur_run_tests.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        room_code: GAME_CONFIG.roomCode,
                        code: code
                    })
                });
                const data = await response.json();

                if (data.success) {
                    updateTestDisplay(data.test_results);
                    showToast(`${data.passed}/${data.total} tests passed`, data.all_passed ? 'success' : 'warning');
                } else {
                    showToast('Error running tests', 'error');
                }
            } catch (err) {
                showToast('Failed to run tests', 'error');
            }
        }

        function updateTestDisplay(results) {
            const list = document.getElementById('testsList');
            list.innerHTML = results.map(test => `
                <div class="test-case ${test.passed ? 'passed' : 'failed'}">
                    <span class="test-check">${test.passed ? '✓' : '✗'}</span>${htmlEscape(test.description)}
                </div>
            `).join('');
        }

        async function checkSabotage() {
            const code = document.getElementById('sab-code-editor').value;

            try {
                const response = await fetch('saboteur_check_sabotage.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        room_code: GAME_CONFIG.roomCode,
                        code: code
                    })
                });
                const data = await response.json();

                if (data.success) {
                    if (data.sabotages_detected > 0) {
                        showToast(`🚨 Found ${data.sabotages_detected} sabotage pattern(s)!`, 'warning');
                    } else {
                        showToast('Code looks clean', 'success');
                    }
                } else {
                    showToast('Error: ' + data.message, 'error');
                }
            } catch (err) {
                showToast('Failed to check sabotage', 'error');
            }
        }

        async function submitCode() {
            const code = document.getElementById('sab-code-editor').value;

            try {
                const response = await fetch('saboteur_save_code.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        room_code: GAME_CONFIG.roomCode,
                        code: code
                    })
                });
                const data = await response.json();

                if (data.success) {
                    showToast('Code submitted!', 'success');
                } else {
                    showToast('Error: ' + data.message, 'error');
                }
            } catch (err) {
                showToast('Failed to submit code', 'error');
            }
        }

        async function callEmergency() {
            try {
                const response = await fetch('saboteur_emergency.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ room_code: GAME_CONFIG.roomCode })
                });
                const data = await response.json();

                if (data.success) {
                    showToast('Emergency meeting called!', 'info');
                } else {
                    showToast('Error: ' + data.message, 'error');
                }
            } catch (err) {
                showToast('Failed to call emergency', 'error');
            }
        }

        // ════════════════════════════════
        // MODALS
        // ════════════════════════════════

        function showRoleReveal(role) {
            const modal = document.getElementById('roleRevealModal');
            const display = document.getElementById('roleDisplay');
            const desc = document.getElementById('roleDescription');

            if (role === 'saboteur') {
                display.innerHTML = '🕵️ SABOTEUR';
                desc.textContent = 'You are the saboteur! Subtly break the code while fixers try to complete it. Win by making the code fail tests or not get finished.';
            } else {
                display.innerHTML = '🔧 FIXER';
                desc.textContent = 'You are a fixer! Work with other fixers to complete the code and pass all tests. Watch out for the saboteur among you.';
            }

            modal.classList.add('show');
        }

        function showVotingModal(players) {
            const modal = document.getElementById('votingModal');
            const options = document.getElementById('votingOptions');

            options.innerHTML = players
                .filter(p => !p.is_eliminated && !p.is_you)
                .map(p => `
                    <div class="player-vote-option" onclick="selectVote(${p.id}, this)">
                        <div class="radio-circle"></div>
                        <div class="player-color ${p.color}" style="width: 12px; height: 12px;"></div>
                        <span>${htmlEscape(p.username)}</span>
                    </div>
                `).join('');

            document.getElementById('confirmVoteBtn').onclick = castVote;
            modal.classList.add('show');
        }

        function selectVote(playerId, element) {
            document.querySelectorAll('.player-vote-option').forEach(el => el.classList.remove('voted'));
            element.classList.add('voted');
            selectedVoteTarget = playerId;
        }

        async function castVote() {
            try {
                const response = await fetch('saboteur_vote.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        room_code: GAME_CONFIG.roomCode,
                        target_user_id: selectedVoteTarget
                    })
                });
                const data = await response.json();

                if (data.success) {
                    document.getElementById('votingModal').classList.remove('show');
                    showToast('Vote cast!', 'success');
                } else {
                    showToast('Error: ' + data.message, 'error');
                }
            } catch (err) {
                showToast('Failed to cast vote', 'error');
            }
        }

        function showGameOver(winner, playerRole) {
            const modal = document.getElementById('gameOverModal');
            const title = document.getElementById('gameOverTitle');
            const content = document.getElementById('gameOverContent');

            let resultText = '';
            let resultEmoji = '';

            if (winner === 'fixers' && playerRole === 'fixer') {
                resultEmoji = '🎉';
                resultText = 'Fixers Won!';
            } else if (winner === 'saboteur' && playerRole === 'saboteur') {
                resultEmoji = '🎯';
                resultText = 'Saboteur Won!';
            } else {
                resultEmoji = '❌';
                resultText = 'You Lost';
            }

            title.textContent = resultEmoji + ' ' + resultText;
            content.innerHTML = `<p style="font-size: 1.2rem; margin: 1.5rem 0;">+100 XP Awarded</p>`;

            // Award XP
            fetch('saboteur_award_xp.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ room_code: GAME_CONFIG.roomCode })
            }).catch(err => console.error(err));

            modal.classList.add('show');
        }

        // ════════════════════════════════
        // UTILITIES
        // ════════════════════════════════

        function htmlEscape(str) {
            return str.replace(/[&<>"']/g, (ch) => {
                const map = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'};
                return map[ch];
            });
        }

        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.textContent = message;
            toast.style.cssText = `
                position: fixed;
                bottom: 1rem;
                right: 1rem;
                padding: 1rem 1.5rem;
                background: ${type === 'error' ? '#ff6b6b' : type === 'warning' ? '#ffa94d' : type === 'success' ? '#51cf66' : '#8b5cf6'};
                color: white;
                border-radius: 8px;
                z-index: 10000;
            `;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        }

        // Start the game
        init();
    </script>
</body>
</html>
