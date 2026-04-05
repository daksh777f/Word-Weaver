<?php
// ════════════════════════════════════════
// FILE: duel.php
// PURPOSE: Main duel gameplay page
// NEW TABLES USED: duel_rooms, duel_players, bug_challenges
// CEREBRAS CALLS: no
// REALTIME: polling
// ════════════════════════════════════════

require_once '../onboarding/config.php';
require_once '../includes/greeting.php';

requireLogin();

$user_id = (int)$_SESSION['user_id'];
$room_code = trim($_GET['room'] ?? '');

if (empty($room_code)) {
    header('Location: duel_lobby.php');
    exit();
}

// Fetch room and verify player belongs
$stmt = $pdo->prepare("
    SELECT dr.*,
        u1.username AS p1_username,
        u2.username AS p2_username
    FROM duel_rooms dr
    LEFT JOIN users u1 ON u1.id = dr.player1_id
    LEFT JOIN users u2 ON u2.id = dr.player2_id
    WHERE dr.room_code = ?
    LIMIT 1
");
$stmt->execute([$room_code]);
$room = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$room || !in_array($user_id, [(int)$room['player1_id'], (int)$room['player2_id']])) {
    header('Location: duel_lobby.php');
    exit();
}

// Fetch challenge
$stmt = $pdo->prepare("SELECT * FROM bug_challenges WHERE id = ?");
$stmt->execute([(int)$room['challenge_id']]);
$challenge = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$challenge) {
    header('Location: duel_lobby.php');
    exit();
}

// Determine opponent
$opponentId = (int)$room['player1_id'] === $user_id ? (int)$room['player2_id'] : (int)$room['player1_id'];
$isVsBot = $opponentId === -1;
$opponentUsername = $isVsBot ? '🤖 Code Bot' : ((int)$room['player1_id'] === $user_id ? ($room['p2_username'] ?? 'Opponent') : ($room['p1_username'] ?? 'Opponent'));

