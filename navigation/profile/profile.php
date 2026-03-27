<?php
require_once '../../onboarding/config.php';
require_once '../../includes/greeting.php';
require_once '../../includes/gwa_updater.php';

// Check if user is logged in, redirect to login if not
if (!isLoggedIn()) {
    header('Location: ../../onboarding/login.php');
    exit();
}

// Get user information
$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT username, email, grade_level, section, about_me, created_at, profile_image, COALESCE(duel_wins, 0) AS duel_wins, COALESCE(duel_losses, 0) AS duel_losses, COALESCE(duel_win_streak, 0) AS duel_win_streak FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    // User not found, destroy session and redirect to login
    session_destroy();
    header('Location: ../../onboarding/login.php');
    exit();
}

// Handle profile update
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $section = trim($_POST['section'] ?? '');
    $about_me = trim($_POST['about_me'] ?? '');
    
    // Validate input
    if (empty($username) || empty($email)) {
        echo json_encode(['success' => false, 'message' => 'Username and email are required']);
        exit();
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email format']);
        exit();
    }
    
    // Check if username or email already exists (excluding current user)
    $stmt = $pdo->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
    $stmt->execute([$username, $email, $user_id]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Username or email already exists']);
        exit();
    }
    
    // Update user profile
    $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ?, section = ?, about_me = ?, updated_at = NOW() WHERE id = ?");
    if ($stmt->execute([$username, $email, $section, $about_me, $user_id])) {
        // AUDIT LOG: Updates Profile Settings
        require_once '../../includes/Logger.php';
        logAudit('Updates Profile Settings', $user_id, $username, "Updated profile settings (Section: $section)");

        // Return the updated values for JavaScript to use
        echo json_encode([
            'success' => true, 
            'message' => 'Profile updated successfully!',
            'about_me' => $about_me,
            'section' => $section,
            'username' => $username
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update profile']);
    }
    exit();
}

// Handle profile image upload
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'upload_profile_image') {
    // Check if file was uploaded
    if (!isset($_FILES['profile_image']) || $_FILES['profile_image']['error'] === UPLOAD_ERR_NO_FILE) {
        echo json_encode(['success' => false, 'message' => 'No file was uploaded']);
        exit();
    }
    
    $file = $_FILES['profile_image'];
    
    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'File upload error']);
        exit();
    }
    
    // Validate file size (5MB = 5,242,880 bytes)
    $maxFileSize = 5 * 1024 * 1024; // 5MB
    if ($file['size'] > $maxFileSize) {
        echo json_encode(['success' => false, 'message' => 'File size must be less than 5MB']);
        exit();
    }
    
    // Validate file type
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $allowedTypes)) {
        echo json_encode(['success' => false, 'message' => 'Only image files (JPG, PNG, GIF, WEBP) are allowed']);
        exit();
    }
    
    // Get file extension
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid file extension']);
        exit();
    }
    
    // Generate unique filename
    $newFilename = 'user_' . $user_id . '_' . time() . '.' . $extension;
    $uploadDir = '../../uploads/profile_avatars/';
    $uploadPath = $uploadDir . $newFilename;
    
    // Create directory if it doesn't exist
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Delete old profile image if exists
    if (!empty($user['profile_image']) && file_exists('../../' . $user['profile_image'])) {
        unlink('../../' . $user['profile_image']);
    }
    
    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
        // Update database with new profile image path
        $relativePath = 'uploads/profile_avatars/' . $newFilename;
        $stmt = $pdo->prepare("UPDATE users SET profile_image = ?, updated_at = NOW() WHERE id = ?");
        
        if ($stmt->execute([$relativePath, $user_id])) {
            // AUDIT LOG: updates Profile Picture
            require_once '../../includes/Logger.php';
            logAudit('updates Profile Picture', $user_id, $user['username'], "Uploaded new profile image: $newFilename");

            echo json_encode([
                'success' => true,
                'message' => 'Profile image updated successfully!',
                'image_path' => $relativePath
            ]);
        } else {
            // Delete uploaded file if database update fails
            unlink($uploadPath);
            echo json_encode(['success' => false, 'message' => 'Failed to update database']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to upload file']);
    }
    exit();
}

