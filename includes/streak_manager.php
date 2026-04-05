<?php

// ════════════════════════════════════════════════════════════════
// FILE: streak_manager.php
// PURPOSE: Manage daily streaks, XP tracking, and streak freezes
// NEW TABLES USED: daily_xp_log, xp_shop_transactions
// DEPENDS ON: config.php (for $pdo)
// ════════════════════════════════════════════════════════════════

if (defined('STREAK_MANAGER_LOADED')) {
    return;
}
define('STREAK_MANAGER_LOADED', true);

/**
 * Update user activity, streak, and XP
 * Called on login and challenge completion
 * @param int $user_id User ID
 * @param PDO $conn Database connection
 * @param int $xp_earned XP from this event
 * @param bool $challenge_completed Whether challenge was just completed
 * @return array Streak and XP update data
 */
function updateUserActivity($user_id, $conn, $xp_earned = 0, $challenge_completed = false) {
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    try {
        // STEP A: Fetch current user data
        $stmt = $conn->prepare(
            "SELECT current_streak, longest_streak, last_active_date, streak_freezes, total_xp 
             FROM users WHERE id = ?"
        );
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            error_log("updateUserActivity: User $user_id not found");
            return ['current_streak' => 0, 'longest_streak' => 0, 'streak_message' => '', 'total_xp' => 0, 'freeze_used' => false];
        }

        // STEP B: Determine streak change
        $last = $user['last_active_date'];
        $current_streak = (int)$user['current_streak'];
        $longest_streak = (int)$user['longest_streak'];
        $streak_message = '';
        $freeze_used = false;

        if ($last === $today) {
            // Already active today - do not increment streak, just add XP
            $streak_changed = false;

        } elseif ($last === $yesterday) {
            // Active yesterday - increment streak
            $current_streak++;
            $streak_changed = true;
            $streak_message = 'Streak extended to ' . $current_streak . ' days';

        } elseif ($last === null) {
            // First time ever active
            $current_streak = 1;
            $streak_changed = true;
            $streak_message = 'Streak started';

        } else {
            // Missed one or more days
            if ((int)$user['streak_freezes'] > 0) {
                // Consume one freeze
                $freeze_used = true;
                $streak_changed = false;
                $streak_message = 'Streak Freeze used automatically';

                // Decrement freeze count
                $freezeStmt = $conn->prepare("UPDATE users SET streak_freezes = streak_freezes - 1 WHERE id = ?");
                $freezeStmt->execute([$user_id]);

            } else {
                // No freeze - reset streak
                $current_streak = 1;
                $streak_changed = true;
                $streak_message = 'Streak reset. Start again.';
            }
        }

        // STEP C: Update longest streak
        if ($current_streak > $longest_streak) {
            $longest_streak = $current_streak;
        }

        // STEP D: Update users table
        $updateStmt = $conn->prepare(
            "UPDATE users SET 
                current_streak = ?,
                longest_streak = ?,
                last_active_date = ?,
                total_xp = total_xp + ?
             WHERE id = ?"
        );
        $updateStmt->execute([$current_streak, $longest_streak, $today, $xp_earned, $user_id]);

        // STEP E: Update daily_xp_log
        $logStmt = $conn->prepare(
            "INSERT INTO daily_xp_log (user_id, log_date, xp_earned, challenges_completed)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                xp_earned = xp_earned + VALUES(xp_earned),
                challenges_completed = challenges_completed + VALUES(challenges_completed)"
        );
        $logStmt->execute([$user_id, $today, $xp_earned, $challenge_completed ? 1 : 0]);

        // STEP F: Return result data
        $newTotalXp = (int)$user['total_xp'] + $xp_earned;
        return [
            'current_streak' => $current_streak,
            'longest_streak' => $longest_streak,
            'streak_message' => $streak_message,
            'total_xp' => $newTotalXp,
            'freeze_used' => $freeze_used
        ];

    } catch (Exception $e) {
        error_log("updateUserActivity error: " . $e->getMessage());
        return ['current_streak' => 0, 'longest_streak' => 0, 'streak_message' => '', 'total_xp' => 0, 'freeze_used' => false];
    }
}

/**
 * Get user's XP calendar data for last 364 days
 * @param int $user_id User ID
 * @param PDO $conn Database connection
 * @return array Array of daily data with date, xp, challenges
 */
function getUserCalendarData($user_id, $conn) {
    $endDate = date('Y-m-d');
    $startDate = date('Y-m-d', strtotime('-363 days'));

    try {
        // Fetch all daily_xp_log rows for this user in date range
        $stmt = $conn->prepare(
            "SELECT log_date, xp_earned, challenges_completed
             FROM daily_xp_log
             WHERE user_id = ?
             AND log_date BETWEEN ? AND ?
             ORDER BY log_date ASC"
        );
        $stmt->execute([$user_id, $startDate, $endDate]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Index fetched rows by date string
        $byDate = [];
        foreach ($rows as $row) {
            $byDate[$row['log_date']] = $row;
        }

        // Build complete array of 364 days
        $calendar = [];
        $current = strtotime($startDate);
        $end = strtotime($endDate);

        while ($current <= $end) {
            $dateStr = date('Y-m-d', $current);
            $calendar[] = [
                'date' => $dateStr,
                'xp' => isset($byDate[$dateStr]) ? (int)$byDate[$dateStr]['xp_earned'] : 0,
                'challenges' => isset($byDate[$dateStr]) ? (int)$byDate[$dateStr]['challenges_completed'] : 0
            ];
            $current = strtotime('+1 day', $current);
        }

        return $calendar;

    } catch (Exception $e) {
        error_log("getUserCalendarData error: " . $e->getMessage());
        // Return empty calendar
        $calendar = [];
        $current = strtotime($startDate);
        $end = strtotime($endDate);
        while ($current <= $end) {
            $dateStr = date('Y-m-d', $current);
            $calendar[] = ['date' => $dateStr, 'xp' => 0, 'challenges' => 0];
            $current = strtotime('+1 day', $current);
        }
        return $calendar;
    }
}

?>
