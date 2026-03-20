<?php
// ════════════════════════════════════════
// FILE: daily-sprint.php
// PURPOSE: Run Daily Bug Sprint mode with 3 bugs in 10 minutes and one completion per day.
// ANALYSES USED: styles.css, menu.css, script.js, menu.js, play/bug-hunt.php, play/obituary.php, onboarding/config.php, navigation/leaderboards/leaderboards.php, play/game-selection.php
// NEW TABLES USED: daily_sprint_locks, daily_sprint_results
// DEPENDS ON: onboarding/config.php, play/obituary.php, play/daily_sprint_complete.php
// CEREBRAS CALLS: yes
// ════════════════════════════════════════

require_once '../onboarding/config.php';
require_once '../includes/greeting.php';

requireLogin();

$user_id = (int)$_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT username, email, profile_image FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header('Location: ../onboarding/login.php');
    exit();
}

$already_completed = false;
$previous_score = 0;
$previous_bugs = 0;
$sprint_lock_id = 0;

$lockStmt = $pdo->prepare("SELECT id, completed, total_score, bugs_fixed FROM daily_sprint_locks WHERE user_id = ? AND sprint_date = CURDATE() LIMIT 1");
$lockStmt->execute([$user_id]);
$existingLock = $lockStmt->fetch(PDO::FETCH_ASSOC);

if ($existingLock) {
    $sprint_lock_id = (int)$existingLock['id'];
    if ((int)$existingLock['completed'] === 1) {
        $already_completed = true;
        $previous_score = (int)$existingLock['total_score'];
        $previous_bugs = (int)$existingLock['bugs_fixed'];
    }
} else {
    $insertLockStmt = $pdo->prepare("INSERT INTO daily_sprint_locks (user_id, sprint_date, completed) VALUES (?, CURDATE(), 0)");
    $insertLockStmt->execute([$user_id]);
    $sprint_lock_id = (int)$pdo->lastInsertId();
}

if ($already_completed):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include '../includes/favicon.php'; ?>
    <title>Daily Bug Sprint</title>
    <link rel="stylesheet" href="../styles.css?v=<?php echo filemtime('../styles.css'); ?>">
    <link rel="stylesheet" href="../MainGame/grammarheroes/game.css?v=<?php echo filemtime('../MainGame/grammarheroes/game.css'); ?>">
    <link rel="stylesheet" href="../notif/toast.css?v=<?php echo filemtime('../notif/toast.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        :root {
            --bh-bg-soft: rgba(13, 22, 51, 0.86);
            --bh-border: rgba(255, 255, 255, 0.16);
            --bh-muted: #b8c2eb;
            --bh-primary: #58e1ff;
            --bh-success: #67e59f;
        }

        .bug-shell {
            width: min(1100px, 95vw);
            margin: 1rem auto 2rem;
        }

        .challenge-card {
            background: var(--bh-bg-soft);
            border: 1px solid var(--bh-border);
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 0.8rem;
        }

        .countdown-next {
            font-size: 1.1rem;
            font-weight: 800;
            color: var(--bh-primary);
            margin: 0.6rem 0;
        }

        .helper-text {
            color: var(--bh-muted);
            margin-bottom: 0.75rem;
        }
    </style>
