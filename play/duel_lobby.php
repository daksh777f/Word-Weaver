<?php
// ════════════════════════════════════════
// FILE: duel_lobby.php
// PURPOSE: Duel matchmaking lobby and waiting room
// NEW TABLES USED: duel_rooms, duel_players, duel_history, users
// CEREBRAS CALLS: no
// ════════════════════════════════════════

require_once '../onboarding/config.php';
require_once '../includes/greeting.php';

requireLogin();

$user_id = (int)$_SESSION['user_id'];

// Fetch user info
$stmt = $pdo->prepare("SELECT username, email, profile_image, duel_wins, duel_losses, duel_win_streak, skill_level FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header('Location: ../onboarding/login.php');
    exit();
}

$languageStmt = $pdo->query("SELECT DISTINCT LOWER(language) AS language FROM bug_challenges WHERE challenge_type = 'bug_fix' AND language IS NOT NULL AND language <> '' ORDER BY language ASC");
$availableLanguages = array_values(array_filter(array_map(static function ($row) {
  return strtolower(trim((string)($row['language'] ?? '')));
}, $languageStmt->fetchAll(PDO::FETCH_ASSOC))));

$selectedLanguage = strtolower(trim((string)($_GET['language'] ?? '')));
if ($selectedLanguage !== '' && !in_array($selectedLanguage, $availableLanguages, true)) {
  $selectedLanguage = '';
}

