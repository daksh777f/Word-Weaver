<?php
require_once '../onboarding/config.php';
requireLogin();

// Redirect to appropriate page if ALREADY in active game (but allow creating/joining new if finished)
$user_id = (int)$_SESSION['user_id'];
$stmt = $pdo->prepare("
    SELECT sr.room_code, sr.status FROM saboteur_rooms sr
    INNER JOIN saboteur_players sp ON sr.id = sp.room_id
    WHERE sp.user_id = ? AND sr.status IN ('lobby', 'role_reveal', 'voting', 'playing')
    LIMIT 1
");
$stmt->execute([$user_id]);
$activeRoom = $stmt->fetch();

if ($activeRoom) {
    $room_code = urlencode($activeRoom['room_code']);
    $status = $activeRoom['status'];
    
    if ($status === 'lobby') {
        header('Location: lobby.php?room=' . $room_code);
        exit;
    } elseif ($status === 'role_reveal') {
        header('Location: role_reveal.php?room=' . $room_code);
        exit;
    } elseif (in_array($status, ['voting', 'playing'])) {
        header('Location: game.php?room=' . $room_code);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Code Mafia</title>
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
            max-width: 400px;
            margin-bottom: 60px;
        }

        .title {
            line-height: 1.2;
            margin-bottom: 0.5rem;
        }

        .title-top {
            color: var(--btn-primary);
            font-size: 2.5rem;
            letter-spacing: 3px;
        }

        .title-bottom {
            color: var(--accent-pink);
            font-size: 2.5rem;
            letter-spacing: 3px;
            margin-top: -0.3rem;
        }

        .subtitle {
            font-size: 3rem;
            color: white;
            margin-bottom: 2rem;
            letter-spacing: 1px;
        }

        .btn-create {
            width: 100%;
            min-height: 2.5rem;
            margin-bottom: 1.5rem;
            font-size: 2.5rem;
        }

        .join-card {
            background: var(--card-bg);
            border: 3px solid var(--card-border);
            padding: 1rem;
            margin-bottom: 1.5rem;
            box-shadow: inset -2px -2px 0 rgba(0, 0, 0, 0.15);
        }

        .join-label {
            font-size: 2rem;
            color: var(--text-muted);
            margin-bottom: 0.5rem;
            text-align: left;
        }

        .join-form {
            display: flex;
            gap: 0.5rem;
        }

        .join-form input {
            flex: 1;
            font-size: 2.5rem;
            padding: 0.75rem;
            background: var(--card-bg);
            border: 3px solid var(--card-border);
            text-transform: uppercase;
        }

        .btn-join {
            background: var(--btn-action);
            min-width: 3rem;
            border-color: #2E7D32;
            font-size: 2rem;
        }

        .footer-text {
            font-size: 2rem;
            color: white;
            letter-spacing: 1px;
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

    <!-- Main Content -->
    <div class="content">
        <div class="title">
            <div class="title-top">CODE</div>
            <div class="title-bottom">MAFIA</div>
        </div>

        <p class="subtitle">Sabotage or Survive</p>

        <!-- Create Button -->
        <button id="createBtn" class="btn-create btn-primary">
            CREATE GAME
        </button>

        <!-- Join Card -->
        <div class="join-card">
            <div class="join-label">Or join a game...</div>
            <div class="join-form">
                <input 
                    id="roomCodeInput"
                    type="text" 
                    placeholder="LOBBY ID" 
                    maxlength="6"
                    required
                >
                <button id="joinBtn" class="btn-join">JOIN</button>
            </div>
        </div>

        <!-- Footer Text -->
        <p class="footer-text">3-5 Players • Find the Impostor</p>
    </div>

    <!-- Ground Strip -->
    <div class="ground"></div>

    <script>
        // CREATE GAME
        document.getElementById('createBtn').addEventListener('click', async () => {
            try {
                const response = await fetch('saboteur_create.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ category: 'all' })
                });
                const data = await response.json();
                
                if (data.success) {
                    window.location.href = 'lobby.php?room=' + encodeURIComponent(data.room_code);
                } else {
                    alert('Error: ' + (data.message || 'Failed to create game'));
                }
            } catch (err) {
                console.error('Create error:', err);
                alert('Error creating game');
            }
        });

        // JOIN GAME
        document.getElementById('joinBtn').addEventListener('click', async () => {
            const code = document.getElementById('roomCodeInput').value.trim().toUpperCase();
            if (!code) {
                alert('Please enter a room code');
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
                    // Use the redirect URL from the API response, or fallback to saboteur_lobby.php
                    const redirectUrl = data.redirect_url || ('saboteur_lobby.php?room=' + encodeURIComponent(code));
                    window.location.href = redirectUrl;
                } else {
                    alert('Error: ' + (data.message || 'Failed to join game'));
                }
            } catch (err) {
                console.error('Join error:', err);
                alert('Error joining game');
            }
        });

        // Allow Enter key in input
        document.getElementById('roomCodeInput').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                document.getElementById('joinBtn').click();
            }
        });
    </script>
</body>
</html>
