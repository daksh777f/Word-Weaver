<?php
// ════════════════════════════════════════
// FILE: live-coding.php
// PURPOSE: Run Live Coding Arena with manual hints, AI obituary, and concept graph visualization.
// ANALYSES USED: play/bug-hunt.php, play/daily-sprint.php, play/intent_api.php, play/obituary.php, onboarding/config.php, styles.css
// NEW TABLES USED: live_coding_challenges, arena_sessions, concept_connections
// DEPENDS ON: onboarding/config.php, play/intent_api.php, play/obituary.php, play/graph_api.php
// CEREBRAS CALLS: yes
// CANVAS RENDERING: yes
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

$tagFilter = strtolower(trim((string)($_GET['tag'] ?? '')));

$languageStmt = $pdo->query("SELECT DISTINCT LOWER(language) AS language FROM live_coding_challenges WHERE language IS NOT NULL AND language <> '' ORDER BY language ASC");
$availableLanguages = array_values(array_filter(array_map(static function ($row) {
    return strtolower(trim((string)($row['language'] ?? '')));
}, $languageStmt->fetchAll(PDO::FETCH_ASSOC))));

$selectedLanguage = strtolower(trim((string)($_GET['language'] ?? '')));
if ($selectedLanguage !== '' && !in_array($selectedLanguage, $availableLanguages, true)) {
    $selectedLanguage = '';
}

$challenge = null;

$where = [];
$params = [];
if ($tagFilter !== '') {
    $where[] = 'concept_tags LIKE ?';
    $params[] = '%' . $tagFilter . '%';
}
if ($selectedLanguage !== '') {
    $where[] = 'LOWER(language) = ?';
    $params[] = $selectedLanguage;
}

$sql = 'SELECT * FROM live_coding_challenges';
if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY RAND() LIMIT 1';

$challengeStmt = $pdo->prepare($sql);
$challengeStmt->execute($params);
$challenge = $challengeStmt->fetch(PDO::FETCH_ASSOC);

if (!$challenge && $tagFilter !== '' && $selectedLanguage !== '') {
    $challengeStmt = $pdo->prepare('SELECT * FROM live_coding_challenges WHERE LOWER(language) = ? ORDER BY RAND() LIMIT 1');
    $challengeStmt->execute([$selectedLanguage]);
    $challenge = $challengeStmt->fetch(PDO::FETCH_ASSOC);
}

if (!$challenge && $selectedLanguage !== '') {
    header('Location: live_coding_seed.php?message=' . urlencode('No Live Coding challenges found for language ' . strtoupper($selectedLanguage) . '. Add more seeded challenges.'));
    exit();
}

if (!$challenge && $tagFilter !== '') {
    $challengeStmt = $pdo->prepare('SELECT * FROM live_coding_challenges WHERE concept_tags LIKE ? ORDER BY RAND() LIMIT 1');
    $challengeStmt->execute(['%' . $tagFilter . '%']);
    $challenge = $challengeStmt->fetch(PDO::FETCH_ASSOC);
}

if (!$challenge) {
    $challengeStmt = $pdo->query("SELECT * FROM live_coding_challenges ORDER BY RAND() LIMIT 1");
    $challenge = $challengeStmt->fetch(PDO::FETCH_ASSOC);
}

if (!$challenge) {
    header('Location: live_coding_seed.php?message=' . urlencode('No live coding challenges found. Seed data first.'));
    exit();
}

$_SESSION['current_challenge_id'] = (int)$challenge['id'];
$_SESSION['current_live_coding_challenge_id'] = (int)$challenge['id'];

$difficulty = strtolower((string)$challenge['difficulty']);
$xpByDifficulty = [
    'beginner' => 80,
    'intermediate' => 100,
    'advanced' => 120,
];
$xpAvailable = $xpByDifficulty[$difficulty] ?? 100;

$tags = array_values(array_filter(array_map('trim', explode(',', (string)$challenge['concept_tags']))));
$testCases = json_decode((string)$challenge['test_cases'], true);
$firstTestCase = (is_array($testCases) && isset($testCases[0]) && is_array($testCases[0])) ? $testCases[0] : ['input' => [], 'expected_output' => null];

$graphStmt = $pdo->prepare('SELECT concept_name, times_encountered, times_solved, last_seen FROM concept_graph WHERE user_id = ? ORDER BY times_encountered DESC');
$graphStmt->execute([$user_id]);
$graphRows = $graphStmt->fetchAll(PDO::FETCH_ASSOC);

$userConceptSet = [];
$graphNodes = [];
foreach ($graphRows as $row) {
    $conceptName = strtolower((string)$row['concept_name']);
    $encountered = max(0, (int)($row['times_encountered'] ?? 0));
    $solved = max(0, (int)($row['times_solved'] ?? 0));
    $mastery = $encountered === 0 ? 0.0 : round($solved / $encountered, 2);
    $size = min(40, 12 + ($encountered * 2));

    $graphNodes[] = [
        'id' => $conceptName,
        'label' => ucwords(str_replace('-', ' ', $conceptName)),
        'times_encountered' => $encountered,
        'times_solved' => $solved,
        'mastery' => $mastery,
        'last_seen' => gmdate('c', strtotime((string)$row['last_seen'])),
        'size' => $size,
    ];

    $userConceptSet[$conceptName] = true;
}

$graphEdges = [];
$edgeSeen = [];
if (count($graphNodes) > 0) {
    $connectionsStmt = $pdo->prepare(
        'SELECT concept_to AS concept, connection_strength FROM concept_connections WHERE concept_from = ?
         UNION
         SELECT concept_from AS concept, connection_strength FROM concept_connections WHERE concept_to = ?'
    );

    foreach (array_keys($userConceptSet) as $conceptName) {
        $connectionsStmt->execute([$conceptName, $conceptName]);
        $rows = $connectionsStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $other = strtolower(trim((string)($row['concept'] ?? '')));
            if ($other === '' || !isset($userConceptSet[$other]) || $other === $conceptName) {
                continue;
            }

            $pair = [$conceptName, $other];
            sort($pair);
            $key = $pair[0] . '::' . $pair[1];
            if (isset($edgeSeen[$key])) {
                continue;
            }

            $edgeSeen[$key] = true;
            $graphEdges[] = [
                'from' => $conceptName,
                'to' => $other,
                'strength' => max(1, min(3, (int)($row['connection_strength'] ?? 1))),
            ];
        }
    }
}