// Check if user already has active duel
$stmt = $pdo->prepare("
    SELECT dr.* FROM duel_rooms dr
    JOIN duel_players dp ON dp.room_id = dr.id
    WHERE dp.user_id = ?
    AND dr.status IN ('waiting','countdown','active')
    LIMIT 1
");
$stmt->execute([$user_id]);
$activeRoom = $stmt->fetch(PDO::FETCH_ASSOC);

// If active, redirect
if ($activeRoom && in_array($activeRoom['status'], ['countdown', 'active'])) {
    header('Location: duel.php?room=' . urlencode($activeRoom['room_code']));
    exit();
}

// Fetch recent duel history
$stmt = $pdo->prepare("
    SELECT dh.*,
        CASE WHEN dh.winner_id = ? THEN 'won' ELSE 'lost' END AS my_result,
        CASE WHEN dh.winner_id = ? 
            THEN u_loser.username 
            ELSE u_winner.username 
        END AS opponent_name
    FROM duel_history dh
    LEFT JOIN users u_winner ON u_winner.id = dh.winner_id
    LEFT JOIN users u_loser ON u_loser.id = dh.loser_id
    WHERE dh.winner_id = ? OR dh.loser_id = ?
    ORDER BY dh.played_at DESC
    LIMIT 5
");
$stmt->execute([$user_id, $user_id, $user_id, $user_id]);
$recentDuels = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Format duel stats
$duelWins = (int)$user['duel_wins'];
$duelLosses = (int)$user['duel_losses'];
$duelWinStreak = (int)$user['duel_win_streak'];
$duelTotal = $duelWins + $duelLosses;
$skillLevel = (int)$user['skill_level'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include '../includes/favicon.php'; ?>
    <title>Bug Duel Arena</title>
    <link rel="stylesheet" href="../styles.css?v=<?php echo filemtime('../styles.css'); ?>">
    <link rel="stylesheet" href="game-selection.css?v=<?php echo filemtime('game-selection.css'); ?>">
    <link rel="stylesheet" href="../MainGame/grammarheroes/game.css?v=<?php echo filemtime('../MainGame/grammarheroes/game.css'); ?>">
    <link rel="stylesheet" href="../notif/toast.css?v=<?php echo filemtime('../notif/toast.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .duel-lobby-grid {
            display: grid;
            grid-template-columns: 1fr 360px;
            gap: 2rem;
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1.5rem;
        }

        @media (max-width: 768px) {
            .duel-lobby-grid {
                grid-template-columns: 1fr;
                gap: 1.5rem;
                margin: 1rem auto;
                padding: 0 1rem;
            }
        }

        .duel-work-card {
            transition: all 0.3s ease;
        }

        .duel-work-card:hover {
            border-color: rgba(88, 225, 255, 0.35);
        }

        #find-opponent-btn {
            width: 100%;
            transition: all 0.25s ease;
        }

        #find-opponent-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 255, 135, 0.4);
        }

        #cancel-search-btn {
            width: 100%;
            padding: 0.8rem;
            background: transparent;
            border: 1px solid var(--g-border);
            color: var(--g-muted);
            border-radius: 8px;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.25s ease;
        }

        #cancel-search-btn:hover {
            border-color: var(--g-danger);
            color: var(--g-danger);
            background: rgba(255, 108, 136, 0.1);
        }

        @media (max-width: 768px) {
            .duel-how-it-works {
                grid-template-columns: 1fr !important;
            }
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
                <h1>⚔️ Bug Duel Arena</h1>
                <p>Challenge a developer to a real-time bug fixing race.</p>
            </div>
            <div class="profile-pill">
                <img src="<?php echo !empty($user['profile_image']) ? '../' . htmlspecialchars($user['profile_image']) : '../assets/menu/defaultuser.png'; ?>" alt="Profile">
                <span><?php echo htmlspecialchars(explode(' ', $user['username'])[0]); ?></span>
            </div>
        </header>

        <div class="duel-lobby-grid">
            <!-- LEFT: Stats, How It Works, History -->
            <div>
                <!-- Duel Stats using HUD -->
                <div class="hud" style="margin-bottom: 2rem;">
                    <div class="hud-item">
                        <span>Wins</span>
                        <strong><?php echo $duelWins; ?></strong>
                    </div>
                    <div class="hud-item">
                        <span>Losses</span>
                        <strong><?php echo $duelLosses; ?></strong>
                    </div>
                    <div class="hud-item">
                        <span>Streak</span>
                        <strong><?php echo $duelWinStreak; ?></strong>
                    </div>
                    <div class="hud-item">
                        <span>Skill</span>
                        <strong><?php echo $skillLevel; ?></strong>
                    </div>
                    <div class="hud-item">
                        <span>Total</span>
                        <strong><?php echo $duelTotal; ?></strong>
                    </div>
                    <div class="hud-item">
                        <span>Ratio</span>
                        <strong><?php echo $duelTotal > 0 ? round(($duelWins / $duelTotal) * 100) : 0; ?>%</strong>
                    </div>
                </div>

                <!-- How It Works -->
                <div style="margin-bottom: 2rem;">
                    <div style="font-size: 0.75rem; font-weight: 700; color: var(--g-muted); text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 1rem;">How Duels Work</div>
                    <div class="duel-how-it-works hud" style="grid-template-columns: repeat(3, 1fr); gap: 1rem;">
                        <div class="duel-work-card hud-item" style="text-align: center; padding: 1.2rem;">
                            <div style="font-size: 1.5rem; margin-bottom: 0.5rem;">🎯</div>
                            <div style="font-size: 0.8rem; font-weight: 600; color: var(--g-primary); margin-bottom: 0.5rem;">Find</div>
                            <div style="font-size: 0.7rem; line-height: 1.4; color: var(--g-muted);">Queue with players your skill level</div>
                        </div>
                        <div class="duel-work-card hud-item" style="text-align: center; padding: 1.2rem;">
                            <div style="font-size: 1.5rem; margin-bottom: 0.5rem;">⚡</div>
                            <div style="font-size: 0.8rem; font-weight: 600; color: var(--g-primary); margin-bottom: 0.5rem;">Race</div>
                            <div style="font-size: 0.7rem; line-height: 1.4; color: var(--g-muted);">Same code. No hints. First wins.</div>
                        </div>
                        <div class="duel-work-card hud-item" style="text-align: center; padding: 1.2rem;">
                            <div style="font-size: 1.5rem; margin-bottom: 0.5rem;">🏆</div>
                            <div style="font-size: 0.8rem; font-weight: 600; color: var(--g-primary); margin-bottom: 0.5rem;">Win</div>
                            <div style="font-size: 0.7rem; line-height: 1.4; color: var(--g-muted);">XP + rank up. Loser gets tips.</div>
                        </div>
                    </div>
                </div>

                <!-- Recent Duels -->
                <?php if (!empty($recentDuels)): ?>
                <div>
                    <div style="font-size: 0.75rem; font-weight: 700; color: var(--g-muted); text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 0.8rem;">Recent Duels</div>
                    <div style="display: flex; flex-direction: column; gap: 0.6rem;">
                        <?php foreach ($recentDuels as $duel): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.7rem; border: 1px solid var(--g-border); border-radius: 8px; background: rgba(255,255,255,0.03); font-size: 0.8rem;">
                            <div style="flex: 1; color: var(--g-text); font-weight: 600;">vs <?php echo htmlspecialchars($duel['opponent_name'] ?? 'Unknown'); ?></div>
                            <div style="padding: 0.2rem 0.6rem; border-radius: 4px; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; background: <?php echo $duel['my_result'] === 'won' ? 'rgba(103,229,159,0.2)' : 'rgba(255,108,136,0.2)'; ?>; color: <?php echo $duel['my_result'] === 'won' ? 'var(--g-success)' : 'var(--g-danger)'; ?>;">
                                <?php echo $duel['my_result'] === 'won' ? 'W' : 'L'; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <div style="color: var(--g-muted); font-size: 0.85rem; text-align: center; padding: 1.2rem; font-style: italic;">No duels yet. Get your first W! 🚀</div>
                <?php endif; ?>
            </div>

            <!-- RIGHT: Find Opponent Card -->
            <div>
                <div class="hud-item" style="background: var(--g-card); border: 1px solid var(--g-border); border-radius: 12px; padding: 1.8rem; text-align: center;">
                    <div id="lobby-state">
                        <div style="font-size: 1.2rem; font-weight: 700; margin-bottom: 0.5rem; color: var(--g-text);">Challenge!</div>
                        <div style="font-size: 0.85rem; color: var(--g-muted); margin-bottom: 1.5rem; line-height: 1.5;">Race another dev to fix the same bug.</div>
                        <form method="GET" action="duel_lobby.php" style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.9rem;">
                          <label for="duelLanguage" style="font-size:0.75rem;color:var(--g-muted);font-weight:700;">Language</label>
                          <select id="duelLanguage" name="language" onchange="this.form.submit()" style="flex:1;background:#080c1e;color:var(--g-text);border:1px solid var(--g-border);border-radius:8px;padding:0.4rem 0.5rem;font-size:0.78rem;">
                            <option value="" <?php echo $selectedLanguage === '' ? 'selected' : ''; ?>>All languages</option>
                            <?php foreach ($availableLanguages as $lang): ?>
                              <option value="<?php echo htmlspecialchars($lang); ?>" <?php echo $selectedLanguage === $lang ? 'selected' : ''; ?>><?php echo htmlspecialchars(strtoupper($lang)); ?></option>
                            <?php endforeach; ?>
                          </select>
                        </form>
                        <div style="display: flex; flex-direction: column; gap: 0.8rem;">
                            <button id="find-opponent-btn" class="play-again">
                                <i class="fas fa-sword"></i> Find Opponent
                            </button>
                            <button id="play-bot-btn" style="background: transparent; border: 1px solid var(--g-primary); color: var(--g-primary); padding: 0.8rem; border-radius: 8px; font-weight: 700; cursor: pointer; transition: all 0.25s ease; font-size: 0.9rem;">
                                <i class="fas fa-robot"></i> Play vs Bot
                            </button>
                        </div>
                    </div>

                    <div id="waiting-state" style="display: none;">
                        <div style="font-size: 1.1rem; font-weight: 700; margin-bottom: 0.8rem; color: var(--g-primary);">🔍 Searching...</div>
                        <div style="font-size: 0.85rem; color: var(--g-muted); animation: pulse 2s infinite; margin-bottom: 1rem;">Waiting for a challenger</div>
                        <div style="background: #080c1e; border: 1px solid var(--g-border); border-radius: 8px; padding: 0.8rem; margin: 1rem 0; font-family: monospace; font-size: 0.9rem; font-weight: 700; color: var(--g-warning); word-break: break-all; letter-spacing: 0.05em;" id="room-code-display"></div>
                        <div style="font-size: 0.8rem; color: var(--g-muted); margin-bottom: 1rem; font-weight: 600;">Elapsed: <span id="wait-elapsed">0s</span></div>
                        <button id="cancel-search-btn">Cancel</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
(function() {

const lobbyState = document.getElementById('lobby-state');
const waitingState = document.getElementById('waiting-state');
const findBtn = document.getElementById('find-opponent-btn');
const botBtn = document.getElementById('play-bot-btn');
const cancelBtn = document.getElementById('cancel-search-btn');
const waitElapsed = document.getElementById('wait-elapsed');
const roomCodeDisplay = document.getElementById('room-code-display');
const selectedLanguage = <?php echo json_encode((string)$selectedLanguage); ?>;

// ── FIND OPPONENT ──────────────────────────────────────

if (findBtn) {
  findBtn.addEventListener('click', async function() {
    findBtn.disabled = true;
    findBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Searching...';
    
    try {
      const response = await fetch('duel_matchmaker.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ language: selectedLanguage || null })
      });
      
      const data = await response.json();
      
      if (data.success) {
        if (data.matched) {
          // Both players ready — redirect
          window.location.href = 'duel.php?room=' + encodeURIComponent(data.room_code);
        } else {
          // Waiting room created — show waiting state
          roomCodeDisplay.textContent = data.room_code;
          
          lobbyState.style.display = 'none';
          waitingState.style.display = 'block';
          
          // Start elapsed timer
          let elapsed = 0;
          const timerInterval = setInterval(() => {
            elapsed++;
            const m = Math.floor(elapsed / 60);
            const s = elapsed % 60;
            waitElapsed.textContent = (m > 0 ? m + 'm ' : '') + s + 's';
          }, 1000);
          
          // Poll for match
          const pollInterval = setInterval(async () => {
            try {
              const pollResp = await fetch('duel_poll.php?room=' + data.room_code + '&phase=lobby');
              const pollData = await pollResp.json();
              
              if (pollData.status === 'countdown' || pollData.status === 'active') {
                clearInterval(timerInterval);
                clearInterval(pollInterval);
                window.location.href = 'duel.php?room=' + encodeURIComponent(data.room_code);
              }
            } catch (e) {
              console.error('Poll error:', e);
            }
          }, 2000);
          
          // Auto-cancel after 5 minutes
          setTimeout(() => {
            clearInterval(timerInterval);
            clearInterval(pollInterval);
            fetch('duel_cancel.php', { method: 'POST' });
            window.location.reload();
          }, 300000);
        }
      } else {
        findBtn.disabled = false;
        findBtn.innerHTML = '<i class="fas fa-sword"></i> Find Opponent';
        alert(data.message || 'Could not find opponent');
      }
    } catch (err) {
      findBtn.disabled = false;
      findBtn.innerHTML = '<i class="fas fa-sword"></i> Find Opponent';
      console.error('Find error:', err);
    }
  });
}

// ── PLAY VS BOT ────────────────────────────────────────

if (botBtn) {
  botBtn.addEventListener('click', async function() {
    botBtn.disabled = true;
    botBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Starting...';
    
    try {
      const response = await fetch('duel_bot_matchmaker.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ language: selectedLanguage || null })
      });
      
      const data = await response.json();
      
      if (data.success) {
        // Bot room created — immediately go to duel
        window.location.href = 'duel.php?room=' + encodeURIComponent(data.room_code);
      } else {
        botBtn.disabled = false;
        botBtn.innerHTML = '<i class="fas fa-robot"></i> Play vs Bot';
        alert(data.message || 'Could not start bot duel');
      }
    } catch (err) {
      botBtn.disabled = false;
      botBtn.innerHTML = '<i class="fas fa-robot"></i> Play vs Bot';
      console.error('Bot error:', err);
    }
  });
}

// ── CANCEL SEARCH ──────────────────────────────────────

if (cancelBtn) {
  cancelBtn.addEventListener('click', async function() {
    cancelBtn.disabled = true;
    try {
      await fetch('duel_cancel.php', { method: 'POST' });
      window.location.reload();
    } catch (err) {
      cancelBtn.disabled = false;
    }
  });
}

})();
    </script>
</body>
</html>
