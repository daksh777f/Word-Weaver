<?php
// ════════════════════════════════════════
// FILE: live_coding_seed.php
// PURPOSE: Seed Live Coding Arena challenges and concept relationship graph one time.
// ANALYSES USED: onboarding/config.php, play/seed.php, play/bug-hunt.php
// NEW TABLES USED: live_coding_challenges, concept_connections, live_coding_seed_flag
// DEPENDS ON: onboarding/config.php
// CEREBRAS CALLS: no
// CANVAS RENDERING: no
// ════════════════════════════════════════

require_once '../onboarding/config.php';

requireLogin();

header('Content-Type: text/plain; charset=utf-8');

try {
    $seedCheck = $pdo->query("SELECT COUNT(*) FROM live_coding_seed_flag WHERE id = 1")->fetchColumn();
    if ((int)$seedCheck > 0) {
        echo "Live coding challenges already seeded.\n";
        exit();
    }

    $challenges = [
        [
            'title' => 'Flatten a Nested Array',
            'description' => 'Implement flattenArray(arr) that returns a one-dimensional array from a nested input array of unknown depth. Preserve element order. Input is an array that may contain primitives and nested arrays; output must be a flat array.',
            'backstory' => 'At OrbitCart, checkout analytics are failing because category filters are nested unpredictably by a legacy exporter. Product wants a hotfix in the next deploy window before campaign traffic spikes.',
            'starter_code' => "function flattenArray(arr) {\n  // TODO: return a flat array from nested arrays\n}\n",
            'reference_solution' => "function flattenArray(arr) {\n  const result = [];\n  for (const item of arr) {\n    if (Array.isArray(item)) {\n      result.push(...flattenArray(item));\n    } else {\n      result.push(item);\n    }\n  }\n  return result;\n}\n",
            'language' => 'javascript',
            'concept_tags' => 'arrays, recursion',
            'difficulty' => 'beginner',
            'expected_output' => 'A flat array containing all nested values in order.',
            'test_cases' => [
                ['input' => [[1, [2, 3], [[4]], 5]], 'expected_output' => [1, 2, 3, 4, 5]],
                ['input' => [[[]]], 'expected_output' => []],
                ['input' => [[0, [null, [false]]]], 'expected_output' => [0, null, false]],
                ['input' => [[-1, [-2, [-3]]]], 'expected_output' => [-1, -2, -3]],
            ],
            'hints' => [
                'Try solving one level at a time, then reuse that logic for deeper levels.',
                'Loop through each item: if it is an array, flatten it recursively; otherwise push it.',
                'Use Array.isArray(item) and spread the recursive result into a single output array.',
            ],
        ],
        [
            'title' => 'Longest Substring Without Repeating Characters',
            'description' => 'Implement lengthOfLongestSubstring(s) that returns the length of the longest substring without duplicate characters. Input is a string; output is an integer length.',
            'backstory' => 'At StreamFuse, session-token diagnostics are timing out in production because repeated-character scans are too slow. Your team needs a fast implementation before the incident review starts.',
            'starter_code' => "function lengthOfLongestSubstring(s) {\n  // TODO: return max length of substring without repeating chars\n}\n",
            'reference_solution' => "function lengthOfLongestSubstring(s) {\n  let left = 0;\n  let best = 0;\n  const seen = new Map();\n\n  for (let right = 0; right < s.length; right++) {\n    const ch = s[right];\n    if (seen.has(ch) && seen.get(ch) >= left) {\n      left = seen.get(ch) + 1;\n    }\n    seen.set(ch, right);\n    best = Math.max(best, right - left + 1);\n  }\n\n  return best;\n}\n",
            'language' => 'javascript',
            'concept_tags' => 'strings, sliding-window, hashing',
            'difficulty' => 'intermediate',
            'expected_output' => 'Length of the longest unique-character substring.',
            'test_cases' => [
                ['input' => ['abcabcbb'], 'expected_output' => 3],
                ['input' => ['bbbbb'], 'expected_output' => 1],
                ['input' => [''], 'expected_output' => 0],
                ['input' => ['pwwkew'], 'expected_output' => 3],
            ],
            'hints' => [
                'A brute-force check of all substrings is too slow for long strings.',
                'Use a sliding window and track the latest index of each character.',
                'When you see a duplicate inside the current window, move left to lastIndex + 1.',
            ],
        ],
        [
            'title' => 'Build Debounce From Scratch',
            'description' => 'Implement debounce(fn, delay) that returns a function which delays execution of fn until no new call has occurred for delay milliseconds. Keep the latest arguments and this-context.',
            'backstory' => 'NimbusForms is flooding the API with keypress requests during live search. Ops has warned that rate limits will start rejecting customer traffic in minutes unless you debounce inputs.',
            'starter_code' => "function debounce(fn, delay) {\n  // TODO: return debounced function\n}\n",
            'reference_solution' => "function debounce(fn, delay) {\n  let timer = null;\n  return function (...args) {\n    const context = this;\n    clearTimeout(timer);\n    timer = setTimeout(() => {\n      fn.apply(context, args);\n    }, delay);\n  };\n}\n",
            'language' => 'javascript',
            'concept_tags' => 'async, scope, conditionals',
            'difficulty' => 'intermediate',
            'expected_output' => 'A debounced wrapper function.',
            'test_cases' => [
                ['input' => ['calls at t=0,50 with delay=100'], 'expected_output' => 'fn called once after last call'],
                ['input' => ['single call with delay=0'], 'expected_output' => 'fn called asynchronously once'],
                ['input' => ['rapid 5 calls'], 'expected_output' => 'only final args used'],
                ['input' => ['null arg'], 'expected_output' => 'null is forwarded correctly'],
            ],
            'hints' => [
                'You need memory that persists between calls to the returned function.',
                'Store a timer id, clear it on each call, then set a new timeout.',
                'Use fn.apply(this, args) inside setTimeout so context and arguments are preserved.',
            ],
        ],
        [
            'title' => 'Two Sum Indices',
            'description' => 'Implement twoSum(nums, target) that returns indices [i, j] such that nums[i] + nums[j] === target. Return an empty array if no pair exists.',
            'backstory' => 'At LedgerLoop, fraud rules are matching amounts in O(n²) and delaying payouts. The finance lead needs the optimized index lookup before the overnight batch starts.',
            'starter_code' => "function twoSum(nums, target) {\n  // TODO: return [i, j] indices or []\n}\n",
            'reference_solution' => "function twoSum(nums, target) {\n  const map = new Map();\n  for (let i = 0; i < nums.length; i++) {\n    const needed = target - nums[i];\n    if (map.has(needed)) {\n      return [map.get(needed), i];\n    }\n    map.set(nums[i], i);\n  }\n  return [];\n}\n",
            'language' => 'javascript',
            'concept_tags' => 'arrays, hashing',
            'difficulty' => 'beginner',
            'expected_output' => 'Pair of indices or empty array.',
            'test_cases' => [
                ['input' => [[2, 7, 11, 15], 9], 'expected_output' => [0, 1]],
                ['input' => [[3, 2, 4], 6], 'expected_output' => [1, 2]],
                ['input' => [[0, 4, 3, 0], 0], 'expected_output' => [0, 3]],
                ['input' => [[-3, 4, 3, 90], 0], 'expected_output' => [0, 2]],
            ],
            'hints' => [
                'Think about what number you need at each step to hit the target.',
                'Keep a hash map of value -> index for numbers already seen.',
                'Check for target - nums[i] before storing the current number.',
            ],
        ],
        [
            'title' => 'Deep Clone Object (No JSON.parse)',
            'description' => 'Implement deepClone(value) that deeply copies objects and arrays, preserving nested structure. Primitive values should be returned directly. Do not use JSON stringify/parse.',
            'backstory' => 'AtlasCMS is mutating shared config objects between requests, causing random customer settings to leak. Security escalated this to P1 and needs a safe deep copy utility now.',
            'starter_code' => "function deepClone(value) {\n  // TODO: deeply clone arrays/objects without JSON.parse\n}\n",
            'reference_solution' => "function deepClone(value) {\n  if (value === null || typeof value !== 'object') {\n    return value;\n  }\n\n  if (Array.isArray(value)) {\n    return value.map((item) => deepClone(item));\n  }\n\n  const out = {};\n  for (const key of Object.keys(value)) {\n    out[key] = deepClone(value[key]);\n  }\n  return out;\n}\n",
            'language' => 'javascript',
            'concept_tags' => 'recursion, data-structures',
            'difficulty' => 'advanced',
            'expected_output' => 'Deeply cloned copy of the input value.',
            'test_cases' => [
                ['input' => [['a', ['b']]], 'expected_output' => ['a', ['b']]],
                ['input' => [[['x' => 1, 'y' => ['z' => 2]]]], 'expected_output' => [['x' => 1, 'y' => ['z' => 2]]]],
                ['input' => [[null]], 'expected_output' => null],
                ['input' => [[['n' => -5, 'arr' => [0, 1]]]], 'expected_output' => [['n' => -5, 'arr' => [0, 1]]]],
            ],
            'hints' => [
                'First decide how to handle primitives and null values.',
                'Arrays and plain objects need separate clone branches.',
                'Recurse into each element/property and assign into a fresh container.',
            ],
        ],
        [
            'title' => 'Binary Search',
            'description' => 'Implement binarySearch(nums, target) on a sorted ascending array and return the index of target, or -1 if not found.',
            'backstory' => 'At SearchGrid, the recommendation service is timing out on large sorted datasets. Product analytics needs logarithmic lookup before the launch toggle flips.',
            'starter_code' => "function binarySearch(nums, target) {\n  // TODO: return index of target in sorted nums, else -1\n}\n",
            'reference_solution' => "function binarySearch(nums, target) {\n  let left = 0;\n  let right = nums.length - 1;\n\n  while (left <= right) {\n    const mid = Math.floor((left + right) / 2);\n    if (nums[mid] === target) return mid;\n    if (nums[mid] < target) {\n      left = mid + 1;\n    } else {\n      right = mid - 1;\n    }\n  }\n\n  return -1;\n}\n",
            'language' => 'javascript',
            'concept_tags' => 'arrays, searching, off-by-one',
            'difficulty' => 'intermediate',
            'expected_output' => 'Target index or -1.',
            'test_cases' => [
                ['input' => [[1, 2, 3, 4, 5], 4], 'expected_output' => 3],
                ['input' => [[1, 2, 3, 4, 5], 6], 'expected_output' => -1],
                ['input' => [[], 10], 'expected_output' => -1],
                ['input' => [[-5, -2, 0, 8], -5], 'expected_output' => 0],
            ],
            'hints' => [
                'Use two pointers to represent the current search interval.',
                'After checking mid, discard half the interval based on comparison.',
                'The loop condition should allow left and right to meet to avoid off-by-one misses.',
            ],
        ],
        [
            'title' => 'Group Anagrams',
            'description' => 'Implement groupAnagrams(words) that groups strings that are anagrams. Return an array of groups.',
            'backstory' => 'LexiCloud is building word-game lobbies and players report duplicate rooms due to bad grouping logic. The PM needs reliable anagram grouping before the school event opens.',
            'starter_code' => "function groupAnagrams(words) {\n  // TODO: group anagrams together\n}\n",
            'reference_solution' => "function groupAnagrams(words) {\n  const map = new Map();\n\n  for (const word of words) {\n    const key = word.split('').sort().join('');\n    if (!map.has(key)) map.set(key, []);\n    map.get(key).push(word);\n  }\n\n  return Array.from(map.values());\n}\n",
            'language' => 'javascript',
            'concept_tags' => 'strings, hashing, arrays',
            'difficulty' => 'intermediate',
            'expected_output' => 'List of grouped anagram arrays.',
            'test_cases' => [
                ['input' => [['eat', 'tea', 'tan', 'ate', 'nat', 'bat']], 'expected_output' => [['eat', 'tea', 'ate'], ['tan', 'nat'], ['bat']]],
                ['input' => [['']], 'expected_output' => [['']]],
                ['input' => [['a']], 'expected_output' => [['a']]],
                ['input' => [['ab', 'ba', 'abc', 'bca']], 'expected_output' => [['ab', 'ba'], ['abc', 'bca']]],
            ],
            'hints' => [
                'Anagrams share the same letters in a different order.',
                'Create a canonical key for each word, like its sorted characters.',
                'Use a map from key to array, then return map values.',
            ],
        ],
        [
            'title' => 'Memoize a Function',
            'description' => 'Implement memoize(fn) that returns a function caching results by argument list. If called again with the same args, return cached value.',
            'backstory' => 'At QuantLearn, expensive scoring functions are recomputed for every student dashboard refresh. Infra costs are spiking and your lead asks for memoization before finance review.',
            'starter_code' => "function memoize(fn) {\n  // TODO: cache fn results by arguments\n}\n",
            'reference_solution' => "function memoize(fn) {\n  const cache = new Map();\n  return function (...args) {\n    const key = JSON.stringify(args);\n    if (cache.has(key)) {\n      return cache.get(key);\n    }\n    const result = fn.apply(this, args);\n    cache.set(key, result);\n    return result;\n  };\n}\n",
            'language' => 'javascript',
            'concept_tags' => 'scope, data-structures, dynamic-programming',
            'difficulty' => 'advanced',
            'expected_output' => 'A memoized wrapper that reuses cached results.',
            'test_cases' => [
                ['input' => ['fn(2,3) then fn(2,3)'], 'expected_output' => 'second call returns cached result'],
                ['input' => ['fn(-1) then fn(-1)'], 'expected_output' => 'negative argument cached'],
                ['input' => ['fn(0) then fn(0)'], 'expected_output' => 'zero argument cached'],
                ['input' => ['fn(null) then fn(null)'], 'expected_output' => 'null argument cached'],
            ],
            'hints' => [
                'You need a persistent cache in the closure around the returned function.',
                'Create a stable cache key from args and check cache before running fn.',
                'Store computed result in the cache and return it; reuse on repeated keys.',
            ],
        ],
        [
            'title' => 'Queue Using Two Stacks',
            'description' => 'Implement createQueue() that returns an object with enqueue, dequeue, and peek using two stacks.',
            'backstory' => 'PacketRail’s worker scheduler is out of order under burst traffic. The backend architect wants a queue abstraction built from stack primitives in the shared runtime today.',
            'starter_code' => "function createQueue() {\n  // TODO: implement queue using two stacks\n}\n",
            'reference_solution' => "function createQueue() {\n  const inStack = [];\n  const outStack = [];\n\n  function shiftStacks() {\n    if (outStack.length === 0) {\n      while (inStack.length > 0) {\n        outStack.push(inStack.pop());\n      }\n    }\n  }\n\n  return {\n    enqueue(value) {\n      inStack.push(value);\n    },\n    dequeue() {\n      shiftStacks();\n      return outStack.length ? outStack.pop() : null;\n    },\n    peek() {\n      shiftStacks();\n      return outStack.length ? outStack[outStack.length - 1] : null;\n    }\n  };\n}\n",
            'language' => 'javascript',
            'concept_tags' => 'data-structures, arrays',
            'difficulty' => 'advanced',
            'expected_output' => 'Queue API that behaves FIFO.',
            'test_cases' => [
                ['input' => ['enqueue 1,2 then dequeue'], 'expected_output' => 1],
                ['input' => ['enqueue 1,2 then peek'], 'expected_output' => 1],
                ['input' => ['dequeue empty queue'], 'expected_output' => null],
                ['input' => ['enqueue -1,0 then dequeue twice'], 'expected_output' => [-1, 0]],
            ],
            'hints' => [
                'One stack receives new items; the other serves old items.',
                'Only transfer from in-stack to out-stack when out-stack is empty.',
                'FIFO emerges after reversing order during transfer.',
            ],
        ],
        [
            'title' => 'Count Islands in 2D Grid',
            'description' => 'Implement countIslands(grid) where grid contains "1" (land) and "0" (water). Return number of connected islands using 4-direction adjacency.',
            'backstory' => 'GeoPulse maps classroom network outages as land/water clusters and incident triage depends on accurate region counts. Leadership needs this fixed before the live operations dashboard refresh.',
            'starter_code' => "function countIslands(grid) {\n  // TODO: count connected islands of '1' cells\n}\n",
            'reference_solution' => "function countIslands(grid) {\n  if (!Array.isArray(grid) || grid.length === 0) return 0;\n\n  const rows = grid.length;\n  const cols = grid[0].length;\n  let islands = 0;\n\n  function dfs(r, c) {\n    if (r < 0 || c < 0 || r >= rows || c >= cols || grid[r][c] !== '1') return;\n    grid[r][c] = '0';\n    dfs(r + 1, c);\n    dfs(r - 1, c);\n    dfs(r, c + 1);\n    dfs(r, c - 1);\n  }\n\n  for (let r = 0; r < rows; r++) {\n    for (let c = 0; c < cols; c++) {\n      if (grid[r][c] === '1') {\n        islands++;\n        dfs(r, c);\n      }\n    }\n  }\n\n  return islands;\n}\n",
            'language' => 'javascript',
            'concept_tags' => 'arrays, recursion, data-structures',
            'difficulty' => 'advanced',
            'expected_output' => 'Integer number of islands.',
            'test_cases' => [
                ['input' => [[['1','1','0','0'],['1','0','0','1'],['0','0','1','1']]], 'expected_output' => 3],
                ['input' => [[['0','0'],['0','0']]], 'expected_output' => 0],
                ['input' => [[[]]], 'expected_output' => 0],
                ['input' => [[['1']]], 'expected_output' => 1],
            ],
            'hints' => [
                'Each unseen land cell starts a new island count.',
                'Use DFS/BFS to mark all connected land from that starting cell.',
                'Mutate visited land to water (or track visited set) so it is not counted twice.',
            ],
        ],
    ];

    $insertChallenge = $pdo->prepare(
        "INSERT INTO live_coding_challenges
        (title, description, backstory, starter_code, reference_solution, language, concept_tags, difficulty, expected_output, test_cases, hints)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $conceptPairs = [
        ['arrays', 'loops'],
        ['arrays', 'sorting'],
        ['arrays', 'searching'],
        ['arrays', 'two-pointers'],
        ['arrays', 'sliding-window'],
        ['recursion', 'dynamic-programming'],
        ['recursion', 'data-structures'],
        ['hashing', 'arrays'],
        ['hashing', 'strings'],
        ['strings', 'loops'],
        ['strings', 'sliding-window'],
        ['scope', 'async'],
        ['scope', 'closures'],
        ['data-structures', 'recursion'],
        ['searching', 'off-by-one'],
    ];

    $insertConnection = $pdo->prepare(
        "INSERT IGNORE INTO concept_connections (concept_from, concept_to, connection_strength) VALUES (?, ?, 1)"
    );

    $pdo->beginTransaction();

    foreach ($challenges as $challenge) {
        $insertChallenge->execute([
            $challenge['title'],
            $challenge['description'],
            $challenge['backstory'],
            $challenge['starter_code'],
            $challenge['reference_solution'],
            $challenge['language'],
            $challenge['concept_tags'],
            $challenge['difficulty'],
            $challenge['expected_output'],
            json_encode($challenge['test_cases'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($challenge['hints'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    foreach ($conceptPairs as $pair) {
        $insertConnection->execute([$pair[0], $pair[1]]);
    }

    $pdo->exec("INSERT INTO live_coding_seed_flag (id) VALUES (1)");

    $pdo->commit();

    echo "Live Coding Arena seed complete. Inserted 10 challenges and concept connections.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo "Seed failed: " . $e->getMessage() . "\n";
}