$graph_data = [
    'nodes' => $graphNodes,
    'edges' => $graphEdges,
    'total_concepts' => count($graphNodes),
];

$challengeObj = (object)$challenge;
$hintsDecoded = json_decode((string)$challengeObj->hints, true);
if (!is_array($hintsDecoded)) {
    $hintsDecoded = [];
}

$arenaSessionId = 0;
try {
    $sessionInsert = $pdo->prepare('INSERT INTO arena_sessions (user_id, challenge_id, completed) VALUES (?, ?, 0)');
    $sessionInsert->execute([$user_id, (int)$challenge['id']]);
    $arenaSessionId = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    $arenaSessionId = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include '../includes/favicon.php'; ?>
    <title>Live Coding Arena</title>
    <link rel="stylesheet" href="../styles.css?v=<?php echo filemtime('../styles.css'); ?>">
    <link rel="stylesheet" href="../MainGame/grammarheroes/game.css?v=<?php echo filemtime('../MainGame/grammarheroes/game.css'); ?>">
    <link rel="stylesheet" href="../notif/toast.css?v=<?php echo filemtime('../notif/toast.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        :root {
            --primary: #58e1ff;
            --bg: #0b1230;
            --card: rgba(13, 22, 51, 0.86);
            --text: #f3f6ff;
            --muted-color: #b8c2eb;
            --warning-color: #ffcc70;
            --success-color: #67e59f;
            --danger-color: #ff6c88;
            --border-color: rgba(255, 255, 255, 0.16);
            --darkest-bg: #060b1a;
            --card-radius: 12px;
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
            border: 1px solid var(--border-color);
            margin-right: 0.4rem;
            margin-bottom: 0.4rem;
            text-transform: capitalize;
        }

        .difficulty-pill { background: rgba(255, 204, 112, 0.2); color: var(--warning-color); }
        .concept-pill { background: rgba(88, 225, 255, 0.15); color: var(--primary); }
        .lang-pill { background: rgba(103, 229, 159, 0.15); color: var(--success-color); text-transform: uppercase; }

        .challenge-card {
            background: var(--card);
            border: 1px solid var(--border-color);
            border-radius: var(--card-radius);
            padding: 1rem;
            margin-bottom: 0.8rem;
        }

        .challenge-card h3 {
            margin-bottom: 0.5rem;
        }

        .challenge-io-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.8rem;
            margin-top: 0.8rem;
        }

        .challenge-io-code {
            display: inline-block;
            background: var(--darkest-bg);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: var(--card-radius);
            padding: 4px 8px;
            font-family: 'Courier New', Courier, monospace;
            font-size: 0.85rem;
            color: var(--text);
            word-break: break-word;
            margin-top: 0.3rem;
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
            border: 1px solid var(--border-color);
            border-radius: 12px;
            overflow: hidden;
            background: var(--darkest-bg);
            min-height: 300px;
        }

        .line-numbers {
            background: var(--darkest-bg);
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

        #lc-code-editor {
            width: 100%;
            min-height: 300px;
            border: 0;
            outline: none;
            resize: vertical;
            background: var(--darkest-bg);
            color: var(--text);
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

        .lc-penalty-text {
            margin-top: 0.45rem;
            color: var(--muted-color);
            font-size: 0.83rem;
        }

        .mentor-hint {
            position: fixed;
            right: 16px;
            bottom: 16px;
            width: min(360px, calc(100vw - 24px));
            background: rgba(13, 22, 51, 0.96);
            border: 1px solid var(--border-color);
            border-left: 4px solid var(--warning-color);
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
            color: var(--warning-color);
            margin-bottom: 0.35rem;
            font-weight: 700;
        }

        .hint-text {
            margin-bottom: 0.35rem;
        }

        .hint-penalty {
            color: var(--danger-color);
            font-size: 0.78rem;
            margin-bottom: 0.6rem;
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
            color: var(--primary);
        }

        .section-title {
            margin: 0.6rem 0 0.4rem;
            color: var(--muted-color);
            font-size: 0.92rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .obit-code {
            background: var(--darkest-bg);
            border-radius: 10px;
            border: 1px solid var(--border-color);
            padding: 0.8rem;
            white-space: pre-wrap;
            font-family: 'Courier New', Courier, monospace;
            max-height: 220px;
            overflow: auto;
        }

        .edge-good { color: var(--success-color); font-weight: 700; }
        .edge-warning { color: var(--warning-color); }

        .concepts-unlocked {
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem;
            margin-top: 0.35rem;
        }

        .concept-badge-new {
            background: rgba(103, 229, 159, 0.2);
            color: var(--success-color);
            border-color: rgba(103, 229, 159, 0.45);
        }

        .concept-badge-reinforced {
            background: rgba(184, 194, 235, 0.18);
            color: var(--muted-color);
        }

        .graph-panel-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.8rem;
            margin-bottom: 0.2rem;
        }

        .graph-panel-subtext {
            color: var(--muted-color);
            font-size: 0.86rem;
            margin-bottom: 0.75rem;
        }

        .graph-empty {
            border: 1px dashed var(--border-color);
            border-radius: var(--card-radius);
            padding: 1rem;
            color: var(--muted-color);
            background: rgba(255, 255, 255, 0.02);
            text-align: center;
        }

        #concept-graph-canvas {
            width: 100%;
            height: 400px;
            border-radius: var(--card-radius);
            background: var(--darkest-bg);
            cursor: pointer;
        }

        .graph-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 0.9rem;
            margin-top: 0.65rem;
            color: var(--muted-color);
            font-size: 0.78rem;
        }

        .graph-legend-dot {
            width: 8px;
            height: 8px;
            border-radius: 999px;
            display: inline-block;
            margin-right: 0.35rem;
            transform: translateY(1px);
        }

        #graph-node-detail {
            margin-top: 0.7rem;
            max-height: 0;
            opacity: 0;
            overflow: hidden;
            transition: max-height 300ms ease, opacity 300ms ease;
        }

        #graph-node-detail.open {
            max-height: 220px;
            opacity: 1;
        }

        .graph-detail-card {
            border: 1px solid var(--border-color);
            border-radius: var(--card-radius);
            background: rgba(255,255,255,0.03);
            padding: 0.75rem;
        }

        .graph-detail-progress {
            width: 100%;
            height: 8px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.12);
            overflow: hidden;
            margin: 0.45rem 0 0.55rem;
        }

        .graph-detail-progress-fill {
            height: 100%;
            background: var(--primary);
            width: 0%;
            transition: width 250ms ease;
        }

        .lc-hint-btn-pulse {
            animation: hintPulse 2s ease-in-out infinite;
        }

        @keyframes hintPulse {
            0%, 100% {
                border-color: var(--primary);
                box-shadow: none;
            }
            50% {
                border-color: var(--warning-color);
                box-shadow: 0 0 8px var(--warning-color);
            }
        }

        @media (max-width: 680px) {
            .score-banner { flex-direction: column; align-items: flex-start; }
            .challenge-io-row { grid-template-columns: 1fr; }
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
                <h1>Live Coding Arena</h1>
                <p>Write it from scratch. Your mentor is watching.</p>
            </div>
            <div class="profile-pill">
                <img src="<?php echo !empty($user['profile_image']) ? '../' . htmlspecialchars($user['profile_image']) : '../assets/menu/defaultuser.png'; ?>" alt="Profile">
                <span><?php echo htmlspecialchars($user['username']); ?></span>
            </div>
        </header>

        <section class="hud">
            <div class="hud-item"><span>Score</span><strong id="lc-score">0</strong></div>
            <div class="hud-item"><span>XP Available</span><strong id="lc-xpAvailable"><?php echo (int)$xpAvailable; ?></strong></div>
            <div class="hud-item"><span>Timer</span><strong id="lc-timer">00:00</strong></div>
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
            <h3>The Challenge</h3>
            <p><?php echo nl2br(htmlspecialchars((string)$challenge['description'])); ?></p>
            <p class="warning-text">Context: <?php echo htmlspecialchars((string)$challenge['backstory']); ?></p>
            <div class="challenge-io-row">
                <div>
                    <strong>Input:</strong>
                    <div class="challenge-io-code"><?php echo htmlspecialchars(json_encode($firstTestCase['input'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></div>
                </div>
                <div>
                    <strong>Expected Output:</strong>
                    <div class="challenge-io-code"><?php echo htmlspecialchars(json_encode($firstTestCase['expected_output'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></div>
                </div>
            </div>
        </section>

        <section class="challenge-card">
            <div class="editor-label">
                <h3>Your Solution — complete the implementation</h3>
                <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;justify-content:flex-end;">
                    <form method="GET" action="live-coding.php" style="display:flex;align-items:center;gap:0.35rem;">
                        <?php if ($tagFilter !== ''): ?>
                            <input type="hidden" name="tag" value="<?php echo htmlspecialchars($tagFilter); ?>">
                        <?php endif; ?>
                        <label for="liveCodingLanguage" style="font-size:0.72rem;color:var(--muted-color);font-weight:700;">Language</label>
                        <select id="liveCodingLanguage" name="language" onchange="this.form.submit()" style="background:#060b1a;color:var(--text);border:1px solid var(--border-color);border-radius:8px;padding:0.28rem 0.5rem;font-size:0.72rem;">
                            <option value="" <?php echo $selectedLanguage === '' ? 'selected' : ''; ?>>All</option>
                            <?php foreach ($availableLanguages as $lang): ?>
                                <option value="<?php echo htmlspecialchars($lang); ?>" <?php echo $selectedLanguage === $lang ? 'selected' : ''; ?>><?php echo htmlspecialchars(strtoupper($lang)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <span class="lang-pill"><?php echo htmlspecialchars((string)$challenge['language']); ?></span>
                </div>
            </div>
            <div class="editor-shell">
                <div class="line-numbers" id="lc-lineNumbers">1</div>
                <textarea id="lc-code-editor" spellcheck="false"><?php echo htmlspecialchars((string)$challenge['starter_code']); ?></textarea>
            </div>
            <div class="action-bar">
                <button id="lc-hintBtn" class="ghost-btn">Hint (3 remaining)</button>
                <button id="lc-submitBtn" class="play-again">Submit Solution</button>
                <button id="lc-resetBtn" class="ghost-btn">Reset</button>
            </div>
            <div class="lc-penalty-text" id="lc-penaltyText">Score penalty so far: -0pts</div>
        </section>

        <section class="challenge-card">
            <div class="graph-panel-head">
                <h3>Your Knowledge Graph</h3>
                <strong id="graphConceptCount"><?php echo count($graphNodes); ?> concepts unlocked</strong>
            </div>
            <div class="graph-panel-subtext">Grows as you solve challenges. Click any node to explore.</div>
            <div id="graphEmptyState" class="graph-empty" <?php echo count($graphNodes) > 0 ? 'hidden' : ''; ?>>
                Complete your first challenge to start building your graph
            </div>
            <canvas id="concept-graph-canvas" width="800" height="400" <?php echo count($graphNodes) === 0 ? 'hidden' : ''; ?>></canvas>
            <div class="graph-legend">
                <span><span class="graph-legend-dot" style="background: var(--success-color);"></span>Mastered (&gt;75%)</span>
                <span><span class="graph-legend-dot" style="background: var(--warning-color);"></span>Learning (25-75%)</span>
                <span><span class="graph-legend-dot" style="background: var(--primary);"></span>New (&lt;25%)</span>
                <span><span class="graph-legend-dot" style="background: var(--muted-color);"></span>Not yet encountered</span>
            </div>
            <div id="graph-node-detail"></div>
        </section>
    </div>

    <div class="mentor-hint" id="lc-mentorHint">
        <div class="hint-label" id="lc-hintLabel">Mentor Hint [1/3]:</div>
        <div class="hint-text" id="lc-mentorHintText"></div>
        <div class="hint-penalty" id="lc-hintPenaltyText"></div>
        <button id="lc-dismissHintBtn" class="ghost-btn">Got it</button>
    </div>

    <div class="result-modal" id="lc-obituaryModal" hidden>
        <div class="result-card">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:0.6rem;">
                <h2>Code Obituary</h2>
                <button id="lc-closeObitBtn" class="ghost-btn"><i class="fas fa-xmark"></i></button>
            </div>

            <div class="score-banner">
                <div>
                    <div class="section-title">Final Score</div>
                    <div class="score-main" id="lc-obitScore">0</div>
                </div>
                <div>
                    <div class="section-title">XP Awarded</div>
                    <div class="score-main" id="lc-obitXp">0</div>
                </div>
            </div>

            <div class="section-title">The Roast</div>
            <p id="lc-obitRoast" style="font-style: italic;"></p>

            <div class="result-grid" style="margin-top:0.2rem;">
                <div>
                    <span>Time Complexity</span>
                    <strong id="lc-obitTimeComplexity"></strong>
                </div>
                <div>
                    <span>Space Complexity</span>
                    <strong id="lc-obitSpaceComplexity"></strong>
                </div>
            </div>

            <div class="section-title">Edge Cases Missed</div>
            <div id="lc-obitEdgeCases"></div>

            <div class="section-title">Cleaner Alternative</div>
            <pre class="obit-code" id="lc-obitAlternative"></pre>

            <div class="section-title">Concepts from this challenge:</div>
            <div class="concepts-unlocked" id="lc-conceptsUnlocked"></div>

            <div class="section-title">Senior Dev Says:</div>
            <blockquote id="lc-obitSeniorComment" style="margin:0.2rem 0 0.8rem;border-left:3px solid rgba(255,255,255,0.2);padding-left:0.7rem;"></blockquote>

            <div class="result-actions">
                <button id="lc-playAgainBtn" class="play-again">Play Again</button>
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
        const arenaChallenge = <?php echo json_encode($challengeObj); ?>;
        const arenaUserId = <?php echo (int)$_SESSION['user_id']; ?>;
        const initialGraphData = <?php echo json_encode($graph_data); ?>;
        const arenaHints = <?php echo json_encode($hintsDecoded); ?>;
        const arenaSessionId = <?php echo (int)$arenaSessionId; ?>;
        const LIVE_CODING_SELECTED_LANGUAGE = <?php echo json_encode((string)$selectedLanguage); ?>;
        const LIVE_CODING_TAG_FILTER = <?php echo json_encode((string)$tagFilter); ?>;

        const arenaState = {
            hintsUsed: 0,
            hintPenalty: 0,
            timerSeconds: 0,
            timerInterval: null,
            isSubmitting: false,
            intentDebounceTimer: null,
            lastHintTime: 0,
            sessionStartTime: Date.now(),
            lastSentCode: '',
            lastIntentTime: 0
        };

        // ── SMART DEBOUNCE TRACKING ────────────
        const minimumCharacterDelta = 3;
        const minimumTimeBetweenCalls = 10000;

        const lcCodeEditor = document.getElementById('lc-code-editor');
        const lcLineNumbers = document.getElementById('lc-lineNumbers');
        const lcTimer = document.getElementById('lc-timer');
        const lcScoreEl = document.getElementById('lc-score');

        const lcHintBtn = document.getElementById('lc-hintBtn');
        const lcSubmitBtn = document.getElementById('lc-submitBtn');
        const lcResetBtn = document.getElementById('lc-resetBtn');
        const lcPenaltyText = document.getElementById('lc-penaltyText');

        const lcMentorHint = document.getElementById('lc-mentorHint');
        const lcHintLabel = document.getElementById('lc-hintLabel');
        const lcMentorHintText = document.getElementById('lc-mentorHintText');
        const lcHintPenaltyText = document.getElementById('lc-hintPenaltyText');
        const lcDismissHintBtn = document.getElementById('lc-dismissHintBtn');

        const lcObituaryModal = document.getElementById('lc-obituaryModal');
        const lcCloseObitBtn = document.getElementById('lc-closeObitBtn');
        const lcPlayAgainBtn = document.getElementById('lc-playAgainBtn');

        const graphCanvas = document.getElementById('concept-graph-canvas');
        const graphEmptyState = document.getElementById('graphEmptyState');
        const graphConceptCount = document.getElementById('graphConceptCount');

        let hintHideTimer = null;
        const knownConcepts = new Set((initialGraphData.nodes || []).map((node) => String(node.id).toLowerCase()));

        function formatTime(seconds) {
            const mm = String(Math.floor(seconds / 60)).padStart(2, '0');
            const ss = String(seconds % 60).padStart(2, '0');
            return `${mm}:${ss}`;
        }

        arenaState.timerInterval = setInterval(() => {
            arenaState.timerSeconds += 1;
            lcTimer.textContent = formatTime(arenaState.timerSeconds);
        }, 1000);

        function renderLineNumbers() {
            const lines = lcCodeEditor.value.split('\n').length;
            let nums = '';
            for (let i = 1; i <= lines; i += 1) {
                nums += i + (i < lines ? '\n' : '');
            }
            lcLineNumbers.textContent = nums;
        }

        function debounce(fn, wait = 700) {
            let timeout;
            return (...args) => {
                clearTimeout(timeout);
                timeout = setTimeout(() => fn(...args), wait);
            };
        }

        function showHintBubble(text, hintIndex) {
            const penalties = [100, 150, 200];
            const penalty = penalties[hintIndex] || 0;
            lcHintLabel.textContent = `Mentor Hint [${hintIndex + 1}/3]:`;
            
            // Clear and rebuild hint text with specificity label
            lcMentorHintText.innerHTML = '';
            
            // Add specificity label
            const labelDiv = document.createElement('div');
            labelDiv.style.cssText = 'font-size:0.75rem;color:rgba(255,255,255,0.7);margin-bottom:0.35rem;font-weight:600;';
            labelDiv.textContent = 'Based on what you wrote';
            lcMentorHintText.appendChild(labelDiv);
            
            // Add hint text
            const hintDiv = document.createElement('div');
            hintDiv.textContent = text;
            lcMentorHintText.appendChild(hintDiv);
            
            lcHintPenaltyText.textContent = `(-${penalty} points)`;
            lcMentorHint.classList.add('show');

            if (hintHideTimer) {
                clearTimeout(hintHideTimer);
            }

            hintHideTimer = setTimeout(() => {
                lcMentorHint.classList.remove('show');
            }, 20000);
        }

        function hideHintBubble() {
            lcMentorHint.classList.remove('show');
            if (hintHideTimer) {
                clearTimeout(hintHideTimer);
                hintHideTimer = null;
            }
        }

        function setSubmitLoading(isLoading) {
            arenaState.isSubmitting = isLoading;
            lcSubmitBtn.disabled = isLoading;
            if (isLoading) {
                lcSubmitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
            } else {
                lcSubmitBtn.innerHTML = 'Submit Solution';
            }
        }

        function updateHintUi() {
            const remaining = Math.max(0, 3 - arenaState.hintsUsed);
            lcHintBtn.textContent = `Hint (${remaining} remaining)`;
            lcHintBtn.disabled = remaining === 0;
            lcPenaltyText.textContent = `Score penalty so far: -${arenaState.hintPenalty}pts`;
        }

        function showToast(message) {
            const toast = document.getElementById('toast');
            const overlay = document.querySelector('.toast-overlay');

            if (toast && overlay) {
                overlay.classList.add('show');
                toast.textContent = message;
                toast.classList.remove('hide');
                toast.classList.add('show');
                setTimeout(() => {
                    toast.classList.remove('show');
                    toast.classList.add('hide');
                    overlay.classList.remove('show');
                }, 1500);
            }
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

        // ── SMART INTENT CHECK GATE ────────────
        function shouldFireIntentApi(currentCode) {
            // GATE 1: minimum time between calls
            const now = Date.now();
            if (now - arenaState.lastIntentTime < minimumTimeBetweenCalls) {
                return false;
            }

            // GATE 2: meaningful change in code
            const currentTrimmed = currentCode.trim();
            const lastTrimmed = arenaState.lastSentCode.trim();
            
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
                arenaChallenge.starter_code || '';
            if (currentTrimmed === originalCode.trim()) {
                return false;
            }

            return true;
        }

        async function checkIntent() {
            const current = lcCodeEditor.value;
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
                        language: arenaChallenge.language,
                        challenge_id: arenaChallenge.id,
                        game_type: 'live_coding',
                        session_token: 'bug_hunt_session'
                });
                
                // Update tracking after successful call
                arenaState.lastSentCode = current.trim();
                arenaState.lastIntentTime = Date.now();
                
                if (data && data.should_show === true && data.hint) {
                    lcHintBtn.classList.add('lc-hint-btn-pulse');
                }
            } catch (error) {
                console.error('live coding intent_api.php failed:', error);
            }
        }

        const debouncedIntentCheck = debounce(checkIntent, 900);

        lcCodeEditor.addEventListener('input', () => {
            renderLineNumbers();
            debouncedIntentCheck();
        });

        lcCodeEditor.addEventListener('scroll', () => {
            lcLineNumbers.scrollTop = lcCodeEditor.scrollTop;
        });

        lcCodeEditor.addEventListener('keydown', (event) => {
            if (event.key === 'Tab') {
                event.preventDefault();
                const start = lcCodeEditor.selectionStart;
                const end = lcCodeEditor.selectionEnd;
                const value = lcCodeEditor.value;
                lcCodeEditor.value = value.slice(0, start) + '  ' + value.slice(end);
                lcCodeEditor.selectionStart = lcCodeEditor.selectionEnd = start + 2;
                renderLineNumbers();
            }
        });

        lcDismissHintBtn.addEventListener('click', hideHintBubble);

        lcHintBtn.addEventListener('click', () => {
            if (arenaState.hintsUsed >= 3) {
                showToast('No more hints available');
                return;
            }

            const nextHint = arenaHints[arenaState.hintsUsed] || 'Focus on the function contract and edge cases.';
            showHintBubble(nextHint, arenaState.hintsUsed);

            arenaState.hintsUsed += 1;
            const penalties = [100, 150, 200];
            arenaState.hintPenalty += penalties[arenaState.hintsUsed - 1] || 0;
            lcHintBtn.classList.remove('lc-hint-btn-pulse');
            updateHintUi();
        });

        function escapeHtml(str) {
            return String(str)
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        }

        function renderConceptsUnlocked(graphData) {
            const wrap = document.getElementById('lc-conceptsUnlocked');
            wrap.innerHTML = '';

            const challengeConcepts = String(arenaChallenge.concept_tags || '')
                .split(',')
                .map((item) => item.trim().toLowerCase())
                .filter(Boolean);

            const latestNodeSet = new Set((graphData.nodes || []).map((node) => String(node.id).toLowerCase()));

            challengeConcepts.forEach((concept) => {
                if (!latestNodeSet.has(concept)) {
                    return;
                }

                const badge = document.createElement('span');
                badge.className = 'concept-pill';
                if (!knownConcepts.has(concept)) {
                    badge.classList.add('concept-badge-new');
                    badge.textContent = `${concept} (new)`;
                } else {
                    badge.classList.add('concept-badge-reinforced');
                    badge.textContent = `${concept} (reinforced)`;
                }
                wrap.appendChild(badge);
            });

            (graphData.nodes || []).forEach((node) => knownConcepts.add(String(node.id).toLowerCase()));
        }

        let lastObituaryData = null;

        function openObituaryModal(payload, finalScore, finalXp) {
            document.getElementById('lc-obitScore').textContent = String(finalScore);
            document.getElementById('lc-obitXp').textContent = String(finalXp);
            document.getElementById('lc-obitRoast').textContent = payload.roast ?? '';
            document.getElementById('lc-obitTimeComplexity').textContent = payload.time_complexity ?? '';
            document.getElementById('lc-obitSpaceComplexity').textContent = payload.space_complexity ?? '';
            document.getElementById('lc-obitAlternative').textContent = payload.cleaner_alternative ?? '';
            document.getElementById('lc-obitSeniorComment').textContent = payload.senior_dev_comment ?? '';

            lcScoreEl.textContent = String(finalScore);

            const edgeWrap = document.getElementById('lc-obitEdgeCases');
            const edgeCases = Array.isArray(payload.edge_cases_missed) ? payload.edge_cases_missed : [];

            if (edgeCases.length === 0) {
                edgeWrap.innerHTML = '<p class="edge-good">✅ You nailed all edge cases!</p>';
            } else {
                edgeWrap.innerHTML = '<ul>' + edgeCases.map(item => `<li class="edge-warning">${escapeHtml(String(item))}</li>`).join('') + '</ul>';
            }

            // Store obituary data for recommendation fetch
            lastObituaryData = payload;
            lcObituaryModal.hidden = false;
        }

        function closeObituaryModal() {
            lcObituaryModal.hidden = true;
            
            // Trigger recommendation fetch after modal closes
            if (lastObituaryData) {
                fetchRecommendation(
                    arenaChallenge.id || 0,
                    'live_coding',
                    lastObituaryData.score || 0,
                    lastObituaryData.hints_used || 0,
                    arenaState.timerSeconds,
                    lastObituaryData.difficulty || 'beginner'
                ).then(data => {
                    showRecommendationCard(data);
                });
                lastObituaryData = null;
            }
        }

        class ConceptGraphRenderer {
            constructor(canvasId, graphData, cssVariables) {
                this.canvas = document.getElementById(canvasId);
                this.ctx = this.canvas ? this.canvas.getContext('2d') : null;
                this.nodes = Array.isArray(graphData?.nodes) ? graphData.nodes.map((node) => ({ ...node })) : [];
                this.edges = Array.isArray(graphData?.edges) ? graphData.edges.map((edge) => ({ ...edge })) : [];
                this.cssVariables = cssVariables || {};
                this.hoveredNode = null;
                this.selectedNode = null;
                this.animationFrame = null;
                this.physics = { running: true, frameCount: 0, maxFrames: 180 };
                this.nodeMap = new Map();
            }

            init() {
                if (!this.canvas || !this.ctx) {
                    return;
                }

                const rootStyles = getComputedStyle(document.documentElement);
                this.cssVariables = {
                    primary: rootStyles.getPropertyValue('--primary').trim() || '#58e1ff',
                    warning: rootStyles.getPropertyValue('--warning-color').trim() || '#ffcc70',
                    success: rootStyles.getPropertyValue('--success-color').trim() || '#67e59f',
                    muted: rootStyles.getPropertyValue('--muted-color').trim() || '#b8c2eb',
                    text: rootStyles.getPropertyValue('--text').trim() || '#f3f6ff',
                    border: rootStyles.getPropertyValue('--border-color').trim() || 'rgba(255,255,255,0.16)',
                    bodyFont: rootStyles.getPropertyValue('--font-primary').trim() || 'Quicksand, sans-serif'
                };

                this.assignInitialPositions();
                this.bindEvents();
                this.runPhysics();
                this.render();
            }

            assignInitialPositions() {
                const centerX = this.canvas.width / 2;
                const centerY = this.canvas.height / 2;
                const radius = Math.min(this.canvas.width, this.canvas.height) * 0.35;
                const count = Math.max(1, this.nodes.length);

                this.nodeMap.clear();

                this.nodes.forEach((node, index) => {
                    const angle = (2 * Math.PI * index) / count;
                    node.x = centerX + radius * Math.cos(angle);
                    node.y = centerY + radius * Math.sin(angle);
                    node.vx = 0;
                    node.vy = 0;
                    this.nodeMap.set(String(node.id), node);
                });
            }

            runPhysics() {
                if (!this.canvas || !this.ctx) {
                    return;
                }

                const centerX = this.canvas.width / 2;
                const centerY = this.canvas.height / 2;
                const padding = 40;

                const step = () => {
                    if (!this.physics.running) {
                        this.render();
                        return;
                    }

                    this.physics.frameCount += 1;

                    for (let i = 0; i < this.nodes.length; i += 1) {
                        for (let j = i + 1; j < this.nodes.length; j += 1) {
                            const a = this.nodes[i];
                            const b = this.nodes[j];
                            const dx = b.x - a.x;
                            const dy = b.y - a.y;
                            const distSq = Math.max(1, dx * dx + dy * dy);
                            const dist = Math.sqrt(distSq);

                            if (dist < 120) {
                                const force = 800 / distSq;
                                const fx = (dx / dist) * force;
                                const fy = (dy / dist) * force;
                                a.vx -= fx;
                                a.vy -= fy;
                                b.vx += fx;
                                b.vy += fy;
                            }
                        }
                    }

                    this.edges.forEach((edge) => {
                        const fromNode = this.nodeMap.get(String(edge.from));
                        const toNode = this.nodeMap.get(String(edge.to));
                        if (!fromNode || !toNode) {
                            return;
                        }

                        const dx = toNode.x - fromNode.x;
                        const dy = toNode.y - fromNode.y;
                        const dist = Math.max(1, Math.sqrt(dx * dx + dy * dy));
                        if (dist > 150) {
                            const force = (dist - 150) * 0.01;
                            const fx = (dx / dist) * force;
                            const fy = (dy / dist) * force;
                            fromNode.vx += fx;
                            fromNode.vy += fy;
                            toNode.vx -= fx;
                            toNode.vy -= fy;
                        }
                    });

                    this.nodes.forEach((node) => {
                        node.vx += (centerX - node.x) * 0.002;
                        node.vy += (centerY - node.y) * 0.002;
                        node.vx *= 0.85;
                        node.vy *= 0.85;

                        node.x += node.vx;
                        node.y += node.vy;

                        if (node.x < padding) {
                            node.x = padding;
                            node.vx *= -0.45;
                        }
                        if (node.x > this.canvas.width - padding) {
                            node.x = this.canvas.width - padding;
                            node.vx *= -0.45;
                        }
                        if (node.y < padding) {
                            node.y = padding;
                            node.vy *= -0.45;
                        }
                        if (node.y > this.canvas.height - padding) {
                            node.y = this.canvas.height - padding;
                            node.vy *= -0.45;
                        }
                    });

                    this.render();

                    if (this.physics.frameCount >= this.physics.maxFrames) {
                        this.physics.running = false;
                        this.animationFrame = null;
                        return;
                    }

                    this.animationFrame = requestAnimationFrame(step);
                };

                if (this.animationFrame) {
                    cancelAnimationFrame(this.animationFrame);
                }
                this.physics.running = true;
                this.physics.frameCount = 0;
                this.animationFrame = requestAnimationFrame(step);
            }

            masteryColor(mastery) {
                if (mastery >= 0.75) return this.cssVariables.success;
                if (mastery >= 0.25) return this.cssVariables.warning;
                if (mastery > 0) return 'rgba(88, 225, 255, 0.5)';
                return this.cssVariables.muted;
            }

            render() {
                if (!this.canvas || !this.ctx) {
                    return;
                }

                const ctx = this.ctx;
                ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);

                this.edges.forEach((edge) => {
                    const fromNode = this.nodeMap.get(String(edge.from));
                    const toNode = this.nodeMap.get(String(edge.to));
                    if (!fromNode || !toNode) {
                        return;
                    }

                    ctx.beginPath();
                    ctx.moveTo(fromNode.x, fromNode.y);
                    ctx.lineTo(toNode.x, toNode.y);
                    ctx.strokeStyle = 'rgba(184, 194, 235, 0.4)';
                    ctx.lineWidth = Math.max(1, Math.min(3, Number(edge.strength || 1)));
                    ctx.stroke();
                });

                this.nodes.forEach((node) => {
                    const color = this.masteryColor(Number(node.mastery || 0));

                    ctx.save();
                    if (node === this.selectedNode) {
                        ctx.shadowColor = this.cssVariables.primary;
                        ctx.shadowBlur = 15;
                    }

                    ctx.beginPath();
                    ctx.arc(node.x, node.y, Number(node.size || 12), 0, Math.PI * 2);
                    ctx.fillStyle = color;
                    ctx.fill();
                    ctx.restore();

                    if (node === this.hoveredNode) {
                        ctx.beginPath();
                        ctx.arc(node.x, node.y, Number(node.size || 12) + 4, 0, Math.PI * 2);
                        ctx.strokeStyle = this.cssVariables.primary;
                        ctx.lineWidth = 3;
                        ctx.stroke();
                    }

                    if (node === this.selectedNode) {
                        ctx.beginPath();
                        ctx.arc(node.x, node.y, Number(node.size || 12) + 6, 0, Math.PI * 2);
                        ctx.strokeStyle = this.cssVariables.warning;
                        ctx.lineWidth = 3;
                        ctx.stroke();
                    }

                    ctx.font = `12px ${this.cssVariables.bodyFont}`;
                    ctx.fillStyle = this.cssVariables.text;
                    ctx.textAlign = 'center';
                    ctx.fillText(node.label || node.id, node.x, node.y + Number(node.size || 12) + 14);
                });
            }

            getMousePosition(event) {
                const rect = this.canvas.getBoundingClientRect();
                const scaleX = this.canvas.width / rect.width;
                const scaleY = this.canvas.height / rect.height;
                const x = (event.clientX - rect.left) * scaleX;
                const y = (event.clientY - rect.top) * scaleY;
                return { x, y };
            }

            handleMouseMove(event) {
                const { x, y } = this.getMousePosition(event);
                this.hoveredNode = null;

                for (const node of this.nodes) {
                    const dx = x - node.x;
                    const dy = y - node.y;
                    const distance = Math.sqrt((dx * dx) + (dy * dy));
                    if (distance <= Number(node.size || 12)) {
                        this.hoveredNode = node;
                        break;
                    }
                }

                this.canvas.style.cursor = this.hoveredNode ? 'pointer' : 'default';
                this.render();
            }

            handleClick(event) {
                this.handleMouseMove(event);
                if (this.hoveredNode) {
                    this.selectedNode = this.hoveredNode;
                    this.showNodeDetail(this.selectedNode);
                }
                this.render();
            }

            relativeTime(isoDate) {
                const t = new Date(isoDate).getTime();
                if (!t) return 'just now';
                const diffMs = Date.now() - t;
                const diffSec = Math.max(0, Math.floor(diffMs / 1000));
                if (diffSec < 60) return `${diffSec}s ago`;
                const diffMin = Math.floor(diffSec / 60);
                if (diffMin < 60) return `${diffMin}m ago`;
                const diffHr = Math.floor(diffMin / 60);
                if (diffHr < 24) return `${diffHr}h ago`;
                const diffDay = Math.floor(diffHr / 24);
                return `${diffDay} day${diffDay === 1 ? '' : 's'} ago`;
            }

            showNodeDetail(node) {
                const detail = document.getElementById('graph-node-detail');
                if (!detail) return;

                const masteryPct = Math.round(Number(node.mastery || 0) * 100);
                const connected = this.edges
                    .filter((edge) => String(edge.from) === String(node.id) || String(edge.to) === String(node.id))
                    .map((edge) => String(edge.from) === String(node.id) ? String(edge.to) : String(edge.from));

                const uniqueConnected = Array.from(new Set(connected));

                const practiceLink = masteryPct < 50
                    ? `<a href="live-coding.php?tag=${encodeURIComponent(String(node.id))}${LIVE_CODING_SELECTED_LANGUAGE ? `&language=${encodeURIComponent(LIVE_CODING_SELECTED_LANGUAGE)}` : ''}" style="color: var(--primary); text-decoration: underline;">Practice this more — try a challenge tagged with ${escapeHtml(String(node.id))}</a>`
                    : '';

                detail.innerHTML = `
                    <div class="graph-detail-card">
                        <div><strong>${escapeHtml(String(node.label || node.id))}</strong></div>
                        <div style="margin-top:0.35rem; color: var(--muted-color);">Encountered: ${Number(node.times_encountered || 0)} times</div>
                        <div style="color: var(--muted-color);">Solved: ${Number(node.times_solved || 0)} times</div>
                        <div style="color: var(--muted-color);">Mastery: ${masteryPct}%</div>
                        <div class="graph-detail-progress"><div class="graph-detail-progress-fill" style="width:${masteryPct}%;"></div></div>
                        <div style="color: var(--muted-color);">Last seen: ${this.relativeTime(node.last_seen)}</div>
                        <div style="margin-top:0.35rem; color: var(--muted-color);">Connected to: ${uniqueConnected.length ? uniqueConnected.join(', ') : 'None yet'}</div>
                        <div style="margin-top:0.45rem;">${practiceLink}</div>
                    </div>
                `;

                detail.classList.add('open');
            }

            updateGraph(newGraphData) {
                const incomingNodes = Array.isArray(newGraphData?.nodes) ? newGraphData.nodes : [];
                const incomingEdges = Array.isArray(newGraphData?.edges) ? newGraphData.edges : [];

                const existingMap = new Map(this.nodes.map((node) => [String(node.id), node]));
                const centerX = this.canvas.width / 2;
                const centerY = this.canvas.height / 2;

                this.nodes = incomingNodes.map((node) => {
                    const id = String(node.id);
                    const existing = existingMap.get(id);

                    if (existing) {
                        return {
                            ...existing,
                            ...node,
                            x: existing.x,
                            y: existing.y,
                            vx: existing.vx || 0,
                            vy: existing.vy || 0
                        };
                    }

                    const relatedEdge = incomingEdges.find((edge) => String(edge.from) === id || String(edge.to) === id);
                    let x = centerX;
                    let y = centerY;

                    if (relatedEdge) {
                        const connectedId = String(relatedEdge.from) === id ? String(relatedEdge.to) : String(relatedEdge.from);
                        const connectedNode = existingMap.get(connectedId);
                        if (connectedNode) {
                            x = connectedNode.x + ((Math.random() * 60) - 30);
                            y = connectedNode.y + ((Math.random() * 60) - 30);
                        }
                    }

                    return {
                        ...node,
                        x,
                        y,
                        vx: 0,
                        vy: 0
                    };
                });

                this.edges = incomingEdges.map((edge) => ({ ...edge }));
                this.nodeMap.clear();
                this.nodes.forEach((node) => this.nodeMap.set(String(node.id), node));

                this.physics.running = true;
                this.physics.frameCount = 0;
                this.physics.maxFrames = 60;
                this.runPhysics();
                this.render();
            }

            bindEvents() {
                this.canvas.addEventListener('mousemove', (event) => this.handleMouseMove(event));
                this.canvas.addEventListener('click', (event) => this.handleClick(event));
            }
        }

        let graphRenderer = null;
        if (graphCanvas) {
            graphRenderer = new ConceptGraphRenderer('concept-graph-canvas', initialGraphData, {});
            if ((initialGraphData.nodes || []).length > 0) {
                graphRenderer.init();
            }
        }

        function applyGraphVisibility(nodes) {
            const count = Array.isArray(nodes) ? nodes.length : 0;
            graphConceptCount.textContent = `${count} concepts unlocked`;
            if (count === 0) {
                graphEmptyState.hidden = false;
                graphCanvas.hidden = true;
            } else {
                graphEmptyState.hidden = true;
                graphCanvas.hidden = false;
            }
        }

        applyGraphVisibility(initialGraphData.nodes || []);

        lcSubmitBtn.addEventListener('click', async () => {
            if (arenaState.isSubmitting) {
                return;
            }

            setSubmitLoading(true);
            hideHintBubble();

            const timeTaken = Math.floor((Date.now() - arenaState.sessionStartTime) / 1000);

            try {
                const obitData = await fetchJsonWithDiagnostics('obituary.php', {
                        submitted_code: lcCodeEditor.value,
                        challenge_id: arenaChallenge.id,
                        time_taken: timeTaken,
                        language: arenaChallenge.language,
                        user_id: arenaUserId,
                        game_type: 'live_coding',
                        hint_penalty: arenaState.hintPenalty,
                        hints_used: arenaState.hintsUsed,
                        arena_session_id: arenaSessionId
                });

                const graphData = await fetchJsonWithDiagnostics('graph_api.php', {
                        user_id: arenaUserId,
                        challenge_id: arenaChallenge.id,
                        challenge_type: 'live_coding',
                        solved: true,
                        hints_used: arenaState.hintsUsed
                });
                if (graphData && graphData.success) {
                    applyGraphVisibility(graphData.nodes || []);
                    if (graphRenderer) {
                        if ((initialGraphData.nodes || []).length === 0 && (graphData.nodes || []).length > 0 && graphCanvas.hidden === false) {
                            graphRenderer = new ConceptGraphRenderer('concept-graph-canvas', graphData, {});
                            graphRenderer.init();
                        } else {
                            graphRenderer.updateGraph(graphData);
                        }
                    }
                    renderConceptsUnlocked(graphData);
                }

                const adjustedScore = Math.max(0, Number(obitData?.score || 0));
                const adjustedXp = Math.floor(adjustedScore / 10);
                openObituaryModal(obitData || {}, adjustedScore, adjustedXp);
            } catch (error) {
                console.error('live coding submit failed:', error);
                openObituaryModal({
                    roast: "Our AI mentor is taking a coffee break. Here's what we know: your code was submitted.",
                    time_complexity: 'Unable to analyze right now.',
                    space_complexity: 'Unable to analyze right now.',
                    edge_cases_missed: [],
                    cleaner_alternative: '',
                    senior_dev_comment: 'Ship it. (Just kidding.)'
                }, 500, 50);
            } finally {
                setSubmitLoading(false);
            }
        });

        lcResetBtn.addEventListener('click', () => {
            lcCodeEditor.value = String(arenaChallenge.starter_code || '');
            renderLineNumbers();
            lcCodeEditor.focus();
        });

        lcPlayAgainBtn.addEventListener('click', () => {
            const params = new URLSearchParams();
            if (LIVE_CODING_TAG_FILTER) {
                params.set('tag', LIVE_CODING_TAG_FILTER);
            }
            if (LIVE_CODING_SELECTED_LANGUAGE) {
                params.set('language', LIVE_CODING_SELECTED_LANGUAGE);
            }
            const qs = params.toString();
            window.location.href = 'live-coding.php' + (qs ? ('?' + qs) : '');
        });

        // ── RECOMMENDATION SYSTEM ─────────────
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
                    return null;
                }
                
                const text = await response.text();
                if (!text.trim()) {
                    return null;
                }
                
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Recommendation parse error');
                    return null;
                }
            } catch (err) {
                console.error('Recommendation fetch error:', err);
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

        lcCloseObitBtn.addEventListener('click', closeObituaryModal);

        renderLineNumbers();
        updateHintUi();
    </script>

    <div class="toast-overlay"></div>
    <div id="toast" class="toast"></div>
</body>
</html>
