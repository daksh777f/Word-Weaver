<?php
require_once '../../onboarding/config.php';
require_once '../../includes/greeting.php';

requireLogin();

$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT username, email, grade_level, profile_image FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header('Location: ../../onboarding/login.php');
    exit();
}

$stmt = $pdo->prepare("SELECT * FROM game_progress WHERE user_id = ? AND game_type = 'grammar-heroes' LIMIT 1");
$stmt->execute([$user_id]);
$progress = $stmt->fetch(PDO::FETCH_ASSOC);

$stats = [
    'sessions' => 0,
    'best_score' => 0,
    'best_accuracy' => 0,
    'best_streak' => 0,
    'total_correct' => 0,
    'total_questions' => 0
];

if (!empty($progress['achievements'])) {
    $decoded = json_decode($progress['achievements'], true);
    if (is_array($decoded)) {
        $stats['sessions'] = (int)($decoded['total_sessions'] ?? 0);
        $stats['best_score'] = (int)($decoded['best_score'] ?? 0);
        $stats['best_accuracy'] = (int)($decoded['best_accuracy'] ?? 0);
        $stats['best_streak'] = (int)($decoded['best_streak'] ?? 0);
        $stats['total_correct'] = (int)($decoded['total_correct'] ?? 0);
        $stats['total_questions'] = (int)($decoded['total_questions'] ?? 0);
    }
}

$currentLevel = (int)($progress['player_level'] ?? 1);
$xpEarned = (int)($progress['total_experience_earned'] ?? 0);

$stmt = $pdo->prepare("SELECT gwa FROM user_gwa WHERE user_id = ? AND game_type = 'grammar-heroes' LIMIT 1");
$stmt->execute([$user_id]);
$gwaRow = $stmt->fetch(PDO::FETCH_ASSOC);
$currentGwa = $gwaRow ? (float)$gwaRow['gwa'] : 0.0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include '../../includes/favicon.php'; ?>
    <title>Grammar Heroes</title>
    <link rel="stylesheet" href="../../styles.css?v=<?php echo filemtime('../../styles.css'); ?>">
    <link rel="stylesheet" href="../../navigation/shared/navigation.css?v=<?php echo filemtime('../../navigation/shared/navigation.css'); ?>">
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
</head>
<body>
    <?php include '../../includes/page-loader.php'; ?>

    <header class="gh-header">
        <button class="back-btn" onclick="window.location.href='../../play/game-selection.php'">
            <i class="fas fa-arrow-left"></i>
            Back
        </button>
        <div class="user-pill">
            <img src="<?php echo !empty($user['profile_image']) ? '../../' . htmlspecialchars($user['profile_image']) : '../../assets/menu/defaultuser.png'; ?>" alt="Profile">
            <div>
                <div class="greeting"><?php echo htmlspecialchars(getGreeting()); ?></div>
                <div class="username"><?php echo htmlspecialchars($user['username']); ?></div>
            </div>
        </div>
    </header>

    <main class="gh-main">
        <section class="hero-card">
            <div class="hero-overlay"></div>
            <div class="hero-content">
                <img src="../../assets/selection/Grammarlogo.webp" alt="Grammar Heroes" class="hero-logo">
                <h1>Grammar Heroes</h1>
                <p>Sharpen grammar instinct with fast-paced correction battles and streak multipliers.</p>
                <div class="hero-actions">
                    <a href="game.php" class="play-btn">
                        <i class="fas fa-play"></i>
                        Start Challenge
                    </a>
                </div>
            </div>
        </section>

        <section class="stats-grid">
            <article class="stat-card">
                <h3>Current Level</h3>
                <p><?php echo $currentLevel; ?></p>
            </article>
            <article class="stat-card">
                <h3>Best Score</h3>
                <p><?php echo $stats['best_score']; ?></p>
            </article>
            <article class="stat-card">
                <h3>Best Accuracy</h3>
                <p><?php echo $stats['best_accuracy']; ?>%</p>
            </article>
            <article class="stat-card">
                <h3>Best Streak</h3>
                <p><?php echo $stats['best_streak']; ?></p>
            </article>
            <article class="stat-card">
                <h3>Total Sessions</h3>
                <p><?php echo $stats['sessions']; ?></p>
            </article>
            <article class="stat-card">
                <h3>Current Mastery Score</h3>
                <p><?php echo number_format($currentGwa, 2); ?></p>
            </article>
            <article class="stat-card full">
                <h3>Lifetime Accuracy</h3>
                <?php $lifetimeAccuracy = $stats['total_questions'] > 0 ? round(($stats['total_correct'] / $stats['total_questions']) * 100) : 0; ?>
                <p><?php echo $lifetimeAccuracy; ?>% <span>(<?php echo $stats['total_correct']; ?>/<?php echo $stats['total_questions']; ?>)</span></p>
            </article>
            <article class="stat-card full">
                <h3>Total XP Earned</h3>
                <p><?php echo $xpEarned; ?></p>
            </article>
        </section>
    </main>
</body>
</html>
