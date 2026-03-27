<?php
require_once '../onboarding/config.php';
require_once '../includes/greeting.php';
require_once 'adaptive_engine.php';

// Check if user is logged in, redirect to login if not
if (!isLoggedIn()) {
    header('Location: ../onboarding/login.php');
    exit();
}

// Get user information
$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT username, email, grade_level, profile_image, COALESCE(duel_wins, 0) AS duel_wins, COALESCE(duel_losses, 0) AS duel_losses, COALESCE(duel_win_streak, 0) AS duel_win_streak FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    // User not found, destroy session and redirect to login
    session_destroy();
    header('Location: ../onboarding/login.php');
    exit();
}

// Build performance profile for adaptive recommendations
$profile = buildPerformanceProfile($user_id, $pdo);

// Determine which games to recommend
$skillLevel = $profile['skill_level'];
$weakConcepts = array_map('strtolower', array_column(array_values($profile['weak_concepts']), 'concept_name'));
$gameTypeBreakdown = $profile['game_type_breakdown'];

$recommendBugHunt = false;
$recommendLiveCoding = false;
$recommendSprint = false;

$bugHuntCount = (int)($gameTypeBreakdown['bug_hunt'] ?? 0);
$liveCodingCount = (int)($gameTypeBreakdown['live_coding'] ?? 0);

if ($bugHuntCount < 3) {
    // New to bug hunting
    $recommendBugHunt = true;
} elseif (!empty($weakConcepts) && $skillLevel < 60) {
    // Has weak spots, debugging helps
    $recommendBugHunt = true;
}

if ($liveCodingCount >= 3 && $skillLevel >= 60) {
    // Ready for writing from scratch
    $recommendLiveCoding = true;
}

if ($profile['current_streak'] >= 2) {
    // Has momentum, sprint to maintain it
    $recommendSprint = true;
}

// Get user's game progress for all games shown in selection
$game_progress = [];
$stmt = $pdo->prepare("SELECT game_type, player_level, achievements FROM game_progress WHERE user_id = ?");
$stmt->execute([$user_id]);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $bestScore = 0;
    $maxLevel = (int)($row['player_level'] ?? 1);

    if (!empty($row['achievements'])) {
        $achievements = json_decode($row['achievements'], true);
        if (is_array($achievements)) {
            $bestScore = (int)($achievements['best_score'] ?? 0);
            $maxLevel = max($maxLevel, (int)($achievements['highest_level'] ?? 1));
        }
    }

    $game_progress[$row['game_type']] = [
        'best_score' => $bestScore,
        'max_level' => $maxLevel
    ];
}

$bugCountStmt = $pdo->query("SELECT COUNT(*) FROM bug_challenges WHERE challenge_type = 'bug_fix'");
$bugChallengeCount = (int)$bugCountStmt->fetchColumn();

$dailySprintCheckStmt = $pdo->prepare("SELECT completed FROM daily_sprint_locks WHERE user_id = ? AND sprint_date = CURDATE() LIMIT 1");
$dailySprintCheckStmt->execute([$user_id]);
$dailySprintRow = $dailySprintCheckStmt->fetch(PDO::FETCH_ASSOC);
$dailySprintCompleted = $dailySprintRow && (int)$dailySprintRow['completed'] === 1;

$liveCodingCountStmt = $pdo->query("SELECT COUNT(*) FROM live_coding_challenges");
$liveCodingChallengeCount = (int)$liveCodingCountStmt->fetchColumn();

$conceptGraphCountStmt = $pdo->prepare("SELECT COUNT(*) FROM concept_graph WHERE user_id = ?");
$conceptGraphCountStmt->execute([$user_id]);
$conceptGraphCount = (int)$conceptGraphCountStmt->fetchColumn();

