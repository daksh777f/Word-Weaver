<?php
require_once '../onboarding/config.php';
requireLogin();

$user_id = (int)$_SESSION['user_id'];
$room_code = isset($_GET['room']) ? trim($_GET['room']) : null;

if (!$room_code) {
    header('Location: index.php');
    exit;
}

// Get room
$stmt = $pdo->prepare("SELECT id FROM saboteur_rooms WHERE room_code = ? LIMIT 1");
$stmt->execute([$room_code]);
$room = $stmt->fetch();

if (!$room) {
    header('Location: index.php');
    exit;
}

$room_id = (int)$room['id'];

// Get all categories and their vote counts
$stmt = $pdo->prepare("
    SELECT DISTINCT category FROM saboteur_challenges ORDER BY category
");
$stmt->execute();
$categories = array_column($stmt->fetchAll(), 'category');

// Get voting state (example: store in session or DB)
$hasVoted = isset($_SESSION['voted_category_' . $room_code]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Code Mafia - Vote Category</title>
    <link rel="stylesheet" href="retro-game.css">
    <style>
        body {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: white;
            position: relative;
        }

        .clouds-container {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 50%;
            pointer-events: none;
        }

        .content {
            position: relative;
            z-index: 10;
            text-align: center;
            max-width: 500px;
            margin-bottom: 60px;
        }

        .vote-heading {
            font-size: 1.8rem;
            color: var(--btn-primary);
            margin-bottom: 1rem;
            letter-spacing: 2px;
        }

        .timer-box {
            display: inline-block;
            background: var(--card-bg);
            border: 3px solid var(--card-border);
            padding: 0.75rem 1rem;
            margin-bottom: 1.5rem;
            font-size: 2.5rem;
            color: var(--text-primary);
            font-weight: bold;
        }

        .categories-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        .category-btn {
            background: var(--card-bg);
            border: 3px solid var(--card-border);
            padding: 1rem 0.75rem;
            cursor: pointer;
            font-size: 2rem;
            color: var(--text-primary);
            text-transform: uppercase;
            box-shadow: inset -2px -2px 0 rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: all 0.1s;
        }

        .category-btn:hover {
            background: #F0D090;
            transform: translateY(-2px);
        }

        .category-btn.selected {
            border-left: 4px solid var(--btn-primary);
            background: #F0D090;
        }

        .category-name {
            flex: 1;
            text-align: left;
            font-size: 2rem;
        }

        .vote-count {
            background: var(--btn-action);
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 0;
            font-size: 1.8rem;
            font-weight: bold;
            min-width: 1.5rem;
        }

        .status-text {
            font-size: 2rem;
            color: white;
        }

        .waiting-text {
            font-size: 2rem;
            color: var(--text-muted);
            font-style: italic;
        }
    </style>
</head>
<body>
    <!-- Clouds -->
    <div class="clouds-container">
        <div class="cloud" style="left: 5%; top: 15%;"></div>
        <div class="cloud" style="left: 25%; top: 5%;"></div>
        <div class="cloud" style="left: 45%; top: 20%;"></div>
        <div class="cloud" style="left: 65%; top: 8%;"></div>
        <div class="cloud" style="left: 80%; top: 18%;"></div>
        <div class="cloud" style="left: 15%; top: 40%;"></div>
        <div class="cloud" style="left: 75%; top: 35%;"></div>
    </div>

    <!-- Main Content -->
    <div class="content">
        <h1 class="vote-heading">VOTE CATEGORY</h1>

        <!-- Timer -->
        <div class="timer-box">
            <span id="timer">7s</span>
        </div>

        <!-- Category Grid -->
        <div class="categories-grid" id="categoriesGrid">
            <?php foreach ($categories as $idx => $category): ?>
                <button class="category-btn" onclick="voteCategory('<?php echo htmlspecialchars($category); ?>', this)">
                    <span class="category-name"><?php echo htmlspecialchars($category); ?></span>
                    <span class="vote-count" id="count-<?php echo $idx; ?>">0</span>
                </button>
            <?php endforeach; ?>
        </div>

        <!-- Status Text -->
        <div id="statusArea">
            <p class="status-text">Select a category to vote</p>
            <p class="waiting-text">Waiting for all players...</p>
        </div>
    </div>

    <!-- Ground Strip -->
    <div class="ground"></div>

    <script>
        const roomCode = <?php echo json_encode($room_code); ?>;
        let selectedCategory = null;
        let hasVoted = <?php echo $hasVoted ? 'true' : 'false'; ?>;
        let timeLeft = 7;

        function voteCategory(category, button) {
            if (hasVoted) return;

            selectedCategory = category;
            
            // Remove previous selection
            document.querySelectorAll('.category-btn').forEach(btn => {
                btn.classList.remove('selected');
            });
            
            button.classList.add('selected');

            // Send vote
            fetch('saboteur_vote_category.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    room_code: roomCode,
                    category: category
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    hasVoted = true;
                    document.getElementById('statusArea').innerHTML = `
                        <p class="status-text">✓ Vote recorded!</p>
                        <p class="waiting-text">Waiting for others...</p>
                    `;
                    updateVotes();
                }
            })
            .catch(err => console.error(err));
        }

        function updateVotes() {
            fetch('saboteur_get_category_votes.php?room=' + encodeURIComponent(roomCode))
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.votes) {
                        Object.keys(data.votes).forEach((category, idx) => {
                            const el = document.getElementById('count-' + idx);
                            if (el) el.textContent = data.votes[category];
                        });
                    }
                })
                .catch(err => console.error(err));
        }

        // Timer countdown
        setInterval(() => {
            timeLeft--;
            if (timeLeft <= 0) {
                timeLeft = 7;
                updateVotes();
            }
            document.getElementById('timer').textContent = timeLeft + 's';
        }, 1000);

        // Initial vote fetch
        updateVotes();

        // Poll for category selection completion
        setInterval(() => {
            fetch('saboteur_poll.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ room_code: roomCode })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success && data.room.challenge_id) {
                    // Category voted, proceed
                    window.location.href = 'game.php?room=' + encodeURIComponent(roomCode);
                }
            })
            .catch(err => console.error(err));
        }, 2000);
    </script>
</body>
</html>
