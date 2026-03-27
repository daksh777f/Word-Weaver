<?php
// ════════════════════════════════════════
// FILE: activity_logger.php
// PURPOSE: Shared utility for logging user activities to feed
// NEW TABLES USED: codedungeon_activity_feed
// DEPENDS ON: config.php (for $pdo)
// EXTERNAL API: No
// ════════════════════════════════════════

if (defined('ACTIVITY_LOGGER_LOADED')) return;
define('ACTIVITY_LOGGER_LOADED', true);

function logActivity(
    $user_id,
    $activity_type,
    $title,
    $subtitle = '',
    $xp_earned = 0,
    $score = 0,
    $game_type = '',
    $challenge_id = null,
    $conn
) {
    $short_hash = substr(md5(uniqid(rand(), true)), 0, 7);
    
    try {
        $stmt = $conn->prepare(
            "INSERT INTO codedungeon_activity_feed 
            (user_id, activity_type, title, subtitle, xp_earned, score, 
             game_type, challenge_id, short_hash) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        
        $stmt->execute([
            $user_id,
            $activity_type,
            $title,
            $subtitle,
            $xp_earned,
            $score,
            $game_type,
            $challenge_id,
            $short_hash
        ]);
        
        return $short_hash;
    } catch (Exception $e) {
        error_log('Activity logger error: ' . $e->getMessage());
        return $short_hash;
    }
}

function getActivityFeed(
    $user_id, 
    $limit = 20,
    $conn
) {
    try {
        $stmt = $conn->prepare(
            "SELECT id, user_id, activity_type, title, subtitle, 
                    xp_earned, score, game_type, challenge_id, 
                    short_hash, created_at 
             FROM codedungeon_activity_feed 
             WHERE user_id = ? 
             ORDER BY created_at DESC 
             LIMIT ?"
        );
        
        $stmt->execute([$user_id, $limit]);
        return $stmt->fetchAll() ?? [];
    } catch (Exception $e) {
        error_log('Activity feed fetch error: ' . $e->getMessage());
        return [];
    }
}