$tags = array_filter(array_map('trim', explode(',', (string)$challenge['concept_tags'])));
$difficulty = strtolower((string)$challenge['difficulty']);
$xpByDifficulty = ['beginner' => 80, 'intermediate' => 100, 'advanced' => 120];
$xpAvailable = $xpByDifficulty[$difficulty] ?? 100;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include '../includes/favicon.php'; ?>
    <title>Bug Duel</title>
    <link rel="stylesheet" href="../styles.css?v=<?php echo filemtime('../styles.css'); ?>">
    <link rel="stylesheet" href="../MainGame/grammarheroes/game.css?v=<?php echo filemtime('../MainGame/grammarheroes/game.css'); ?>">
    <link rel="stylesheet" href="../notif/toast.css?v=<?php echo filemtime('../notif/toast.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .duel-player-card {
            display: flex;
            flex-direction: column;
        }

        .duel-player-name {
            font-weight: 700;
            font-size: 0.95rem;
            margin-bottom: 0.4rem;
            letter-spacing: 0.02em;
        }

        .duel-player-status {
            font-size: 0.75rem;
            color: var(--g-muted);
            display: flex;
            align-items: center;
            gap: 0.3rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-weight: 600;
        }

        .duel-player-status.editing {
            color: var(--g-primary);
        }

        .duel-player-status.submitted {
            color: var(--g-success);
        }

        .duel-vs-badge {
            text-align: center;
            font-weight: 700;
            color: var(--g-muted);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .duel-progress-bar {
            width: 100%;
            height: 4px;
            background: rgba(88, 225, 255, 0.1);
            border-radius: 999px;
            overflow: hidden;
            border: 1px solid rgba(88, 225, 255, 0.15);
            margin-top: 0.5rem;
        }

        .duel-progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--g-primary), var(--g-success));
            width: 0%;
            transition: width 0.3s ease;
        }

        .countdown-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.95);
            display: none;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            color: white;
            text-align: center;
            backdrop-filter: blur(4px);
        }

        .countdown-overlay.active {
            display: flex;
        }

        .countdown-number {
            font-size: 8rem;
            font-weight: 900;
            color: var(--g-warning);
            font-family: 'Courier New', monospace;
            text-shadow: 0 0 30px rgba(255, 204, 112, 0.5);
            margin-bottom: 2rem;
            font-variant-numeric: tabular-nums;
            animation: slideDown 0.5s ease;
        }

        .countdown-text {
            font-size: 1.3rem;
            color: var(--g-text);
            font-weight: 600;
            letter-spacing: 0.02em;
        }

        .duel-result-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.9);
            display: none;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 9998;
            backdrop-filter: blur(4px);
        }

        .duel-result-modal.active {
            display: flex;
        }

        .result-card {
            width: min(620px, 92vw);
            background: #0d1432;
            border: 1px solid var(--g-border);
            border-radius: 15px;
            padding: 1.5rem;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.6);
        }

        .duel-score-banner {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--g-border);
        }

        .duel-score-item {
            text-align: center;
        }

        .duel-score-label {
            font-size: 0.7rem;
            color: var(--g-muted);
            text-transform: uppercase;
            margin-bottom: 0.4rem;
            letter-spacing: 0.08em;
            font-weight: 700;
        }

        .duel-score-value {
            font-size: 2rem;
            font-weight: 800;
            color: var(--g-primary);
            font-variant-numeric: tabular-nums;
        }

        .result-header {
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 1rem;
            text-align: center;
            letter-spacing: 0.02em;
        }

        .result-header.won {
            color: var(--g-success);
        }

        .result-header.lost {
            color: var(--g-danger);
        }

        .result-section-header {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--g-muted);
            margin-top: 1rem;
            margin-bottom: 0.6rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .result-section-content {
            font-size: 0.9rem;
            line-height: 1.6;
            color: var(--g-text);
            margin-bottom: 1rem;
        }

        .duel-comment {
            background: rgba(103, 229, 159, 0.12);
            border-left: 3px solid var(--g-success);
            padding: 0.8rem;
            margin-top: 1rem;
            border-radius: 6px;
            font-size: 0.9rem;
            line-height: 1.5;
            color: var(--g-text);
        }

        .challenge-section {
            background: var(--g-card);
            border: 1px solid var(--g-border);
            border-radius: 12px;
            padding: 1.2rem;
            margin-bottom: 1rem;
        }

        .challenge-section h3 {
            margin: 0 0 0.6rem;
            font-size: 1rem;
            font-weight: 700;
            color: var(--g-text);
            letter-spacing: 0.02em;
        }

        .challenge-section p {
            margin: 0.4rem 0;
            font-size: 0.9rem;
            line-height: 1.6;
            color: var(--g-text);
        }

        .code-wrapper {
            display: grid;
            grid-template-columns: 40px 1fr;
            border: 1px solid var(--g-border);
            border-radius: 10px;
            overflow: hidden;
            background: #060b1a;
            min-height: 300px;
        }

        .line-numbers {
            background: #060b1a;
            color: rgba(255, 255, 255, 0.3);
            border-right: 1px solid var(--g-border);
            text-align: right;
            padding: 12px 8px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.6;
            user-select: none;
            white-space: pre;
        }

        #duel-code-editor {
            width: 100%;
            min-height: 300px;
            border: 0;
            outline: none;
            resize: vertical;
            background: #060b1a;
            color: var(--g-text);
            font-family: 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.6;
            padding: 12px;
            tab-size: 2;
        }

        .action-button {
            background: linear-gradient(135deg, rgba(103, 229, 159, 0.2) 0%, rgba(103, 229, 159, 0.05) 100%);
            border: 1px solid var(--g-success);
            color: var(--g-success);
            padding: 0.8rem 1.6rem;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.25s ease;
            font-size: 0.9rem;
        }

        .action-button:hover:not(:disabled) {
            background: linear-gradient(135deg, rgba(103, 229, 159, 0.35) 0%, rgba(103, 229, 159, 0.15) 100%);
            box-shadow: 0 0 16px rgba(103, 229, 159, 0.25);
        }

        .action-button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        @media (max-width: 768px) {
            .result-card {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <?php include '../includes/page-loader.php'; ?>

    <div class="game-shell">
        <!-- GAME HEADER -->
        <header class="game-header">
            <div class="duel-vs-badge" style="grid-column: 1;">⚔️ Bug Duel</div>
            <div class="hud-item" style="grid-column: 2; text-align: center; background: transparent; border: none; padding: 0;">
                <span style="font-size: 0.8rem;">Time</span>
                <strong style="display: block; font-size: 1.6rem; color: var(--g-warning); margin-top: 0.2rem;" id="duel-timer">3:00</strong>
            </div>
            <div style="grid-column: 3; text-align: right; color: var(--g-muted); font-size: 0.85rem;">
                <small><?php echo htmlspecialchars($challenge['title']); ?></small>
            </div>
        </header>

        <!-- PLAYER VS INDICATOR -->
        <div style="display: grid; grid-template-columns: 1fr auto 1fr; gap: 1rem; align-items: center; padding: 1rem; background: var(--g-card); border: 1px solid var(--g-border); margin: 1rem; border-radius: 12px;">
            <div class="duel-player-card">
                <div class="duel-player-name" style="color: var(--g-primary);">You</div>
                <div class="duel-player-status" id="my-status">Waiting...</div>
                <div class="duel-progress-bar">
                    <div class="duel-progress-fill" id="my-progress-fill"></div>
                </div>
            </div>
            <div class="duel-vs-badge">VS</div>
            <div class="duel-player-card">
                <div class="duel-player-name" style="color: var(--g-danger);"><?php echo htmlspecialchars($opponentUsername); ?></div>
                <div class="duel-player-status" id="opponent-status">Waiting...</div>
                <div class="duel-progress-bar">
                    <div class="duel-progress-fill" id="opponent-progress-fill"></div>
                </div>
            </div>
        </div>

        <!-- COUNTDOWN OVERLAY -->
        <div class="countdown-overlay" id="countdown-overlay">
            <div class="countdown-number" id="countdown-number">3</div>
            <div class="countdown-text">vs <?php echo htmlspecialchars($opponentUsername); ?></div>
        </div>

        <!-- MAIN CONTENT -->
        <div style="max-width: 1000px; margin: 1rem auto; padding: 0 1rem;">
            <!-- Challenge Card -->
            <div class="challenge-section">
                <h3><?php echo htmlspecialchars($challenge['title']); ?></h3>
                <p><?php echo htmlspecialchars($challenge['backstory']); ?></p>
                <p style="color: var(--g-warning); font-weight: 600; margin-top: 0.8rem;">⚠️ Real-world consequence: <?php echo htmlspecialchars($challenge['real_world_consequence']); ?></p>
            </div>

            <!-- Code Editor Section -->
            <div class="challenge-section">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.8rem;">
                    <span style="font-size: 0.9rem; font-weight: 600; color: var(--g-text);">Fix the broken code:</span>
                    <span style="background: rgba(103, 229, 159, 0.15); color: var(--g-success); border: 1px solid rgba(103, 229, 159, 0.3); padding: 0.2rem 0.6rem; border-radius: 999px; font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700;"><?php echo htmlspecialchars($challenge['language']); ?></span>
                </div>
                <div class="code-wrapper">
                    <div class="line-numbers" id="duel-line-numbers">1</div>
                    <textarea id="duel-code-editor" class="code-textarea" placeholder="Paste your fixed code here..."><?php echo htmlspecialchars($challenge['broken_code']); ?></textarea>
                </div>
            </div>

            <!-- Action Bar -->
            <div class="challenge-section" style="display: flex; gap: 0.8rem; align-items: center;">
                <button id="duel-submit-btn" class="action-button" disabled>Submit Fix ⚔️</button>
                <p style="font-size: 0.8rem; color: var(--g-muted); margin: 0; letter-spacing: 0.02em;">Duel Mode — No hints. First to submit wins.</p>
            </div>
        </div>
    </div>

    <!-- RESULT MODAL -->
    <div class="duel-result-modal" id="duel-result-modal">
        <div class="result-card">
            <div class="result-header" id="result-modal-header">Result</div>
            
            <div class="duel-score-banner">
                <div class="duel-score-item">
                    <div class="duel-score-label">Final Score</div>
                    <div class="duel-score-value" id="result-score">0</div>
                </div>
                <div class="duel-score-item">
                    <div class="duel-score-label">XP Awarded</div>
                    <div class="duel-score-value" id="result-xp">0</div>
                </div>
            </div>

            <div class="result-section-header">The Roast</div>
            <p class="result-section-content" id="result-roast"></p>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div>
                    <span class="result-section-header" style="margin: 0 0 0.4rem;">Time Complexity</span>
                    <strong id="result-time-complexity" style="display: block; color: var(--g-primary); font-size: 0.95rem;"></strong>
                </div>
                <div>
                    <span class="result-section-header" style="margin: 0 0 0.4rem;">Space Complexity</span>
                    <strong id="result-space-complexity" style="display: block; color: var(--g-primary); font-size: 0.95rem;"></strong>
                </div>
            </div>

            <div class="result-section-header">Edge Cases Missed</div>
            <div id="result-edge-cases" class="result-section-content"></div>

            <div class="result-section-header">Cleaner Alternative</div>
            <pre class="result-section-content" id="result-cleaner-alt" style="background: #060b1a; padding: 0.8rem; border-radius: 8px; border: 1px solid var(--g-border); overflow-x: auto; font-family: 'Courier New', monospace; font-size: 0.8rem; margin-bottom: 0;"></pre>

            <div class="result-section-header">Senior Dev Says</div>
            <blockquote class="result-section-content" id="result-senior-dev" style="border-left: 3px solid var(--g-primary); padding-left: 1rem; margin: 0 0 0.5rem; font-style: italic;"></blockquote>

            <div class="duel-comment" id="result-duel-comment"></div>

            <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                <button onclick="window.location.href='duel_lobby.php'" class="play-again" style="flex: 1; padding: 0.8rem;">Find New Opponent</button>
                <button onclick="window.location.href='game-selection.php'" class="ghost-btn" style="flex: 1; padding: 0.8rem;">Back to Selection</button>
            </div>
        </div>
    </div>

    <script>
const duelRoomCode = '<?php echo htmlspecialchars($room_code); ?>';
const duelChallenge = <?php echo json_encode($challenge); ?>;
const duelUserId = <?php echo (int)$user_id; ?>;
const opponentUsername = '<?php echo htmlspecialchars($opponentUsername); ?>';
const duelRoomStatus = '<?php echo $room['status']; ?>';
const isVsBot = <?php echo $isVsBot ? 'true' : 'false'; ?>;

const duelState = {
    phase: duelRoomStatus,
    myStatus: 'editing',
    opponentStatus: 'waiting',
    my_submitted_at: null,
    opponent_submitted_at: null,
    winner_id: null
};

// Cache DOM
const timerEl = document.getElementById('duel-timer');
const editorEl = document.getElementById('duel-code-editor');
const submitBtn = document.getElementById('duel-submit-btn');
const countdownOv = document.getElementById('countdown-overlay');
const countdownNum = document.getElementById('countdown-number');
const resultModal = document.getElementById('duel-result-modal');
const myStatus = document.getElementById('my-status');
const oppStatus = document.getElementById('opponent-status');
const myProgress = document.getElementById('my-progress-fill');
const oppProgress = document.getElementById('opponent-progress-fill');

let countdownInterval, timerInterval, pollInterval;
let timeRemaining = 180;

// ── MAIN INITIALIZATION ──
document.addEventListener('DOMContentLoaded', async () => {
    // If room is countdown or active, show countdown overlay
    if (['countdown', 'active'].includes(duelRoomStatus)) {
        await showCountdown();
    } else {
        // Otherwise, poll for status change
        pollForGameStart();
    }

    // Always enable editor on load
    editorEl.disabled = false;

    // Submit button handling
    if (submitBtn) {
        submitBtn.addEventListener('click', handleSubmit);
        submitBtn.disabled = editorEl.value.trim().length === 0;
        editorEl.addEventListener('input', () => {
            submitBtn.disabled = editorEl.value.trim().length === 0;
        });
    }

    // Start timer countdown
    startDuelTimer();

    // Continuous polling
    startGamePolling();
    
    // Bot AI: Auto-submit after countdown
    if (isVsBot) {
        botAutoSubmit();
    }
});

async function showCountdown() {
    countdownOv.classList.add('active');
    let countdown = 3;
    
    return new Promise(resolve => {
        countdownInterval = setInterval(() => {
            if (countdown > 0) {
                countdownNum.textContent = countdown;
                countdown--;
            } else {
                clearInterval(countdownInterval);
                countdownOv.classList.remove('active');
                editorEl.focus();
                resolve();
            }
        }, 1000);
    });
}

async function pollForGameStart() {
    return new Promise(resolve => {
        const checkInterval = setInterval(async () => {
            try {
                const resp = await fetch('duel_poll.php?room=' + duelRoomCode + '&phase=game');
                const data = await resp.json();
                
                if (data.status === 'countdown' || data.status === 'active') {
                    clearInterval(checkInterval);
                    await showCountdown();
                    resolve();
                }
            } catch (e) {
                console.error('Poll error:', e);
            }
        }, 1000);
    });
}

function startDuelTimer() {
    timerInterval = setInterval(() => {
        timeRemaining--;
        const m = Math.floor(timeRemaining / 60);
        const s = timeRemaining % 60;
        timerEl.textContent = `${m}:${s.toString().padStart(2, '0')}`;
        
        if (timeRemaining <= 0) {
            clearInterval(timerInterval);
            timeRemaining = 0;
        }
    }, 1000);
}

function startGamePolling() {
    pollInterval = setInterval(async () => {
        try {
            const resp = await fetch('duel_poll.php?room=' + duelRoomCode + '&phase=game');
            const data = await resp.json();

            if (data.error) {
                console.error('Duel poll returned error:', data.error);
                return;
            }
            
            // Update states
            if (data.p1_status) duelState.p1_status = data.p1_status;
            if (data.p2_status) duelState.p2_status = data.p2_status;
            if (data.p1_submitted_at) duelState.p1_submitted_at = data.p1_submitted_at;
            if (data.p2_submitted_at) duelState.p2_submitted_at = data.p2_submitted_at;
            
            // Update UI
            const isPlayer1 = duelUserId === data.player1_id;
            const myNewStatus = isPlayer1 ? data.p1_status : data.p2_status;
            const oppNewStatus = isPlayer1 ? data.p2_status : data.p1_status;

            const applyStatus = (el, status, progressEl) => {
                el.classList.remove('submitted', 'editing');
                if (status === 'submitted') {
                    el.textContent = '✓ Submitted';
                    el.classList.add('submitted');
                    progressEl.style.width = '100%';
                } else if (status === 'editing') {
                    el.textContent = 'Editing...';
                    el.classList.add('editing');
                    if (progressEl.style.width === '0%' || !progressEl.style.width) {
                        progressEl.style.width = '35%';
                    }
                } else {
                    el.textContent = 'Waiting...';
                    progressEl.style.width = '0%';
                }
            };
            
            applyStatus(myStatus, myNewStatus, myProgress);
            applyStatus(oppStatus, oppNewStatus, oppProgress);
            
            // If both submitted or time up, show results
            if ((myNewStatus === 'submitted' && oppNewStatus === 'submitted') || timeRemaining <= 0) {
                clearInterval(pollInterval);
                clearInterval(timerInterval);
                await showResult(data);
            }
        } catch (e) {
            console.error('Game poll error:', e);
        }
    }, 1500);
}

async function handleSubmit() {
    if (!editorEl.value.trim()) {
        alert('Please write some code before submitting!');
        return;
    }
    
    submitBtn.disabled = true;
    submitBtn.textContent = '⏳ Checking...';
    
    try {
        const resp = await fetch('duel_submit.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                room_code: duelRoomCode,
                code: editorEl.value,
                challenge_id: duelChallenge.id
            })
        });
        
        const data = await resp.json();
        
        if (data.success) {
            myStatus.textContent = '✓ Submitted';
            myStatus.classList.add('submitted');
            myProgress.style.width = '100%';
            editorEl.disabled = true;
            submitBtn.disabled = true;
            submitBtn.textContent = 'Waiting for opponent...';
        } else {
            alert(data.message || 'Submission failed');
            submitBtn.disabled = false;
            submitBtn.textContent = 'Submit Fix ⚔️';
        }
    } catch (err) {
        console.error('Submit error:', err);
        submitBtn.disabled = false;
        submitBtn.textContent = 'Submit Fix ⚔️';
    }
}

