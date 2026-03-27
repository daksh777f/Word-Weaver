<?php
// ════════════════════════════════════════════════════════════════════
// FILE: adaptive_engine.php
// PURPOSE: Core adaptive difficulty AI engine - analyzes performance, recommends challenges
// NEW TABLES USED: ai_recommendations, performance_snapshots, user_game_sessions, arena_sessions, concept_graph, bug_challenges, live_coding_challenges
// DEPENDS ON: onboarding/config.php (for PDO connection and callCerebras)
// CEREBRAS CALLS: yes (getAIRecommendation calls Cerebras API)
// ════════════════════════════════════════════════════════════════════

if (defined('ADAPTIVE_ENGINE_LOADED')) {
    return;
}
define('ADAPTIVE_ENGINE_LOADED', true);

/**
 * Build a comprehensive performance profile from a student's recent sessions
 */
function buildPerformanceProfile($user_id, $pdo) {
    $user_id = (int)$user_id;
    
    // Fetch last 10 bug hunt sessions
    $bugStmt = $pdo->prepare("
        SELECT ugs.id, ugs.score, ugs.hints_used, ugs.time_taken, ugs.completed_at,
               bc.title, bc.difficulty, bc.concept_tags, bc.language,
               'bug_hunt' AS game_type
        FROM user_game_sessions ugs
        JOIN bug_challenges bc ON bc.id = ugs.challenge_id
        WHERE ugs.user_id = ? AND ugs.game_type = 'bug_hunt'
        ORDER BY ugs.completed_at DESC
        LIMIT 10
    ");
    $bugStmt->execute([$user_id]);
    $bugSessions = $bugStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch last 10 live coding sessions
    $liveStmt = $pdo->prepare("
        SELECT ars.id, ars.score, ars.hints_used, ars.time_taken, ars.completed_at,
               lcc.title, lcc.difficulty, lcc.concept_tags, lcc.language,
               'live_coding' AS game_type
        FROM arena_sessions ars
        JOIN live_coding_challenges lcc ON lcc.id = ars.challenge_id
        WHERE ars.user_id = ? AND ars.completed = 1
        ORDER BY ars.completed_at DESC
        LIMIT 10
    ");
    $liveStmt->execute([$user_id]);
    $liveSessions = $liveStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Merge and sort by date descending, take top 10
    $allSessions = array_merge($bugSessions, $liveSessions);
    usort($allSessions, function($a, $b) {
        $timeA = strtotime($a['completed_at'] ?? 'now');
        $timeB = strtotime($b['completed_at'] ?? 'now');
        return $timeB <=> $timeA;
    });
    $sessions = array_slice($allSessions, 0, 10);
    
    // Fetch concept graph for user
    $conceptStmt = $pdo->prepare("
        SELECT concept_name, times_encountered, times_solved,
               CASE WHEN times_encountered = 0 THEN 0 
                    ELSE times_solved / times_encountered 
               END AS mastery
        FROM concept_graph
        WHERE user_id = ?
        ORDER BY times_encountered DESC
    ");
    $conceptStmt->execute([$user_id]);
    $conceptGraph = $conceptStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch user skill level and streak
    $userStmt = $pdo->prepare("
        SELECT skill_level, weak_concepts, current_streak
        FROM users
        WHERE id = ?
    ");
    $userStmt->execute([$user_id]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        $user = ['skill_level' => 50, 'weak_concepts' => null, 'current_streak' => 0];
    }
    
    // Parse weak_concepts JSON if it exists
    $weakConceptsArray = [];
    if (!empty($user['weak_concepts'])) {
        $weakConceptsArray = json_decode($user['weak_concepts'], true) ?? [];
    }
    
    // Build profile
    $avgScore = !empty($sessions) ? array_sum(array_column($sessions, 'score')) / count($sessions) : 0;
    $avgHints = !empty($sessions) ? array_sum(array_column($sessions, 'hints_used')) / count($sessions) : 0;
    $avgTime = !empty($sessions) ? array_sum(array_column($sessions, 'time_taken')) / count($sessions) : 0;
    
    $profile = [
        'skill_level' => (float)($user['skill_level'] ?? 50),
        'current_streak' => (int)($user['current_streak'] ?? 0),
        
        // Recent session metrics
        'recent_sessions' => count($sessions),
        'avg_score' => $avgScore,
        'avg_hints_used' => $avgHints,
        'avg_time_seconds' => $avgTime,
        
        // Score trend
        'score_trend' => calculateScoreTrend($sessions),
        
        // Difficulty breakdown
        'difficulty_breakdown' => calculateDifficultyBreakdown($sessions),
        
        // Concept performance
        'mastered_concepts' => array_filter($conceptGraph, fn($c) => ($c['mastery'] ?? 0) >= 0.75),
        'weak_concepts' => array_filter($conceptGraph, fn($c) => ($c['mastery'] ?? 0) < 0.5 && ($c['times_encountered'] ?? 0) >= 2),
        'unseen_concepts' => getUnseenConcepts($conceptGraph),
        
        // Concepts from last 3 sessions
        'recent_concepts' => getRecentConcepts(array_slice($sessions, 0, 3)),
        
        // Challenge IDs already seen
        'seen_challenge_ids' => array_column($sessions, 'id'),
        
        // Game type breakdown
        'game_type_breakdown' => calculateGameTypeBreakdown($sessions)
    ];
    
    return $profile;
}

/**
 * Calculate score trend from recent sessions
 */
function calculateScoreTrend($sessions) {
    if (count($sessions) < 4) {
        return 'insufficient_data';
    }
    
    $half = floor(count($sessions) / 2);
    $recentScores = array_slice($sessions, 0, $half);
    $olderScores = array_slice($sessions, $half);
    
    $recentAvg = !empty($recentScores) ? array_sum(array_column($recentScores, 'score')) / count($recentScores) : 0;
    $olderAvg = !empty($olderScores) ? array_sum(array_column($olderScores, 'score')) / count($olderScores) : 0;
    
    if ($olderAvg === 0) return 'insufficient_data';
    
    if ($recentAvg > $olderAvg * 1.1) {
        return 'improving';
    } elseif ($recentAvg < $olderAvg * 0.9) {
        return 'declining';
    } else {
        return 'stable';
    }
}

/**
 * Calculate breakdown of challenges by difficulty
 */
function calculateDifficultyBreakdown($sessions) {
    $breakdown = [
        'beginner' => 0,
        'intermediate' => 0,
        'advanced' => 0
    ];
    
    foreach ($sessions as $session) {
        $diff = strtolower($session['difficulty'] ?? '');
        if (isset($breakdown[$diff])) {
            $breakdown[$diff]++;
        }
    }
    
    return $breakdown;
}

/**
 * Get concepts the student hasn't encountered yet
 */
function getUnseenConcepts($conceptGraph) {
    $allConcepts = [
        'arrays', 'loops', 'recursion', 'strings', 'off-by-one',
        'null-handling', 'type-coercion', 'async', 'scope', 'data-structures',
        'conditionals', 'math', 'sorting', 'searching', 'hashing',
        'two-pointers', 'sliding-window', 'dynamic-programming',
        'if-statements', 'functions', 'objects', 'classes', 'inheritance',
        'polymorphism', 'encapsulation', 'error-handling',
        'file-io', 'regex', 'promises', 'closures', 'higher-order-functions'
    ];
    
    $seenConcepts = array_map('strtolower', array_column($conceptGraph, 'concept_name'));
    return array_values(array_diff($allConcepts, $seenConcepts));
}

/**
 * Get concept tags from the last N sessions
 */
function getRecentConcepts($sessions) {
    $concepts = [];
    foreach ($sessions as $session) {
        $tags = explode(',', $session['concept_tags'] ?? '');
        foreach ($tags as $tag) {
            $tag = trim(strtolower($tag));
            if ($tag) {
                $concepts[] = $tag;
            }
        }
    }
    return array_unique($concepts);
}

/**
 * Calculate breakdown of game types
 */
function calculateGameTypeBreakdown($sessions) {
    $breakdown = [];
    foreach ($sessions as $session) {
        $type = $session['game_type'] ?? 'unknown';
        $breakdown[$type] = ($breakdown[$type] ?? 0) + 1;
    }
    return $breakdown;
}

/**
 * Update user's skill level based on session performance
 */
function updateSkillLevel($user_id, $session_data, $pdo) {
    $user_id = (int)$user_id;
    
    // Fetch current skill level
    $stmt = $pdo->prepare("SELECT skill_level FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $current = (float)($user['skill_level'] ?? 50);
    
    $score = (int)($session_data['score'] ?? 0);
    $hints = (int)($session_data['hints_used'] ?? 0);
    $difficulty = strtolower($session_data['difficulty'] ?? 'beginner');
    
    // Base adjustment from score
    $scoreAdjustment = 0;
    if ($score >= 900) {
        $scoreAdjustment = 3.0;
    } elseif ($score >= 700) {
        $scoreAdjustment = 1.5;
    } elseif ($score >= 500) {
        $scoreAdjustment = 0.5;
    } elseif ($score >= 300) {
        $scoreAdjustment = -0.5;
    } else {
        $scoreAdjustment = -1.5;
    }
    
    // Difficulty multiplier
    $diffMultiplier = [
        'beginner' => 0.5,
        'intermediate' => 1.0,
        'advanced' => 1.5
    ][$difficulty] ?? 1.0;
    
    // Hint penalty - each hint reduces adjustment
    $hintPenalty = $hints * 0.5;
    
    // Final adjustment
    $adjustment = ($scoreAdjustment * $diffMultiplier) - $hintPenalty;
    
    // Apply with bounds 0-100
    $newLevel = max(0, min(100, $current + $adjustment));
    $newLevel = round($newLevel, 2);
    
    // Update database
    $updateStmt = $pdo->prepare("
        UPDATE users
        SET skill_level = ?, skill_level_updated = NOW()
        WHERE id = ?
    ");
    $updateStmt->execute([$newLevel, $user_id]);
    
    return [
        'old_level' => $current,
        'new_level' => $newLevel,
        'adjustment' => $adjustment
    ];
}

/**
 * Get AI recommendation for next challenge
 */
function getAIRecommendation($user_id, $profile, $pdo) {
    $user_id = (int)$user_id;
    
    // Get candidate challenges
    $seenIds = $profile['seen_challenge_ids'];
    $seenIdList = empty($seenIds) ? '(0)' : '(' . implode(',', array_map('intval', $seenIds)) . ')';
    
    // Determine target difficulty based on skill level
    $skill = $profile['skill_level'];
    if ($skill < 35) {
        $targetDifficulties = ['beginner'];
    } elseif ($skill < 65) {
        $targetDifficulties = ['beginner', 'intermediate'];
    } elseif ($skill < 85) {
        $targetDifficulties = ['intermediate', 'advanced'];
    } else {
        $targetDifficulties = ['advanced'];
    }
    
    $diffList = "('" . implode("','", $targetDifficulties) . "')";
    
    $candidates = [];
    
    // Get bug hunt candidates
    $bugStmt = $pdo->prepare("
        SELECT id, title, difficulty, concept_tags, language, 'bug_fix' AS challenge_type
        FROM bug_challenges
        WHERE id NOT IN $seenIdList
        AND difficulty IN $diffList
        ORDER BY RAND()
        LIMIT 10
    ");
    $bugStmt->execute();
    $bugCandidates = $bugStmt->fetchAll(PDO::FETCH_ASSOC);
    $candidates = array_merge($candidates, $bugCandidates);
    
    // Get live coding candidates
    $liveStmt = $pdo->prepare("
        SELECT id, title, difficulty, concept_tags, language, 'live_coding' AS challenge_type
        FROM live_coding_challenges
        WHERE id NOT IN $seenIdList
        AND difficulty IN $diffList
        ORDER BY RAND()
        LIMIT 10
    ");
    $liveStmt->execute();
    $liveCandidates = $liveStmt->fetchAll(PDO::FETCH_ASSOC);
    $candidates = array_merge($candidates, $liveCandidates);
    
    // If all seen, allow repeats
    if (empty($candidates)) {
        $bugStmt = $pdo->prepare("
            SELECT id, title, difficulty, concept_tags, language, 'bug_fix' AS challenge_type
            FROM bug_challenges
            WHERE difficulty IN $diffList
            ORDER BY RAND()
            LIMIT 10
        ");
        $bugStmt->execute();
        $candidates = array_merge($candidates, $bugStmt->fetchAll(PDO::FETCH_ASSOC));
        
        $liveStmt = $pdo->prepare("
            SELECT id, title, difficulty, concept_tags, language, 'live_coding' AS challenge_type
            FROM live_coding_challenges
            WHERE difficulty IN $diffList
            ORDER BY RAND()
            LIMIT 10
        ");
        $liveStmt->execute();
        $candidates = array_merge($candidates, $liveStmt->fetchAll(PDO::FETCH_ASSOC));
    }
    
    if (empty($candidates)) {
        return null;
    }
    
    // Build Cerebras prompt
    $weakConceptNames = array_map('strtolower', array_column(array_values($profile['weak_concepts']), 'concept_name'));
    $weakStr = !empty($weakConceptNames) ? implode(', ', $weakConceptNames) : 'none identified yet';
    
    $candidateSummaries = array_map(function($c) {
        return [
            'id' => $c['id'],
            'type' => $c['challenge_type'],
            'title' => $c['title'],
            'difficulty' => $c['difficulty'],
            'concepts' => $c['concept_tags']
        ];
    }, $candidates);
    
    $systemPrompt = 
        "You are an adaptive learning AI for a coding education platform. " .
        "Your job is to recommend ONE challenge from a list that will best help a " .
        "student improve based on their performance profile. " .
        "\n\n" .
        "Selection criteria in priority order:\n" .
        "1. Targets concepts the student is weak in or has not seen\n" .
        "2. Is at the right difficulty for their current skill level\n" .
        "3. Represents a natural next step in their learning progression\n" .
        "4. Is not too similar to what they just completed\n" .
        "\n" .
        "Respond ONLY in this exact JSON format with no extra text:\n" .
        "{\"challenge_id\": 123, \"challenge_type\": \"bug_fix\" or \"live_coding\", " .
        "\"reason\": \"one sentence explaining why this challenge suits this student\", " .
        "\"confidence\": 0.0 to 1.0}";
    
    $userMessage = 
        "STUDENT PROFILE:\n" .
        "Skill level: " . $profile['skill_level'] . "/100\n" .
        "Score trend: " . $profile['score_trend'] . "\n" .
        "Average score: " . round($profile['avg_score']) . "/1000\n" .
        "Average hints per session: " . round($profile['avg_hints_used'], 1) . "\n" .
        "Average time per challenge: " . round($profile['avg_time_seconds'] / 60, 1) . " minutes\n" .
        "Weak concepts (mastery < 50%): " . $weakStr . "\n" .
        "Unseen concepts: " . implode(', ', array_slice($profile['unseen_concepts'], 0, 5)) . "\n" .
        "Recent concepts (just covered): " . implode(', ', $profile['recent_concepts']) . "\n\n" .
        "AVAILABLE CHALLENGES:\n" .
        json_encode($candidateSummaries, JSON_PRETTY_PRINT) .
        "\n\nSelect the single best challenge for this student and explain why.";
    
    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userMessage]
    ];
    
    $rawContent = callCerebras($messages, 200);
    
    if (!$rawContent) {
        error_log('CodeDungeon adaptive: Cerebras call failed');
        return getFallbackRecommendation($candidates, $profile, $pdo);
    }
    
    // Parse response
    $cleaned = trim($rawContent);
    $cleaned = preg_replace('/^```json\s*/i', '', $cleaned);
    $cleaned = preg_replace('/^```\s*/i', '', $cleaned);
    $cleaned = preg_replace('/```\s*$/i', '', $cleaned);
    $cleaned = trim($cleaned);
    
    $parsed = json_decode($cleaned, true);
    
    if (json_last_error() !== JSON_ERROR_NONE || !isset($parsed['challenge_id']) || !isset($parsed['challenge_type'])) {
        error_log('CodeDungeon adaptive: bad response: ' . $rawContent);
        return getFallbackRecommendation($candidates, $profile, $pdo);
    }
    
    // Validate challenge exists in candidates
    $validIds = array_column($candidates, 'id');
    if (!in_array((int)$parsed['challenge_id'], $validIds)) {
        error_log('CodeDungeon adaptive: AI returned invalid challenge_id: ' . $parsed['challenge_id']);
        return getFallbackRecommendation($candidates, $profile, $pdo);
    }
    
    // Fetch full challenge details
    $challengeId = (int)$parsed['challenge_id'];
    $challengeType = $parsed['challenge_type'];
    
    if ($challengeType === 'live_coding') {
        $stmt = $pdo->prepare("SELECT id, title, difficulty, concept_tags FROM live_coding_challenges WHERE id = ?");
        $table = 'live_coding_challenges';
        $gameUrl = 'live-coding.php?challenge_id=';
    } else {
        $stmt = $pdo->prepare("SELECT id, title, difficulty, concept_tags FROM bug_challenges WHERE id = ?");
        $table = 'bug_challenges';
        $gameUrl = 'bug-hunt.php?challenge_id=';
    }
    
    $stmt->execute([$challengeId]);
    $challenge = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$challenge) {
        return getFallbackRecommendation($candidates, $profile, $pdo);
    }
    
    // Save recommendation to DB
    $saveStmt = $pdo->prepare("
        INSERT INTO ai_recommendations
        (user_id, recommended_challenge_id, recommended_challenge_type, reason, confidence)
        VALUES (?, ?, ?, ?, ?)
    ");
    $saveStmt->execute([
        $user_id,
        $challengeId,
        $challengeType,
        $parsed['reason'] ?? 'Recommended by AI',
        (float)($parsed['confidence'] ?? 0.8)
    ]);
    
    // Update weak_concepts on users table
    $weakConceptsJson = json_encode($weakConceptNames);
    $updateStmt = $pdo->prepare("UPDATE users SET weak_concepts = ? WHERE id = ?");
    $updateStmt->execute([$weakConceptsJson, $user_id]);
    
    return [
        'challenge_id' => $challengeId,
        'challenge_type' => $challengeType,
        'title' => $challenge['title'] ?? '',
        'difficulty' => $challenge['difficulty'] ?? '',
        'concept_tags' => $challenge['concept_tags'] ?? '',
        'reason' => $parsed['reason'] ?? 'Recommended for your current level',
        'confidence' => (float)($parsed['confidence'] ?? 0.8),
        'game_url' => $gameUrl . $challengeId
    ];
}

/**
 * Fallback recommendation when AI fails
 */
function getFallbackRecommendation($candidates, $profile, $pdo) {
    if (empty($candidates)) {
        return null;
    }
    
    // Try to find candidate covering weak concept
    $weakNames = array_map('strtolower', array_column(array_values($profile['weak_concepts']), 'concept_name'));
    
    foreach ($candidates as $candidate) {
        $tags = explode(',', $candidate['concept_tags'] ?? '');
        foreach ($tags as $tag) {
            $tag = strtolower(trim($tag));
            if (in_array($tag, $weakNames)) {
                return [
                    'challenge_id' => $candidate['id'],
                    'challenge_type' => $candidate['challenge_type'],
                    'title' => $candidate['title'],
                    'difficulty' => $candidate['difficulty'],
                    'concept_tags' => $candidate['concept_tags'],
                    'reason' => 'This challenge targets ' . $tag . ', an area where you need practice.',
                    'confidence' => 0.6,
                    'game_url' => ($candidate['challenge_type'] === 'live_coding' ? 'live-coding.php' : 'bug-hunt.php') . '?challenge_id=' . $candidate['id']
                ];
            }
        }
    }
    
    // Last resort: return first candidate
    $c = $candidates[0];
    return [
        'challenge_id' => $c['id'],
        'challenge_type' => $c['challenge_type'],
        'title' => $c['title'],
        'difficulty' => $c['difficulty'],
        'concept_tags' => $c['concept_tags'],
        'reason' => 'A good next challenge based on your current level and skill progression.',
        'confidence' => 0.5,
        'game_url' => ($c['challenge_type'] === 'live_coding' ? 'live-coding.php' : 'bug-hunt.php') . '?challenge_id=' . $c['id']
    ];
}
?>