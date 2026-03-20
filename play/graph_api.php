<?php
// ════════════════════════════════════════
// FILE: graph_api.php
// PURPOSE: Update and return user concept graph data for bug-fix and live-coding challenges.
// ANALYSES USED: onboarding/config.php, play/obituary.php, play/intent_api.php
// NEW TABLES USED: live_coding_challenges, concept_connections
// DEPENDS ON: onboarding/config.php
// CEREBRAS CALLS: no
// CANVAS RENDERING: no
// ════════════════════════════════════════

require_once '../onboarding/config.php';

requireLogin();

header('Content-Type: application/json');

function graphApiFallback(string $message = 'Unable to process graph data.'): void {
    echo json_encode([
        'success' => false,
        'message' => $message,
        'nodes' => [],
        'edges' => [],
        'total_concepts' => 0,
        'strongest_concept' => '',
        'weakest_concept' => ''
    ]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    graphApiFallback('Invalid request method.');
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    graphApiFallback('Invalid JSON body.');
}

$userId = (int)($input['user_id'] ?? 0);
$challengeId = (int)($input['challenge_id'] ?? 0);
$challengeType = trim((string)($input['challenge_type'] ?? ''));
$solved = (bool)($input['solved'] ?? false);
$hintsUsed = max(0, (int)($input['hints_used'] ?? 0));
unset($hintsUsed);

$sessionUserId = (int)($_SESSION['user_id'] ?? 0);
if ($sessionUserId > 0) {
    $userId = $sessionUserId;
}

if ($userId <= 0 || $challengeId <= 0 || !in_array($challengeType, ['live_coding', 'bug_fix'], true)) {
    graphApiFallback('Missing or invalid input fields.');
}

try {
    $conceptTagsRaw = '';

    if ($challengeType === 'live_coding') {
        $challengeStmt = $pdo->prepare('SELECT concept_tags FROM live_coding_challenges WHERE id = ? LIMIT 1');
        $challengeStmt->execute([$challengeId]);
        $row = $challengeStmt->fetch(PDO::FETCH_ASSOC);
        $conceptTagsRaw = (string)($row['concept_tags'] ?? '');
    } else {
        $challengeStmt = $pdo->prepare("SELECT concept_tags FROM bug_challenges WHERE id = ? AND challenge_type = 'bug_fix' LIMIT 1");
        $challengeStmt->execute([$challengeId]);
        $row = $challengeStmt->fetch(PDO::FETCH_ASSOC);
        $conceptTagsRaw = (string)($row['concept_tags'] ?? '');
    }

    if ($conceptTagsRaw === '') {
        graphApiFallback('Challenge not found or missing concept tags.');
    }

    $tags = array_values(array_filter(array_map(
        static fn($tag) => trim(strtolower((string)$tag)),
        explode(',', $conceptTagsRaw)
    )));

    if (count($tags) === 0) {
        graphApiFallback('No concept tags found for challenge.');
    }

    $upsertStmt = $pdo->prepare(
        "INSERT INTO concept_graph (user_id, concept_name, times_encountered, times_solved, last_seen)
         VALUES (?, ?, 1, ?, NOW())
         ON DUPLICATE KEY UPDATE
            times_encountered = times_encountered + 1,
            times_solved = times_solved + VALUES(times_solved),
            last_seen = NOW()"
    );

    $solveIncrement = $solved ? 1 : 0;

    $pdo->beginTransaction();

    foreach ($tags as $concept) {
        $upsertStmt->execute([$userId, $concept, $solveIncrement]);
    }

    $graphStmt = $pdo->prepare(
        'SELECT concept_name, times_encountered, times_solved, last_seen FROM concept_graph WHERE user_id = ? ORDER BY times_encountered DESC'
    );
    $graphStmt->execute([$userId]);
    $graphRows = $graphStmt->fetchAll(PDO::FETCH_ASSOC);

    $pdo->commit();

    $userConceptsSet = [];
    foreach ($graphRows as $row) {
        $userConceptsSet[strtolower((string)$row['concept_name'])] = true;
    }

    $edges = [];
    $edgeSeen = [];
    $connectionsStmt = $pdo->prepare(
        'SELECT concept_to AS concept, connection_strength FROM concept_connections WHERE concept_from = ?
         UNION
         SELECT concept_from AS concept, connection_strength FROM concept_connections WHERE concept_to = ?'
    );

    foreach (array_keys($userConceptsSet) as $conceptName) {
        $connectionsStmt->execute([$conceptName, $conceptName]);
        $connectedRows = $connectionsStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($connectedRows as $connectedRow) {
            $otherConcept = strtolower(trim((string)($connectedRow['concept'] ?? '')));
            if ($otherConcept === '' || !isset($userConceptsSet[$otherConcept])) {
                continue;
            }

            $from = $conceptName;
            $to = $otherConcept;

            if ($from === $to) {
                continue;
            }

            $pair = [$from, $to];
            sort($pair);
            $edgeKey = $pair[0] . '::' . $pair[1];
            if (isset($edgeSeen[$edgeKey])) {
                continue;
            }

            $edgeSeen[$edgeKey] = true;
            $edges[] = [
                'from' => $from,
                'to' => $to,
                'strength' => max(1, min(3, (int)($connectedRow['connection_strength'] ?? 1))),
            ];
        }
    }

    $nodes = [];
    $strongestConcept = '';
    $weakestConcept = '';
    $strongestMastery = -1.0;
    $weakestMastery = 2.0;

    foreach ($graphRows as $row) {
        $encountered = max(0, (int)($row['times_encountered'] ?? 0));
        $solvedCount = max(0, (int)($row['times_solved'] ?? 0));
        $mastery = $encountered === 0 ? 0.0 : round($solvedCount / $encountered, 2);

        if ($mastery > $strongestMastery) {
            $strongestMastery = $mastery;
            $strongestConcept = strtolower((string)$row['concept_name']);
        }

        if ($mastery < $weakestMastery) {
            $weakestMastery = $mastery;
            $weakestConcept = strtolower((string)$row['concept_name']);
        }

        $size = 12 + ($encountered * 2);
        if ($size > 40) {
            $size = 40;
        }

        $nodes[] = [
            'id' => strtolower((string)$row['concept_name']),
            'label' => ucwords(str_replace('-', ' ', (string)$row['concept_name'])),
            'times_encountered' => $encountered,
            'times_solved' => $solvedCount,
            'mastery' => $mastery,
            'last_seen' => gmdate('c', strtotime((string)$row['last_seen'])),
            'size' => $size,
        ];
    }

    echo json_encode([
        'success' => true,
        'nodes' => $nodes,
        'edges' => $edges,
        'total_concepts' => count($nodes),
        'strongest_concept' => $strongestConcept,
        'weakest_concept' => $weakestConcept,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    graphApiFallback('Graph API failed.');
}