</head>
<body>
    <div class="bug-shell game-shell">
        <header class="game-header">
            <button class="ghost-btn" onclick="window.location.href='game-selection.php'">
                <i class="fas fa-arrow-left"></i> Back
            </button>
            <div class="title-wrap">
                <h1>⚡ Daily Bug Sprint</h1>
                <p>3 bugs. 10 minutes. No hints. Go.</p>
            </div>
            <div class="profile-pill">
                <img src="<?php echo !empty($user['profile_image']) ? '../' . htmlspecialchars($user['profile_image']) : '../assets/menu/defaultuser.png'; ?>" alt="Profile">
                <span><?php echo htmlspecialchars($user['username']); ?></span>
            </div>
        </header>

        <section class="challenge-card">
            <h2>⚡ Today's Sprint Complete!</h2>
            <p class="helper-text">You already conquered today's bugs.</p>
            <p><strong>Your score:</strong> <?php echo $previous_score; ?> pts</p>
            <p><strong>Bugs fixed:</strong> <?php echo $previous_bugs; ?>/3</p>
            <p class="helper-text">Come back tomorrow for a new sprint</p>
            <div class="countdown-next" id="nextSprintCountdown">00:00:00</div>
            <div class="action-bar" style="margin-top: 0.8rem; display:flex; gap:0.6rem; flex-wrap:wrap;">
                <button class="play-again" onclick="window.location.href='../navigation/leaderboards/leaderboards.php?game=daily_sprint'">View Leaderboard</button>
                <button class="ghost-btn" onclick="window.location.href='bug-hunt.php'">Play Bug Hunt Arena</button>
            </div>
        </section>
    </div>

    <script>
        const countdownEl = document.getElementById('nextSprintCountdown');

        function updateNextSprintCountdown() {
            const now = new Date();
            const nextMidnight = new Date(now);
            nextMidnight.setHours(24, 0, 0, 0);
            const diff = Math.max(0, Math.floor((nextMidnight.getTime() - now.getTime()) / 1000));

            const hh = String(Math.floor(diff / 3600)).padStart(2, '0');
            const mm = String(Math.floor((diff % 3600) / 60)).padStart(2, '0');
            const ss = String(diff % 60).padStart(2, '0');
            countdownEl.textContent = `${hh}:${mm}:${ss}`;
        }

        updateNextSprintCountdown();
        setInterval(updateNextSprintCountdown, 1000);
    </script>
</body>
</html>
<?php
exit();
endif;

$challengeStmt = $pdo->query("SELECT * FROM bug_challenges WHERE challenge_type = 'bug_fix' ORDER BY RAND() LIMIT 3");
$challenges = $challengeStmt->fetchAll(PDO::FETCH_ASSOC);

