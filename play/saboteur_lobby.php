<?php
// ════════════════════════════════════════
// FILE: saboteur_lobby.php
// PURPOSE: Saboteur lobby and waiting room for multiplayer game
// NEW TABLES USED: saboteur_rooms, saboteur_players, saboteur_challenges, users
// DEPENDS ON: config.php, greeting.php
// CEREBRAS CALLS: no
// ════════════════════════════════════════

require_once '../onboarding/config.php';
require_once '../includes/greeting.php';

requireLogin();

$user_id = (int)$_SESSION['user_id'];

// Fetch user info
$stmt = $pdo->prepare("SELECT username, email, profile_image FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header('Location: ../onboarding/login.php');
    exit();
}

// Check if user already in an unfinished room
$stmt = $pdo->prepare("
    SELECT sr.room_code, sr.status FROM saboteur_rooms sr
    INNER JOIN saboteur_players sp ON sp.room_id = sr.id
    WHERE sp.user_id = ? AND sr.status IN ('lobby', 'role_reveal', 'playing', 'voting')
    LIMIT 1
");
$stmt->execute([$user_id]);
$activeRoom = $stmt->fetch(PDO::FETCH_ASSOC);

// Only auto-restore lobby rooms; active matches should not force-open from game selection.
if ($activeRoom && $activeRoom['status'] !== 'lobby') {
    $activeRoom = null;
}

// Get categories
$stmt = $pdo->prepare("SELECT DISTINCT category FROM saboteur_challenges ORDER BY category");
$stmt->execute();
$categories = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'category');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include '../includes/favicon.php'; ?>
    <title>Bug Saboteur</title>
    <link rel="stylesheet" href="../styles.css?v=<?php echo filemtime('../styles.css'); ?>">
    <link rel="stylesheet" href="game-selection.css?v=<?php echo filemtime('game-selection.css'); ?>">
    <link rel="stylesheet" href="../MainGame/grammarheroes/game.css?v=<?php echo filemtime('../MainGame/grammarheroes/game.css'); ?>">
    <link rel="stylesheet" href="../notif/toast.css?v=<?php echo filemtime('../notif/toast.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        :root {
            --sab-primary: #8b5cf6;
            --sab-danger: #ff6b6b;
            --sab-success: #51cf66;
        }

        .saboteur-lobby {
            display: grid;
            grid-template-columns: 1fr 360px;
            gap: 2rem;
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1.5rem;
        }

        @media (max-width: 768px) {
            .saboteur-lobby {
                grid-template-columns: 1fr;
                gap: 1.5rem;
                margin: 1rem auto;
                padding: 0 1rem;
            }
        }

        .entry-card, .waiting-card, .categories-card {
            background: rgba(13, 22, 51, 0.86);
            border: 1px solid rgba(255, 255, 255, 0.16);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }

        .entry-section {
            margin-bottom: 1.5rem;
        }

        .entry-section h3 {
            font-size: 1.1rem;
            margin-bottom: 0.8rem;
            color: var(--sab-primary);
        }

        #roomCodeInput {
            width: 100%;
            padding: 0.8rem;
            background: #060b1a;
            border: 1px solid rgba(139, 92, 246, 0.3);
            border-radius: 8px;
            color: #f3f6ff;
            font-size: 0.95rem;
            margin-bottom: 0.8rem;
        }

        #createRoomBtn, #joinRoomBtn, #readyBtn, #categorySelect {
            width: 100%;
            padding: 0.8rem;
            margin-bottom: 0.5rem;
            background: linear-gradient(135deg, #8b5cf6, #6d28d9);
            border: none;
            border-radius: 8px;
            color: white;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.25s ease;
        }

        #createRoomBtn:hover, #joinRoomBtn:hover, #readyBtn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(139, 92, 246, 0.4);
        }

        .waiting-room {
            display: none;
        }

        .player-list {
            list-style: none;
            margin: 1rem 0;
        }

        .player-item {
            display: flex;
            align-items: center;
            padding: 0.8rem;
            background: #060b1a;
            border-radius: 8px;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .player-color {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 0.8rem;
        }

        .red { background: #ff6b6b; }
        .blue { background: #4c6ef5; }
        .green { background: #51cf66; }
        .orange { background: #ffa94d; }
        .purple { background: #b197fc; }

        .player-name {
            flex: 1;
        }

        .ready-badge {
            background: var(--sab-success);
            color: white;
            padding: 0.2rem 0.6rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .host-badge {
            background: var(--sab-danger);
            color: white;
            padding: 0.2rem 0.6rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 0.4rem;
        }

        #categorySelect {
            background: #060b1a;
            border: 1px solid rgba(139, 92, 246, 0.3);
            color: #f3f6ff;
            padding: 0.8rem;
            width: 100%;
            border-radius: 8px;
            margin-bottom: 0.8rem;
        }

        .start-btn-area {
            display: none;
            text-align: center;
        }

        .start-btn-area.visible {
            display: block !important;
        }

        .categories-card.visible {
            display: block !important;
        }

        #readyBtn.hidden {
            display: none !important;
        }

        #readyBtn.visible {
            display: block !important;
        }

        .status-message {
            text-align: center;
            padding: 1rem;
            background: rgba(139, 92, 246, 0.1);
            border-radius: 8px;
            color: var(--sab-primary);
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <?php include '../includes/page-loader.php'; ?>

    <div class="game-shell">
        <header class="game-header">
            <button class="ghost-btn" onclick="window.location.href='game-selection.php'">
                <i class="fas fa-arrow-left"></i> Back
            </button>
            <div class="title-wrap">
                <h1>🕵️ Bug Saboteur</h1>
                <p>Find the saboteur before they break the code</p>
            </div>
            <div class="profile-pill">
                <img src="<?php echo !empty($user['profile_image']) ? '../' . htmlspecialchars($user['profile_image']) : '../assets/menu/defaultuser.png'; ?>" alt="Profile">
                <span><?php echo htmlspecialchars($user['username']); ?></span>
            </div>
        </header>

        <div class="saboteur-lobby">
            <div class="main-content">
                <div class="entry-card" id="entryCard">
                    <div class="entry-section">
                        <h3>Create Room</h3>
                        <button id="createRoomBtn">Create New Game</button>
                    </div>

                    <div style="text-align: center; color: rgba(255,255,255,0.5); margin: 1rem 0;">— OR —</div>

                    <div class="entry-section">
                        <h3>Join Room</h3>
                        <input type="text" id="roomCodeInput" placeholder="Enter room code (e.g., ABC123)" maxlength="6">
                        <button id="joinRoomBtn">Join Game</button>
                    </div>
                </div>

                <div class="waiting-card waiting-room" id="waitingRoom">
                    <div class="status-message">
                        Room: <strong id="displayRoomCode">---</strong>
                    </div>

                    <h3 style="color: var(--sab-primary); margin-bottom: 1rem;">Players (<span id="playerCount">0</span>/5)</h3>

                    <ul class="player-list" id="playerList">
                    </ul>

                    <div class="categories-card" id="categoriesSection" style="display: none; background: rgba(139,92,246,0.1); border-color: rgba(139,92,246,0.3);">
                        <h3 style="color: var(--sab-primary);">Challenge Category (Host Only)</h3>
                        <select id="categorySelect">
                            <option value="">-- Select Category --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="start-btn-area" id="startBtnArea">
                        <button id="startGameBtn" style="width: 100%; padding: 0.8rem; background: var(--sab-success); color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.25s ease;">
                            ▶ Start Game
                        </button>
                    </div>

                    <button id="readyBtn" style="display: none; width: 100%; padding: 0.8rem; background: var(--sab-success); color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; margin-top: 1rem;">
                        ✓ Ready
                    </button>

                    <button id="leaveRoomBtn" style="width: 100%; padding: 0.8rem; background: transparent; border: 1px solid var(--sab-danger); color: var(--sab-danger); border-radius: 8px; font-weight: 600; cursor: pointer; margin-top: 0.5rem;">
                        Leave Room
                    </button>
                </div>
            </div>

            <div class="sidebar">
                <div class="entry-card">
                    <h3 style="color: var(--sab-primary); margin-bottom: 1rem;">How It Works</h3>
                    <div style="font-size: 0.85rem; line-height: 1.6; color: #b8c2eb;">
                        <p><strong>Role:</strong> You're either a Fixer or a Saboteur</p>
                        <p><strong>Fixers:</strong> Complete the code challenges</p>
                        <p><strong>Saboteur:</strong> Break the code subtly</p>
                        <p><strong>Vote:</strong> Fixers vote to eliminate the saboteur</p>
                        <p><strong>Win:</strong> Fixers win if saboteur is eliminated, saboteur wins if code fails</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const userId = <?php echo $user_id; ?>;
        let currentRoom = <?php echo !empty($activeRoom) ? "'" . htmlspecialchars($activeRoom['room_code']) . "'" : 'null'; ?>;
        let isHost = false;
        let pollInterval = null;

        document.getElementById('createRoomBtn').addEventListener('click', createRoom);
        document.getElementById('joinRoomBtn').addEventListener('click', joinRoom);
        document.getElementById('readyBtn').addEventListener('click', toggleReady);
        document.getElementById('leaveRoomBtn').addEventListener('click', leaveRoom);
        document.getElementById('startGameBtn').addEventListener('click', startGame);
        document.getElementById('categorySelect').addEventListener('change', (e) => {
            localStorage.setItem('selectedCategory', e.target.value);
        });

        async function createRoom() {
            try {
                const response = await fetch('saboteur_create.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        category: localStorage.getItem('selectedCategory') || 'Object Oriented'
                    })
                });
                const data = await response.json();
                if (data.success) {
                    currentRoom = data.room_code;
                    isHost = true;
                    showWaitingRoom();
                    startPolling();
                } else {
                    showToast('Error: ' + data.message, 'error');
                }
            } catch (err) {
                showToast('Failed to create room', 'error');
            }
        }

        async function joinRoom() {
            const code = document.getElementById('roomCodeInput').value.trim().toUpperCase();
            if (!code) {
                showToast('Please enter a room code', 'warning');
                return;
            }

            try {
                const response = await fetch('saboteur_join.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ room_code: code })
                });
                const data = await response.json();
                if (data.success) {
                    if (data.redirect_url) {
                        window.location.href = data.redirect_url;
                        return;
                    }
                    currentRoom = code;
                    isHost = false;
                    showWaitingRoom();
                    startPolling();
                } else {
                    if (data.redirect_url) {
                        window.location.href = data.redirect_url;
                        return;
                    }
                    showToast('Error: ' + data.message, 'error');
                }
            } catch (err) {
                showToast('Failed to join room', 'error');
            }
        }

        async function toggleReady() {
            if (!currentRoom) return;

            try {
                const response = await fetch('saboteur_ready.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        room_code: currentRoom,
                        is_ready: true
                    })
                });
                const data = await response.json();
                if (data.success) {
                    showToast('✓ You\'re ready to play!', 'success');
                    document.getElementById('readyBtn').classList.remove('visible');
                    document.getElementById('readyBtn').classList.add('hidden');
                    // Immediate poll to sync with server
                    setTimeout(pollRoom, 300);
                } else {
                    showToast('Error: ' + data.message, 'error');
                }
            } catch (err) {
                showToast('Failed to mark ready', 'error');
                console.error(err);
            }
        }

        async function startGame() {
            if (!currentRoom) {
                showToast('No room selected', 'error');
                return;
            }
            
            if (!isHost) {
                showToast('Only the host can start the game', 'error');
                return;
            }

            try {
                const response = await fetch('saboteur_start.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ room_code: currentRoom })
                });
                
                let data;
                try {
                    data = await response.json();
                } catch (e) {
                    console.error('Failed to parse response:', response.text());
                    showToast('Server error - check console', 'error');
                    return;
                }

                if (data.success) {
                    showToast('Starting game...', 'success');
                    setTimeout(() => {
                        window.location.href = 'saboteur_game.php?room=' + encodeURIComponent(currentRoom);
                    }, 500);
                } else {
                    showToast('Error: ' + (data.message || 'Unknown error'), 'error');
                    console.error('Start game error:', data);
                }
            } catch (err) {
                showToast('Failed to start game', 'error');
                console.error('Start game exception:', err);
            }
        }

        async function leaveRoom() {
            if (!currentRoom) return;

            try {
                await fetch('saboteur_leave.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ room_code: currentRoom })
                });
            } catch (err) {
                console.error('Leave room error:', err);
            } finally {
                currentRoom = null;
                isHost = false;
                showEntryCard();
                if (pollInterval) clearInterval(pollInterval);
                pollInterval = null;
            }
        }

        async function pollRoom() {
            if (!currentRoom) return;

            try {
                const response = await fetch('saboteur_poll.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ room_code: currentRoom })
                });
                const data = await response.json();
                if (data.success) {
                    // Update isHost from poll response (handles page refresh)
                    isHost = data.current_player.is_host;
                    
                    updatePlayerList(data.players, data.current_player);
                    document.getElementById('playerCount').textContent = data.players.length;

                    // Show/hide buttons based on role and ready status
                    const startBtnArea = document.getElementById('startBtnArea');
                    const categoriesSection = document.getElementById('categoriesSection');
                    const readyBtn = document.getElementById('readyBtn');

                    if (isHost) {
                        // Host: show start game button and category selector
                        startBtnArea.classList.add('visible');
                        startBtnArea.style.display = 'block';
                        categoriesSection.classList.add('visible');
                        categoriesSection.style.display = 'block';
                        readyBtn.classList.add('hidden');
                        readyBtn.classList.remove('visible');
                        readyBtn.style.display = 'none';
                    } else {
                        // Non-host: show ready button only if not ready yet
                        startBtnArea.classList.remove('visible');
                        startBtnArea.style.display = 'none';
                        categoriesSection.classList.remove('visible');
                        categoriesSection.style.display = 'none';

                        const playerIsReady = data.current_player.is_ready;
                        if (playerIsReady) {
                            readyBtn.classList.add('hidden');
                            readyBtn.classList.remove('visible');
                            readyBtn.style.display = 'none';
                        } else {
                            readyBtn.classList.add('visible');
                            readyBtn.classList.remove('hidden');
                            readyBtn.style.display = 'block';
                        }
                    }

                    // If room status changes from lobby, redirect to game
                    if (data.room.status !== 'lobby') {
                        window.location.href = 'saboteur_game.php?room=' + encodeURIComponent(currentRoom);
                    }
                }
            } catch (err) {
                console.error('Poll error:', err);
            }
        }

        function updatePlayerList(players, currentPlayer) {
            const list = document.getElementById('playerList');
            list.innerHTML = players.map((p) => `
                <li class="player-item">
                    <div class="player-color ${p.color}"></div>
                    <span class="player-name">${htmlEscape(p.username)} ${p.is_you ? '(You)' : ''}</span>
                    ${p.is_host ? '<span class="host-badge">HOST</span>' : ''}
                    ${p.is_ready ? '<span class="ready-badge">READY</span>' : ''}
                </li>
            `).join('');
        }

        function showWaitingRoom() {
            document.getElementById('entryCard').style.display = 'none';
            document.getElementById('waitingRoom').style.display = 'block';
            document.getElementById('displayRoomCode').textContent = currentRoom;
        }

        function showEntryCard() {
            document.getElementById('entryCard').style.display = 'block';
            document.getElementById('waitingRoom').style.display = 'none';
            document.getElementById('categorySelect').value = localStorage.getItem('selectedCategory') || '';
        }

        function startPolling() {
            if (pollInterval) clearInterval(pollInterval);
            pollRoom();
            pollInterval = setInterval(pollRoom, 1000);
        }

        function htmlEscape(str) {
            return str.replace(/[&<>"']/g, (ch) => {
                const escapeMap = {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;'
                };
                return escapeMap[ch];
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
                background: ${type === 'error' ? '#ff6b6b' : type === 'warning' ? '#ffa94d' : '#51cf66'};
                color: white;
                border-radius: 8px;
                z-index: 10000;
                animation: slideIn 0.3s ease;
            `;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        }

        if (currentRoom) {
            showWaitingRoom();
            startPolling();
        }
    </script>
</body>
</html>