async function showResult(data) {
    // Determine winner
    const isPlayer1 = duelUserId === data.player1_id;
    const myTime = isPlayer1 ? (data.p1_submitted_at || timeRemaining) : (data.p2_submitted_at || timeRemaining);
    const oppTime = isPlayer1 ? (data.p2_submitted_at || timeRemaining) : (data.p1_submitted_at || timeRemaining);
    
    const won = myTime < oppTime;
    
    // Show result modal
    resultModal.classList.add('active');
    
    const headerEl = document.getElementById('result-modal-header');
    headerEl.textContent = won ? '🎉 Victory!' : '☠️ Defeat';
    headerEl.className = 'result-header' + (won ? ' won' : ' lost');
    
    // Fetch full result data
    try {
        const resp = await fetch('duel_poll.php?room=' + duelRoomCode + '&phase=result');
        const result = await resp.json();
        
        document.getElementById('result-score').textContent = result.score || '0';
        document.getElementById('result-xp').textContent = result.xp || '<?php echo $xpAvailable; ?>';
        document.getElementById('result-roast').textContent = result.roast || 'Great attempt!';
        document.getElementById('result-time-complexity').textContent = result.time_complexity || 'N/A';
        document.getElementById('result-space-complexity').textContent = result.space_complexity || 'N/A';
        document.getElementById('result-edge-cases').textContent = result.edge_cases || 'None identified';
        document.getElementById('result-cleaner-alt').textContent = result.cleaner || 'See challenge feedback';
        document.getElementById('result-senior-dev').textContent = result.senior_advice || 'Well done!';
        
        const comment = document.getElementById('result-duel-comment');
        if (result.duel_comment) {
            comment.textContent = result.duel_comment;
            comment.style.display = 'block';
        } else {
            comment.style.display = 'none';
        }
    } catch (e) {
        console.error('Result fetch error:', e);
    }
}

