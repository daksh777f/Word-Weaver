<?php
// ════════════════════════════════════════
// FILE: saboteur_seed.php
// PURPOSE: Seed saboteur_challenges with 8 challenges across 4 categories
// NEW TABLES USED: saboteur_challenges, saboteur_seed_flag
// DEPENDS ON: config.php
// CEREBRAS: no
// ════════════════════════════════════════

require_once '../onboarding/config.php';

// Check if already seeded
$stmt = $pdo->prepare("SELECT 1 FROM saboteur_seed_flag WHERE id = 1");
$stmt->execute();
if ($stmt->fetch()) {
    echo "✓ Saboteur challenges already seeded.";
    exit;
}

$challenges = [
    // ════════════════════════════════════════
    // CATEGORY 1: Object Oriented (2 challenges)
    // ════════════════════════════════════════
    [
        'title' => 'Counter Class',
        'category' => 'Object Oriented',
        'difficulty' => 'beginner',
        'base_code' => "class Counter {
  constructor() {
    this.count = 0;
    this.history = [];
  }

  increment() {
    this.count += 1;
    this.history.push('increment');
    return this.count;
  }

  decrement() {
    this.count -= 1;
    this.history.push('decrement');
    return this.count;
  }

  // TODO: Implement reset — should set count to 0 and clear history
  reset() {
    // Fix me!
  }

  // TODO: Implement value — should return current count
  value() {
    // Fix me!
  }

  // TODO: Implement last_change — should return last action from history
  last_change() {
    // Fix me!
  }
}",
        'test_cases' => json_encode([
            [
                'id' => 1,
                'description' => 'increment increments count',
                'check_type' => 'contains',
                'pattern' => 'this\\.count \\+= 1|count\\s*\\+\\+',
                'negate' => false
            ],
            [
                'id' => 2,
                'description' => 'decrement decrements count',
                'check_type' => 'contains',
                'pattern' => 'this\\.count -= 1|count\\s*--',
                'negate' => false
            ],
            [
                'id' => 3,
                'description' => 'reset clears count and history',
                'check_type' => 'has_function',
                'pattern' => 'reset',
                'negate' => false
            ]
        ]),
        'todo_descriptions' => json_encode([
            'Fix the reset method — should set count to 0 and clear history array',
            'Implement the value method — should return current count',
            'Implement the last_change method — should return the last item from history'
        ]),
        'sabotage_tasks' => json_encode([
            [
                'id' => 1,
                'description' => 'Break increment by making it add 2 instead of 1',
                'target_function' => 'increment',
                'detection_pattern' => 'count \\+= 2|\\+= 2',
                'hint' => 'Change += 1 to += 2'
            ],
            [
                'id' => 2,
                'description' => 'Break decrement by making it subtract 2 instead of 1',
                'target_function' => 'decrement',
                'detection_pattern' => 'count -= 2|- 2',
                'hint' => 'Change -= 1 to -= 2'
            ],
            [
                'id' => 3,
                'description' => 'Break history tracking by removing push calls',
                'target_function' => 'constructor',
                'detection_pattern' => 'history.*=.*\\[\\]',
                'hint' => 'Delete the history initialization'
            ]
        ])
    ],
    [
        'title' => 'Bank Account Class',
        'category' => 'Object Oriented',
        'difficulty' => 'intermediate',
        'base_code' => "class BankAccount {
  constructor(owner, initialBalance = 0) {
    this.owner = owner;
    this.balance = initialBalance;
    this.transactions = [];
  }

  deposit(amount) {
    if (amount <= 0) return false;
    this.balance += amount;
    this.transactions.push({
      type: 'deposit',
      amount: amount,
      timestamp: new Date()
    });
    return true;
  }

  get_balance() {
    return this.balance;
  }

  // TODO: Implement withdraw — should reduce balance and return success
  withdraw(amount) {
    // Fix me!
  }

  // TODO: Implement transfer — should create deposit in target account
  transfer(targetAccount, amount) {
    // Fix me!
  }

  // TODO: Implement get_history — return all transactions
  get_history() {
    // Fix me!
  }
}",
        'test_cases' => json_encode([
            [
                'id' => 1,
                'description' => 'deposit adds to balance',
                'check_type' => 'contains',
                'pattern' => 'balance \\+= amount',
                'negate' => false
            ],
            [
                'id' => 2,
                'description' => 'deposit logs transaction',
                'check_type' => 'contains',
                'pattern' => 'transactions\\.push',
                'negate' => false
            ],
            [
                'id' => 3,
                'description' => 'withdraw reduces balance',
                'check_type' => 'has_function',
                'pattern' => 'withdraw',
                'negate' => false
            ]
        ]),
        'todo_descriptions' => json_encode([
            'Implement withdraw method — check if amount <= balance, subtract and push transaction',
            'Implement transfer — call withdraw on this account and deposit on target account',
            'Implement get_history — return the transactions array'
        ]),
        'sabotage_tasks' => json_encode([
            [
                'id' => 1,
                'description' => 'Break deposit by removing the balance update',
                'target_function' => 'deposit',
                'detection_pattern' => 'balance -= amount',
                'hint' => 'Change += to -='
            ],
            [
                'id' => 2,
                'description' => 'Break get_balance by returning 0 always',
                'target_function' => 'get_balance',
                'detection_pattern' => 'balance.*0',
                'hint' => 'Return 0 instead of this.balance'
            ],
            [
                'id' => 3,
                'description' => 'Break transaction logging by removing push',
                'target_function' => 'deposit',
                'detection_pattern' => '// transactions',
                'hint' => 'Comment out the transactions.push line'
            ]
        ])
    ],
    // ════════════════════════════════════════
    // CATEGORY 2: Arrays and Loops (2 challenges)
    // ════════════════════════════════════════
    [
        'title' => 'Shopping Cart',
        'category' => 'Arrays and Loops',
        'difficulty' => 'beginner',
        'base_code' => "class ShoppingCart {
  constructor() {
    this.items = [];
  }

  add_item(name, price, quantity = 1) {
    this.items.push({
      name: name,
      price: price,
      quantity: quantity
    });
    return true;
  }

  get_total() {
    let total = 0;
    for (let item of this.items) {
      total += item.price * item.quantity;
    }
    return total;
  }

  // TODO: Implement remove_item — remove item by name
  remove_item(name) {
    // Fix me!
  }

  // TODO: Implement apply_discount — discount percentage off total
  apply_discount(percentage) {
    // Fix me!
  }

  // TODO: Implement get_item_count — return total quantity
  get_item_count() {
    // Fix me!
  }
}",
        'test_cases' => json_encode([
            [
                'id' => 1,
                'description' => 'add_item pushes to items array',
                'check_type' => 'contains',
                'pattern' => 'items\\.push',
                'negate' => false
            ],
            [
                'id' => 2,
                'description' => 'get_total calculates correctly',
                'check_type' => 'contains',
                'pattern' => 'price \\* quantity|quantity.*\\* .*price',
                'negate' => false
            ],
            [
                'id' => 3,
                'description' => 'remove_item filters items',
                'check_type' => 'has_function',
                'pattern' => 'remove_item',
                'negate' => false
            ]
        ]),
        'todo_descriptions' => json_encode([
            'Implement remove_item — filter items array to remove item with matching name',
            'Implement apply_discount — return get_total() minus (percentage/100 * get_total())',
            'Implement get_item_count — sum all item quantities'
        ]),
        'sabotage_tasks' => json_encode([
            [
                'id' => 1,
                'description' => 'Break get_total by adding instead of multiplying',
                'target_function' => 'get_total',
                'detection_pattern' => 'price \\+ quantity',
                'hint' => 'Change * to +'
            ],
            [
                'id' => 2,
                'description' => 'Break add_item by pushing wrong structure',
                'target_function' => 'add_item',
                'detection_pattern' => 'price: price',
                'negate' => true,
                'hint' => 'Change the property names'
            ],
            [
                'id' => 3,
                'description' => 'Break item tracking by resetting items array',
                'target_function' => 'add_item',
                'detection_pattern' => 'this\\.items = \\[\\]',
                'hint' => 'Add this.items = [] at the start'
            ]
        ])
    ],
    [
        'title' => 'Score Tracker',
        'category' => 'Arrays and Loops',
        'difficulty' => 'intermediate',
        'base_code' => "class ScoreTracker {
  constructor() {
    this.scores = [];
  }

  add_score(score) {
    if (score >= 0 && score <= 100) {
      this.scores.push(score);
      return true;
    }
    return false;
  }

  get_average() {
    if (this.scores.length === 0) return 0;
    let sum = 0;
    for (let score of this.scores) {
      sum += score;
    }
    return sum / this.scores.length;
  }

  // TODO: Implement get_high_score — return max score
  get_high_score() {
    // Fix me!
  }

  // TODO: Implement reset_scores — clear scores array
  reset_scores() {
    // Fix me!
  }

  // TODO: Implement get_rank — return letter grade based on average
  get_rank() {
    // Fix me!
  }
}",
        'test_cases' => json_encode([
            [
                'id' => 1,
                'description' => 'add_score validates range',
                'check_type' => 'contains',
                'pattern' => 'score >= 0.*score <= 100|score.*100',
                'negate' => false
            ],
            [
                'id' => 2,
                'description' => 'get_average calculates correctly',
                'check_type' => 'contains',
                'pattern' => 'sum.*scores\\.length|length',
                'negate' => false
            ],
            [
                'id' => 3,
                'description' => 'get_high_score returns maximum',
                'check_type' => 'has_function',
                'pattern' => 'get_high_score',
                'negate' => false
            ]
        ]),
        'todo_descriptions' => json_encode([
            'Implement get_high_score — find and return the maximum score from array',
            'Implement reset_scores — clear the scores array completely',
            'Implement get_rank — map average to letter grades (90+=A, 80+=B, etc)'
        ]),
        'sabotage_tasks' => json_encode([
            [
                'id' => 1,
                'description' => 'Break get_average by not dividing sum',
                'target_function' => 'get_average',
                'detection_pattern' => 'sum / this\\.scores\\.length',
                'negate' => true,
                'hint' => 'Remove the division operation'
            ],
            [
                'id' => 2,
                'description' => 'Break add_score validation',
                'target_function' => 'add_score',
                'detection_pattern' => 'score >= 0',
                'negate' => true,
                'hint' => 'Remove the validation check'
            ],
            [
                'id' => 3,
                'description' => 'Break score storage by not pushing',
                'target_function' => 'add_score',
                'detection_pattern' => 'scores\\.push',
                'negate' => true,
                'hint' => 'Comment out the push line'
            ]
        ])
    ],
    // ════════════════════════════════════════
    // CATEGORY 3: String Manipulation (2 challenges)
    // ════════════════════════════════════════
    [
        'title' => 'Text Processor',
        'category' => 'String Manipulation',
        'difficulty' => 'beginner',
        'base_code' => "class TextProcessor {
  static capitalize(str) {
    return str.charAt(0).toUpperCase() + str.slice(1).toLowerCase();
  }

  static reverse(str) {
    return str.split('').reverse().join('');
  }

  // TODO: Implement count_words — count words in string
  static count_words(str) {
    // Fix me!
  }

  // TODO: Implement remove_duplicates — remove duplicate characters
  static remove_duplicates(str) {
    // Fix me!
  }

  // TODO: Implement truncate — limit string to n chars + \"...\"
  static truncate(str, length = 20) {
    // Fix me!
  }
}",
        'test_cases' => json_encode([
            [
                'id' => 1,
                'description' => 'capitalize works',
                'check_type' => 'contains',
                'pattern' => 'toUpperCase.*slice',
                'negate' => false
            ],
            [
                'id' => 2,
                'description' => 'reverse works',
                'check_type' => 'contains',
                'pattern' => 'reverse.*join',
                'negate' => false
            ],
            [
                'id' => 3,
                'description' => 'count_words implemented',
                'check_type' => 'has_function',
                'pattern' => 'count_words',
                'negate' => false
            ]
        ]),
        'todo_descriptions' => json_encode([
            'Implement count_words — split by spaces and return length',
            'Implement remove_duplicates — use Set to keep unique characters',
            'Implement truncate — if length > n, return first n chars + "..."'
        ]),
        'sabotage_tasks' => json_encode([
            [
                'id' => 1,
                'description' => 'Break capitalize with toLowerCase instead of toUpperCase',
                'target_function' => 'capitalize',
                'detection_pattern' => 'toLowerCase\\(\\)',
                'negate' => true,
                'hint' => 'Change toUpperCase to toLowerCase'
            ],
            [
                'id' => 2,
                'description' => 'Break reverse by not joining',
                'target_function' => 'reverse',
                'detection_pattern' => 'join',
                'negate' => true,
                'hint' => 'Remove the join() call'
            ],
            [
                'id' => 3,
                'description' => 'Break split/join pattern',
                'target_function' => 'reverse',
                'detection_pattern' => "split.*''",
                'negate' => true,
                'hint' => 'Remove the split'
            ]
        ])
    ],
    [
        'title' => 'Password Validator',
        'category' => 'String Manipulation',
        'difficulty' => 'intermediate',
        'base_code' => "class PasswordValidator {
  static check_length(pwd, min = 8) {
    return pwd.length >= min;
  }

  static has_uppercase(pwd) {
    return /[A-Z]/.test(pwd);
  }

  // TODO: Implement has_number — check if string contains digit
  static has_number(pwd) {
    // Fix me!
  }

  // TODO: Implement has_special — check for special chars
  static has_special(pwd) {
    // Fix me!
  }

  // TODO: Implement get_strength — return weak/medium/strong
  static get_strength(pwd) {
    // Fix me!
  }
}",
        'test_cases' => json_encode([
            [
                'id' => 1,
                'description' => 'check_length validates minimum',
                'check_type' => 'contains',
                'pattern' => 'length >= min',
                'negate' => false
            ],
            [
                'id' => 2,
                'description' => 'has_uppercase uses regex',
                'check_type' => 'contains',
                'pattern' => 'A-Z',
                'negate' => false
            ],
            [
                'id' => 3,
                'description' => 'has_number implemented',
                'check_type' => 'has_function',
                'pattern' => 'has_number',
                'negate' => false
            ]
        ]),
        'todo_descriptions' => json_encode([
            'Implement has_number — use regex /[0-9]/ test',
            'Implement has_special — check for regex pattern /[!@#$%^&*]/',
            'Implement get_strength — count checks passed, return strength level'
        ]),
        'sabotage_tasks' => json_encode([
            [
                'id' => 1,
                'description' => 'Break length check by using < instead of >=',
                'target_function' => 'check_length',
                'detection_pattern' => '< min',
                'negate' => false,
                'hint' => 'Change >= to <'
            ],
            [
                'id' => 2,
                'description' => 'Break uppercase detection with lowercase pattern',
                'target_function' => 'has_uppercase',
                'detection_pattern' => 'a-z',
                'negate' => false,
                'hint' => 'Change A-Z to a-z'
            ],
            [
                'id' => 3,
                'description' => 'Break regex test by using exec instead',
                'target_function' => 'has_uppercase',
                'detection_pattern' => '\\.test',
                'negate' => true,
                'hint' => 'Remove the .test() method'
            ]
        ])
    ],
    // ════════════════════════════════════════
    // CATEGORY 4: Data Structures (2 challenges)
    // ════════════════════════════════════════
    [
        'title' => 'Stack Implementation',
        'category' => 'Data Structures',
        'difficulty' => 'intermediate',
        'base_code' => "class Stack {
  constructor() {
    this.items = [];
  }

  push(element) {
    this.items.push(element);
    return true;
  }

  is_empty() {
    return this.items.length === 0;
  }

  // TODO: Implement pop — remove and return top element
  pop() {
    // Fix me!
  }

  // TODO: Implement peek — return top element without removing
  peek() {
    // Fix me!
  }

  // TODO: Implement get_size — return number of elements
  get_size() {
    // Fix me!
  }
}",
        'test_cases' => json_encode([
            [
                'id' => 1,
                'description' => 'push adds to items',
                'check_type' => 'contains',
                'pattern' => 'items\\.push',
                'negate' => false
            ],
            [
                'id' => 2,
                'description' => 'is_empty checks length',
                'check_type' => 'contains',
                'pattern' => 'length === 0',
                'negate' => false
            ],
            [
                'id' => 3,
                'description' => 'pop implemented',
                'check_type' => 'has_function',
                'pattern' => 'pop',
                'negate' => false
            ]
        ]),
        'todo_descriptions' => json_encode([
            'Implement pop — call items.pop() which removes and returns last element',
            'Implement peek — return items at length-1 without removing',
            'Implement get_size — return this.items.length'
        ]),
        'sabotage_tasks' => json_encode([
            [
                'id' => 1,
                'description' => 'Break push by unshifting instead',
                'target_function' => 'push',
                'detection_pattern' => 'items\\.unshift',
                'negate' => false,
                'hint' => 'Change push() to unshift()'
            ],
            [
                'id' => 2,
                'description' => 'Break is_empty by checking for > 0',
                'target_function' => 'is_empty',
                'detection_pattern' => '> 0|length > 0',
                'negate' => false,
                'hint' => 'Change === 0 to > 0'
            ],
            [
                'id' => 3,
                'description' => 'Break peek by returning at wrong index',
                'target_function' => 'peek',
                'detection_pattern' => '\\[0\\]',
                'negate' => false,
                'hint' => 'Change length-1 to 0'
            ]
        ])
    ],
    [
        'title' => 'Queue Implementation',
        'category' => 'Data Structures',
        'difficulty' => 'advanced',
        'base_code' => "class Queue {
  constructor() {
    this.items = [];
    this.front = 0;
  }

  enqueue(element) {
    this.items.push(element);
    return true;
  }

  get_length() {
    return this.items.length - this.front;
  }

  // TODO: Implement dequeue — remove and return front element
  dequeue() {
    // Fix me!
  }

  // TODO: Implement peek — return front element without removing
  peek() {
    // Fix me!
  }

  // TODO: Implement clear — empty the queue
  clear() {
    // Fix me!
  }
}",
        'test_cases' => json_encode([
            [
                'id' => 1,
                'description' => 'enqueue adds to items',
                'check_type' => 'contains',
                'pattern' => 'items\\.push',
                'negate' => false
            ],
            [
                'id' => 2,
                'description' => 'get_length subtracts front from length',
                'check_type' => 'contains',
                'pattern' => 'length - this\\.front',
                'negate' => false
            ],
            [
                'id' => 3,
                'description' => 'dequeue implemented',
                'check_type' => 'has_function',
                'pattern' => 'dequeue',
                'negate' => false
            ]
        ]),
        'todo_descriptions' => json_encode([
            'Implement dequeue — return this.items[this.front] and increment front',
            'Implement peek — return element at this.front position',
            'Implement clear — reset items array and front to 0'
        ]),
        'sabotage_tasks' => json_encode([
            [
                'id' => 1,
                'description' => 'Break get_length by not subtracting front',
                'target_function' => 'get_length',
                'detection_pattern' => 'this\\.front',
                'negate' => true,
                'hint' => 'Remove the - this.front'
            ],
            [
                'id' => 2,
                'description' => 'Break enqueue by using unshift',
                'target_function' => 'enqueue',
                'detection_pattern' => 'items\\.unshift',
                'negate' => false,
                'hint' => 'Change push to unshift'
            ],
            [
                'id' => 3,
                'description' => 'Break dequeue by not incrementing front',
                'target_function' => 'dequeue',
                'detection_pattern' => 'front\\+\\+|front = this\\.front.*1',
                'negate' => true,
                'hint' => 'Remove the front++ increment'
            ]
        ])
    ]
];

// Insert challenges
foreach ($challenges as $challenge) {
    $stmt = $pdo->prepare("
        INSERT INTO saboteur_challenges
        (title, category, base_code, test_cases, todo_descriptions, sabotage_tasks, language, difficulty)
        VALUES (?, ?, ?, ?, ?, ?, 'javascript', ?)
    ");
    
    $success = $stmt->execute([
        $challenge['title'],
        $challenge['category'],
        $challenge['base_code'],
        $challenge['test_cases'],
        $challenge['todo_descriptions'],
        $challenge['sabotage_tasks'],
        $challenge['difficulty']
    ]);
    
    if (!$success) {
        echo "✗ Failed to insert: " . $challenge['title'] . "<br>";
    }
}

// Mark as seeded
$stmt = $pdo->prepare("INSERT INTO saboteur_seed_flag (id) VALUES (1) ON DUPLICATE KEY UPDATE seeded_at = NOW()");
$stmt->execute();

echo "✓ Saboteur challenges seeded successfully (8 challenges across 4 categories)!";
?>