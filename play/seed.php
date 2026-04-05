<?php
// ════════════════════════════════════════
// FILE: seed.php
// PURPOSE: Seed Bug Hunt Arena challenge records once.
// ANALYSES USED: styles.css, menu.css, script.js, menu.js, MainGame/grammarheroes/game.php, MainGame/vocabworld/game.php, onboarding/config.php, play/game-selection.php
// NEW TABLES USED: bug_challenges, bug_hunt_seed_flag
// CEREBRAS CALLS: no
// ════════════════════════════════════════

require_once '../onboarding/config.php';

requireLogin();

try {
    $seededStmt = $pdo->query("SELECT COUNT(*) FROM bug_hunt_seed_flag");
    $alreadySeeded = (int)$seededStmt->fetchColumn() > 0;

    $challenges = [
        [
            'title' => 'Checkout Batch Loop',
            'backstory' => 'At BrightCart Labs, the flash-sale checkout pipeline is missing some invoices right before midnight reporting. Product asked for a hotfix before the 8:00 AM finance sync, and your team has one deploy window left. The bug appears only when processing the full order list under heavy traffic.',
                        'broken_code' => <<<'CODE'
function processInvoices(invoices) {
    const sent = [];
    for (let i = 0; i <= invoices.length; i++) {
        sent.push(`invoice:${invoices[i]}`);
    }
    return sent;
}

console.log(processInvoices([101, 102, 103]));
CODE,
            'language' => 'javascript',
            'bug_description' => 'The loop runs one step too far and processes an undefined entry at runtime.',
            'real_world_consequence' => 'Finance exports include corrupted records, causing billing retries and failed reconciliations.',
            'concept_tags' => 'loops,arrays,off-by-one',
            'difficulty' => 'beginner',
            'challenge_type' => 'bug_fix'
        ],
        [
            'title' => 'Profile Greeting Crash',
            'backstory' => 'NimbusLearn is demoing personalized dashboards to a district board this afternoon. The greeting widget sometimes crashes for transfer students whose profile payload is incomplete. Support has a hard SLA and asked for a safe guard before the demo starts.',
                        'broken_code' => <<<'CODE'
function buildGreeting(student) {
    return `Welcome back, ${student.profile.name}!`;
}

const transferStudent = { profile: null };
console.log(buildGreeting(transferStudent));
CODE,
            'language' => 'javascript',
            'bug_description' => 'Code accesses a nested property on null and throws before rendering.',
            'real_world_consequence' => 'Users see a blank dashboard and abandon onboarding when profile data is partial.',
            'concept_tags' => 'null-handling,strings,conditionals',
            'difficulty' => 'beginner',
            'challenge_type' => 'bug_fix'
        ],
        [
            'title' => 'Feature Flag Misfire',
            'backstory' => 'At CedarForge Education, a production toggle is supposed to gate a beta badge to invited students only. QA reported everyone suddenly sees the badge during a release freeze, and leadership wants it contained before parent access hours begin. You are patching under a 15-minute rollback deadline.',
            'broken_code' => "function canShowBetaBadge(userRole) {\n  let enabled = false;\n  if (enabled = userRole === 'beta') {\n    return true;\n  }\n  return false;\n}\n\nconsole.log(canShowBetaBadge('student'));",
            'language' => 'javascript',
            'bug_description' => 'An assignment inside the condition mutates state and causes incorrect branch behavior.',
            'real_world_consequence' => 'Unauthorized users receive hidden features and trigger support incidents across classrooms.',
            'concept_tags' => 'conditionals,type-coercion,scope',
            'difficulty' => 'beginner',
            'challenge_type' => 'bug_fix'
        ],
        [
            'title' => 'Threaded Replies Counter',
            'backstory' => 'Ridgeway LMS rolled out nested discussion threads for a late-night assignment sprint. Moderators noticed CPU spikes whenever deeply nested replies load, and incidents started paging the on-call team. You need a safe recursion guard before peak submissions in an hour.',
            'broken_code' => "function countReplies(thread) {\n  if (!thread) return 0;\n  return 1 + countReplies(thread.replies[0]);\n}\n\nconst topic = { replies: [{ replies: [{ replies: [] }] }] };\nconsole.log(countReplies(topic));",
            'language' => 'javascript',
            'bug_description' => 'The recursive traversal misses a real base case for empty arrays and can recurse into undefined.',
            'real_world_consequence' => 'Thread pages stall or crash, blocking student participation during assignment deadlines.',
            'concept_tags' => 'recursion,null-handling,arrays',
            'difficulty' => 'intermediate',
            'challenge_type' => 'bug_fix'
        ],
        [
            'title' => 'Queue Cleanup Skip',
            'backstory' => 'BlueHarbor Academy batches stale notifications before morning announcements. Ops found that only some expired messages are removed, leaving noisy reminders in student inboxes. You have one maintenance window before the next class block starts.',
            'broken_code' => "function removeExpired(queue) {\n  for (let i = 0; i < queue.length; i++) {\n    if (queue[i].expired) {\n      queue.splice(i, 1);\n    }\n  }\n  return queue;\n}\n\nconsole.log(removeExpired([{id:1,expired:true},{id:2,expired:true},{id:3,expired:false}]));",
            'language' => 'javascript',
            'bug_description' => 'Mutating the array while incrementing forward skips items after removals.',
            'real_world_consequence' => 'Expired alerts persist and students miss real-time messages that matter.',
            'concept_tags' => 'arrays,loops,data-structures',
            'difficulty' => 'intermediate',
            'challenge_type' => 'bug_fix'
        ],
        [
            'title' => 'Roster Prefetch Race',
            'backstory' => 'NorthSignal Schools preloads class rosters from an API before attendance opens each period. Teachers report empty lists on first load, but refreshing usually fixes it, which hints at a timing race. The principal requested a fix before first period tomorrow.',
            'broken_code' => "async function fetchRoster() {\n  return ['Ana', 'Leo', 'Mina'];\n}\n\nfunction renderRoster() {\n  const roster = fetchRoster();\n  return roster.join(', ');\n}\n\nconsole.log(renderRoster());",
            'language' => 'javascript',
            'bug_description' => 'The code treats a pending Promise like resolved data and uses it too early.',
            'real_world_consequence' => 'Attendance tools open without students listed, forcing manual corrections and delays.',
            'concept_tags' => 'async,arrays,conditionals',
            'difficulty' => 'intermediate',
            'challenge_type' => 'bug_fix'
        ],
        [
            'title' => 'Tuition Totals Drift',
            'backstory' => 'PineMetric Finance exports monthly tuition deltas to the board report every Friday evening. The totals look too high but only for mixed-format imports from legacy systems. Your team must fix it before the CFO locks the report in 30 minutes.',
            'broken_code' => "def total_balance(rows):\n    total = 0\n    for amount in rows:\n        total += str(amount)\n    return total\n\nprint(total_balance([100, 25, 5]))",
            'language' => 'python',
            'bug_description' => 'String coercion during accumulation breaks numeric math and raises type-related runtime errors.',
            'real_world_consequence' => 'Financial summaries become unreliable and can lead to incorrect budget decisions.',
            'concept_tags' => 'type-coercion,math,loops',
            'difficulty' => 'intermediate',
            'challenge_type' => 'bug_fix'
        ],
        [
            'title' => 'Exam Window Index Leak',
            'backstory' => 'OrbitPrep publishes timed exam slots per room and logs the final slot for auditing. During compliance review, the final slot ID is missing and alerts started firing in the overnight pipeline. You are patching this before auditors rerun extracts at dawn.',
            'broken_code' => "function lastSlot(rooms) {\n  for (let i = 0; i < rooms.length; i++) {\n    const slot = rooms[i];\n  }\n  return slot.id;\n}\n\nconsole.log(lastSlot([{id:'A1'},{id:'B2'}]));",
            'language' => 'javascript',
            'bug_description' => 'A block-scoped variable is referenced outside the loop where it was declared.',
            'real_world_consequence' => 'Audit pipelines crash and schools lose traceability for scheduled assessments.',
            'concept_tags' => 'scope,loops,conditionals',
            'difficulty' => 'advanced',
            'challenge_type' => 'bug_fix'
        ],
        [
            'title' => 'Placement Search Drift',
            'backstory' => 'HelixLearn matches students into intervention groups using a sorted score list. Counselors report some exact-match scores are never found, delaying placement decisions before registration closes. You need a precise fix before counselors open enrollment this afternoon.',
            'broken_code' => "def find_score(scores, target):\n    left = 0\n    right = len(scores) - 1\n\n    while left < right:\n        mid = (left + right) // 2\n        if scores[mid] == target:\n            return mid\n        if scores[mid] < target:\n            left = mid + 1\n        else:\n            right = mid - 1\n\n    return -1\n\nprint(find_score([55, 61, 73, 88, 92], 92))",
            'language' => 'python',
            'bug_description' => 'The loop boundary skips checking the final candidate index, missing valid matches.',
            'real_world_consequence' => 'Students are assigned to wrong support tracks because exact scores appear absent.',
            'concept_tags' => 'off-by-one,data-structures,conditionals',
            'difficulty' => 'advanced',
            'challenge_type' => 'bug_fix'
        ],
        [
            'title' => 'Template Clone Contamination',
            'backstory' => 'SummitClass generates assignment templates for multiple teachers during nightly sync. Product saw rubric edits in one class unexpectedly appear in another, and release notes are due in two hours. You need to isolate state before the morning publish.',
            'broken_code' => "function cloneTemplate(template) {\n  const copy = { ...template };\n  copy.rules[0].points = 5;\n  return copy;\n}\n\nconst original = { rules: [{ points: 10 }, { points: 20 }] };\nconst cloned = cloneTemplate(original);\nconsole.log(original.rules[0].points, cloned.rules[0].points);",
            'language' => 'javascript',
            'bug_description' => 'A shallow copy leaves nested objects shared, so updates mutate both structures.',
            'real_world_consequence' => 'Cross-class rubric corruption causes grading disputes and teacher rework.',
            'concept_tags' => 'data-structures,arrays,scope',
            'difficulty' => 'advanced',
            'challenge_type' => 'bug_fix'
        ],
        [
            'title' => 'Schedule Comparator Null Crash',
            'backstory' => 'BeaconDistrict compares class periods to detect overlap before publishing teacher schedules. A null period slipped through from an import job and now approvals fail for entire departments.',
            'broken_code' => "public class ScheduleCheck {\n    static boolean hasOverlap(Integer startA, Integer endA, Integer startB, Integer endB) {\n        if (startA.equals(startB) || endA.equals(endB)) {\n            return true;\n        }\n        return startA < endB && startB < endA;\n    }\n\n    public static void main(String[] args) {\n        System.out.println(hasOverlap(null, 10, 8, 12));\n    }\n}",
            'language' => 'java',
            'bug_description' => 'Calling equals on a potentially null Integer throws a NullPointerException.',
            'real_world_consequence' => 'Schedule publishing is blocked and teachers cannot finalize class rosters.',
            'concept_tags' => 'null-handling,conditionals,type-safety',
            'difficulty' => 'intermediate',
            'challenge_type' => 'bug_fix'
        ],
        [
            'title' => 'Reverse Buffer Off-by-One',
            'backstory' => 'Skyline Exams reverses encrypted seat tokens before validation in a C++ service. A single memory write is tripping sanitizer alarms and stopping nightly batch verification.',
            'broken_code' => "#include <iostream>\n#include <string>\n\nstd::string reverseToken(const std::string& token) {\n    std::string out(token.size(), ' ');\n    for (size_t i = 0; i <= token.size(); ++i) {\n        out[token.size() - 1 - i] = token[i];\n    }\n    return out;\n}\n\nint main() {\n    std::cout << reverseToken(\"ABCD\") << std::endl;\n    return 0;\n}",
            'language' => 'cpp',
            'bug_description' => 'The loop condition writes one index past valid bounds, causing undefined behavior.',
            'real_world_consequence' => 'Token validation jobs fail and exam-room access cannot be confirmed automatically.',
            'concept_tags' => 'off-by-one,arrays,loops',
            'difficulty' => 'advanced',
            'challenge_type' => 'bug_fix'
        ],
        [
            'title' => 'Attendance Sum Type Mismatch',
            'backstory' => 'CampusPulse aggregates attendance minutes in a C# worker before reporting to district analytics. The latest deploy started returning impossible totals in production snapshots.',
            'broken_code' => "using System;\n\nclass Program {\n    static int SumMinutes(string[] values) {\n        int total = 0;\n        foreach (var value in values) {\n            total += value;\n        }\n        return total;\n    }\n\n    static void Main() {\n        Console.WriteLine(SumMinutes(new[] { \"45\", \"30\", \"15\" }));\n    }\n}",
            'language' => 'csharp',
            'bug_description' => 'A string is added directly to an integer accumulator instead of being parsed first.',
            'real_world_consequence' => 'Attendance totals become unreliable and weekly compliance exports are rejected.',
            'concept_tags' => 'type-coercion,loops,math',
            'difficulty' => 'beginner',
            'challenge_type' => 'bug_fix'
        ],
    ];

    $pdo->beginTransaction();

    $insertStmt = $pdo->prepare(
        "INSERT INTO bug_challenges
            (title, backstory, broken_code, language, bug_description, real_world_consequence, concept_tags, difficulty, challenge_type)
         VALUES
            (:title, :backstory, :broken_code, :language, :bug_description, :real_world_consequence, :concept_tags, :difficulty, :challenge_type)"
    );

    $existsStmt = $pdo->prepare(
        "SELECT id FROM bug_challenges WHERE title = :title AND challenge_type = :challenge_type LIMIT 1"
    );

    $inserted = 0;

    foreach ($challenges as $challenge) {
        $existsStmt->execute([
            ':title' => $challenge['title'],
            ':challenge_type' => $challenge['challenge_type'],
        ]);
        if (!$existsStmt->fetch(PDO::FETCH_ASSOC)) {
            $insertStmt->execute($challenge);
            $inserted++;
        }
    }

    $flagStmt = $pdo->prepare("INSERT IGNORE INTO bug_hunt_seed_flag (id) VALUES (1)");
    $flagStmt->execute();

    $pdo->commit();

    if ($alreadySeeded) {
        echo "Bug Hunt Arena updated. Added {$inserted} new challenge(s).";
    } else {
        echo "Bug Hunt Arena seeded successfully ({$inserted} challenges inserted).";
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo 'Seeding failed: ' . htmlspecialchars($e->getMessage());
}
