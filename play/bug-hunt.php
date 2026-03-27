<?php
// ════════════════════════════════════════
// FILE: bug-hunt.php
// PURPOSE: Main gameplay page for Bug Hunt Arena.
// ANALYSES USED: MainGame/grammarheroes/game.php, MainGame/grammarheroes/game.css, MainGame/grammarheroes/script.js, script.js, menu.js, play/game-selection.php, onboarding/config.php
// NEW TABLES USED: bug_challenges, user_game_sessions, concept_graph
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

$challengeStmt = $pdo->query("SELECT * FROM bug_challenges WHERE challenge_type = 'bug_fix' ORDER BY RAND() LIMIT 1");
$challenge = $challengeStmt->fetch(PDO::FETCH_ASSOC);

if (!$challenge) {
    header('Location: seed.php?message=' . urlencode('No challenges found. Seed data first.'));
    exit();
}

$_SESSION['current_challenge_id'] = (int)$challenge['id'];

$difficulty = strtolower((string)$challenge['difficulty']);
$xpByDifficulty = [
    'beginner' => 80,
    'intermediate' => 100,
    'advanced' => 120,
];
$xpAvailable = $xpByDifficulty[$difficulty] ?? 100;

$tags = array_filter(array_map('trim', explode(',', (string)$challenge['concept_tags'])));
$debugMode = isset($_GET['debug']) && $_GET['debug'] === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include '../includes/favicon.php'; ?>
    <title>Bug Hunt Arena</title>
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
            --bh-text: #f3f6ff;
            --bh-muted: #b8c2eb;
        }

        .bug-shell {
            width: min(1100px, 95vw);
            margin: 1rem auto 2rem;
        }

        .difficulty-pill,
        .concept-pill,
        .lang-pill {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 0.25rem 0.7rem;
            font-size: 0.75rem;
            font-weight: 700;
            border: 1px solid var(--bh-border);
            margin-right: 0.4rem;
            margin-bottom: 0.4rem;
            text-transform: capitalize;
        }

        .difficulty-pill { background: rgba(255, 204, 112, 0.2); color: var(--bh-warning); }
        .concept-pill { background: rgba(88, 225, 255, 0.15); color: #58e1ff; }
        .lang-pill { background: rgba(103, 229, 159, 0.15); color: var(--bh-success); text-transform: uppercase; }

        .challenge-card {
            background: var(--bh-bg-soft);
            border: 1px solid var(--bh-border);
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 0.8rem;
        }

        .challenge-card h3 {
            margin-bottom: 0.5rem;
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

        #code-editor {
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

        .skip-link {
            color: var(--bh-muted);
            font-weight: 700;
            text-decoration: underline;
        }

        .mentor-hint {
            position: fixed;
            right: 16px;
            bottom: 16px;
            width: min(360px, calc(100vw - 24px));
            background: rgba(13, 22, 51, 0.96);
            border: 1px solid var(--bh-border);
            border-left: 4px solid var(--bh-warning);
            border-radius: 12px;
            padding: 0.9rem;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
            transform: translateY(22px);
            opacity: 0;
            pointer-events: none;
            transition: transform 0.25s ease, opacity 0.25s ease;
            z-index: 999;
        }

        .mentor-hint.show {
            transform: translateY(0);
            opacity: 1;
            pointer-events: auto;
        }

        .hint-label {
            font-size: 0.7rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--bh-warning);
            margin-bottom: 0.35rem;
            font-weight: 700;
        }

        .hint-text {
            margin-bottom: 0.65rem;
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

        .obit-code {
            background: #060b1a;
            border-radius: 10px;
            border: 1px solid var(--bh-border);
            padding: 0.8rem;
            white-space: pre-wrap;
            font-family: 'Courier New', Courier, monospace;
            max-height: 220px;
            overflow: auto;
        }

        .edge-good { color: var(--bh-success); font-weight: 700; }
        .edge-warning { color: var(--bh-warning); }

        @media (max-width: 680px) {
            .score-banner { flex-direction: column; align-items: flex-start; }
        }

        .debug-panel {
            background: var(--bh-bg-soft);
            border: 1px solid var(--bh-border);
            border-radius: 12px;
            padding: 1rem;
            margin: 0.8rem 0;
        }

        .debug-log {
            background: #060b1a;
            border: 1px solid var(--bh-border);
            border-radius: 10px;
            padding: 0.8rem;
            max-height: 240px;
            overflow: auto;
            font-family: 'Courier New', Courier, monospace;
            white-space: pre-wrap;
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
                <h1>Bug Hunt Arena</h1>
                <p>Find and fix the bug before it ships to production</p>
            </div>
            <div class="profile-pill">
                <img src="<?php echo !empty($user['profile_image']) ? '../' . htmlspecialchars($user['profile_image']) : '../assets/menu/defaultuser.png'; ?>" alt="Profile">
                <span><?php echo htmlspecialchars($user['username']); ?></span>
            </div>
        </header>

        <section class="hud">
            <div class="hud-item"><span>Score</span><strong id="score">0</strong></div>
            <div class="hud-item"><span>XP Available</span><strong id="xpAvailable"><?php echo (int)$xpAvailable; ?></strong></div>
            <div class="hud-item"><span>Timer</span><strong id="timer">00:00</strong></div>
            <div class="hud-item full">
                <span>Difficulty & Tags</span>
                <strong>
                    <span class="difficulty-pill"><?php echo htmlspecialchars($challenge['difficulty']); ?></span>
                    <?php foreach ($tags as $tag): ?>
                        <span class="concept-pill"><?php echo htmlspecialchars($tag); ?></span>
                    <?php endforeach; ?>
                </strong>
            </div>
        </section>

        <section class="challenge-card">
            <h3>📋 The Backstory</h3>
            <p><?php echo nl2br(htmlspecialchars($challenge['backstory'])); ?></p>
            <p class="warning-text">⚠️ Real-world consequence: <?php echo htmlspecialchars($challenge['real_world_consequence']); ?></p>
        </section>

        <section class="challenge-card">
            <div class="editor-label">
                <h3>🐛 Broken Code — find the bug and fix it</h3>
                <span class="lang-pill"><?php echo htmlspecialchars($challenge['language']); ?></span>
            </div>
            <div class="editor-shell">
                <div class="line-numbers" id="lineNumbers">1</div>
                <textarea id="code-editor" spellcheck="false"><?php echo htmlspecialchars($challenge['broken_code']); ?></textarea>
            </div>
            <div class="action-bar">
                <button id="submitFixBtn" class="play-again">Submit Fix</button>
                <button id="startOverBtn" class="ghost-btn">Start Over</button>
                <a class="skip-link" href="bug-hunt.php">Skip Challenge</a>
            </div>
        </section>

        <?php if ($debugMode): ?>
        <section class="debug-panel">
            <h3>🛠 Cerebras Debug Panel</h3>
            <p style="margin:0 0 0.7rem;color:var(--bh-muted);">Showing request/response diagnostics for AI endpoints.</p>
            <div class="debug-log" id="debugLog"></div>
        </section>
        <?php endif; ?>
    </div>

    <div class="mentor-hint" id="mentorHint">
        <div class="hint-label">💡 Mentor hint:</div>
        <div class="hint-text" id="mentorHintText"></div>
        <button id="dismissHintBtn" class="ghost-btn">Got it</button>
    </div>

    <div class="result-modal" id="obituaryModal" hidden>
        <div class="result-card">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:0.6rem;">
                <h2>📜 Code Obituary</h2>
                <button id="closeObitBtn" class="ghost-btn"><i class="fas fa-xmark"></i></button>
            </div>

            <div class="score-banner">
                <div>
                    <div class="section-title">Final Score</div>
                    <div class="score-main" id="obitScore">0</div>
                </div>
                <div>
                    <div class="section-title">XP Awarded</div>
                    <div class="score-main" id="obitXp">0</div>
                </div>
            </div>

            <div class="section-title">The Roast</div>
            <p id="obitRoast" style="font-style: italic;"></p>

            <div class="result-grid" style="margin-top:0.2rem;">
                <div>
                    <span>Time Complexity</span>
                    <strong id="obitTimeComplexity"></strong>
                </div>
                <div>
                    <span>Space Complexity</span>
                    <strong id="obitSpaceComplexity"></strong>
                </div>
            </div>

            <div class="section-title">Edge Cases Missed</div>
            <div id="obitEdgeCases"></div>

            <div class="section-title">Cleaner Alternative</div>
            <pre class="obit-code" id="obitAlternative"></pre>

            <div class="section-title">Senior Dev Says:</div>
            <blockquote id="obitSeniorComment" style="margin:0.2rem 0 0.8rem;border-left:3px solid rgba(255,255,255,0.2);padding-left:0.7rem;"></blockquote>

            <div class="result-actions">
                <button id="playAgainBtn" class="play-again">Play Again</button>
                <button class="ghost-btn" onclick="window.location.href='game-selection.php'">Back to Game Selection</button>
            </div>
        </div>
    </div>

    <!-- RECOMMENDATION CARD -->
    <div id="recommendation-card"
        style="display: none;
        position: fixed;
        bottom: 24px;
        right: 24px;
        width: 320px;
        z-index: 10000;
        transform: translateY(20px);
        opacity: 0;
        transition: transform 0.3s ease, opacity 0.3s ease;">
        
        <div class="challenge-card"
            style="border: 1px solid rgba(88, 225, 255, 0.4);
            box-shadow: 0 8px 32px rgba(0,0,0,0.4);
            padding: 16px;">
            
            <!-- Header -->
            <div style="display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;">
                <div style="font-size: 0.75rem;
                font-weight: bold;
                color: rgba(88, 225, 255, 0.8);
                text-transform: uppercase;
                letter-spacing: 1px;">
                    🧠 Try This Next
                </div>
                <button id="rec-dismiss-btn"
                    style="background: none;
                    border: none;
                    cursor: pointer;
                    color: rgba(184, 194, 235, 0.6);
                    font-size: 1rem;
                    line-height: 1;
                    padding: 0;">
                    ×
                </button>
            </div>
            
            <!-- Challenge title -->
            <div id="rec-title"
                style="font-weight: bold;
                font-size: 1rem;
                margin-bottom: 6px;">
            </div>
            
            <!-- Difficulty badge -->
            <div id="rec-difficulty"
                class="difficulty-pill"
                style="display: inline-block;
                margin-bottom: 10px;
                font-size: 0.7rem;">
            </div>
            
            <!-- AI reason -->
            <div id="rec-reason"
                style="font-size: 0.82rem;
                color: rgba(184, 194, 235, 0.8);
                line-height: 1.5;
                margin-bottom: 14px;
                font-style: italic;">
            </div>
            
            <!-- Concept tags -->
            <div id="rec-concepts"
                style="display: flex;
                flex-wrap: wrap;
                gap: 6px;
                margin-bottom: 14px;">
            </div>
            
            <!-- CTA Button -->
            <a id="rec-play-btn"
                href="#"
                class="play-again"
                style="display: block;
                text-align: center;
                text-decoration: none;
                width: 100%;
                box-sizing: border-box;">
                Play This Next →
            </a>
            
            <!-- Skill level update -->
            <div id="rec-skill-update"
                style="text-align: center;
                margin-top: 8px;
                font-size: 0.75rem;
                color: rgba(184, 194, 235, 0.6);">
            </div>
            
        </div>
    </div>

    <script>
        // ════════════════════════════════════════
        // FILE: bug-hunt.php
        // CHANGE: Add smarter debounce gates, game_type param, hint specificity label
        // LINES CHANGED: Added tracking variables, shouldFireIntentApi function, updated event listener, added game_type, updated hint HTML
        // LINES PRESERVED: debounce function, all existing event handlers, obituary modal, UI rendering
        // ════════════════════════════════════════

        const BUG_HUNT_CONFIG = {
            challengeId: <?php echo (int)$challenge['id']; ?>,
            language: <?php echo json_encode((string)$challenge['language']); ?>,
            originalCode: <?php echo json_encode((string)$challenge['broken_code']); ?>,
            userId: <?php echo (int)$user_id; ?>,
            debugMode: <?php echo $debugMode ? 'true' : 'false'; ?>
        };

        // ── SMART DEBOUNCE TRACKING ────────────
        let lastSentCode = '';
        let lastHintTime = 0;
        const minimumCharacterDelta = 3;
        const minimumTimeBetweenCalls = 10000;

        const codeEditor = document.getElementById('code-editor');
        const lineNumbers = document.getElementById('lineNumbers');
        const timerEl = document.getElementById('timer');
        const scoreEl = document.getElementById('score');

        const submitFixBtn = document.getElementById('submitFixBtn');
        const startOverBtn = document.getElementById('startOverBtn');
        const playAgainBtn = document.getElementById('playAgainBtn');

        const mentorHint = document.getElementById('mentorHint');
        const mentorHintText = document.getElementById('mentorHintText');
        const dismissHintBtn = document.getElementById('dismissHintBtn');

        const obituaryModal = document.getElementById('obituaryModal');
        const closeObitBtn = document.getElementById('closeObitBtn');
        const debugLogEl = document.getElementById('debugLog');

        let elapsedSeconds = 0;
        let hintHideTimer = null;
        let submitting = false;

        function appendDebugLog(message) {
            if (!BUG_HUNT_CONFIG.debugMode || !debugLogEl) {
                return;
            }

            const now = new Date();
            const ts = now.toLocaleTimeString();
            debugLogEl.textContent += `[${ts}] ${message}\n`;
            debugLogEl.scrollTop = debugLogEl.scrollHeight;
        }

        async function fetchJsonWithDiagnostics(url, bodyPayload) {
            appendDebugLog(`→ POST ${url}`);
            appendDebugLog(`Payload: ${JSON.stringify(bodyPayload)}`);

            const res = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(bodyPayload)
            });

            const text = await res.text();
            appendDebugLog(`← ${url} HTTP ${res.status}`);
            appendDebugLog(`Raw response: ${text.slice(0, 1000)}`);

            if (!res.ok) {
                throw new Error(`${url} returned HTTP ${res.status}. Body: ${text.slice(0, 300)}`);
            }

            let data;
            try {
                data = JSON.parse(text);
            } catch (parseError) {
                throw new Error(`${url} returned invalid JSON: ${parseError.message}. Body: ${text.slice(0, 300)}`);
            }

            return data;
        }

        function formatTime(seconds) {
            const mm = String(Math.floor(seconds / 60)).padStart(2, '0');
            const ss = String(seconds % 60).padStart(2, '0');
            return `${mm}:${ss}`;
        }

        setInterval(() => {
            elapsedSeconds += 1;
            timerEl.textContent = formatTime(elapsedSeconds);
        }, 1000);

        function renderLineNumbers() {
            const lines = codeEditor.value.split('\n').length;
            let nums = '';
            for (let i = 1; i <= lines; i += 1) {
                nums += i + (i < lines ? '\n' : '');
            }
            lineNumbers.textContent = nums;
        }

        function debounce(fn, wait = 700) {
            let timeout;
            return (...args) => {
                clearTimeout(timeout);
                timeout = setTimeout(() => fn(...args), wait);
            };
        }

        function showHint(message) {
            mentorHintText.innerHTML = '';
            
            // Add specificity label
            const labelDiv = document.createElement('div');
            labelDiv.style.cssText = 'font-size:0.75rem;color:rgba(255,255,255,0.7);margin-bottom:0.35rem;font-weight:600;';
            labelDiv.textContent = 'Based on what you wrote';
            mentorHintText.appendChild(labelDiv);
            
            // Add hint text
            const hintDiv = document.createElement('div');
            hintDiv.textContent = message;
            mentorHintText.appendChild(hintDiv);
            
            mentorHint.classList.add('show');

            if (hintHideTimer) {
                clearTimeout(hintHideTimer);
            }

            hintHideTimer = setTimeout(() => {
                mentorHint.classList.remove('show');
            }, 15000);
        }

        function hideHint() {
            mentorHint.classList.remove('show');
            if (hintHideTimer) {
                clearTimeout(hintHideTimer);
                hintHideTimer = null;
            }
        }

        // ── SMART INTENT CHECK GATE ────────────
        function shouldFireIntentApi(currentCode) {
            // GATE 1: minimum time between calls
            const now = Date.now();
            if (now - lastHintTime < minimumTimeBetweenCalls) {
                return false;
            }

            // GATE 2: meaningful change in code
            const currentTrimmed = currentCode.trim();
            const lastTrimmed = lastSentCode.trim();
            
            if (currentTrimmed === lastTrimmed) {
                return false;
            }
            
            const lengthDiff = Math.abs(
                currentTrimmed.length - lastTrimmed.length
            );
            
            const contentChanged = 
                currentTrimmed !== lastTrimmed;
            
            if (lengthDiff < minimumCharacterDelta 
                && contentChanged) {
                return false;
            }

            // GATE 3: minimum code length
            const noWhitespace = currentTrimmed
                .replace(/\s+/g, '');
            if (noWhitespace.length < 10) {
                return false;
            }

            // GATE 4: code must have changed from original
            const originalCode = 
                BUG_HUNT_CONFIG.originalCode || '';
            if (currentTrimmed === originalCode.trim()) {
                return false;
            }

            return true;
        }

        async function checkIntent() {
            const current = codeEditor.value;
            if (!current.trim()) {
                return;
            }

            // Apply gates before calling API
            if (!shouldFireIntentApi(current)) {
                return;
            }

            try {
                const data = await fetchJsonWithDiagnostics('intent_api.php', {
                        partial_code: current,
                        language: BUG_HUNT_CONFIG.language,
                        challenge_id: BUG_HUNT_CONFIG.challengeId,
                        game_type: 'bug_hunt',
                        session_token: 'bug_hunt_session'
                });
                
                // Update tracking after successful call
                lastSentCode = current.trim();
                lastHintTime = Date.now();
                
                if (data && data.should_show === true && data.hint) {
                    showHint(data.hint);
                }
            } catch (error) {
                appendDebugLog(`intent_api.php error: ${error.message}`);
                console.error('intent_api.php failed:', error);
            }
        }

        const debouncedIntentCheck = debounce(checkIntent, 900);

        codeEditor.addEventListener('input', () => {
            renderLineNumbers();
            debouncedIntentCheck();
        });

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

        dismissHintBtn.addEventListener('click', hideHint);

        function setSubmitLoading(isLoading) {
            submitting = isLoading;
            submitFixBtn.disabled = isLoading;
            if (isLoading) {
                submitFixBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
            } else {
                submitFixBtn.innerHTML = 'Submit Fix';
            }
        }

        function escapeHtml(str) {
            return str
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        }

        function openObituaryModal(payload) {
            document.getElementById('obitScore').textContent = String(payload.score ?? 0);
            document.getElementById('obitXp').textContent = String(payload.xp_awarded ?? 0);
            document.getElementById('obitRoast').textContent = payload.roast ?? '';
            document.getElementById('obitTimeComplexity').textContent = payload.time_complexity ?? '';
            document.getElementById('obitSpaceComplexity').textContent = payload.space_complexity ?? '';
            document.getElementById('obitAlternative').textContent = payload.cleaner_alternative ?? '';
            document.getElementById('obitSeniorComment').textContent = payload.senior_dev_comment ?? '';

            scoreEl.textContent = String(payload.score ?? 0);

            const edgeWrap = document.getElementById('obitEdgeCases');
            const edgeCases = Array.isArray(payload.edge_cases_missed) ? payload.edge_cases_missed : [];

            if (edgeCases.length === 0) {
                edgeWrap.innerHTML = '<p class="edge-good">✅ You nailed all edge cases!</p>';
            } else {
                edgeWrap.innerHTML = '<ul>' + edgeCases.map(item => `<li class="edge-warning">${escapeHtml(String(item))}</li>`).join('') + '</ul>';
            }

            // Store obituary data for recommendation fetch
            lastObituaryData = payload;
            obituaryModal.hidden = false;
        }

        function closeObituaryModal() {
            obituaryModal.hidden = true;
            
            // Trigger recommendation fetch after modal closes
            if (lastObituaryData) {
                fetchRecommendation(
                    BUG_HUNT_CONFIG.challengeId,
                    'bug_hunt',
                    lastObituaryData.score || 0,
                    lastObituaryData.hints_used || 0,
                    elapsedSeconds,
                    lastObituaryData.difficulty || 'beginner'
                ).then(data => {
                    showRecommendationCard(data);
                });
                lastObituaryData = null;
            }
        }

        submitFixBtn.addEventListener('click', async () => {
            if (submitting) {
                return;
            }

            setSubmitLoading(true);
            hideHint();

            try {
                const data = await fetchJsonWithDiagnostics('obituary.php', {
                        submitted_code: codeEditor.value,
                        challenge_id: BUG_HUNT_CONFIG.challengeId,
                        time_taken: elapsedSeconds,
                        language: BUG_HUNT_CONFIG.language,
                        user_id: BUG_HUNT_CONFIG.userId
                });
                openObituaryModal(data || {});
            } catch (error) {
                appendDebugLog(`obituary.php error: ${error.message}`);
                console.error('obituary.php failed:', error);
                openObituaryModal({
                    roast: "Our AI mentor is taking a coffee break. Here's what we know: your code was submitted.",
                    time_complexity: 'Unable to analyze right now.',
                    space_complexity: 'Unable to analyze right now.',
                    edge_cases_missed: [],
                    cleaner_alternative: '',
                    senior_dev_comment: 'Ship it. (Just kidding.)',
                    score: 500,
                    xp_awarded: 50
                });
            } finally {
                setSubmitLoading(false);
            }
        });

        startOverBtn.addEventListener('click', () => {
            codeEditor.value = BUG_HUNT_CONFIG.originalCode;
            renderLineNumbers();
            hideHint();
            codeEditor.focus();
        });

        playAgainBtn.addEventListener('click', () => {
            window.location.href = 'bug-hunt.php';
        });

        closeObitBtn.addEventListener('click', closeObituaryModal);

        // ── RECOMMENDATION SYSTEM ─────────────
        let lastObituaryData = null;

        async function fetchRecommendation(
            challengeId,
            gameType,
            score,
            hintsUsed,
            timeTaken,
            difficulty
        ) {
            try {
                const response = await fetch('recommend_api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        challenge_id: challengeId,
                        game_type: gameType,
                        score: score,
                        hints_used: hintsUsed,
                        time_taken: timeTaken,
                        difficulty: difficulty
                    })
                });
                
                if (!response.ok) {
                    appendDebugLog(`recommend_api.php HTTP ${response.status}`);
                    return null;
                }
                
                const text = await response.text();
                if (!text.trim()) {
                    appendDebugLog('recommend_api.php returned empty');
                    return null;
                }
                
                try {
                    return JSON.parse(text);
                } catch (e) {
                    appendDebugLog('Recommendation parse error: ' + text.slice(0, 200));
                    return null;
                }
            } catch (err) {
                appendDebugLog('Recommendation fetch error: ' + err.message);
                return null;
            }
        }

        function showRecommendationCard(data) {
            if (!data || !data.success) {
                return;
            }
            
            const rec = data.recommendation;
            const skillUpdate = data.skill_update;
            const card = document.getElementById('recommendation-card');
            
            if (!card || !rec) {
                return;
            }
            
            // Populate title
            const titleEl = document.getElementById('rec-title');
            if (titleEl) {
                titleEl.textContent = rec.title;
            }
            
            // Populate difficulty badge
            const diffEl = document.getElementById('rec-difficulty');
            if (diffEl) {
                diffEl.textContent = rec.difficulty;
                const diffColors = {
                    beginner: '#67e59f',
                    intermediate: '#ffcc70',
                    advanced: '#ff6b6b'
                };
                diffEl.style.color = diffColors[rec.difficulty] || '#58e1ff';
            }
            
            // Populate AI reason
            const reasonEl = document.getElementById('rec-reason');
            if (reasonEl) {
                reasonEl.textContent = '"' + rec.reason + '"';
            }
            
            // Populate concept tags
            const conceptsEl = document.getElementById('rec-concepts');
            if (conceptsEl && rec.concept_tags) {
                conceptsEl.innerHTML = '';
                const tags = rec.concept_tags.split(',');
                tags.forEach(tag => {
                    tag = tag.trim();
                    if (!tag) return;
                    const pill = document.createElement('span');
                    pill.className = 'concept-pill';
                    pill.textContent = tag;
                    pill.style.fontSize = '0.7rem';
                    conceptsEl.appendChild(pill);
                });
            }
            
            // Set play button href
            const playBtn = document.getElementById('rec-play-btn');
            if (playBtn) {
                playBtn.href = rec.game_url;
            }
            
            // Show skill level change
            const skillEl = document.getElementById('rec-skill-update');
            if (skillEl && skillUpdate) {
                const diff = skillUpdate.new_level - skillUpdate.old_level;
                const sign = diff >= 0 ? '+' : '';
                const color = diff >= 0 ? '#67e59f' : '#ff6b6b';
                skillEl.innerHTML = 
                    'Skill level: <span style="color:' + color + '">' +
                    sign + diff.toFixed(1) +
                    '</span> → ' +
                    skillUpdate.new_level.toFixed(1) + '/100';
            }
            
            // Show card with animation
            card.style.display = 'block';
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    card.style.transform = 'translateY(0)';
                    card.style.opacity = '1';
                });
            });
            
            // Auto dismiss after 30 seconds
            setTimeout(() => {
                dismissRecommendation();
            }, 30000);
        }

        function dismissRecommendation() {
            const card = document.getElementById('recommendation-card');
            if (!card) {
                return;
            }
            card.style.transform = 'translateY(20px)';
            card.style.opacity = '0';
            setTimeout(() => {
                card.style.display = 'none';
            }, 300);
        }

        // Dismiss button
        document.addEventListener('DOMContentLoaded', function() {
            const dismissBtn = document.getElementById('rec-dismiss-btn');
            if (dismissBtn) {
                dismissBtn.addEventListener('click', dismissRecommendation);
            }
        });

        renderLineNumbers();
    </script>
</body>
</html>