if (count($challenges) < 3) {
    header('Location: seed.php?message=' . urlencode('Daily Sprint needs at least 3 bug challenges.'));
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include '../includes/favicon.php'; ?>
    <title>Daily Bug Sprint</title>
    <link rel="stylesheet" href="../styles.css?v=<?php echo filemtime('../styles.css'); ?>">
    <link rel="stylesheet" href="../MainGame/grammarheroes/game.css?v=<?php echo filemtime('../MainGame/grammarheroes/game.css'); ?>">
    <link rel="stylesheet" href="../notif/toast.css?v=<?php echo filemtime('../notif/toast.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        :root {
            --bh-bg: #0b1230;
            --bh-bg-soft: rgba(13, 22, 51, 0.86);
            --bh-border: rgba(255, 255, 255, 0.16);
            --bh-warning: #ffcc70;
            --bh-success: #67e59f;
            --bh-danger: #ff6c88;
            --bh-text: #f3f6ff;
            --bh-muted: #b8c2eb;
            --bh-primary: #58e1ff;
        }

        .bug-shell {
            width: min(1100px, 95vw);
            margin: 1rem auto 2rem;
        }

        .sprint-timer {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--bh-primary);
        }

        .sprint-timer.warning { color: var(--bh-warning); }
        .sprint-timer.danger { color: var(--bh-danger); }
        .sprint-timer.pulse { animation: sprintPulse 1s ease-in-out infinite; }

        @keyframes sprintPulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .progress-wrap {
            background: var(--bh-bg-soft);
            border: 1px solid var(--bh-border);
            border-radius: 12px;
            padding: 0.9rem;
            margin-bottom: 0.8rem;
        }

        .sprint-progress {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.6rem;
            align-items: start;
        }

        .progress-step {
            text-align: center;
            position: relative;
        }

        .progress-node {
            width: 22px;
            height: 22px;
            border-radius: 999px;
            margin: 0 auto 0.35rem;
            border: 2px solid rgba(255, 255, 255, 0.35);
            background: transparent;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.72rem;
            font-weight: 700;
        }

        .progress-step.current .progress-node {
            background: var(--bh-primary);
            border-color: var(--bh-primary);
            color: #091225;
            transform: scale(1.08);
            animation: sprintPulse 1s ease-in-out infinite;
        }

        .progress-step.completed .progress-node {
            background: var(--bh-success);
            border-color: var(--bh-success);
            color: #08151b;
        }

        .progress-step.upcoming .progress-node {
            background: transparent;
            border-color: rgba(255, 255, 255, 0.35);
            color: var(--bh-muted);
        }

        .progress-label {
            font-size: 0.78rem;
            color: var(--bh-muted);
            margin-bottom: 0.2rem;
        }

        .progress-score {
            font-size: 0.75rem;
            color: var(--bh-text);
            font-weight: 700;
        }

        .progress-step:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 10px;
            right: -50%;
            width: 100%;
            height: 2px;
            background: rgba(255,255,255,0.25);
            z-index: 0;
        }

        .progress-step.completed:not(:last-child)::after {
            background: var(--bh-success);
        }

        .challenge-card {
            background: var(--bh-bg-soft);
            border: 1px solid var(--bh-border);
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 0.8rem;
        }

        .warning-text {
            color: var(--bh-warning);
            font-weight: 600;
            margin-top: 0.6rem;
        }

        .editor-label {
            display: flex;
            justify-content: space-between;
            gap: 0.7rem;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .lang-pill {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 0.25rem 0.7rem;
            font-size: 0.75rem;
            font-weight: 700;
            border: 1px solid var(--bh-border);
            background: rgba(103, 229, 159, 0.15);
            color: var(--bh-success);
            text-transform: uppercase;
        }

        .editor-shell {
            display: grid;
            grid-template-columns: 40px 1fr;
            border: 1px solid var(--bh-border);
            border-radius: 12px;
            overflow: hidden;
            background: #060b1a;
            min-height: 300px;
        }

        .line-numbers {
            background: #060b1a;
            color: rgba(255, 255, 255, 0.5);
            border-right: 1px solid rgba(255, 255, 255, 0.1);
            text-align: right;
            padding: 16px 6px 16px 0;
            font-family: 'Courier New', Courier, monospace;
            font-size: 14px;
            line-height: 1.6;
            user-select: none;
            white-space: pre;
        }

        #sprint-code-editor {
            width: 100%;
            min-height: 300px;
            border: 0;
            outline: none;
            resize: vertical;
            background: #060b1a;
            color: var(--bh-text);
            font-family: 'Courier New', Courier, monospace;
            font-size: 14px;
            line-height: 1.6;
            padding: 16px;
        }

        .action-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 0.6rem;
            align-items: center;
            margin-top: 0.8rem;
        }

        .bug-counter {
            color: var(--bh-muted);
            font-size: 0.85rem;
            width: 100%;
        }

        .sprint-toast {
            position: fixed;
            right: 16px;
            bottom: 16px;
            width: min(340px, calc(100vw - 24px));
            background: rgba(13, 22, 51, 0.96);
            border: 1px solid var(--bh-border);
            border-left: 4px solid var(--bh-success);
            border-radius: 12px;
            padding: 0.9rem;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
            transform: translateY(22px);
            opacity: 0;
            pointer-events: none;
            transition: transform 0.25s ease, opacity 0.25s ease;
            z-index: 999;
        }

        .sprint-toast.show {
            transform: translateY(0);
            opacity: 1;
            pointer-events: auto;
        }

        .toast-label {
            font-size: 0.7rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--bh-success);
            margin-bottom: 0.35rem;
            font-weight: 700;
        }

        .score-banner {
            background: rgba(88, 225, 255, 0.15);
            border: 1px solid rgba(88, 225, 255, 0.35);
            border-radius: 12px;
            padding: 0.8rem;
            margin: 0.9rem 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.8rem;
        }

        .score-main {
            font-size: 1.7rem;
            font-weight: 800;
            color: #58e1ff;
        }

        .section-title {
            margin: 0.6rem 0 0.4rem;
            color: var(--bh-muted);
            font-size: 0.92rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .badge-large {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            padding: 0.5rem 1rem;
            font-weight: 800;
            margin: 0.5rem 0 0.8rem;
            border: 1px solid var(--bh-border);
        }

        .badge-gold { background: rgba(255, 215, 0, 0.2); color: #ffd700; }
        .badge-silver { background: rgba(192, 192, 192, 0.2); color: #c0c0c0; }
        .badge-bronze { background: rgba(205, 127, 50, 0.2); color: #cd7f32; }
        .badge-muted { background: rgba(184, 194, 235, 0.2); color: #b8c2eb; }

        .breakdown-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0.4rem 0 0.8rem;
        }

        .breakdown-table th,
        .breakdown-table td {
            border-bottom: 1px solid rgba(255,255,255,0.12);
            padding: 0.45rem;
            text-align: left;
            font-size: 0.88rem;
        }

        .mini-obit-item {
            border: 1px solid rgba(255,255,255,0.14);
            border-radius: 10px;
            margin-bottom: 0.45rem;
            overflow: hidden;
        }

        .mini-obit-trigger {
            width: 100%;
            background: rgba(255,255,255,0.06);
            color: #fff;
            border: 0;
            text-align: left;
            padding: 0.55rem 0.7rem;
            cursor: pointer;
            font-weight: 700;
        }

        .mini-obit-body {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.25s ease;
            background: rgba(0,0,0,0.18);
            padding: 0 0.7rem;
        }

        .mini-obit-body.open {
            max-height: 260px;
            padding: 0.6rem 0.7rem;
        }

        .leaderboard-mini {
            margin-top: 0.45rem;
        }

        .leaderboard-row {
            display: grid;
            grid-template-columns: 1fr auto auto;
            gap: 0.5rem;
            padding: 0.35rem 0.45rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .leaderboard-row.current {
            background: rgba(88,225,255,0.14);
            border-radius: 8px;
        }

        .fade-swap {
            transition: opacity 0.2s ease;
        }

        @media (max-width: 680px) {
            .score-banner { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>
    <div class="bug-shell game-shell">
        <header class="game-header">
            <button class="ghost-btn" onclick="window.location.href='game-selection.php'">
                <i class="fas fa-arrow-left"></i> Back
            </button>
            <div class="title-wrap">
                <h1>⚡ Daily Bug Sprint</h1>
                <p>3 bugs. 10 minutes. No hints. Go.</p>
            </div>
            <div class="profile-pill">
                <img src="<?php echo !empty($user['profile_image']) ? '../' . htmlspecialchars($user['profile_image']) : '../assets/menu/defaultuser.png'; ?>" alt="Profile">
                <span><?php echo htmlspecialchars($user['username']); ?></span>
            </div>
        </header>

        <section class="hud">
            <div class="hud-item"><span>Total Score</span><strong id="score">0</strong></div>
            <div class="hud-item"><span>XP</span><strong id="xpEarned">0</strong></div>
            <div class="hud-item"><span>Countdown</span><strong id="sprintTimer" class="sprint-timer">10:00</strong></div>
            <div class="hud-item full"><span>Sprint</span><strong id="sprintHeaderText">Bug 1 of 3</strong></div>
        </section>

        <section class="progress-wrap">
            <div class="sprint-progress" id="sprintProgress">
                <div class="progress-step current" data-step="0">
                    <div class="progress-node">1</div>
                    <div class="progress-label">Bug 1</div>
                    <div class="progress-score" id="score-0">0 pts</div>
                </div>
                <div class="progress-step upcoming" data-step="1">
                    <div class="progress-node">2</div>
                    <div class="progress-label">Bug 2</div>
                    <div class="progress-score" id="score-1">0 pts</div>
                </div>
                <div class="progress-step upcoming" data-step="2">
                    <div class="progress-node">3</div>
                    <div class="progress-label">Bug 3</div>
                    <div class="progress-score" id="score-2">0 pts</div>
                </div>
            </div>
        </section>

        <section class="challenge-card fade-swap" id="backstoryPanel">
            <h3>📋 The Backstory</h3>
            <p id="backstoryText"></p>
            <p class="warning-text" id="consequenceText"></p>
        </section>

        <section class="challenge-card">
            <div class="editor-label">
                <h3>🐛 Broken Code — find the bug and fix it</h3>
                <span class="lang-pill" id="languagePill">JS</span>
            </div>
            <div class="editor-shell">
                <div class="line-numbers" id="lineNumbers">1</div>
                <textarea id="sprint-code-editor" spellcheck="false"></textarea>
            </div>
            <div class="action-bar">
                <button id="submitFixBtn" class="play-again">Submit Fix</button>
                <div class="bug-counter" id="bugCounter">Bug 1 of 3</div>
            </div>
        </section>
    </div>

    <div class="sprint-toast" id="sprintToast">
        <div class="toast-label">Sprint update</div>
        <div id="sprintToastText"></div>
    </div>

    <div class="result-modal" id="sprintSummaryModal" hidden>
        <div class="result-card">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:0.6rem;">
                <h2>⚡ Sprint Complete!</h2>
                <button id="closeSummaryBtn" class="ghost-btn"><i class="fas fa-xmark"></i></button>
            </div>
            <p id="summaryTime" style="color:#b8c2eb;"></p>

            <div class="score-banner">
                <div>
                    <div class="section-title">Total Score</div>
                    <div class="score-main" id="summaryTotalScore">0</div>
                </div>
                <div>
                    <div class="section-title">XP Awarded</div>
                    <div class="score-main" id="summaryXpAwarded">0</div>
                </div>
            </div>

            <div class="section-title">Bug Breakdown</div>
            <table class="breakdown-table" id="breakdownTable">
                <thead>
                    <tr><th>Bug title</th><th>Fixed?</th><th>Time</th><th>Score</th></tr>
                </thead>
                <tbody></tbody>
            </table>

            <div class="section-title">Performance Badge</div>
            <div id="performanceBadge" class="badge-large badge-muted">😅 The Bugs Won Today</div>

            <div class="section-title">Mini Obituaries</div>
            <div id="miniObituaries"></div>

            <div class="section-title">Today's Top Sprint Scores</div>
            <div class="leaderboard-mini" id="top5List"></div>
            <a href="../navigation/leaderboards/leaderboards.php?game=daily_sprint" style="color:#b8c2eb; text-decoration: underline;">View Full Leaderboard</a>

            <div class="result-actions" style="margin-top:0.8rem;">
                <button class="play-again" onclick="window.location.href='../navigation/leaderboards/leaderboards.php?game=daily_sprint'">View Full Leaderboard</button>
                <button class="ghost-btn" onclick="window.location.href='bug-hunt.php'">Play Bug Hunt Arena</button>
            </div>
        </div>
    </div>

    <script>
        const sprintChallenges = <?php echo json_encode($challenges); ?>;
        const sprintLockId = <?php echo (int)$sprint_lock_id; ?>;
        const alreadyCompleted = <?php echo json_encode($already_completed); ?>;
        const sprintUserId = <?php echo (int)$user_id; ?>;

        const sprintState = {
            currentBugIndex: 0,
            timeRemaining: 600,
            timerInterval: null,
            startTime: Date.now(),
            bugStartTime: Date.now(),
            scores: [0, 0, 0],
            fixed: [false, false, false],
            feedbacks: [null, null, null],
            isSubmitting: false,
            isEnded: false,
            bugTimes: [0, 0, 0],
            submittedCode: ['', '', '']
        };

        const sprintTimer = document.getElementById('sprintTimer');
        const sprintHeaderText = document.getElementById('sprintHeaderText');
        const backstoryPanel = document.getElementById('backstoryPanel');
        const backstoryText = document.getElementById('backstoryText');
        const consequenceText = document.getElementById('consequenceText');
        const languagePill = document.getElementById('languagePill');
        const bugCounter = document.getElementById('bugCounter');
        const codeEditor = document.getElementById('sprint-code-editor');
        const lineNumbers = document.getElementById('lineNumbers');
        const submitFixBtn = document.getElementById('submitFixBtn');
        const totalScoreEl = document.getElementById('score');
        const xpEarnedEl = document.getElementById('xpEarned');

        const sprintToast = document.getElementById('sprintToast');
        const sprintToastText = document.getElementById('sprintToastText');

        const summaryModal = document.getElementById('sprintSummaryModal');
        const closeSummaryBtn = document.getElementById('closeSummaryBtn');

        function formatTime(seconds) {
            const mm = Math.floor(seconds / 60).toString().padStart(2, '0');
            const ss = (seconds % 60).toString().padStart(2, '0');
            return `${mm}:${ss}`;
        }

        function updateHeaderScore() {
            const total = sprintState.scores.reduce((a, b) => a + b, 0);
            totalScoreEl.textContent = String(total);
            xpEarnedEl.textContent = String(Math.floor(total / 10));
        }

        function renderLineNumbers() {
            const lines = codeEditor.value.split('\n').length;
            let nums = '';
            for (let i = 1; i <= lines; i += 1) {
                nums += i + (i < lines ? '\n' : '');
            }
            lineNumbers.textContent = nums;
        }

        codeEditor.addEventListener('scroll', () => {
            lineNumbers.scrollTop = codeEditor.scrollTop;
        });

        codeEditor.addEventListener('keydown', (event) => {
            if (event.key === 'Tab') {
                event.preventDefault();
                const start = codeEditor.selectionStart;
                const end = codeEditor.selectionEnd;
                const value = codeEditor.value;
                codeEditor.value = value.slice(0, start) + '  ' + value.slice(end);
                codeEditor.selectionStart = codeEditor.selectionEnd = start + 2;
                renderLineNumbers();
            }
        });

        codeEditor.addEventListener('input', renderLineNumbers);

        function setSubmitLoading(isLoading) {
            sprintState.isSubmitting = isLoading;
            submitFixBtn.disabled = isLoading;
            if (isLoading) {
                submitFixBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
            } else {
                submitFixBtn.innerHTML = 'Submit Fix';
            }
        }

        function showToast(message) {
            sprintToastText.textContent = message;
            sprintToast.classList.add('show');
            setTimeout(() => sprintToast.classList.remove('show'), 1500);
        }

        async function fetchJsonWithDiagnostics(url, bodyPayload) {
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(bodyPayload)
            });

            const text = await res.text();
            if (!res.ok) {
                throw new Error(`${url} returned HTTP ${res.status}. Body: ${text.slice(0, 300)}`);
            }

            try {
                return JSON.parse(text);
            } catch (parseError) {
                throw new Error(`${url} returned invalid JSON: ${parseError.message}. Body: ${text.slice(0, 300)}`);
            }
        }

        function updateProgressIndicator() {
            const steps = document.querySelectorAll('.progress-step');
            steps.forEach((stepEl, index) => {
                stepEl.classList.remove('completed', 'current', 'upcoming');
                const node = stepEl.querySelector('.progress-node');
                if (sprintState.fixed[index]) {
                    stepEl.classList.add('completed');
                    node.textContent = '✓';
                } else if (index === sprintState.currentBugIndex && !sprintState.isEnded) {
                    stepEl.classList.add('current');
                    node.textContent = String(index + 1);
                } else {
                    stepEl.classList.add('upcoming');
                    node.textContent = String(index + 1);
                }
                const scoreEl = document.getElementById(`score-${index}`);
                scoreEl.textContent = `${sprintState.scores[index]} pts`;
            });
        }

        function updateTimerVisual() {
            sprintTimer.textContent = formatTime(sprintState.timeRemaining);
            sprintTimer.classList.remove('warning', 'danger', 'pulse');

            if (sprintState.timeRemaining <= 60) {
                sprintTimer.classList.add('danger', 'pulse');
            } else if (sprintState.timeRemaining <= 180) {
                sprintTimer.classList.add('warning');
            }
        }

        function loadBug(index) {
            if (!sprintChallenges[index]) {
                return;
            }

            const challenge = sprintChallenges[index];
            sprintHeaderText.textContent = `Bug ${index + 1} of 3`;
            bugCounter.textContent = `Bug ${index + 1} of 3`;

            backstoryPanel.style.opacity = '0';
            setTimeout(() => {
                backstoryText.textContent = challenge.backstory || '';
                consequenceText.textContent = `⚠️ Real-world consequence: ${challenge.real_world_consequence || ''}`;
                languagePill.textContent = String(challenge.language || 'javascript').toUpperCase();
                backstoryPanel.style.opacity = '1';
            }, 200);

            codeEditor.value = challenge.broken_code || '';
            renderLineNumbers();
            sprintState.bugStartTime = Date.now();
            updateProgressIndicator();
        }

        async function submitBug(forceEnd = false) {
            if (sprintState.isSubmitting || sprintState.isEnded) {
                return;
            }

            const index = sprintState.currentBugIndex;
            const challenge = sprintChallenges[index];
            if (!challenge) {
                endSprint();
                return;
            }

            setSubmitLoading(true);

            const timeTaken = Math.floor((Date.now() - sprintState.bugStartTime) / 1000);
            sprintState.bugTimes[index] = timeTaken;
            sprintState.submittedCode[index] = codeEditor.value;

            try {
                const data = await fetchJsonWithDiagnostics('obituary.php', {
                        submitted_code: codeEditor.value,
                        challenge_id: challenge.id,
                        time_taken: timeTaken,
                        language: challenge.language,
                        user_id: sprintUserId,
                        game_type: 'daily_sprint'
                });
                sprintState.feedbacks[index] = data || null;
                sprintState.scores[index] = Number(data?.score || 0);
                sprintState.fixed[index] = true;

                updateHeaderScore();
                updateProgressIndicator();
                showToast(`+${sprintState.scores[index]} pts on Bug ${index + 1}`);
            } catch (error) {
                console.error('daily sprint obituary.php failed:', error);
                sprintState.feedbacks[index] = null;
                sprintState.scores[index] = 0;
                sprintState.fixed[index] = false;
                updateHeaderScore();
                updateProgressIndicator();
                showToast(`Bug ${index + 1} submission failed`);
            } finally {
                setSubmitLoading(false);
            }

            if (forceEnd) {
                endSprint();
                return;
            }

            if (sprintState.currentBugIndex < 2) {
                sprintState.currentBugIndex += 1;
                loadBug(sprintState.currentBugIndex);
            } else {
                endSprint();
            }
        }

        async function endSprint() {
            if (sprintState.isEnded) {
                return;
            }

            sprintState.isEnded = true;
            clearInterval(sprintState.timerInterval);

            for (let i = 0; i < 3; i += 1) {
                if (!sprintState.fixed[i]) {
                    sprintState.scores[i] = 0;
                    sprintState.feedbacks[i] = null;
                }
            }

            updateHeaderScore();
            updateProgressIndicator();

            const totalScore = sprintState.scores.reduce((a, b) => a + b, 0);
            const bugsFixed = sprintState.fixed.filter(Boolean).length;
            const totalTime = 600 - sprintState.timeRemaining;

            try {
                const result = await fetchJsonWithDiagnostics('daily_sprint_complete.php', {
                        sprint_lock_id: sprintLockId,
                        user_id: sprintUserId,
                        scores: sprintState.scores,
                        feedbacks: sprintState.feedbacks,
                        bugs_fixed: bugsFixed,
                        total_score: totalScore,
                        total_time: totalTime,
                        challenge_ids: sprintChallenges.map((challenge) => challenge.id),
                        time_per_bug: sprintState.bugTimes,
                        submitted_code: sprintState.submittedCode
                });
                populateSummary(result, totalTime);
            } catch (error) {
                console.error('daily_sprint_complete.php failed:', error);
                populateSummary({
                    success: false,
                    total_score: totalScore,
                    xp_awarded: Math.floor(totalScore / 10),
                    leaderboard_rank: '-',
                    top5: []
                }, totalTime);
            }
        }

        function getPerformanceBadge(fixedCount) {
            if (fixedCount === 3) return { text: '🏆 Exterminator', cls: 'badge-gold' };
            if (fixedCount === 2) return { text: '🥈 Bug Squasher', cls: 'badge-silver' };
            if (fixedCount === 1) return { text: '🥉 Getting There', cls: 'badge-bronze' };
            return { text: '😅 The Bugs Won Today', cls: 'badge-muted' };
        }

        function populateSummary(result, totalTime) {
            const totalScore = Number(result?.total_score ?? sprintState.scores.reduce((a, b) => a + b, 0));
            const xpAwarded = Number(result?.xp_awarded ?? Math.floor(totalScore / 10));
            const bugsFixed = sprintState.fixed.filter(Boolean).length;

            document.getElementById('summaryTime').textContent = `Finished in ${formatTime(totalTime)}`;
            document.getElementById('summaryTotalScore').textContent = String(totalScore);
            document.getElementById('summaryXpAwarded').textContent = String(xpAwarded);

            const breakdownTbody = document.querySelector('#breakdownTable tbody');
            breakdownTbody.innerHTML = '';
            sprintChallenges.forEach((challenge, i) => {
                const row = document.createElement('tr');
                const fixedText = sprintState.fixed[i] ? '✅' : '❌';
                const timeText = sprintState.fixed[i] ? `${sprintState.bugTimes[i]}s` : '—';
                row.innerHTML = `<td>${challenge.title}</td><td>${fixedText}</td><td>${timeText}</td><td>${sprintState.scores[i]}</td>`;
                breakdownTbody.appendChild(row);
            });

            const badge = getPerformanceBadge(bugsFixed);
            const badgeEl = document.getElementById('performanceBadge');
            badgeEl.className = `badge-large ${badge.cls}`;
            badgeEl.textContent = badge.text;

            const miniWrap = document.getElementById('miniObituaries');
            miniWrap.innerHTML = '';
            sprintChallenges.forEach((challenge, i) => {
                if (!sprintState.feedbacks[i]) return;
                const item = document.createElement('div');
                item.className = 'mini-obit-item';
                const roast = sprintState.feedbacks[i]?.roast || '';
                const senior = sprintState.feedbacks[i]?.senior_dev_comment || '';
                item.innerHTML = `
                    <button class="mini-obit-trigger" data-target="obit-${i}">${challenge.title} ▼</button>
                    <div class="mini-obit-body" id="obit-${i}">
                        <p style="font-style:italic; margin-bottom:0.45rem;">${roast}</p>
                        <p style="margin:0;">${senior}</p>
                    </div>
                `;
                miniWrap.appendChild(item);
            });

            miniWrap.querySelectorAll('.mini-obit-trigger').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const id = btn.getAttribute('data-target');
                    const body = document.getElementById(id);
                    body.classList.toggle('open');
                });
            });

            const top5Wrap = document.getElementById('top5List');
            top5Wrap.innerHTML = '';
            const top5 = Array.isArray(result?.top5) ? result.top5 : [];
            top5.forEach((entry) => {
                const isCurrent = entry.username === <?php echo json_encode($user['username']); ?>;
                const row = document.createElement('div');
                row.className = `leaderboard-row ${isCurrent ? 'current' : ''}`;
                row.innerHTML = `<span>${entry.username}</span><span>${entry.total_score} pts</span><span>${entry.bugs_fixed}/3</span>`;
                top5Wrap.appendChild(row);
            });

            summaryModal.hidden = false;
        }

        function timerTick() {
            if (sprintState.isEnded) {
                return;
            }

            sprintState.timeRemaining = Math.max(0, sprintState.timeRemaining - 1);
            updateTimerVisual();

            if (sprintState.timeRemaining === 0) {
                const currentOriginal = sprintChallenges[sprintState.currentBugIndex]?.broken_code || '';
                const changed = codeEditor.value !== currentOriginal;
                if (changed && !sprintState.fixed[sprintState.currentBugIndex]) {
                    submitBug(true);
                } else {
                    endSprint();
                }
            }
        }

        closeSummaryBtn.addEventListener('click', () => {
            summaryModal.hidden = true;
        });

        submitFixBtn.addEventListener('click', () => submitBug(false));

        updateHeaderScore();
        updateProgressIndicator();
        updateTimerVisual();
        loadBug(0);

        sprintState.timerInterval = setInterval(timerTick, 1000);
    </script>
</body>
</html>
