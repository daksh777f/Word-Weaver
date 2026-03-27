<?php
// ════════════════════════════════════════
// FILE: github_api.php
// PURPOSE: Shared GitHub API utility functions for profile integration
// NEW TABLES USED: None (reads from users table cache)
// DEPENDS ON: config.php (for $pdo)
// EXTERNAL API: Yes - GitHub public API + GitHub contributions API
// ════════════════════════════════════════

if (defined('GITHUB_API_LOADED')) return;
define('GITHUB_API_LOADED', true);

function validateGitHubUsername($username) {
    // Sanitize input
    $username = trim($username);
    $username = preg_replace('/[^a-zA-Z0-9\-]/', '', $username);
    
    if (empty($username) || strlen($username) > 39) {
        return [
            'valid' => false,
            'message' => 'Invalid GitHub username'
        ];
    }
    
    // Call GitHub public API
    // No auth needed for public profile
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.github.com/users/' . urlencode($username),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'User-Agent: CodeDungeon/1.0',
            'Accept: application/vnd.github.v3+json'
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        error_log('CodeDungeon GitHub API cURL error: ' . $curlError);
        return [
            'valid' => false,
            'message' => 'Could not reach GitHub. Try again.'
        ];
    }
    
    if ($httpCode === 404) {
        return [
            'valid' => false,
            'message' => 'GitHub user "' . htmlspecialchars($username) . '" not found.'
        ];
    }
    
    if ($httpCode !== 200) {
        return [
            'valid' => false,
            'message' => 'GitHub API error: ' . $httpCode
        ];
    }
    
    $data = json_decode($response, true);
    if (!$data || !isset($data['login'])) {
        return [
            'valid' => false,
            'message' => 'Invalid response from GitHub'
        ];
    }
    
    return [
        'valid' => true,
        'login' => $data['login'],
        'avatar_url' => $data['avatar_url'] ?? '',
        'name' => $data['name'] ?? '',
        'public_repos' => $data['public_repos'] ?? 0,
        'followers' => $data['followers'] ?? 0,
        'bio' => $data['bio'] ?? ''
    ];
}

function getGitHubStats($username, $user_id, $conn) {
    // Check cache first
    $cacheStmt = $conn->prepare(
        "SELECT github_data, github_cache_updated FROM users WHERE id = ?"
    );
    $cacheStmt->execute([$user_id]);
    $cacheRow = $cacheStmt->fetch();
    
    if (!$cacheRow) {
        return null;
    }
    
    $cacheAge = $cacheRow['github_cache_updated'] 
        ? (time() - strtotime($cacheRow['github_cache_updated'])) 
        : PHP_INT_MAX;
    
    // Return cache if less than 24 hours old
    if ($cacheAge < 86400 && !empty($cacheRow['github_data'])) {
        return json_decode($cacheRow['github_data'], true);
    }
    
    // Fetch fresh from GitHub API
    
    // CALL 1: user profile
    $profile = fetchGitHubEndpoint(
        'https://api.github.com/users/' . urlencode($username)
    );
    
    if (!$profile) return null;
    
    // CALL 2: repos to get star count and most used language
    $repos = fetchGitHubEndpoint(
        'https://api.github.com/users/' . urlencode($username) . '/repos?per_page=100&sort=updated'
    );
    
    $totalStars = 0;
    $languages = [];
    
    if ($repos && is_array($repos)) {
        foreach ($repos as $repo) {
            $totalStars += $repo['stargazers_count'] ?? 0;
            $lang = $repo['language'] ?? null;
            if ($lang) {
                $languages[$lang] = ($languages[$lang] ?? 0) + 1;
            }
        }
    }
    
    arsort($languages);
    $topLanguage = !empty($languages) 
        ? array_key_first($languages) 
        : 'Unknown';
    
    $stats = [
        'login' => $profile['login'],
        'name' => $profile['name'] ?? '',
        'avatar_url' => $profile['avatar_url'] ?? '',
        'bio' => $profile['bio'] ?? '',
        'public_repos' => $profile['public_repos'] ?? 0,
        'followers' => $profile['followers'] ?? 0,
        'following' => $profile['following'] ?? 0,
        'total_stars' => $totalStars,
        'top_language' => $topLanguage,
        'github_url' => 'https://github.com/' . $username,
        'cached_at' => date('Y-m-d H:i:s')
    ];
    
    // Save to cache
    $updateStmt = $conn->prepare(
        "UPDATE users SET 
            github_data = ?, 
            github_cache_updated = NOW(),
            github_avatar_url = ?
        WHERE id = ?"
    );
    $updateStmt->execute([
        json_encode($stats),
        $stats['avatar_url'],
        $user_id
    ]);
    
    return $stats;
}

function getGitHubContributions($username) {
    // GitHub does not have a public API for contribution graph data.
    // We use a public proxy service that reads the contribution SVG.
    // Service: github-contributions-api by Joker
    
    $url = 'https://github-contributions-api.jogruber.de/v4/' . urlencode($username) . '?y=last';
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'User-Agent: CodeDungeon/1.0',
            'Accept: application/json'
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError || $httpCode !== 200) {
        error_log('CodeDungeon GitHub contributions API error: ' 
            . $curlError . ' HTTP:' . $httpCode);
        return null;
    }
    
    $data = json_decode($response, true);
    if (!$data || !isset($data['contributions'])) {
        return null;
    }
    
    // data['contributions'] is array of:
    // {date: "2024-01-15", count: 3, level: 0-4}
    
    // Convert to our calendar format:
    // {date: "2024-01-15", contributions: 3}
    
    $byDate = [];
    foreach ($data['contributions'] as $day) {
        $byDate[$day['date']] = [
            'date' => $day['date'],
            'contributions' => $day['count'],
            'level' => $day['level']
        ];
    }
    
    // Build 364 day array same as CodeDungeon calendar format
    $calendar = [];
    $current = strtotime('-363 days');
    $end = time();
    
    while ($current <= $end) {
        $dateStr = date('Y-m-d', $current);
        $calendar[] = [
            'date' => $dateStr,
            'contributions' => $byDate[$dateStr]['contributions'] ?? 0,
            'level' => $byDate[$dateStr]['level'] ?? 0
        ];
        $current = strtotime('+1 day', $current);
    }
    
    return $calendar;
}

function fetchGitHubEndpoint($url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'User-Agent: CodeDungeon/1.0',
            'Accept: application/vnd.github.v3+json'
        ]
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) return null;
    return json_decode($response, true);
}
