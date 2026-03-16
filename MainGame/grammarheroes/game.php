<?php
require_once '../../onboarding/config.php';
require_once '../../includes/greeting.php';

requireLogin();

$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT username, email, profile_image FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header('Location: ../../onboarding/login.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include '../../includes/favicon.php'; ?>
    <title>Grammar Heroes - Arena</title>
    <link rel="stylesheet" href="../../styles.css?v=<?php echo filemtime('../../styles.css'); ?>">
    <link rel="stylesheet" href="game.css?v=<?php echo filemtime('game.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
</head>
<body>
    <div class="game-shell">
        <header class="game-header">
            <button class="ghost-btn" onclick="window.location.href='index.php'">
                <i class="fas fa-arrow-left"></i> Menu
            </button>
            <div class="title-wrap">
                <h1>Grammar Heroes</h1>
                <p>Dodge mistakes. Catch the right words. Survive the grammar storm.</p>
            </div>
            <div class="profile-pill">
                <img src="<?php echo !empty($user['profile_image']) ? '../../' . htmlspecialchars($user['profile_image']) : '../../assets/menu/defaultuser.png'; ?>" alt="Profile">
                <span><?php echo htmlspecialchars($user['username']); ?></span>
            </div>
        </header>

        <section class="hud">
            <div class="hud-item"><span>Score</span><strong id="score">0</strong></div>
            <div class="hud-item"><span>Lives</span><strong id="lives">5</strong></div>
            <div class="hud-item"><span>Combo</span><strong id="combo">0</strong></div>
            <div class="hud-item"><span>Wave</span><strong id="wave">1</strong></div>
            <div class="hud-item"><span>Time</span><strong id="time">0</strong>s</div>
            <div class="hud-item"><span>Enemies</span><strong id="enemies">0</strong></div>
            <div class="hud-item full"><span>Rule</span><strong id="modeLabel">Catch only verbs</strong></div>
            <div class="hud-item full"><span>Boss</span><strong id="bossState">No boss active</strong></div>
        </section>

        <section class="arena-wrap">
            <canvas id="arena" width="960" height="540" aria-label="Grammar Heroes Arena"></canvas>
            <div id="modeFlash" class="mode-flash" hidden></div>
            <div id="startOverlay" class="overlay-panel">
                <h2>Grammar Arena</h2>
                <p>Move, aim by position, and shoot words to classify them under the active grammar rule.</p>
                <ul>
                    <li>Keyboard: <strong>A / D</strong> or <strong>Arrow Left / Right</strong> to move</li>
                    <li>Keyboard: <strong>Space</strong> to shoot</li>
                    <li>Shoot a word to decide: <strong>green = correct target</strong>, <strong>red = wrong pick</strong></li>
                    <li>Enemy drones and boss waves shoot back, so dodge projectiles</li>
                </ul>
                <button id="startBtn" class="play-again">Start Battle</button>
            </div>
        </section>

        <section class="touch-controls">
            <button id="leftBtn" class="touch-btn" aria-label="Move Left"><i class="fas fa-chevron-left"></i></button>
            <button id="shootBtn" class="touch-btn" aria-label="Shoot"><i class="fas fa-crosshairs"></i></button>
            <button id="pauseBtn" class="touch-btn" aria-label="Pause"><i class="fas fa-pause"></i></button>
            <button id="rightBtn" class="touch-btn" aria-label="Move Right"><i class="fas fa-chevron-right"></i></button>
        </section>
    </div>

    <div class="result-modal" id="resultModal" hidden>
        <div class="result-card">
            <h2>Run Complete</h2>
            <div class="result-grid">
                <div><span>Score</span><strong id="finalScore">0</strong></div>
                <div><span>Accuracy</span><strong id="finalAccuracy">0%</strong></div>
                <div><span>Best Combo</span><strong id="finalStreak">0</strong></div>
                <div><span>XP Earned</span><strong id="finalXp">0</strong></div>
                <div><span>Wave Reached</span><strong id="finalWave">1</strong></div>
                <div><span>Correct Catches</span><strong id="finalHits">0</strong></div>
                <div><span>Enemies Defeated</span><strong id="finalEnemies">0</strong></div>
                <div><span>Bosses Defeated</span><strong id="finalBosses">0</strong></div>
            </div>
            <p id="saveStatus" class="save-status">Saving progress...</p>
            <div class="result-actions">
                <button class="ghost-btn" onclick="window.location.href='index.php'">Back to Menu</button>
                <button class="play-again" id="playAgainBtn">Play Again</button>
            </div>
        </div>
    </div>

    <script>
        window.grammarHeroesConfig = {
            saveEndpoint: 'save_progress.php',
            maxDurationSeconds: 180
        };
    </script>
    <script src="script.js?v=<?php echo filemtime('script.js'); ?>"></script>
</body>
</html>