// Bot AI: Auto-submit after random delay
async function botAutoSubmit() {
    // Random delay between 8-25 seconds (to make it feel natural)
    const delay = Math.random() * 17000 + 8000;
    
    setTimeout(async () => {
        try {
            // Generate simple bot code based on challenge
            let botCode = generateBotCode();
            
            // Submit bot code
            const resp = await fetch('duel_bot_submit.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    room_code: duelRoomCode,
                    code: botCode,
                    challenge_id: duelChallenge.id
                })
            });
            
            const data = await resp.json();
            console.log('Bot submitted:', data);
        } catch (err) {
            console.error('Bot submission error:', err);
        }
    }, delay);
}

// Generate simple bot fix code
function generateBotCode() {
    // Extract broken code from the challenge
    let code = <?php echo json_encode((string)$challenge['broken_code']); ?>;
    
    // Simple bot "fixes" - add comments and fix common issues
    const fixes = [
        'let result = 0;',
        'let index = 0;',
        'let count = 0;',
        'return result;',
        '// Fixed: check edge case',
        '// Fixed: initialize properly',
        'if (arr.length === 0) return [];',
        'for (let i = 0; i < arr.length; i++)',
    ];
    
    // Bot randomly picks a fix strategy
    const strategy = fixes[Math.floor(Math.random() * fixes.length)];
    
    // Apply fix - just insert at end for demo
    code = code.replace(/function\s+\w+\s*\(/, `// Bot fix attempt\\nfunction `);
    code += `\\n\\n// Strategy: ${strategy}`;
    
    return code;
}

// Cleanup on leave
window.addEventListener('beforeunload', () => {
    clearInterval(timerInterval);
    clearInterval(pollInterval);
    clearInterval(countdownInterval);
});
    </script>
</body>
</html>