// Get user's rank from leaderboard
$rank_stmt = $pdo->prepare("
    SELECT COUNT(*) + 1 as user_rank
    FROM users u
    LEFT JOIN game_progress gp ON u.id = gp.user_id AND gp.game_type = 'vocabworld'
    WHERE u.id != ?
    AND (COALESCE(gp.player_level, 1) > COALESCE((SELECT player_level FROM game_progress WHERE user_id = ? AND game_type = 'vocabworld'), 1)
         OR (COALESCE(gp.player_level, 1) = COALESCE((SELECT player_level FROM game_progress WHERE user_id = ? AND game_type = 'vocabworld'), 1)
             AND COALESCE(gp.total_monsters_defeated, 0) > COALESCE((SELECT total_monsters_defeated FROM game_progress WHERE user_id = ? AND game_type = 'vocabworld'), 0)))
");
$rank_stmt->execute([$user_id, $user_id, $user_id, $user_id]);
$user_rank = $rank_stmt->fetchColumn();

// Update all user GWAs first
updateAllUserGWAs($pdo, $user_id);

// Get user's game stats with stored GWA
$stmt = $pdo->prepare("SELECT 
    gp.game_type,
    ug.gwa as gwa_score,
    gp.player_level,
    gp.total_experience_earned,
    gp.total_monsters_defeated,
    gp.total_play_time as total_play_time_seconds
    FROM game_progress gp
    LEFT JOIN user_gwa ug ON gp.user_id = ug.user_id AND gp.game_type = ug.game_type
    WHERE gp.user_id = ?");
$stmt->execute([$user_id]);
$game_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// For backward compatibility, ensure gwa_score is set (fallback to calculation if not in user_gwa)
foreach ($game_stats as &$stat) {
    if (!isset($stat['gwa_score']) || $stat['gwa_score'] === null) {
        $stat['gwa_score'] = $stat['player_level'] * 1.5;
    }
}
unset($stat); // Break the reference

// Get player level, experience, and monsters defeated
$stmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(player_level), 1) as total_level,
        COALESCE(SUM(total_experience_earned), 0) as total_experience,
        COALESCE(SUM(total_monsters_defeated), 0) as total_monsters_defeated,
        COALESCE(SUM(total_play_time), 0) as total_play_time_seconds
    FROM game_progress 
    WHERE user_id = ?
");
$stmt->execute([$user_id]);
$player_stats = $stmt->fetch();

// Get character selection data for JavaScript
$character_stmt = $pdo->prepare("
    SELECT character_image_path, selected_character 
    FROM character_selections 
    WHERE user_id = ? AND game_type = 'vocabworld' 
    LIMIT 1
");
$character_stmt->execute([$user_id]);
$character_result = $character_stmt->fetch();

// Set default values for JavaScript
$character_images = [
    'emma' => '../../MainGame/vocabworld/assets/characters/girl_char/character_emma.png',
    'ethan' => '../../MainGame/vocabworld/assets/characters/boy_char/character_ethan.png',
    'amber' => '../../MainGame/vocabworld/assets/characters/amber_char/amber.png',
    'kael' => '../../MainGame/vocabworld/assets/characters/kael_char/kael.png',
    'rex' => '../../MainGame/vocabworld/assets/characters/rex_char/rex.png',
    'orion' => '../../MainGame/vocabworld/assets/characters/orion_char/orion.png',
    'ember' => '../../MainGame/vocabworld/assets/characters/ember_char/ember.png',
    'astra' => '../../MainGame/vocabworld/assets/characters/astra_char/astra.png',
    'sylvi' => '../../MainGame/vocabworld/assets/characters/sylvi_char/sylvi.png',
    'girl' => '../../MainGame/vocabworld/assets/characters/girl_char/character_emma.png',
    'boy' => '../../MainGame/vocabworld/assets/characters/boy_char/character_ethan.png'
];

$character_image = $character_images['ethan'];
$character_name = 'Ethan';

if ($character_result) {
    // If we have a character selection in the database
    if (!empty($character_result['selected_character'])) {
        $character_name = $character_result['selected_character'];
        $char_key = strtolower($character_name);
        
        // If we have a direct match in our character images array
        if (isset($character_images[$char_key])) {
            $character_image = $character_images[$char_key];
        } 
        // Otherwise try to find a matching character in the paths
        else if (!empty($character_result['character_image_path'])) {
            $character_image = $character_result['character_image_path'];
            foreach ($character_images as $char => $path) {
                if (stripos($character_image, $char) !== false) {
                    $character_image = $path;
                    break;
                }
            }
        }
    } 
    // Fallback to extracting name from image path if no selected_character
    else if (!empty($character_result['character_image_path'])) {
        $character_image = $character_result['character_image_path'];
        if (preg_match('/character_([^.]+)\./', $character_image, $matches)) {
            $character_name = ucfirst($matches[1]);
        }
    }
}


// Get Essence
$essence = 0;
$essence_manager_path = '../../MainGame/vocabworld/api/essence_manager.php';
if (file_exists($essence_manager_path)) {
    require_once $essence_manager_path;
    if (class_exists('EssenceManager')) {
        $essenceManager = new EssenceManager($pdo);
        $essence = $essenceManager->getEssence($user_id);
    }
}

// Get Shards
$shards = 0;
$shard_manager_path = '../../MainGame/vocabworld/shard_manager.php';
if (file_exists($shard_manager_path)) {
    require_once $shard_manager_path;
    if (class_exists('ShardManager')) {
        $shardManager = new ShardManager($pdo);
        $shard_result = $shardManager->getShardBalance($user_id);
        if ($shard_result && isset($shard_result['current_shards'])) {
            $shards = $shard_result['current_shards'];
        }
    }
}

// ════════════════════════════════════════
// DAILY STREAK & XP ACTIVITY CALENDAR
// ════════════════════════════════════════
require_once '../../includes/streak_manager.php';

// Fetch calendar data
$calendarData = getUserCalendarData($user_id, $pdo);

// Fetch fresh user streak data
$streakStmt = $pdo->prepare("SELECT current_streak, longest_streak, streak_freezes, total_xp FROM users WHERE id = ?");
$streakStmt->execute([$user_id]);
$streakInfo = $streakStmt->fetch();

// Pass calendar to JS
$calendarJson = json_encode($calendarData);

// Calculate max XP in any single day for color scaling
$maxDayXp = 0;
foreach ($calendarData as $day) {
    if ($day['xp'] > $maxDayXp) {
        $maxDayXp = $day['xp'];
    }
}
// Minimum scale of 100 so even small XP days show some color
$maxDayXp = max($maxDayXp, 100);

// Get user's favorites with game info
$stmt = $pdo->prepare("SELECT game_type FROM user_favorites WHERE user_id = ?");
$stmt->execute([$user_id]);
$favorites = $stmt->fetchAll();

// Get pending friend requests for the current user
$stmt = $pdo->prepare("
    SELECT fr.id, fr.requester_id, fr.created_at, u.username, u.email, u.grade_level
    FROM friend_requests fr
    JOIN users u ON fr.requester_id = u.id
    WHERE fr.receiver_id = ? AND fr.status = 'pending'
    ORDER BY fr.created_at DESC
");
$stmt->execute([$user_id]);
$friend_requests = $stmt->fetchAll();

// Get crescent notifications
$stmt = $pdo->prepare("
    SELECT id, type, message, data, created_at
    FROM notifications
    WHERE user_id = ? AND type = 'cresent_received'
");
$stmt->execute([$user_id]);
$cresent_notifications = $stmt->fetchAll();

// Get notification count for badge (both friend requests and crescent notifications)
$notification_count = count($friend_requests) + count($cresent_notifications);

// Get user's friends count
$stmt = $pdo->prepare("
    SELECT COUNT(*) as friends_count 
    FROM friends 
    WHERE user1_id = ? OR user2_id = ?
");
$stmt->execute([$user_id, $user_id]);
$friends_count = $stmt->fetch()['friends_count'];

// Initialize user_fame table and get user stats
function initializeUserFame($pdo, $username) {
    $stmt = $pdo->prepare("INSERT IGNORE INTO user_fame (username, cresents, views) VALUES (?, 0, 0)");
    $stmt->execute([$username]);
}

function getUserFame($pdo, $username) {
    initializeUserFame($pdo, $username);
    $stmt = $pdo->prepare("SELECT cresents, views FROM user_fame WHERE username = ?");
    $stmt->execute([$username]);
    return $stmt->fetch();
}

// Create user_crescents table if it doesn't exist
$pdo->exec("CREATE TABLE IF NOT EXISTS user_crescents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    giver_username VARCHAR(255) NOT NULL,
    receiver_username VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_crescent (giver_username, receiver_username)
)");

// Get user fame stats
$user_fame = getUserFame($pdo, $user['username']);
$views_count = $user_fame ? $user_fame['views'] : 0;
$crescents_count = $user_fame ? $user_fame['cresents'] : 0;

// ════════════════════════════════════════
// GITHUB INTEGRATION
// ════════════════════════════════════════
require_once '../../includes/github_api.php';
require_once '../../includes/activity_logger.php';

// Get activity feed
$activityFeed = getActivityFeed($_SESSION['user_id'], 20, $pdo);

// Get GitHub data if connected
$githubStats = null;
$githubCalendar = null;
$githubUsername = $user['github_username'] ?? null;

if ($githubUsername) {
    $githubStats = getGitHubStats(
        $githubUsername, 
        $_SESSION['user_id'],
        $pdo
    );
    $githubCalendar = getGitHubContributions($githubUsername);
}

// Pass to JS
$activityJson = json_encode($activityFeed);
$githubCalendarJson = json_encode($githubCalendar ?? []);
$githubConnected = !empty($githubUsername) ? 'true' : 'false';

// No longer calculating user level since it's been replaced with email
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include '../../includes/favicon.php'; ?>
    <title>Profile - CodeDungeon</title>
    <link rel="stylesheet" href="../../styles.css">
    <link rel="stylesheet" href="../shared/navigation.css?v=<?php echo filemtime('../shared/navigation.css'); ?>">
    <link rel="stylesheet" href="../../notif/toast.css?v=<?php echo filemtime('../../notif/toast.css'); ?>">
    <link rel="stylesheet" href="../../includes/loader.css?v=<?php echo filemtime('../../includes/loader.css'); ?>">
    <link rel="stylesheet" href="../../includes/crop-modal.css?v=<?php echo filemtime('../../includes/crop-modal.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css">
    <link rel="stylesheet" href="profile.css?v=<?php echo filemtime('profile.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
</head>
<body>
    <?php include '../../includes/page-loader.php'; ?>
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
            <a href="../../menu.php" class="nav-link">
                <i class="fas fa-house"></i>
                <span>Menu</span>
            </a>
            <a href="../favorites/favorites.php" class="nav-link">
                <i class="fas fa-star"></i>
                <span>Favorites</span>
            </a>
            <a href="../friends/friends.php" class="nav-link">
                <i class="fas fa-users"></i>
                <span>Friends</span>
            </a>
            <a href="profile.php" class="nav-link active">
                <i class="fas fa-user"></i>
                <span>Profile</span>
            </a>
            <?php if (in_array($user['grade_level'], ['Teacher', 'Admin', 'Developer'])): ?>
            <a href="../teacher/dashboard.php" class="nav-link">
                <i class="fas fa-chalkboard-teacher"></i>
                <span>Teacher</span>
            </a>
            <?php endif; ?>
            <?php if (in_array($user['grade_level'], ['Developer', 'Admin'])): ?>
            <a href="../admin/dashboard.php" class="nav-link">
                <i class="fas fa-shield-alt"></i>
                <span>Admin</span>
            </a>
            <?php endif; ?>
        </nav>
    </div>

    <!-- Header -->
    <header class="top-header">
        <div class="header-right">
            <div class="notification-icon" onclick="window.location.href='../notification.php'">
                <i class="fas fa-bell"></i>
                <span class="notification-badge"><?php echo $notification_count; ?></span>
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
                        <img src="<?php echo !empty($user['profile_image']) ? '../../' . htmlspecialchars($user['profile_image']) : '../../assets/menu/defaultuser.png'; ?>" alt="Profile" class="profile-img">
                    </a>
                    <div class="profile-dropdown-content">
                        <div class="profile-dropdown-header">
                            <img src="<?php echo !empty($user['profile_image']) ? '../../' . htmlspecialchars($user['profile_image']) : '../../assets/menu/defaultuser.png'; ?>" alt="Profile" class="profile-dropdown-avatar">
                            <div class="profile-dropdown-info">
                                <div class="profile-dropdown-name"><?php echo htmlspecialchars($user['username']); ?></div>
                                <div class="profile-dropdown-email"><?php echo htmlspecialchars($user['email']); ?></div>
                            </div>
                        </div>
                        <div class="profile-dropdown-menu">
                            <a href="profile.php" class="profile-dropdown-item">
                                <i class="fas fa-user"></i>
                                <span>View Profile</span>
                            </a>
                            <a href="../favorites/favorites.php" class="profile-dropdown-item">
                                <i class="fas fa-star"></i>
                                <span>My Favorites</span>
                            </a>
                            <a href="../../settings/settings.php" class="profile-dropdown-item">
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

    <!-- Main Content -->
    <div class="main-content">
        <div class="profile-container">
            <div class="profile-header">
                <div class="profile-avatar">
                    <img src="<?php echo !empty($user['profile_image']) ? '../../' . htmlspecialchars($user['profile_image']) : '../../assets/menu/defaultuser.png'; ?>" alt="Profile" class="large-avatar" id="profile-avatar-img">
                    <button class="change-avatar-btn" id="change-avatar-btn">
                        <i class="fas fa-camera"></i>
                    </button>
                    <input type="file" id="profile-image-input" accept="image/jpeg,image/jpg,image/png,image/gif,image/webp" style="display: none;">
                </div>
                <div class="profile-info">
                    <h1><?php echo htmlspecialchars($user['username']); ?></h1>
                    <p class="player-email"><?php echo htmlspecialchars($user['email']); ?></p>
                    <p class="about-me-text"><?php echo ($user['about_me'] !== null && $user['about_me'] !== '') ? htmlspecialchars($user['about_me']) : 'Tell us something about yourself...'; ?></p>
                    
                    <!-- User Fame Section -->
                    <div class="user-fame-section">
                        <div class="fame-stats">
                            <div class="fame-item">
                                <div class="tooltip">Friends: <?php echo number_format($friends_count); ?></div>
                                <img src="../../assets/pixels/friendhat.png" alt="Friends" class="fame-icon">
                                <span class="fame-value"><?php echo number_format($friends_count); ?></span>
                            </div>
                            <span class="fame-separator">●</span>
                            <div class="fame-item">
                                <div class="tooltip">Profile Views: <?php echo number_format($views_count); ?></div>
                                <img src="../../assets/pixels/eyeviews.png" alt="Views" class="fame-icon">
                                <span class="fame-value"><?php echo number_format($views_count); ?></span>
                            </div>
                            <span class="fame-separator">●</span>
                            <div class="fame-item">
                                <div class="tooltip">Crescents: <?php echo number_format($crescents_count); ?></div>
                                <img src="../../assets/pixels/cresent.png" alt="Crescents" class="fame-icon">
                                <span class="fame-value"><?php echo number_format($crescents_count); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="badge-container">
                        <?php 
                        $is_jaderby = in_array(strtolower($user['username']), ['daksh goel', 'chirag aggarwal'], true);
                        $is_admin = ($user['grade_level'] === 'Admin' || $is_jaderby);
                        $is_teacher = ($user['grade_level'] === 'Teacher');
                        
                        if ($is_jaderby): ?>
                            <div class="badge-wrapper" onclick="showBadgeInfo('Developer', 'Lead Developer of CodeDungeon'); return false;">
                                <img src="../../assets/badges/developer.png" alt="Developer Badge" class="user-badge">
                                <div class="badge-tooltip">
                                    <span class="badge-title">Developer</span>
                                    <span class="badge-desc">Lead Developer</span>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if ($is_admin): ?>
                            <div class="badge-wrapper" onclick="showBadgeInfo('Administrator', 'Has full administrative privileges' . ($is_jaderby ? ' and is the developer' : '') . '.'); return false;">
                                <img src="../../assets/badges/moderator.png" alt="Admin Badge" class="user-badge">
                                <div class="badge-tooltip">
                                    <span class="badge-title">Admin</span>
                                    <span class="badge-desc">System Admin</span>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if ($is_teacher): ?>
                            <div class="badge-wrapper" onclick="showBadgeInfo('Teacher', 'Certified educator with teaching privileges.'); return false;">
                                <img src="../../assets/badges/teacher.png" alt="Teacher Badge" class="user-badge">
                                <div class="badge-tooltip">
                                    <span class="badge-title">Teacher</span>
                                    <span class="badge-desc">Educator</span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Grade & Section Container -->
            <div class="grade-section-container">
                <div class="section-header">
                    <i class="fas fa-graduation-cap"></i>
                    <h3>Grade & Section</h3>
                </div>
                <div class="grade-section-grid">
                    <div class="grade-section-item">
                        <div class="grade-section-icon">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <div class="grade-section-details">
                            <span class="grade-section-label">Grade Level</span>
                            <span class="grade-section-value"><?php echo htmlspecialchars($user['grade_level']); ?></span>
                        </div>
                    </div>
                    <div class="grade-section-item">
                        <div class="grade-section-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="grade-section-details">
                            <span class="grade-section-label">Section</span>
                            <span class="grade-section-value"><?php echo !empty($user['section']) ? htmlspecialchars($user['section']) : 'Not specified'; ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Player Stats Section (Empty, will be moved to Game Stats) -->
            <div class="player-stats-section" style="display: none;"></div>
            
            <!-- Game Stats Section -->
            <div class="gamestats-section">
                <div class="section-header">
                    <div class="header-title">
                        <i class="fas fa-gamepad"></i>
                        <h3>Game Stats</h3>
                    </div>
                </div>

                <div class="game-stats-body">
                    <!-- Player Profile Section -->
                    <div class="player-profile-section">
                        <div class="character-display">
                            <div class="character-avatar-wrapper">
                                <div class="character-avatar-glow"></div>
                                <img src="<?php echo htmlspecialchars($character_image); ?>" 
                                     alt="<?php echo htmlspecialchars($character_name); ?>" 
                                     class="character-avatar"
                                     id="character-sprite"
                                     data-character="<?php echo strtolower($character_name); ?>">
                            </div>
                            <div class="character-details">
                                <h3 class="character-name" id="character-name"><?php echo htmlspecialchars($character_name); ?></h3>
                                <div class="character-level-badge">
                                    <i class="fas fa-star"></i>
                                    <span>Level <?php echo number_format($player_stats['total_level']); ?></span>
                                </div>
                            </div>
                            <?php if (isset($user_rank)): ?>
                                <div class="character-rank-badge rank-<?php echo $user_rank <= 3 ? $user_rank : 'other'; ?>">
                                    <?php if ($user_rank <= 3): ?>
                                        <i class="fas fa-trophy"></i>
                                    <?php endif; ?>
                                    <span>Rank #<?php echo $user_rank; ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Stats Grid -->
                    <div class="stats-sections">
                        <!-- Combat Stats -->
                        <div class="stats-category">
                            <div class="category-header">
                                <img src="../../MainGame/vocabworld/charactermenu/assets/fc1089.png" alt="Stats" style="width: 24px; height: 24px; margin-right: 8px;">
                                <h4>Stats</h4>
                            </div>
                            <div class="stats-cards-grid">
                                <div class="stat-card-modern">
                                    <div class="stat-card-icon">
                                        <img src="../../MainGame/vocabworld/assets/stats/level.png" alt="Level">
                                    </div>
                                    <div class="stat-card-content">
                                        <span class="stat-card-label">Level</span>
                                        <span class="stat-card-value"><?php echo number_format($player_stats['total_level']); ?></span>
                                    </div>
                                </div>
                                <div class="stat-card-modern">
                                    <div class="stat-card-icon">
                                        <img src="../../MainGame/vocabworld/assets/stats/total_xp.png" alt="Experience">
                                    </div>
                                    <div class="stat-card-content">
                                        <span class="stat-card-label">Experience</span>
                                        <span class="stat-card-value"><?php echo number_format($player_stats['total_experience']); ?></span>
                                    </div>
                                </div>
                                <div class="stat-card-modern">
                                    <div class="stat-card-icon">
                                        <img src="../../MainGame/vocabworld/assets/stats/sword1.png" alt="Monsters">
                                    </div>
                                    <div class="stat-card-content">
                                        <span class="stat-card-label">Monsters Defeated</span>
                                        <span class="stat-card-value"><?php echo number_format($player_stats['total_monsters_defeated']); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Resources -->
                        <div class="stats-category">
                            <div class="category-header">
                                <img src="assets/fc133.png" alt="Resources" style="width: 24px; height: 24px; margin-right: 8px;">
                                <h4>Resources</h4>
                            </div>
                            <div class="stats-cards-grid resources-grid">
                                <div class="stat-card-modern resource-card">
                                    <div class="stat-card-icon">
                                        <img src="../../MainGame/vocabworld/assets/currency/essence.png" alt="Essence">
                                    </div>
                                    <div class="stat-card-content">
                                        <span class="stat-card-label">Essence</span>
                                        <span class="stat-card-value essence-value"><?php echo number_format($essence); ?></span>
                                    </div>
                                </div>
                                <div class="stat-card-modern resource-card">
                                    <div class="stat-card-icon">
                                        <img src="../../MainGame/vocabworld/assets/currency/shard1.png" alt="Shards">
                                    </div>
                                    <div class="stat-card-content">
                                        <span class="stat-card-label">Shards</span>
                                        <span class="stat-card-value shard-value"><?php echo number_format($shards); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Performance -->
                        <div class="stats-category">
                            <div class="category-header">
                                <img src="assets/fc112.png" alt="Performance" style="width: 24px; height: 24px; margin-right: 8px;">
                                <h4>Performance</h4>
                            </div>
                            <div class="stats-cards-grid">
                                <div class="stat-card-modern gwa-card">
                                    <div class="stat-card-icon gwa-icon">
                                        <img src="../../MainGame/vocabworld/assets/stats/gwa.png" alt="GWA">
                                    </div>
                                    <div class="stat-card-content">
                                        <span class="stat-card-label">GWA</span>
                                        <span class="stat-card-value gwa-value-display"><?php echo number_format($player_stats['total_level'] * 1.5, 2); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- STREAK STATS SECTION -->
            <div style="background: rgba(255, 255, 255, 0.03); border-radius: 15px; padding: 1.5rem; margin: 2rem 0; border: 1px solid rgba(96, 239, 255, 0.1); position: relative; overflow: hidden; width: 100%;">
                <div style="position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, #60efff, #00ff87);"></div>
                
                <div style="display: flex; align-items: center; gap: 0.8rem; margin-bottom: 1.5rem;">
                    <i class="fas fa-fire" style="color: #ff6b6b; font-size: 1.2rem;"></i>
                    <h3 style="color: white; margin: 0; font-size: 1.1rem; font-weight: 600;">Coding Streak</h3>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem;">
                
                    <!-- Current Streak -->
                    <div style="background: rgba(30, 30, 40, 0.6); border: 1px solid rgba(96, 239, 255, 0.2); border-radius: 12px; padding: 1.2rem; text-align: center; transition: all 0.3s ease;">
                        <div style="font-size: 2.5rem; font-weight: bold; color: #60efff; margin-bottom: 0.5rem;" id="streak-number">
                            <?php echo $streakInfo['current_streak'] ?? 0; ?>
                        </div>
                        <div style="font-size: 0.8rem; color: rgba(255, 255, 255, 0.7); margin-bottom: 0.5rem;">
                            Day Streak
                        </div>
                        <div style="font-size: 1.5rem;">
                            🔥
                        </div>
                    </div>
                    
                    <!-- Longest Streak -->
                    <div style="background: rgba(30, 30, 40, 0.6); border: 1px solid rgba(96, 239, 255, 0.2); border-radius: 12px; padding: 1.2rem; text-align: center; transition: all 0.3s ease;">
                        <div style="font-size: 2.5rem; font-weight: bold; color: #00ff87; margin-bottom: 0.5rem;">
                            <?php echo $streakInfo['longest_streak'] ?? 0; ?>
                        </div>
                        <div style="font-size: 0.8rem; color: rgba(255, 255, 255, 0.7); margin-bottom: 0.5rem;">
                            Best Streak
                        </div>
                        <div style="font-size: 1.5rem;">
                            🏆
                        </div>
                    </div>
                    
                    <!-- Streak Freezes -->
                    <div style="background: rgba(30, 30, 40, 0.6); border: 1px solid rgba(96, 239, 255, 0.2); border-radius: 12px; padding: 1.2rem; text-align: center; transition: all 0.3s ease;">
                        <div style="font-size: 2.5rem; font-weight: bold; color: #60efff; margin-bottom: 0.5rem;">
                            <?php echo $streakInfo['streak_freezes'] ?? 0; ?>
                        </div>
                        <div style="font-size: 0.8rem; color: rgba(255, 255, 255, 0.7); margin-bottom: 0.5rem;">
                            Freezes Left
                        </div>
                        <div style="font-size: 1.5rem;">
                            🧊
                        </div>
                    </div>
                    
                </div>
                
                <!-- Streak message if any -->
                <?php if (!empty($_SESSION['streak_message'])): ?>
                <div style="margin-top: 1rem; padding: 0.8rem 1rem; border-radius: 8px; background: rgba(0, 255, 135, 0.1); font-size: 0.85rem; color: #00ff87; border: 1px solid rgba(0, 255, 135, 0.2);">
                    🔥 <?php echo htmlspecialchars($_SESSION['streak_message']); ?>
                </div>
                <?php endif; ?>
                
            </div>

            <!-- XP ACTIVITY CALENDAR SECTION -->
            <div style="background: rgba(255, 255, 255, 0.03); border-radius: 15px; padding: 1.5rem; margin: 0; border: 1px solid rgba(96, 239, 255, 0.1); position: relative; overflow: hidden; width: 100%;">
                <div style="position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, #60efff, #00ff87);"></div>

                <div style="display: flex; align-items: center; gap: 0.8rem; margin-bottom: 1.5rem;">
                    <i class="fas fa-chart-line" style="color: #00ff87; font-size: 1.2rem;"></i>
                    <div>
                        <h3 style="color: white; margin: 0; font-size: 1.1rem; font-weight: 600;">XP Activity</h3>
                        <span style="font-size: 0.75rem; color: rgba(255, 255, 255, 0.6); font-weight: normal;">
                            Last 52 weeks
                        </span>
                    </div>
                </div>
                
                <!-- Month labels row -->
                <div id="calendar-months" 
                    style="display: flex; margin-left: 32px; font-size: 0.7rem; color: rgba(255, 255, 255, 0.6); margin-bottom: 8px;">
                    <!-- Populated by JS -->
                </div>
                
                <!-- Calendar grid wrapper -->
                <div style="display: flex; gap: 4px; margin-bottom: 1rem;">
                
                    <!-- Day labels column -->
                    <div style="display: flex; flex-direction: column; gap: 3px; justify-content: space-around; font-size: 0.65rem; color: rgba(255, 255, 255, 0.6); padding-top: 2px;">
                        <span>Mon</span>
                        <span>Wed</span>
                        <span>Fri</span>
                    </div>
                    
                    <!-- The grid itself -->
                    <div id="xp-calendar-grid"
                        style="display: flex; gap: 3px; flex-wrap: nowrap; overflow-x: auto; padding-bottom: 4px;">
                        <!-- 52 columns of 7 squares each -->
                        <!-- Populated by JS -->
                    </div>
                    
                </div>
                
                <!-- Legend -->
                <div style="display: flex; align-items: center; gap: 6px; font-size: 0.75rem; color: rgba(255, 255, 255, 0.6); justify-content: flex-end;">
                    <span>Less</span>
                    <div style="width:12px; height:12px; border-radius: 2px; background: rgba(255,255,255,0.06);"></div>
                    <div style="width:12px; height:12px; border-radius: 2px; background: rgba(96,239,255,0.3);"></div>
                    <div style="width:12px; height:12px; border-radius: 2px; background: rgba(96,239,255,0.6);"></div>
                    <div style="width:12px; height:12px; border-radius: 2px; background: rgba(96,239,255,0.85);"></div>
                    <div style="width:12px; height:12px; border-radius: 2px; background: #60efff;"></div>
                    <span>More XP</span>
                </div>
                
                <!-- Tooltip element -->
                <div id="calendar-tooltip"
                    style="position: fixed; display: none; background: rgba(10, 10, 15, 0.95); border: 1px solid rgba(96, 239, 255, 0.3); border-radius: 12px; padding: 8px 12px; font-size: 0.8rem; color: white; pointer-events: none; z-index: 9999; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);">
                </div>
                
            </div>

            <!-- XP SHOP SECTION -->
            <div style="background: rgba(255, 255, 255, 0.03); border-radius: 15px; padding: 1.5rem; margin: 2rem 0; border: 1px solid rgba(96, 239, 255, 0.1); position: relative; overflow: hidden; width: 100%;">
                <div style="position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, #60efff, #00ff87);"></div>
                
                <div style="display: flex; align-items: center; gap: 0.8rem; margin-bottom: 1.5rem;">
                    <i class="fas fa-store" style="color: #f3c13a; font-size: 1.2rem;"></i>
                    <h3 style="color: white; margin: 0; font-size: 1.1rem; font-weight: 600;">XP Shop</h3>
                </div>
                
                <div style="display: flex; align-items: center; justify-content: space-between; padding: 1.2rem; background: rgba(96, 239, 255, 0.05); border-radius: 12px; border: 1px solid rgba(96, 239, 255, 0.15);">
                    
                    <div>
                        <div style="font-size: 1.1rem; font-weight: bold; color: white; margin-bottom: 0.5rem;">
                            🧊 Streak Freeze
                        </div>
                        <div style="font-size: 0.85rem; color: rgba(255, 255, 255, 0.7); line-height: 1.4;">
                            Protects your streak for one missed day. Used automatically.
                        </div>
                    </div>
                    
                    <div style="text-align: right; flex-shrink: 0; margin-left: 2rem;">
                        <div style="font-size: 0.8rem; color: rgba(255, 255, 255, 0.6); margin-bottom: 0.5rem;">
                            Cost
                        </div>
                        <div style="font-size: 1.3rem; font-weight: bold; color: #60efff; margin-bottom: 0.8rem;">
                            500 XP
                        </div>
                        <button id="buy-freeze-btn"
                            style="padding: 0.7rem 1.2rem; background: linear-gradient(135deg, #60efff 0%, #00d4ff 100%); border: none; color: white; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 0.9rem; transition: all 0.3s ease; white-space: nowrap;"
                            <?php if (($streakInfo['total_xp'] ?? 0) < 500 || ($streakInfo['streak_freezes'] ?? 0) >= 3): ?>
                                disabled
                                style="padding: 0.7rem 1.2rem; background: #555; border: none; color: #999; border-radius: 8px; cursor: not-allowed; font-weight: 600; font-size: 0.9rem;"
                            <?php endif; ?>>
                            <?php if (($streakInfo['streak_freezes'] ?? 0) >= 3): ?>
                                Max owned (3)
                            <?php elseif (($streakInfo['total_xp'] ?? 0) < 500): ?>
                                Need more XP
                            <?php else: ?>
                                Buy Freeze
                            <?php endif; ?>
                        </button>
                    </div>
                    
                </div>
                
                <div style="margin-top: 1rem; font-size: 0.8rem; color: rgba(255, 255, 255, 0.6);">
                    Your XP balance: 
                    <strong style="color: #60efff;">
                        <?php echo number_format($streakInfo['total_xp'] ?? 0); ?> XP
                    </strong>
                    · Max 3 freezes at a time
                </div>
                
            </div>

            <!-- DUEL STATS SECTION (NEW) -->
            <div style="background: rgba(255, 255, 255, 0.03); border-radius: 15px; padding: 1.5rem; margin: 2rem 0; border: 1px solid rgba(96, 239, 255, 0.1); position: relative; overflow: hidden; width: 100%;">
                <div style="position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, #ff6666, #ffcc70);"></div>
                
                <div style="display: flex; align-items: center; gap: 0.8rem; margin-bottom: 1.5rem;">
                    <i class="fas fa-crossed-swords" style="color: #ff6666; font-size: 1.2rem;"></i>
                    <h3 style="color: white; margin: 0; font-size: 1.1rem; font-weight: 600;">Bug Duel Record</h3>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                    
                    <!-- Wins -->
                    <div style="background: rgba(30, 30, 40, 0.6); border: 1px solid rgba(103, 229, 159, 0.2); border-radius: 12px; padding: 1.2rem; text-align: center;">
                        <div style="font-size: 2.5rem; font-weight: bold; color: #67e59f; margin-bottom: 0.5rem;">
                            <?php echo (int)$user['duel_wins'] ?? 0; ?>
                        </div>
                        <div style="font-size: 0.8rem; color: rgba(255, 255, 255, 0.7);">Wins</div>
                    </div>
                    
                    <!-- Losses -->
                    <div style="background: rgba(30, 30, 40, 0.6); border: 1px solid rgba(255, 102, 102, 0.2); border-radius: 12px; padding: 1.2rem; text-align: center;">
                        <div style="font-size: 2.5rem; font-weight: bold; color: #ff6666; margin-bottom: 0.5rem;">
                            <?php echo (int)$user['duel_losses'] ?? 0; ?>
                        </div>
                        <div style="font-size: 0.8rem; color: rgba(255, 255, 255, 0.7);">Losses</div>
                    </div>
                    
                    <!-- Win Rate -->
                    <div style="background: rgba(30, 30, 40, 0.6); border: 1px solid rgba(96, 239, 255, 0.2); border-radius: 12px; padding: 1.2rem; text-align: center;">
                        <div style="font-size: 2.5rem; font-weight: bold; color: #60efff; margin-bottom: 0.5rem;">
                            <?php 
                            $duelTotal = ((int)$user['duel_wins'] ?? 0) + ((int)$user['duel_losses'] ?? 0);
                            echo $duelTotal > 0 ? round(((int)$user['duel_wins'] ?? 0) / $duelTotal * 100) : 0; 
                            ?>%
                        </div>
                        <div style="font-size: 0.8rem; color: rgba(255, 255, 255, 0.7);">Win Rate</div>
                    </div>
                    
                </div>
                
                <!-- Streak info -->
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 1rem; background: rgba(96, 239, 255, 0.05); border-radius: 8px; border: 1px solid rgba(96, 239, 255, 0.15); margin-bottom: 1.5rem;">
                    <div>
                        <div style="font-size: 0.8rem; color: rgba(255, 255, 255, 0.6);">Current Win Streak</div>
                        <div style="font-size: 1.5rem; font-weight: bold; color: #ffd700;">
                            <?php echo (int)$user['duel_win_streak'] ?? 0; ?> 🔥
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <a href="duel_lobby.php" style="padding: 0.7rem 1.5rem; background: linear-gradient(135deg, #ff6666, #ffcc70); border: none; color: white; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 0.9rem; display: inline-block;">
                            Find Opponent ⚔️
                        </a>
                    </div>
                </div>
                
                <!-- Recent duels -->
                <?php
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
                $duelHistId = (int)$_SESSION['user_id'];
                $stmt->execute([$duelHistId, $duelHistId, $duelHistId, $duelHistId]);
                $recentDuels = $stmt->fetchAll(PDO::FETCH_ASSOC);
                ?>
                
                <?php if (!empty($recentDuels)): ?>
                <div>
                    <div style="font-size: 0.8rem; color: rgba(255, 255, 255, 0.6); margin-bottom: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px;">Recent Duels</div>
                    <?php foreach ($recentDuels as $duel): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.7rem; border-bottom: 1px solid rgba(96, 239, 255, 0.1); font-size: 0.9rem;">
                        <span style="color: rgba(255, 255, 255, 0.8);">
                            vs <strong><?php echo htmlspecialchars($duel['opponent_name'] ?? 'Unknown'); ?></strong>
                        </span>
                        <div style="display: flex; align-items: center; gap: 0.8rem;">
                            <span style="font-size: 0.8rem; color: rgba(255, 255, 255, 0.5);">
                                <?php echo htmlspecialchars($duel['challenge_title'] ?? ''); ?>
                            </span>
                            <span style="padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.75rem; font-weight: bold; color: <?php echo $duel['my_result'] === 'won' ? '#67e59f' : '#ff6666'; ?>; background: <?php echo $duel['my_result'] === 'won' ? 'rgba(103, 229, 159, 0.2)' : 'rgba(255, 102, 102, 0.2)'; ?>;">
                                <?php echo strtoupper($duel['my_result']); ?>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p style="font-size: 0.9rem; color: rgba(255, 255, 255, 0.5); text-align: center; padding: 1rem;">No duel history yet. Challenge someone!</p>
                <?php endif; ?>
                
            </div>

            <!-- ════════════════════════════════════════ -->
            <!-- GITHUB INTEGRATION SECTIONS -->
            <!-- ════════════════════════════════════════ -->

            <?php if (!$githubUsername): ?>

            <!-- CONNECT GITHUB SECTION -->
            <div style="background: rgba(255, 255, 255, 0.03); border-radius: 15px; padding: 1.5rem; margin: 2rem 0; border: 1px solid rgba(96, 239, 255, 0.1); position: relative; overflow: hidden; width: 100%;">
                <div style="position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, #60efff, #00ff87);"></div>
                
                <div style="display: flex; align-items: center; gap: 0.8rem; margin-bottom: 1.5rem;">
                    <svg height="24" width="24" viewBox="0 0 16 16" style="fill: rgba(255,255,255,0.7);">
                        <path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/>
                    </svg>
                    <h3 style="color: white; margin: 0; font-size: 1.1rem; font-weight: 600;">GitHub Integration</h3>
                </div>
                
                <div style="display: flex; align-items: center; gap: 16px; margin-top: 16px; margin-bottom: 16px;">
                    
                    <div style="flex: 1;">
                        <div style="font-weight: 600; margin-bottom: 4px; color: white;">Connect your GitHub account</div>
                        <div style="font-size: 0.85rem; color: rgba(255, 255, 255, 0.7); line-height: 1.4;">
                            Show your GitHub contributions alongside your CodeDungeon activity. Uses public GitHub API only — no permissions needed.
                        </div>
                    </div>
                    
                </div>
                
                <div style="display: flex; gap: 12px; margin-top: 16px; align-items: center;">
                    
                    <input type="text"
                        id="github-username-input"
                        placeholder="Your GitHub username"
                        style="flex: 1; max-width: 300px; padding: 0.6rem 0.8rem; background: rgba(30, 30, 40, 0.6); border: 1px solid rgba(96, 239, 255, 0.2); border-radius: 8px; color: white; font-size: 0.9rem;"
                        maxlength="39">
                    
                    <button id="connect-github-btn"
                        style="padding: 0.7rem 1.2rem; background: linear-gradient(135deg, #60efff 0%, #00d4ff 100%); border: none; color: white; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 0.9rem; transition: all 0.3s ease; white-space: nowrap; min-width: 140px;">
                        Connect GitHub
                    </button>
                    
                </div>
                
                <div id="github-connect-status" style="margin-top: 8px; font-size: 0.85rem; display: none;"></div>
                
            </div>

            <?php else: ?>

            <!-- GITHUB STATS CARD -->
            <div style="background: rgba(255, 255, 255, 0.03); border-radius: 15px; padding: 1.5rem; margin: 2rem 0; border: 1px solid rgba(96, 239, 255, 0.1); position: relative; overflow: hidden; width: 100%;">
                <div style="position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, #60efff, #00ff87);"></div>
                
                <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                    
                    <div style="display: flex; align-items: center; gap: 0.8rem;">
                        <svg height="24" width="24" viewBox="0 0 16 16" style="fill: #60efff;">
                            <path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/>
                        </svg>
                        <h3 style="color: white; margin: 0; font-size: 1.1rem; font-weight: 600;">GitHub</h3>
                    </div>
                    
                    <button id="disconnect-github-btn"
                        style="padding: 0.4rem 0.8rem; background: transparent; border: 1px solid rgba(96, 239, 255, 0.2); color: rgba(255, 255, 255, 0.7); border-radius: 6px; cursor: pointer; font-weight: 500; font-size: 0.75rem; transition: all 0.3s ease;">
                        Disconnect
                    </button>
                    
                </div>
                
                <?php if ($githubStats): ?>
                
                <div style="display: flex; align-items: center; gap: 16px; margin-top: 16px;">
                    
                    <!-- Avatar -->
                    <img 
                        src="<?php echo htmlspecialchars($githubStats['avatar_url']); ?>"
                        alt="GitHub avatar"
                        style="width: 56px; height: 56px; border-radius: 50%; border: 2px solid rgba(96, 239, 255, 0.3);">
                    
                    <div>
                        <div style="font-weight: 600; font-size: 1.05rem; color: white;">
                            <?php echo htmlspecialchars($githubStats['name'] ?: $githubStats['login']); ?>
                        </div>
                        <a href="<?php echo htmlspecialchars($githubStats['github_url']); ?>"
                            target="_blank"
                            style="font-size: 0.85rem; color: #60efff; text-decoration: none;">
                            @<?php echo htmlspecialchars($githubStats['login']); ?>
                        </a>
                        <?php if (!empty($githubStats['bio'])): ?>
                        <div style="font-size: 0.8rem; color: rgba(255, 255, 255, 0.6); margin-top: 4px;">
                            <?php echo htmlspecialchars($githubStats['bio']); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                </div>
                
                <!-- GitHub Stats Grid -->
                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-top: 16px;">
                    
                    <div style="background: rgba(30, 30, 40, 0.6); border: 1px solid rgba(96, 239, 255, 0.15); border-radius: 10px; padding: 1rem; text-align: center;">
                        <div style="font-size: 1.3rem; font-weight: bold; color: #60efff; margin-bottom: 0.4rem;">
                            <?php echo number_format($githubStats['public_repos']); ?>
                        </div>
                        <div style="font-size: 0.7rem; color: rgba(255, 255, 255, 0.6);">Repos</div>
                    </div>
                    
                    <div style="background: rgba(30, 30, 40, 0.6); border: 1px solid rgba(96, 239, 255, 0.15); border-radius: 10px; padding: 1rem; text-align: center;">
                        <div style="font-size: 1.3rem; font-weight: bold; color: #60efff; margin-bottom: 0.4rem;">
                            <?php echo number_format($githubStats['followers']); ?>
                        </div>
                        <div style="font-size: 0.7rem; color: rgba(255, 255, 255, 0.6);">Followers</div>
                    </div>
                    
                    <div style="background: rgba(30, 30, 40, 0.6); border: 1px solid rgba(96, 239, 255, 0.15); border-radius: 10px; padding: 1rem; text-align: center;">
                        <div style="font-size: 1.3rem; font-weight: bold; color: #60efff; margin-bottom: 0.4rem;">
                            <?php echo number_format($githubStats['total_stars']); ?>
                        </div>
                        <div style="font-size: 0.7rem; color: rgba(255, 255, 255, 0.6);">Stars</div>
                    </div>
                    
                    <div style="background: rgba(30, 30, 40, 0.6); border: 1px solid rgba(96, 239, 255, 0.15); border-radius: 10px; padding: 1rem; text-align: center;">
                        <div style="font-size: 1rem; font-weight: bold; color: #00ff87; margin-bottom: 0.4rem;">
                            <?php echo htmlspecialchars($githubStats['top_language'] ?: 'N/A'); ?>
                        </div>
                        <div style="font-size: 0.7rem; color: rgba(255, 255, 255, 0.6);">Top Language</div>
                    </div>
                    
                </div>
                
                <?php endif; ?>
                
            </div>

            <!-- DUAL CALENDAR SECTION -->
            <?php if ($githubCalendar): ?>
            <div style="background: rgba(255, 255, 255, 0.03); border-radius: 15px; padding: 1.5rem; margin: 2rem 0; border: 1px solid rgba(96, 239, 255, 0.1); position: relative; overflow: hidden; width: 100%;">
                <div style="position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, #60efff, #00ff87);"></div>
                
                <div style="display: flex; align-items: center; gap: 0.8rem; margin-bottom: 1.5rem;">
                    <i class="fas fa-chart-line" style="color: #00ff87; font-size: 1.2rem;"></i>
                    <div>
                        <h3 style="color: white; margin: 0; font-size: 1.1rem; font-weight: 600;">Activity Comparison</h3>
                        <span style="font-size: 0.75rem; font-weight: normal; color: rgba(255, 255, 255, 0.6);">
                            CodeDungeon vs GitHub · Last 52 weeks
                        </span>
                    </div>
                </div>
                
                <!-- GitHub Calendar -->
                <div style="margin-top: 16px;">
                    <div style="font-size: 0.8rem; color: rgba(255, 255, 255, 0.6); margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
                        <svg height="14" width="14" viewBox="0 0 16 16" style="fill: rgba(255,255,255,0.6);">
                            <path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/>
                        </svg>
                        GitHub Contributions
                    </div>
                    
                    <div style="display: flex; gap: 4px;">
                    
                        <div style="display: flex; flex-direction: column; gap: 3px; justify-content: space-around; font-size: 0.65rem; color: rgba(255, 255, 255, 0.6); padding-top: 2px;">
                            <span>Mon</span>
                            <span>Wed</span>
                            <span>Fri</span>
                        </div>
                        
                        <div id="github-calendar-grid" style="display: flex; gap: 3px; flex-wrap: nowrap; overflow-x: auto;">
                            <!-- Populated by JS -->
                        </div>
                        
                    </div>
                    
                    <!-- GitHub Legend -->
                    <div style="display: flex; align-items: center; gap: 6px; margin-top: 8px; font-size: 0.75rem; color: rgba(255, 255, 255, 0.6); justify-content: flex-end;">
                        <span>Less</span>
                        <div style="width:11px; height:11px; border-radius:2px; background:#ebedf0; opacity:0.15;"></div>
                        <div style="width:11px; height:11px; border-radius:2px; background:#9be9a8; opacity:0.6;"></div>
                        <div style="width:11px; height:11px; border-radius:2px; background:#40c463;"></div>
                        <div style="width:11px; height:11px; border-radius:2px; background:#30a14e;"></div>
                        <div style="width:11px; height:11px; border-radius:2px; background:#216e39;"></div>
                        <span>More</span>
                    </div>
                    
                </div>
                
            </div>
            <?php endif; ?>

            <?php endif; ?>

            <!-- ACTIVITY FEED SECTION -->
            <div style="background: rgba(255, 255, 255, 0.03); border-radius: 15px; padding: 1.5rem; margin: 2rem 0; border: 1px solid rgba(96, 239, 255, 0.1); position: relative; overflow: hidden; width: 100%;">
                <div style="position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, #60efff, #00ff87);"></div>
                
                <div style="display: flex; align-items: center; gap: 0.8rem; margin-bottom: 1.5rem;">
                    <i class="fas fa-book" style="color: #f3c13a; font-size: 1.2rem;"></i>
                    <div>
                        <h3 style="color: white; margin: 0; font-size: 1.1rem; font-weight: 600;">Activity</h3>
                        <span style="font-size: 0.75rem; font-weight: normal; color: rgba(255, 255, 255, 0.6);">
                            Your recent CodeDungeon commits
                        </span>
                    </div>
                </div>
                
                <?php if (empty($activityFeed)): ?>
                
                <div style="text-align: center; padding: 32px 16px; color: rgba(255, 255, 255, 0.6); font-size: 0.9rem;">
                    No activity yet. Solve your first challenge to see commits here.
                </div>
                
                <?php else: ?>
                
                <div style="margin-top: 16px;">
                
                <?php foreach ($activityFeed as $activity): ?>
                
                <div style="display: flex; align-items: flex-start; gap: 12px; padding: 12px 0; border-bottom: 1px solid rgba(96, 239, 255, 0.1); font-size: 0.85rem;">
                    
                    <!-- Activity icon -->
                    <div style="font-size: 1.2rem; flex-shrink: 0; margin-top: 2px;">
                        <?php
                        $icons = [
                            'bug_fixed' => '🐛',
                            'challenge_solved' => '⚔️',
                            'sprint_completed' => '⚡',
                            'streak_milestone' => '🔥',
                            'freeze_purchased' => '🧊'
                        ];
                        echo $icons[$activity['activity_type']] ?? '⚔️';
                        ?>
                    </div>
                    
                    <div style="flex: 1; min-width: 0;">
                    
                        <!-- Title -->
                        <div style="font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: white;">
                            <?php echo htmlspecialchars($activity['title']); ?>
                        </div>
                        
                        <!-- Subtitle row -->
                        <div style="color: rgba(255, 255, 255, 0.5); font-size: 0.78rem; margin-top: 2px; display: flex; gap: 8px; flex-wrap: wrap;">
                            
                            <span>
                                <?php
                                // Relative time
                                $diff = time() - strtotime($activity['created_at']);
                                if ($diff < 60) echo 'just now';
                                elseif ($diff < 3600) echo floor($diff/60) . 'm ago';
                                elseif ($diff < 86400) echo floor($diff/3600) . 'h ago';
                                else echo floor($diff/86400) . 'd ago';
                                ?>
                            </span>
                            
                            <?php if (!empty($activity['subtitle'])): ?>
                            <span>·</span>
                            <span><?php echo htmlspecialchars($activity['subtitle']); ?></span>
                            <?php endif; ?>
                            
                        </div>
                        
                    </div>
                    
                    <!-- Commit hash style ID -->
                    <div style="font-family: monospace; font-size: 0.75rem; color: rgba(255, 255, 255, 0.5); background: rgba(30, 30, 40, 0.6); padding: 2px 6px; border-radius: 4px; flex-shrink: 0; border: 1px solid rgba(96, 239, 255, 0.1);">
                        <?php echo htmlspecialchars($activity['short_hash']); ?>
                    </div>
                    
                </div>
                
                <?php endforeach; ?>
                
                </div>
                
                <?php endif; ?>
                
            </div>

            <!-- Favorites Section -->
            <div class="favorites-section">
                <div class="section-header">
                    <i class="fas fa-heart"></i>
                    <h3>Favorite Games</h3>
                </div>
                <div class="favorites-container">
                    <?php if (empty($favorites)): ?>
                        <div class="no-data">
                            <i class="fas fa-heart-broken"></i>
                            <p>No favorite games yet. Add some games to your favorites!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($favorites as $favorite): ?>
                            <?php
                            $game_name = '';
                            $game_logo = '';
                            switch ($favorite['game_type']) {
                                case 'grammar-heroes':
                                    $game_name = 'Grammar Heroes';
                                    $game_logo = '../../assets/selection/Grammarlogo.webp';
                                    break;
                                case 'vocabworld':
                                    $game_name = 'Vocabworld';
                                    $game_logo = '../../assets/selection/vocablogo.webp';
                                    break;
                                default:
                                    $game_name = ucfirst(str_replace('-', ' ', $favorite['game_type']));
                                    $game_logo = '../../assets/selection/vocablogo.webp';
                            }
                            ?>
                            <div class="favorite-card">
                                <img src="<?php echo $game_logo; ?>" alt="<?php echo $game_name; ?>" class="game-logo" title="<?php echo $game_name; ?>">
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Member Since Section -->
            <div class="member-since-section">
                <p class="member-since">Member since <?php echo date('F j, Y', strtotime($user['created_at'])); ?></p>
            </div>
            
            <div class="profile-settings">
                <h2><i class="fas fa-cog"></i> Profile Settings</h2>
                <form class="settings-form" id="profileForm" data-ajax="true">
                    <div class="form-group">
                        <label>Player Name</label>
                        <input type="text" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>
                            Email 
                            <span class="info-icon" onclick="showEmailTooltip(this)">
                                <i class="fas fa-info-circle"></i>
                                <span class="tooltip-text">Contact the Developer if you want to change your email</span>
                            </span>
                        </label>
                        <input type="email" value="<?php echo htmlspecialchars($user['email']); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>About Me</label>
                        <textarea name="about_me" rows="4" placeholder="Tell us about yourself..."><?php echo htmlspecialchars($user['about_me'] ?? ''); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Grade Level</label>
                        <input type="text" value="<?php echo htmlspecialchars($user['grade_level']); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>Section</label>
                        <input type="text" name="section" value="<?php echo htmlspecialchars($user['section'] ?? ''); ?>" placeholder="Enter your section (e.g., A, B, Diamond, etc.)">
                    </div>
                    <button type="submit" class="save-button">
                        <img src="../../assets/pixels/save.png" alt="Save" class="button-icon">
                        Save Changes
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="toast-overlay"></div>
    <div id="toast" class="toast"></div>
    
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

    <!-- Image Crop Modal -->
    <div class="crop-modal-overlay" id="crop-modal">
        <div class="crop-modal">
            <div class="crop-modal-header">
                <h3>Crop Profile Image</h3>
                <button class="crop-modal-close" id="crop-modal-close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="crop-container">
                <img id="crop-image" src="" alt="Image to crop">
            </div>
            <div class="crop-controls">
                <button class="crop-btn crop-btn-cancel" id="crop-cancel">Cancel</button>
                <button class="crop-btn crop-btn-done" id="crop-done">Done</button>
            </div>
        </div>
    </div>

    <!-- Upload Loader Overlay -->
    <div class="upload-loader-overlay" id="upload-loader">
        <div class="loader"></div>
        <div class="loader-text">Uploading profile image...</div>
    </div>

    <script src="../../script.js"></script>
    <script src="../shared/notification-badge.js"></script>
    <script src="../shared/profile-dropdown.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js"></script>
    <script>
    // Mobile menu functionality
    document.addEventListener('DOMContentLoaded', function() {
        const mobileMenuBtn = document.querySelector('.mobile-menu-btn');
        const sidebar = document.querySelector('.sidebar');
        const profileTrigger = document.getElementById('profile-dropdown-trigger');
        const dropdownMenu = document.querySelector('.nav-dropdown-menu');
        
        // Initialize mobile menu
        if (mobileMenuBtn && sidebar) {
            // Make sure sidebar is hidden by default on mobile
            if (window.innerWidth <= 768) {
                sidebar.style.transform = 'translateX(-100%)';
            }
            
            // Simple toggle function for mobile menu
            function toggleMobileMenu() {
                if (sidebar.style.transform === 'translateX(0%)') {
                    sidebar.style.transform = 'translateX(-100%)';
                    document.body.style.overflow = '';
                } else {
                    sidebar.style.transform = 'translateX(0%)';
                    document.body.style.overflow = 'hidden';
                }
            }
            
            // Add click event to mobile menu button
            mobileMenuBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                toggleMobileMenu();
            });
            
            // Close menu when clicking outside
            document.addEventListener('click', function(e) {
                if (window.innerWidth <= 768 && 
                    sidebar.style.transform === 'translateX(0%)' && 
                    !sidebar.contains(e.target) && 
                    !mobileMenuBtn.contains(e.target)) {
                    toggleMobileMenu();
                }
            });
            
            // Handle profile dropdown if it exists
            if (profileTrigger && dropdownMenu) {
                // Close dropdown initially
                dropdownMenu.style.display = 'none';
                
                // Toggle dropdown on click
                const toggleDropdown = (e) => {
                    if (e) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                    
                    const isVisible = dropdownMenu.style.display === 'block';
                    dropdownMenu.style.display = isVisible ? 'none' : 'block';
                    
                    // Toggle active class for arrow rotation
                    const parentItem = profileTrigger.closest('.nav-item-with-dropdown');
                    parentItem.classList.toggle('active', !isVisible);
                    
                    // For mobile, ensure the dropdown is visible in the viewport
                    if (!isVisible && window.innerWidth <= 768) {
                        // Ensure sidebar is open on mobile when clicking dropdown
                        if (!sidebar.classList.contains('active')) {
                            toggleSidebar();
                        }
                        // Small delay to ensure the sidebar is open before scrolling
                        setTimeout(() => {
                            dropdownMenu.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                        }, 50);
                    }
                };
                
                // Handle both touch and click events for better mobile support
                profileTrigger.addEventListener('click', toggleDropdown);
                
                // Close dropdown when clicking outside on both desktop and mobile
                const handleOutsideClick = (e) => {
                    // Don't close if clicking on profile trigger or dropdown menu
                    if (profileTrigger.contains(e.target) || dropdownMenu.contains(e.target)) {
                        return;
                    }
                    
                    // For mobile, check if clicking outside sidebar
                    if (window.innerWidth <= 768) {
                        if (!sidebar.contains(e.target) && !mobileMenuBtn.contains(e.target)) {
                            dropdownMenu.style.display = 'none';
                            profileTrigger.closest('.nav-item-with-dropdown').classList.remove('active');
                        }
                    } else {
                        // For desktop, just close the dropdown
                        dropdownMenu.style.display = 'none';
                        profileTrigger.closest('.nav-item-with-dropdown').classList.remove('active');
                    }
                };
                
                // Use both click and touch events for better mobile support
                document.addEventListener('click', handleOutsideClick);
                document.addEventListener('touchend', handleOutsideClick);
                
                // Close on escape key
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape' && dropdownMenu.style.display === 'block') {
                        dropdownMenu.style.display = 'none';
                        profileTrigger.closest('.nav-item-with-dropdown').classList.remove('active');
                    }
                });
            }
            
            // Close menu when clicking outside on mobile
            document.addEventListener('click', function(e) {
                if (window.innerWidth <= 768 && 
                    sidebar.classList.contains('active') && 
                    !sidebar.contains(e.target) && 
                    !mobileMenuBtn.contains(e.target) &&
                    !(dropdownMenu && dropdownMenu.contains(e.target))) {
                    sidebar.classList.remove('active');
                    document.body.style.overflow = '';
                    
                    // Also close dropdown if open
                    if (dropdownMenu && dropdownMenu.style.display === 'block') {
                        dropdownMenu.style.display = 'none';
                        if (profileTrigger) {
                            profileTrigger.closest('.nav-item-with-dropdown').classList.remove('active');
                        }
                    }
                }
            });
            
            // Close menu when clicking a nav link on mobile
            const navLinks = document.querySelectorAll('.nav-link:not(#profile-dropdown-trigger)');
            navLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    if (window.innerWidth <= 768) {
                        // Don't close if this is the profile dropdown trigger
                        if (this.id === 'profile-dropdown-trigger') {
                            return;
                        }
                        sidebar.classList.remove('active');
                        document.body.style.overflow = '';
                    }
                });
            });
            
            // Handle window resize to ensure proper behavior
            window.addEventListener('resize', function() {
                // If resizing to mobile view, ensure the dropdown is closed
                if (window.innerWidth <= 768) {
                    if (dropdownMenu) {
                        dropdownMenu.style.display = 'none';
                        if (profileTrigger) {
                            profileTrigger.closest('.nav-item-with-dropdown').classList.remove('active');
                        }
                    }
                }
            });
        }
    });
    </script>
    <script>
    // Define logout modal functions in global scope
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
            modal.classList.remove('show');
            confirmation.classList.remove('show');
            confirmation.classList.add('hide');
        }
    }

    function confirmLogout() {
        // Play click sound
        playClickSound();
        
        // Redirect to logout endpoint
        window.location.href = '../../onboarding/logout.php';
    }
    
    // Function to show/hide email tooltip
    function showEmailTooltip(element) {
        element.classList.toggle('show-tooltip');
        
        // Close tooltip when clicking outside
        const closeTooltip = (e) => {
            if (!element.contains(e.target)) {
                element.classList.remove('show-tooltip');
                document.removeEventListener('click', closeTooltip);
            }
        };
        
        // Add event listener to close on outside click
        if (element.classList.contains('show-tooltip')) {
            setTimeout(() => {
                document.addEventListener('click', closeTooltip);
            }, 0);
        } else {
            document.removeEventListener('click', closeTooltip);
        }
    }
    
    // Inline JavaScript to handle profile form
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.querySelector('.settings-form');
        
        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const formData = new FormData();
                formData.append('action', 'update_profile');
                formData.append('username', document.querySelector('input[name="username"]').value);
                // Get email from the readonly input
                formData.append('email', document.querySelector('input[type="email"][readonly]').value);
                formData.append('about_me', document.querySelector('textarea[name="about_me"]').value);
                formData.append('section', document.querySelector('input[name="section"]').value);
                
                fetch('profile.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Show success message using the existing toast system
                        if (typeof showToast === 'function') {
                            showToast(data.message);
                        }
                        
                        // Wait for toast to be seen, then show loader and reload
                        setTimeout(() => {
                            const loader = document.getElementById('pageLoader');
                            if (loader) {
                                loader.classList.remove('hidden');
                            }
                            
                            // Reload the page
                            window.location.reload();
                        }, 1500); // 1.5 seconds delay to see the toast
                        
                    } else {
                        if (typeof showToast === 'function') {
                            showToast('Error: ' + data.message);
                        } else {
                            alert('Error: ' + data.message);
                        }
                    }
                })
                .catch(error => {
                    alert('Error updating profile');
                });
            });
        }
        
        // Profile image upload functionality with cropping
        const changeAvatarBtn = document.getElementById('change-avatar-btn');
        const profileImageInput = document.getElementById('profile-image-input');
        const profileAvatarImg = document.getElementById('profile-avatar-img');
        const cropModal = document.getElementById('crop-modal');
        const cropImage = document.getElementById('crop-image');
        const cropDone = document.getElementById('crop-done');
        const cropCancel = document.getElementById('crop-cancel');
        const cropModalClose = document.getElementById('crop-modal-close');
        
        let cropper = null;
        let selectedFile = null;
        
        if (changeAvatarBtn && profileImageInput) {
            // Trigger file input when button is clicked
            changeAvatarBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                profileImageInput.click();
            });
            
            // Handle file selection
            profileImageInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                
                if (!file) {
                    return;
                }
                
                // Validate file type
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
                if (!allowedTypes.includes(file.type)) {
                    if (typeof showToast === 'function') {
                        showToast('Only image files (JPG, PNG, GIF, WEBP) are allowed');
                    } else {
                        alert('Only image files (JPG, PNG, GIF, WEBP) are allowed');
                    }
                    profileImageInput.value = '';
                    return;
                }
                
                // Validate file size (5MB)
                const maxSize = 5 * 1024 * 1024; // 5MB
                if (file.size > maxSize) {
                    if (typeof showToast === 'function') {
                        showToast('File size must be less than 5MB');
                    } else {
                        alert('File size must be less than 5MB');
                    }
                    profileImageInput.value = '';
                    return;
                }
                
                // Store the selected file
                selectedFile = file;
                
                // Show crop modal
                const reader = new FileReader();
                reader.onload = function(event) {
                    cropImage.src = event.target.result;
                    cropModal.classList.add('show');
                    
                    // Initialize Cropper.js
                    if (cropper) {
                        cropper.destroy();
                    }
                    
                    cropper = new Cropper(cropImage, {
                        aspectRatio: 1, // 1:1 square ratio
                        viewMode: 1,
                        dragMode: 'move',
                        autoCropArea: 1,
                        restore: false,
                        guides: true,
                        center: true,
                        highlight: false,
                        cropBoxMovable: true,
                        cropBoxResizable: true,
                        toggleDragModeOnDblclick: false,
                    });
                };
                reader.readAsDataURL(file);
            });
            
            // Handle crop done button
            if (cropDone) {
                cropDone.addEventListener('click', function() {
                    if (!cropper) return;
                    
                    // Get cropped canvas
                    const canvas = cropper.getCroppedCanvas({
                        width: 500,
                        height: 500,
                        imageSmoothingEnabled: true,
                        imageSmoothingQuality: 'high',
                    });
                    
                    // Convert canvas to blob
                    canvas.toBlob(function(blob) {
                        // Create a new file from the blob
                        const croppedFile = new File([blob], selectedFile.name, {
                            type: selectedFile.type,
                            lastModified: Date.now(),
                        });
                        
                        // Close crop modal
                        cropModal.classList.remove('show');
                        if (cropper) {
                            cropper.destroy();
                            cropper = null;
                        }
                        
                        // Upload the cropped file
                        uploadProfileImage(croppedFile);
                        
                        // Reset file input
                        profileImageInput.value = '';
                    }, selectedFile.type);
                });
            }
            
            // Handle crop cancel
            const closeCropModal = function() {
                cropModal.classList.remove('show');
                if (cropper) {
                    cropper.destroy();
                    cropper = null;
                }
                profileImageInput.value = '';
                selectedFile = null;
            };
            
            if (cropCancel) {
                cropCancel.addEventListener('click', closeCropModal);
            }
            
            if (cropModalClose) {
                cropModalClose.addEventListener('click', closeCropModal);
            }
            
            // Upload function
            function uploadProfileImage(file) {
                const formData = new FormData();
                formData.append('action', 'upload_profile_image');
                formData.append('profile_image', file);
                
                // Show loading overlay
                const uploadLoader = document.getElementById('upload-loader');
                if (uploadLoader) {
                    uploadLoader.classList.add('show');
                }
                
                fetch('profile.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    // Hide loading overlay
                    if (uploadLoader) {
                        uploadLoader.classList.remove('show');
                    }
                    
                    if (data.success) {
                        // Show success message
                        if (typeof showToast === 'function') {
                            showToast(data.message);
                        }
                        
                        // Update all profile images on the page
                        const imagePath = '../../' + data.image_path;
                        
                        // Update large profile avatar
                        if (profileAvatarImg) {
                            profileAvatarImg.src = imagePath;
                        }
                        
                        // Update header profile images
                        const headerProfileImg = document.querySelector('.profile-img');
                        if (headerProfileImg) {
                            headerProfileImg.src = imagePath;
                        }
                        
                        const dropdownAvatar = document.querySelector('.profile-dropdown-avatar');
                        if (dropdownAvatar) {
                            dropdownAvatar.src = imagePath;
                        }
                        
                    } else {
                        if (typeof showToast === 'function') {
                            showToast('Error: ' + data.message);
                        } else {
                            alert('Error: ' + data.message);
                        }
                    }
                })
                .catch(error => {
                    // Hide loading overlay
                    const uploadLoader = document.getElementById('upload-loader');
                    if (uploadLoader) {
                        uploadLoader.classList.remove('show');
                    }
                    
                    console.error('Upload error:', error);
                    if (typeof showToast === 'function') {
                        showToast('Error uploading profile image');
                    } else {
                        alert('Error uploading profile image');
                    }
                });
            }
        }
    });
    </script>
    <script>
        // Pass user data to JavaScript
        window.userData = {
            id: <?php echo $user_id; ?>,
            username: '<?php echo htmlspecialchars($user['username']); ?>',
            email: '<?php echo htmlspecialchars($user['email']); ?>',
            gradeLevel: '<?php echo htmlspecialchars($user['grade_level']); ?>',
            section: '<?php echo htmlspecialchars($user['section'] ?? ''); ?>',
            aboutMe: '<?php echo htmlspecialchars($user['about_me'] ?? ''); ?>',
            favorites: <?php echo json_encode($favorites); ?>
        };

        // Logout functionality is now in global scope above

        // Game stats filter functionality
        document.addEventListener('DOMContentLoaded', function() {
            // No need for game stats sort functionality as there's only one option
        });
    </script>

    <!-- Calendar and XP Shop Script -->
    <script>
    (function() {

    // ── DATA FROM PHP ──────────────────────
    const calendarData = <?php echo $calendarJson; ?>;
    const maxDayXp = <?php echo $maxDayXp; ?>;

    // ── COLOR SCALE FUNCTION ───────────────
    function getSquareColor(xp) {
        if (xp === 0) {
            return 'rgba(255,255,255,0.06)';
        }
        
        // Calculate intensity 0.0 to 1.0
        const intensity = Math.min(xp / maxDayXp, 1);
        
        // Four intensity levels for cyan color
        if (intensity < 0.25) {
            return 'rgba(96,239,255,0.3)';
        } else if (intensity < 0.5) {
            return 'rgba(96,239,255,0.5)';
        } else if (intensity < 0.75) {
            return 'rgba(96,239,255,0.7)';
        } else {
            return 'rgba(96,239,255,1)';
        }
    }

    // ── BUILD THE GRID ─────────────────────

    function buildCalendar() {
        const grid = document.getElementById('xp-calendar-grid');
        const monthsRow = document.getElementById('calendar-months');
            
        if (!grid) return;
        
        // Group days into 52 weeks
        const weeks = [];
        for (let i = 0; i < calendarData.length; i += 7) {
            weeks.push(calendarData.slice(i, i + 7));
        }
        
        // Track which months we have labeled
        let lastMonth = -1;
        const monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        
        weeks.forEach((week, weekIndex) => {
        
            // Create week column
            const weekCol = document.createElement('div');
            weekCol.style.cssText = 'display:flex; flex-direction:column; gap:3px;';
            
            // Month label
            const firstDay = week[0];
            const monthNum = new Date(firstDay.date + 'T00:00:00').getMonth();
            
            if (monthNum !== lastMonth) {
                lastMonth = monthNum;
                const label = document.createElement('span');
                label.textContent = monthNames[monthNum];
                label.style.cssText = 'min-width:28px; font-size:0.7rem; color: #999;';
                label.style.marginLeft = (weekIndex * 15) + 'px';
                monthsRow.appendChild(label);
            }
            
            week.forEach(day => {
            
                const square = document.createElement('div');
                
                square.style.cssText = 
                    'width:11px; height:11px;' +
                    'border-radius:2px;' +
                    'cursor:' + (day.xp > 0 ? 'pointer' : 'default') + ';' +
                    'transition: transform 0.1s;';
                
                square.style.backgroundColor = getSquareColor(day.xp);
                
                // Hover effect
                square.addEventListener('mouseenter', function(e) {
                    this.style.transform = 'scale(1.4)';
                    showTooltip(e, day);
                });
                
                square.addEventListener('mouseleave', function() {
                    this.style.transform = 'scale(1)';
                    hideTooltip();
                });
                
                square.addEventListener('mousemove', function(e) {
                    moveTooltip(e);
                });
                
                weekCol.appendChild(square);
            });
            
            grid.appendChild(weekCol);
        });
    }

    // ── TOOLTIP ───────────────────────

    const tooltip = document.getElementById('calendar-tooltip');

    function showTooltip(event, day) {
        if (!tooltip) return;
        
        const date = new Date(day.date + 'T00:00:00');
        const formatted = date.toLocaleDateString('en-US', { 
            weekday: 'short',
            month: 'short', 
            day: 'numeric',
            year: 'numeric'
        });
        
        if (day.xp === 0) {
            tooltip.innerHTML = 
                '<strong>' + formatted + '</strong><br>No activity';
        } else {
            tooltip.innerHTML = 
                '<strong>' + formatted + '</strong><br>' + 
                day.xp + ' XP earned<br>' + 
                day.challenges + ' challenge' + 
                (day.challenges !== 1 ? 's' : '') + ' completed';
        }
        
        tooltip.style.display = 'block';
        moveTooltip(event);
    }

    function moveTooltip(event) {
        if (!tooltip) return;
        let x = event.clientX + 12;
        let y = event.clientY + 12;
        
        // Keep tooltip within viewport
        const rect = tooltip.getBoundingClientRect();
        if (x + rect.width > window.innerWidth) {
            x = event.clientX - rect.width - 12;
        }
        if (y + rect.height > window.innerHeight) {
            y = event.clientY - rect.height - 12;
        }
        
        tooltip.style.left = x + 'px';
        tooltip.style.top = y + 'px';
    }

    function hideTooltip() {
        if (tooltip) {
            tooltip.style.display = 'none';
        }
    }

    // ── XP SHOP ───────────────────────

    const buyFreezeBtn = document.getElementById('buy-freeze-btn');

    if (buyFreezeBtn && !buyFreezeBtn.disabled) {
        buyFreezeBtn.addEventListener('click', async function() {
        
            buyFreezeBtn.disabled = true;
            const originalText = buyFreezeBtn.textContent;
            buyFreezeBtn.textContent = 'Buying...';
            
            try {
                const response = await fetch('../../play/buy_freeze.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        item: 'streak_freeze',
                        cost: 500
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Reload page to show updated freeze count and XP balance
                    window.location.reload();
                } else {
                    buyFreezeBtn.disabled = false;
                    buyFreezeBtn.textContent = originalText;
                    alert(data.message || 'Purchase failed');
                }
                
            } catch (err) {
                buyFreezeBtn.disabled = false;
                buyFreezeBtn.textContent = originalText;
                console.error('Buy freeze error:', err);
            }
        });
    }

    // ── INITIALISE ────────────────────────

    document.addEventListener('DOMContentLoaded', function() {
        buildCalendar();
        buildGitHubCalendar();
    });

    // ── GITHUB CALENDAR DATA ───────────────
    const githubCalendarData = <?php echo $githubCalendarJson; ?>;
    const githubConnected = <?php echo $githubConnected; ?>;

    // ── BUILD GITHUB CALENDAR ──────────────

    function buildGitHubCalendar() {
        const grid = document.getElementById('github-calendar-grid');
        if (!grid || !githubCalendarData.length) return;
        
        // GitHub uses green color scale
        // Level 0-4 maps to official colors
        const githubColors = [
            'rgba(255,255,255,0.06)', // 0: none
            '#9be9a8',                 // 1: light
            '#40c463',                 // 2: medium
            '#30a14e',                 // 3: dark
            '#216e39'                  // 4: darkest
        ];
        
        // Group into weeks of 7
        const weeks = [];
        for (let i = 0; i < githubCalendarData.length; i += 7) {
            weeks.push(githubCalendarData.slice(i, i + 7));
        }
        
        weeks.forEach(week => {
            const weekCol = document.createElement('div');
            weekCol.style.cssText = 'display:flex; flex-direction:column; gap:3px;';
            
            week.forEach(day => {
                const square = document.createElement('div');
                const level = Math.min(day.level || 0, 4);
                
                square.style.cssText = 
                    'width:11px; height:11px;' +
                    'border-radius:2px;' +
                    'cursor:' + (day.contributions > 0 ? 'pointer' : 'default') + ';' +
                    'transition: transform 0.1s;' +
                    'background-color:' + githubColors[level] + ';';
                
                square.addEventListener('mouseenter', function(e) {
                    this.style.transform = 'scale(1.4)';
                    showGitHubTooltip(e, day);
                });
                
                square.addEventListener('mouseleave', function() {
                    this.style.transform = 'scale(1)';
                    hideGitHubTooltip();
                });
                
                square.addEventListener('mousemove', function(e) {
                    moveGitHubTooltip(e);
                });
                
                weekCol.appendChild(square);
            });
            
            grid.appendChild(weekCol);
        });
    }

    function showGitHubTooltip(event, day) {
        const tooltip = document.getElementById('calendar-tooltip');
        if (!tooltip) return;
        
        const date = new Date(day.date + 'T00:00:00');
        const formatted = date.toLocaleDateString('en-US', {
            weekday: 'short',
            month: 'short',
            day: 'numeric',
            year: 'numeric'
        });
        
        if (day.contributions === 0) {
            tooltip.innerHTML = 
                '<strong>' + formatted + '</strong><br>No GitHub contributions';
        } else {
            tooltip.innerHTML = 
                '<strong>' + formatted + '</strong><br>' + day.contributions + 
                ' contribution' + (day.contributions !== 1 ? 's' : '');
        }
        
        tooltip.style.display = 'block';
        moveGitHubTooltip(event);
    }

    function hideGitHubTooltip() {
        const tooltip = document.getElementById('calendar-tooltip');
        if (tooltip) {
            tooltip.style.display = 'none';
        }
    }

    function moveGitHubTooltip(event) {
        const tooltip = document.getElementById('calendar-tooltip');
        if (!tooltip || !tooltip.offsetParent) return;
        
        const padding = 10;
        let x = event.clientX + padding;
        let y = event.clientY + padding;
        
        // Keep tooltip in viewport
        const tooltipRect = tooltip.getBoundingClientRect();
        if (x + tooltipRect.width > window.innerWidth) {
            x = event.clientX - tooltipRect.width - padding;
        }
        if (y + tooltipRect.height > window.innerHeight) {
            y = event.clientY - tooltipRect.height - padding;
        }
        
        tooltip.style.left = x + 'px';
        tooltip.style.top = y + 'px';
    }

    // ── CONNECT GITHUB ─────────────────────

    const connectBtn = document.getElementById('connect-github-btn');
    const usernameInput = document.getElementById('github-username-input');
    const statusDiv = document.getElementById('github-connect-status');

    if (connectBtn && usernameInput) {

        // Allow pressing Enter in input
        usernameInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                connectBtn.click();
            }
        });

        connectBtn.addEventListener('click', async function() {
            
            const username = usernameInput.value.trim();
            
            if (!username) {
                showGitHubStatus('Please enter your GitHub username', 'warning');
                return;
            }
            
            connectBtn.disabled = true;
            connectBtn.textContent = 'Connecting...';
            
            try {
                const response = await fetch('connect_github.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        username: username
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showGitHubStatus('✅ ' + data.message, 'success');
                    // Reload after 1.5 seconds to show the connected state
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    showGitHubStatus('❌ ' + data.message, 'error');
                    connectBtn.disabled = false;
                    connectBtn.textContent = 'Connect GitHub';
                }
                
            } catch (err) {
                showGitHubStatus('Connection failed. Try again.', 'error');
                connectBtn.disabled = false;
                connectBtn.textContent = 'Connect GitHub';
            }
        });
    }

    function showGitHubStatus(message, type) {
        if (!statusDiv) return;
        statusDiv.textContent = message;
        statusDiv.style.display = 'block';
        const colors = {
            success: '#00ff87',
            error: '#ff6b6b',
            warning: '#f3c13a'
        };
        statusDiv.style.color = colors[type] || colors.warning;
    }

    // ── DISCONNECT GITHUB ──────────────────

    const disconnectBtn = document.getElementById('disconnect-github-btn');

    if (disconnectBtn) {
        disconnectBtn.addEventListener('click', async function() {
            
            if (!confirm('Disconnect your GitHub account from CodeDungeon?')) return;
            
            disconnectBtn.disabled = true;
            
            try {
                const response = await fetch('disconnect_github.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                });
                
                const data = await response.json();
                
                if (data.success) {
                    window.location.reload();
                } else {
                    disconnectBtn.disabled = false;
                    alert('Disconnect failed');
                }
                
            } catch (err) {
                disconnectBtn.disabled = false;
            }
        });
    }

    })();
    </script>
</body>
</html>