// Build recommendations array for template use
$recommendations = [
    'bughunt' => ['recommended' => $recommendBugHunt],
    'livecoding' => ['recommended' => $recommendLiveCoding],
    'dailysprint' => ['recommended' => $recommendSprint]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include '../includes/favicon.php'; ?>
    <title>Select Game - CodeDungeon</title>
    <link rel="stylesheet" href="../styles.css?v=<?php echo filemtime('../styles.css'); ?>">
    <link rel="stylesheet" href="../navigation/shared/navigation.css?v=<?php echo filemtime('../navigation/shared/navigation.css'); ?>">
    <link rel="stylesheet" href="game-selection.css?v=<?php echo filemtime('game-selection.css'); ?>">
    <link rel="stylesheet" href="../notif/toast.css?v=<?php echo filemtime('../notif/toast.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
</head>
<body>
    <?php include '../includes/page-loader.php'; ?>
    <!-- Mobile Menu Button -->
    <button class="mobile-menu-btn" aria-label="Open menu">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-logo">
            <span class="codedungeon-logo sidebar-logo-img"><span class="logo-icon">⚔️</span><span class="logo-text">Code<span class="logo-accent">Dungeon</span></span></span>
        </div>
        <nav class="sidebar-nav">
            <a href="../menu.php?from=selection" class="nav-link">
                <i class="fas fa-house"></i>
                <span>Menu</span>
            </a>
            <a href="../navigation/favorites/favorites.php" class="nav-link">
                <i class="fas fa-star"></i>
                <span>Favorites</span>
            </a>
            <a href="../navigation/friends/friends.php" class="nav-link">
                <i class="fas fa-users"></i>
                <span>Friends</span>
            </a>
            <a href="../navigation/profile/profile.php" class="nav-link">
                <i class="fas fa-user"></i>
                <span>Profile</span>
            </a>
            <?php if (canAccessCreatorConsole($user['grade_level'])): ?>
            <a href="../navigation/teacher/dashboard.php" class="nav-link">
                <i class="fas fa-chalkboard-teacher"></i>
                <span>Creator Studio</span>
            </a>
            <?php endif; ?>
            <?php if (canAccessAdminConsole($user['grade_level'])): ?>
            <a href="../navigation/admin/dashboard.php" class="nav-link">
                <i class="fas fa-shield-alt"></i>
                <span>Admin</span>
            </a>
            <?php endif; ?>
        </nav>
    </div>

    <!-- Header -->
    <header class="top-header">
        <div class="header-right">
            <div class="notification-icon" onclick="window.location.href='../navigation/notification.php'">
                <i class="fas fa-bell"></i>
                <span class="notification-badge">0</span>
            </div>
            <div class="logout-icon" onclick="showLogoutModal()">
                <i class="fas fa-sign-out-alt"></i>
            </div>
            <div class="user-profile">
                <div class="user-info">
                    <span class="greeting"><?php echo getGreeting(); ?></span>
                    <span class="username"><?php echo htmlspecialchars(explode(' ', $user['username'])[0]); ?></span>
                </div>
                <div class="profile-dropdown">
                    <a href="#" class="profile-icon">
                        <img src="<?php echo !empty($user['profile_image']) ? '../' . htmlspecialchars($user['profile_image']) : '../assets/menu/defaultuser.png'; ?>" alt="Profile" class="profile-img">
                    </a>
                    <div class="profile-dropdown-content">
                        <div class="profile-dropdown-header">
                            <img src="<?php echo !empty($user['profile_image']) ? '../' . htmlspecialchars($user['profile_image']) : '../assets/menu/defaultuser.png'; ?>" alt="Profile" class="profile-dropdown-avatar">
                            <div class="profile-dropdown-info">
                                <div class="profile-dropdown-name"><?php echo htmlspecialchars($user['username']); ?></div>
                                <div class="profile-dropdown-email"><?php echo htmlspecialchars($user['email']); ?></div>
                            </div>
                        </div>
                        <div class="profile-dropdown-menu">
                            <a href="../navigation/profile/profile.php" class="profile-dropdown-item">
                                <i class="fas fa-user"></i>
                                <span>View Profile</span>
                            </a>
                            <a href="../navigation/favorites/favorites.php" class="profile-dropdown-item">
                                <i class="fas fa-star"></i>
                                <span>My Favorites</span>
                            </a>
                            <a href="../settings/settings.php" class="profile-dropdown-item">
                                <i class="fas fa-cog"></i>
                                <span>Settings</span>
                            </a>
                        </div>
                        <div class="profile-dropdown-footer">
                            <button class="profile-dropdown-item sign-out" onclick="showLogoutModal()">
                                <i class="fas fa-sign-out-alt"></i>
                                <span>Sign Out</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div class="main-content">
        <div class="games-carousel-container">
            <h1 class="game-title">Select a Game</h1>
            
            <div class="cards-container">
                <!-- VocabWorld Card -->
                <div class="main card game-card" data-game="vocabbg">
                    <div class="card_content">
                        <img src="../MainGame/vocabworld/assets/menu/spaceplay.gif" alt="VocabWorld Preview" style="width: 100%; height: 100%; object-fit: cover; border-radius: 7px;">
                        <div class="play-icon-overlay">
                            <i class="fas fa-play"></i>
                        </div>
                    </div>
                    <div class="card_back"></div>
                    <div class="data">
                        <div class="img">
                            <img src="../MainGame/vocabworld/assets/menu/vv_logo.webp" alt="Vocabworld Logo">
                        </div>
                        <div class="text">
                            <div class="text_m">Vocabworld</div>
                        </div>
                    </div>
                    <div class="btns">
                        <div class="likes">
                            <svg class="likes_svg" viewBox="-2 0 105 92"><path d="M85.24 2.67C72.29-3.08 55.75 2.67 50 14.9 44.25 2 27-3.8 14.76 2.67 1.1 9.14-5.37 25 5.42 44.38 13.33 58 27 68.11 50 86.81 73.73 68.11 87.39 58 94.58 44.38c10.79-18.7 4.32-35.24-9.34-41.71Z"></path></svg>
                            <span class="likes_text"><?php echo isset($game_progress['vocabworld']) ? $game_progress['vocabworld']['best_score'] : '0'; ?></span>
                        </div>
                        <div class="comments">
                            <svg class="comments_svg" viewBox="-405.9 238 56.3 54.8" title="Level"><path d="M-391 291.4c0 1.5 1.2 1.7 1.9 1.2 1.8-1.6 15.9-14.6 15.9-14.6h19.3c3.8 0 4.4-.8 4.4-4.5v-31.1c0-3.7-.8-4.5-4.4-4.5h-47.4c-3.6 0-4.4.9-4.4 4.5v31.1c0 3.7.7 4.4 4.4 4.4h10.4v13.5z"></path></svg>
                            <span class="comments_text"><?php echo isset($game_progress['vocabworld']) ? $game_progress['vocabworld']['max_level'] : '1'; ?></span>
                        </div>
                    </div>
                </div>

                <!-- Grammar Heroes Card -->
                <div class="main card game-card" data-game="grammarbg">
                    <div class="card_content">
                        <img src="../MainGame/vocabworld/assets/menu/grammarbg.gif" alt="Grammar Heroes Preview" style="width: 100%; height: 100%; object-fit: cover; border-radius: 7px;">
                        <div class="play-icon-overlay">
                            <i class="fas fa-play"></i>
                        </div>
                    </div>
                    <div class="card_back"></div>
                    <div class="data">
                        <div class="img">
                            <img src="../assets/selection/Grammarlogo.webp" alt="Grammar Heroes Logo">
                        </div>
                        <div class="text">
                            <div class="text_m">Grammar Heroes</div>
                        </div>
                    </div>
                    <div class="btns">
                        <div class="likes">
                            <svg class="likes_svg" viewBox="-2 0 105 92"><path d="M85.24 2.67C72.29-3.08 55.75 2.67 50 14.9 44.25 2 27-3.8 14.76 2.67 1.1 9.14-5.37 25 5.42 44.38 13.33 58 27 68.11 50 86.81 73.73 68.11 87.39 58 94.58 44.38c10.79-18.7 4.32-35.24-9.34-41.71Z"></path></svg>
                            <span class="likes_text"><?php echo isset($game_progress['coding concepts-heroes']) ? $game_progress['grammar-heroes']['best_score'] : '0'; ?></span>
                        </div>
                        <div class="comments">
                            <svg class="comments_svg" viewBox="-405.9 238 56.3 54.8" title="Level"><path d="M-391 291.4c0 1.5 1.2 1.7 1.9 1.2 1.8-1.6 15.9-14.6 15.9-14.6h19.3c3.8 0 4.4-.8 4.4-4.5v-31.1c0-3.7-.8-4.5-4.4-4.5h-47.4c-3.6 0-4.4.9-4.4 4.5v31.1c0 3.7.7 4.4 4.4 4.4h10.4v13.5z"></path></svg>
                            <span class="comments_text"><?php echo isset($game_progress['coding concepts-heroes']) ? $game_progress['grammar-heroes']['max_level'] : '1'; ?></span>
                        </div>
                    </div>
                </div>

                <!-- Bug Hunt Arena Card -->
                <div class="main card game-card" data-game="bughunt">
                    <div class="card_content">
                        <img src="../assets/selection/Grammarbg.webp" alt="Bug Hunt Arena Preview" style="width: 100%; height: 100%; object-fit: cover; border-radius: 7px;">
                        <div class="play-icon-overlay">
                            <i class="fas fa-play"></i>
                        </div>
                    </div>
                    <div class="card_back"></div>
                    <div class="data">
                        <div class="img">
                            <span class="codedungeon-logo"><span class="logo-icon">⚔️</span><span class="logo-text">Code<span class="logo-accent">Dungeon</span></span></span>
                        </div>
                        <div class="text">
                            <div class="text_m">Bug Hunt Arena</div>
                            <div style="font-size: 0.65rem; opacity: 0.9; margin-top: 0.15rem;">Drop into broken code. Find the bug. Fix it before it ships.</div>
                            <div style="font-size: 0.65rem; opacity: 0.9; margin-top: 0.15rem;">Debug · Beginner → Advanced</div>
                            <div style="font-size: 0.65rem; opacity: 0.9; margin-top: 0.15rem;"><?php echo $bugChallengeCount; ?> bugs available</div>
                            <a href="bug-hunt.php" style="display:none;" aria-hidden="true">Play Bug Hunt Arena</a>
                        </div>
                        <?php if (isset($recommendations['bughunt']) && $recommendations['bughunt']['recommended']): ?>
                        <div class="recommendation-badge">
                            <i class="fas fa-star"></i>
                            <span>Recommended</span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="btns">
                        <div class="likes">
                            <svg class="likes_svg" viewBox="-2 0 105 92"><path d="M85.24 2.67C72.29-3.08 55.75 2.67 50 14.9 44.25 2 27-3.8 14.76 2.67 1.1 9.14-5.37 25 5.42 44.38 13.33 58 27 68.11 50 86.81 73.73 68.11 87.39 58 94.58 44.38c10.79-18.7 4.32-35.24-9.34-41.71Z"></path></svg>
                            <span class="likes_text"><?php echo $bugChallengeCount; ?></span>
                        </div>
                        <div class="comments">
                            <svg class="comments_svg" viewBox="-405.9 238 56.3 54.8" title="Difficulty"><path d="M-391 291.4c0 1.5 1.2 1.7 1.9 1.2 1.8-1.6 15.9-14.6 15.9-14.6h19.3c3.8 0 4.4-.8 4.4-4.5v-31.1c0-3.7-.8-4.5-4.4-4.5h-47.4c-3.6 0-4.4.9-4.4 4.5v31.1c0 3.7.7 4.4 4.4 4.4h10.4v13.5z"></path></svg>
                            <span class="comments_text">B-A</span>
                        </div>
                    </div>
                </div>

                <!-- Daily Bug Sprint Card -->
                <div class="main card game-card" data-game="<?php echo $dailySprintCompleted ? 'dailysprint_done' : 'dailysprint'; ?>" style="<?php echo $dailySprintCompleted ? 'opacity:0.6; filter: grayscale(0.15);' : ''; ?>">
                    <div class="card_content">
                        <img src="../assets/selection/vocabbg.webp" alt="Daily Bug Sprint Preview" style="width: 100%; height: 100%; object-fit: cover; border-radius: 7px;">
                        <div class="play-icon-overlay">
                            <i class="fas fa-play"></i>
                        </div>
                        <?php if ($dailySprintCompleted): ?>
                            <div style="position:absolute; top:8px; right:8px; background: rgba(103,229,159,0.9); color:#08151b; font-size:0.65rem; font-weight:700; padding:0.2rem 0.45rem; border-radius:999px;">✅ Completed today</div>
                        <?php endif; ?>
                    </div>
                    <div class="card_back"></div>
                    <div class="data">
                        <div class="img">
                            <span class="codedungeon-logo"><span class="logo-icon">⚔️</span><span class="logo-text">Code<span class="logo-accent">Dungeon</span></span></span>
                        </div>
                        <div class="text">
                            <div class="text_m">Daily Bug Sprint</div>
                            <div style="font-size: 0.65rem; opacity: 0.9; margin-top: 0.15rem;">3 bugs. 10 minutes. One shot per day. Climb the board.</div>
                            <div style="font-size: 0.65rem; opacity: 0.9; margin-top: 0.15rem;">Daily</div>
                            <div style="font-size: 0.65rem; opacity: 0.9; margin-top: 0.15rem;"><?php echo $dailySprintCompleted ? 'View Leaderboard' : 'Start Sprint'; ?></div>
                            <a href="<?php echo $dailySprintCompleted ? '../navigation/leaderboards/leaderboards.php?game=daily_sprint' : 'daily-sprint.php'; ?>" style="display:none;" aria-hidden="true"><?php echo $dailySprintCompleted ? 'View Leaderboard' : 'Play Daily Bug Sprint'; ?></a>
                        </div>
                    </div>
                    <div class="btns">
                        <div class="likes">
                            <svg class="likes_svg" viewBox="-2 0 105 92"><path d="M85.24 2.67C72.29-3.08 55.75 2.67 50 14.9 44.25 2 27-3.8 14.76 2.67 1.1 9.14-5.37 25 5.42 44.38 13.33 58 27 68.11 50 86.81 73.73 68.11 87.39 58 94.58 44.38c10.79-18.7 4.32-35.24-9.34-41.71Z"></path></svg>
                            <span class="likes_text">10:00</span>
                        </div>
                        <div class="comments">
                            <svg class="comments_svg" viewBox="-405.9 238 56.3 54.8" title="Daily"><path d="M-391 291.4c0 1.5 1.2 1.7 1.9 1.2 1.8-1.6 15.9-14.6 15.9-14.6h19.3c3.8 0 4.4-.8 4.4-4.5v-31.1c0-3.7-.8-4.5-4.4-4.5h-47.4c-3.6 0-4.4.9-4.4 4.5v31.1c0 3.7.7 4.4 4.4 4.4h10.4v13.5z"></path></svg>
                            <span class="comments_text">3</span>
                        </div>
                    </div>
                </div>

                <!-- Live Coding Arena Card -->
                <div class="main card game-card <?php echo $recommendLiveCoding ? 'recommended-game' : ''; ?>" data-game="livecoding">
                    <div class="card_content">
                        <img src="../assets/selection/Grammarbg.webp" alt="Live Coding Arena Preview" style="width: 100%; height: 100%; object-fit: cover; border-radius: 7px;">
                        <div class="play-icon-overlay">
                            <i class="fas fa-play"></i>
                        </div>
                        <?php if ($recommendLiveCoding): ?>
                        <div class="recommendation-badge">
                            <span class="recommendation-star">⭐</span>
                            <span class="recommendation-text">Recommended</span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="card_back"></div>
                    <div class="data">
                        <div class="img">
                            <span class="codedungeon-logo"><span class="logo-icon">⚔️</span><span class="logo-text">Code<span class="logo-accent">Dungeon</span></span></span>
                        </div>
                        <div class="text">
                            <div class="text_m">Live Coding Arena</div>
                            <div style="font-size: 0.65rem; opacity: 0.9; margin-top: 0.15rem;">Write real solutions from scratch. Your AI mentor watches as you type. Grow your knowledge graph.</div>
                            <div style="font-size: 0.65rem; opacity: 0.9; margin-top: 0.15rem;">Code · Beginner → Advanced</div>
                            <div style="font-size: 0.65rem; opacity: 0.9; margin-top: 0.15rem;"><?php echo $liveCodingChallengeCount; ?> challenges available</div>
                            <div style="font-size: 0.65rem; opacity: 0.9; margin-top: 0.15rem;"><?php echo $conceptGraphCount > 0 ? ($conceptGraphCount . ' concepts in your graph') : 'Start building your graph →'; ?></div>
                            <?php if ($recommendLiveCoding): ?>
                            <div style="font-size: 0.65rem; opacity: 0.9; margin-top: 0.15rem; color: #58e1ff;">
                                <i class="fas fa-brain"></i> AI recommends: Ready for coding from scratch!
                            </div>
                            <?php endif; ?>
                            <a href="live-coding.php" style="display:none;" aria-hidden="true">Play Live Coding Arena</a>
                        </div>
                    </div>
                    <div class="btns">
                        <div class="likes">
                            <svg class="likes_svg" viewBox="-2 0 105 92"><path d="M85.24 2.67C72.29-3.08 55.75 2.67 50 14.9 44.25 2 27-3.8 14.76 2.67 1.1 9.14-5.37 25 5.42 44.38 13.33 58 27 68.11 50 86.81 73.73 68.11 87.39 58 94.58 44.38c10.79-18.7 4.32-35.24-9.34-41.71Z"></path></svg>
                            <span class="likes_text"><?php echo $liveCodingChallengeCount; ?></span>
                        </div>
                        <div class="comments">
                            <svg class="comments_svg" viewBox="-405.9 238 56.3 54.8" title="Concepts"><path d="M-391 291.4c0 1.5 1.2 1.7 1.9 1.2 1.8-1.6 15.9-14.6 15.9-14.6h19.3c3.8 0 4.4-.8 4.4-4.5v-31.1c0-3.7-.8-4.5-4.4-4.5h-47.4c-3.6 0-4.4.9-4.4 4.5v31.1c0 3.7.7 4.4 4.4 4.4h10.4v13.5z"></path></svg>
                            <span class="comments_text"><?php echo $conceptGraphCount; ?></span>
                        </div>
                    </div>
                </div>

                <!-- Bug Duel Arena Card (NEW) -->
                <div class="main card game-card" data-game="duel">
                    <div class="card_content">
                        <img src="../assets/selection/Grammarbg.webp" alt="Bug Duel Arena Preview" style="width: 100%; height: 100%; object-fit: cover; border-radius: 7px;">
                        <div class="play-icon-overlay">
                            <i class="fas fa-play"></i>
                        </div>
                        <div class="new-badge" style="position: absolute; top: 8px; left: 8px; background: rgba(255, 102, 102, 0.9); color: white; font-size: 0.65rem; font-weight: 700; padding: 0.25rem 0.6rem; border-radius: 999px;">NEW</div>
                    </div>
                    <div class="card_back"></div>
                    <div class="data">
                        <div class="img">
                            <span class="codedungeon-logo"><span class="logo-icon">⚔️</span><span class="logo-text">Code<span class="logo-accent">Dungeon</span></span></span>
                        </div>
                        <div class="text">
                            <div class="text_m">Bug Duel Arena</div>
                            <div style="font-size: 0.65rem; opacity: 0.9; margin-top: 0.15rem;">Challenge a developer to a real-time bug fixing race. Same broken code. First fix wins.</div>
                            <div style="font-size: 0.65rem; opacity: 0.9; margin-top: 0.15rem;">⚔️ Live · 1v1</div>
                            <?php
                            $stmt = $pdo->query("SELECT COUNT(*) FROM duel_history WHERE DATE(played_at) = CURDATE()");
                            $todayDuelCount = (int)$stmt->fetchColumn();
                            ?>
                            <div style="font-size: 0.65rem; opacity: 0.9; margin-top: 0.15rem;"><?php echo $todayDuelCount; ?> duels played today</div>
                            <?php
                            $duelWins = (int)($user['duel_wins'] ?? 0);
                            $duelLosses = (int)($user['duel_losses'] ?? 0);
                            $duelStreak = (int)($user['duel_win_streak'] ?? 0);
                            ?>
                            <div style="font-size: 0.65rem; opacity: 0.9; margin-top: 0.15rem; color: #ffd700;">Your record: <?php echo $duelWins; ?>-<?php echo $duelLosses; ?></div>
                            <a href="duel_lobby.php" style="display:none;" aria-hidden="true">Play Bug Duel Arena</a>
                        </div>
                    </div>
                    <div class="btns">
                        <div class="likes">
                            <svg class="likes_svg" viewBox="-2 0 105 92"><path d="M85.24 2.67C72.29-3.08 55.75 2.67 50 14.9 44.25 2 27-3.8 14.76 2.67 1.1 9.14-5.37 25 5.42 44.38 13.33 58 27 68.11 50 86.81 73.73 68.11 87.39 58 94.58 44.38c10.79-18.7 4.32-35.24-9.34-41.71Z"></path></svg>
                            <span class="likes_text"><?php echo $duelWins; ?></span>
                        </div>
                        <div class="comments">
                            <svg class="comments_svg" viewBox="-405.9 238 56.3 54.8" title="Streak"><path d="M-391 291.4c0 1.5 1.2 1.7 1.9 1.2 1.8-1.6 15.9-14.6 15.9-14.6h19.3c3.8 0 4.4-.8 4.4-4.5v-31.1c0-3.7-.8-4.5-4.4-4.5h-47.4c-3.6 0-4.4.9-4.4 4.5v31.1c0 3.7.7 4.4 4.4 4.4h10.4v13.5z"></path></svg>
                            <span class="comments_text"><?php echo $duelStreak; ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <nav class="menu-buttons">
                <button id="backToMenu" class="back-button">
                    <i class="fas fa-arrow-left back-icon"></i>
                    Back
                </button>
            </nav>
        </div>
    </div>
    <div class="toast-overlay"></div>
    <div id="toast" class="toast"></div>
    <script src="../script.js"></script>
    <script src="../navigation/shared/notification-badge.js"></script>
    <!-- Logout Confirmation Modal -->
    <div class="toast-overlay" id="logoutModal">
        <div class="toast" id="logoutConfirmation">
            <h3>Logout Confirmation</h3>
            <p>Are you sure you want to logout?</p>
            <div class="modal-buttons">
                <button class="logout-btn" onclick="confirmLogout()">Yes, Logout</button>
                <button class="cancel-btn" onclick="hideLogoutModal()">Cancel</button>
            </div>
        </div>
    </div>

    <script src="game-selection.js?v=<?php echo filemtime('game-selection.js'); ?>"></script>
    <script src="../navigation/shared/profile-dropdown.js"></script>
    <script>
        // Override showToast to correct notification paths without playing sound locally
        const originalShowToast = window.showToast;
        const originalPlayClickSound = window.playClickSound;
        
        window.showToast = function(message, iconPath = null) {
            const toast = document.getElementById('toast');
            const overlay = document.querySelector('.toast-overlay');
            
            if (toast && overlay) {
                // Clear previous content
                toast.innerHTML = '';
                
                // Create container for vertical layout
                const container = document.createElement('div');
                container.style.cssText = 'display: flex; flex-direction: column; align-items: center; text-align: center;';
                
                // Add icon if provided
                if (iconPath) {
                    const icon = document.createElement('img');
                    icon.src = iconPath;
                    icon.alt = 'Icon';
                    icon.style.cssText = 'width: 24px; height: 24px; margin-bottom: 8px;';
                    container.appendChild(icon);
                }
                
                // Add message
                const messageSpan = document.createElement('span');
                messageSpan.textContent = message;
                messageSpan.style.cssText = 'font-family: "Press Start 2P", cursive; font-size: 14px;';
                container.appendChild(messageSpan);
                
                toast.appendChild(container);
                
                // Show overlay and toast
                overlay.classList.add('show');
                toast.classList.remove('hide');
                toast.classList.add('show');
                
                // Hide after delay
                setTimeout(() => {
                    toast.classList.remove('show');
                    toast.classList.add('hide');
                    overlay.classList.remove('show');
                }, 1500);
            } else {
                console.error('Toast or overlay elements not found');
            }
        };
        
        window.playClickSound = function() {
            // Sound disabled for this page as requested
        };
        
        // Override notification badge path for this page
        const originalUpdateNotificationBadge = updateNotificationBadge;
        updateNotificationBadge = function() {
            const badge = document.querySelector('.notification-badge');
            if (!badge) return;
            
            // Make API call to get notification count
            fetch('../navigation/get_notification_count.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const count = data.count;
                        badge.textContent = count;
                        
                        // Add pulse animation if there are new notifications
                        if (count > 0) {
                            badge.classList.add('pulse');
                        } else {
                            badge.classList.remove('pulse');
                        }
                    }
                })
                .catch(error => {
                    console.error('Error updating notification badge:', error);
                });
        };
        
        // Initialize notification badge
        document.addEventListener('DOMContentLoaded', function() {
            updateNotificationBadge();
            // Update every 30 seconds
            setInterval(updateNotificationBadge, 30000);
        });
    </script>
    <script>
        // Pass user data to JavaScript
        window.userData = {
            id: <?php echo $user_id; ?>,
            username: '<?php echo htmlspecialchars($user['username']); ?>',
            email: '<?php echo htmlspecialchars($user['email']); ?>',
            gradeLevel: '<?php echo htmlspecialchars($user['grade_level']); ?>'
        };
        
        // Pass game progress data
        window.gameProgress = <?php echo json_encode($game_progress); ?>;

        // Logout functionality
        function showLogoutModal() {
            const modal = document.getElementById('logoutModal');
            const confirmation = document.getElementById('logoutConfirmation');
            
            if (modal && confirmation) {
                modal.classList.add('show');
                confirmation.classList.remove('hide');
                confirmation.classList.add('show');
            }
        }

        function hideLogoutModal() {
            const modal = document.getElementById('logoutModal');
            const confirmation = document.getElementById('logoutConfirmation');
            
            if (modal && confirmation) {
                confirmation.classList.remove('show');
                confirmation.classList.add('hide');
                modal.classList.remove('show');
            }
        }

        function confirmLogout() {
            // Play click sound
            playClickSound();
            
            // Redirect to logout endpoint
            window.location.href = '../onboarding/logout.php';
        }
        
    </script>
</body>
</html>
