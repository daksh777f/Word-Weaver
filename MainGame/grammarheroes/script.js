const MODES = [
    {
        label: 'Shoot only verbs',
        targets: ['run', 'write', 'build', 'speak', 'learn', 'solve', 'grow', 'create'],
        decoys: ['quick', 'table', 'bright', 'garden', 'silent', 'river', 'happy', 'mountain']
    },
    {
        label: 'Shoot only nouns',
        targets: ['planet', 'teacher', 'library', 'engine', 'bridge', 'student', 'dragon', 'forest'],
        decoys: ['quickly', 'sing', 'blue', 'careful', 'jump', 'softly', 'brave', 'write']
    },
    {
        label: 'Shoot only adjectives',
        targets: ['brave', 'silent', 'rapid', 'golden', 'curious', 'gentle', 'ancient', 'clever'],
        decoys: ['book', 'dance', 'city', 'code', 'repair', 'teacher', 'build', 'window']
    },
    {
        label: 'Shoot only adverbs',
        targets: ['quickly', 'carefully', 'boldly', 'softly', 'calmly', 'rarely', 'brightly', 'surely'],
        decoys: ['hero', 'green', 'run', 'paper', 'vivid', 'teacher', 'swim', 'glass']
    }
];

const canvas = document.getElementById('arena');
const ctx = canvas.getContext('2d');

const ui = {
    score: document.getElementById('score'),
    lives: document.getElementById('lives'),
    combo: document.getElementById('combo'),
    wave: document.getElementById('wave'),
    time: document.getElementById('time'),
    enemies: document.getElementById('enemies'),
    modeLabel: document.getElementById('modeLabel'),
    bossState: document.getElementById('bossState'),
    modeFlash: document.getElementById('modeFlash'),
    startOverlay: document.getElementById('startOverlay'),
    startBtn: document.getElementById('startBtn'),
    pauseBtn: document.getElementById('pauseBtn'),
    leftBtn: document.getElementById('leftBtn'),
    rightBtn: document.getElementById('rightBtn'),
    shootBtn: document.getElementById('shootBtn'),
    resultModal: document.getElementById('resultModal'),
    finalScore: document.getElementById('finalScore'),
    finalAccuracy: document.getElementById('finalAccuracy'),
    finalStreak: document.getElementById('finalStreak'),
    finalXp: document.getElementById('finalXp'),
    finalWave: document.getElementById('finalWave'),
    finalHits: document.getElementById('finalHits'),
    finalEnemies: document.getElementById('finalEnemies'),
    finalBosses: document.getElementById('finalBosses'),
    saveStatus: document.getElementById('saveStatus'),
    playAgainBtn: document.getElementById('playAgainBtn')
};

const state = {
    running: false,
    paused: false,
    gameOver: false,
    score: 0,
    lives: 5,
    combo: 0,
    bestCombo: 0,
    correctHits: 0,
    judgedShots: 0,
    enemiesDefeated: 0,
    bossesDefeated: 0,
    wave: 1,
    elapsed: 0,
    waveTimer: 0,
    modeTimer: 0,
    spawnTimer: 0,
    enemySpawnTimer: 0,
    shotCooldown: 0,
    currentModeIndex: 0,
    nextBossWave: 4,
    spawnInterval: 1.1,
    enemySpawnInterval: 6,
    maxDuration: Number(window.grammarHeroesConfig?.maxDurationSeconds || 180),
    lastTs: 0,
    keys: { left: false, right: false },
    player: {
        x: canvas.width / 2 - 34,
        y: canvas.height - 76,
        width: 68,
        height: 68,
        speed: 420
    },
    playerShots: [],
    enemyShots: [],
    words: [],
    drones: [],
    particles: [],
    boss: null,
    statusText: ''
};

function randomBetween(min, max) {
    return Math.random() * (max - min) + min;
}

function chooseMode(exclude = -1) {
    let index = Math.floor(Math.random() * MODES.length);
    if (index === exclude) {
        index = (index + 1) % MODES.length;
    }
    return index;
}

function chooseWord(mode) {
    const isTarget = Math.random() < 0.55;
    const bank = isTarget ? mode.targets : mode.decoys;
    const text = bank[Math.floor(Math.random() * bank.length)];
    return { text, isTarget };
}

function flashMode(text = MODES[state.currentModeIndex].label) {
    ui.modeFlash.textContent = text;
    ui.modeFlash.hidden = false;
    setTimeout(() => {
        ui.modeFlash.hidden = true;
    }, 1200);
}

function setStatus(text) {
    state.statusText = text;
    ui.bossState.textContent = text;
}

function updateHud() {
    ui.score.textContent = state.score;
    ui.lives.textContent = state.lives;
    ui.combo.textContent = state.combo;
    ui.wave.textContent = state.wave;
    ui.time.textContent = Math.floor(state.elapsed);
    ui.enemies.textContent = state.drones.length + (state.boss ? 1 : 0);
    ui.modeLabel.textContent = MODES[state.currentModeIndex].label;
    if (!state.boss && !state.statusText) {
        ui.bossState.textContent = 'No boss active';
    }
}

function spawnParticles(x, y, color, amount = 12) {
    for (let i = 0; i < amount; i += 1) {
        const angle = (Math.PI * 2 * i) / amount;
        const speed = randomBetween(40, 125);
        state.particles.push({
            x,
            y,
            vx: Math.cos(angle) * speed,
            vy: Math.sin(angle) * speed,
            life: 0.55,
            color
        });
    }
}

function spawnWord() {
    const mode = MODES[state.currentModeIndex];
    const word = chooseWord(mode);
    const width = Math.max(96, word.text.length * 12 + 34);

    state.words.push({
        x: randomBetween(10, canvas.width - width - 10),
        y: -34,
        width,
        height: 32,
        vy: randomBetween(72, 120) + state.wave * 9,
        text: word.text,
        isTarget: word.isTarget,
        source: 'ambient'
    });
}

function spawnDrone() {
    const side = Math.random() < 0.5 ? -1 : 1;
    state.drones.push({
        x: side < 0 ? 30 : canvas.width - 90,
        y: randomBetween(70, 180),
        width: 62,
        height: 34,
        vx: side * randomBetween(70, 115),
        fireCooldown: randomBetween(1.1, 2.3),
        hp: 2 + Math.floor(state.wave / 3)
    });
}

function spawnBoss() {
    state.boss = {
        x: canvas.width / 2 - 100,
        y: 36,
        width: 200,
        height: 84,
        vx: 140,
        hp: 22 + state.wave * 3,
        maxHp: 22 + state.wave * 3,
        fireCooldown: 1.3,
        wordCooldown: 1.1
    };
    setStatus(`Boss Wave ${state.wave}: Syntax Titan`);
    flashMode(`Boss Wave ${state.wave}`);
}

function resetState() {
    state.running = false;
    state.paused = false;
    state.gameOver = false;
    state.score = 0;
    state.lives = 5;
    state.combo = 0;
    state.bestCombo = 0;
    state.correctHits = 0;
    state.judgedShots = 0;
    state.enemiesDefeated = 0;
    state.bossesDefeated = 0;
    state.wave = 1;
    state.elapsed = 0;
    state.waveTimer = 0;
    state.modeTimer = 0;
    state.spawnTimer = 0;
    state.enemySpawnTimer = 0;
    state.shotCooldown = 0;
    state.currentModeIndex = chooseMode();
    state.nextBossWave = 4;
    state.spawnInterval = 1.1;
    state.enemySpawnInterval = 6;
    state.lastTs = 0;
    state.player.x = canvas.width / 2 - state.player.width / 2;
    state.playerShots = [];
    state.enemyShots = [];
    state.words = [];
    state.drones = [];
    state.particles = [];
    state.boss = null;
    state.statusText = '';
    ui.resultModal.hidden = true;
    ui.startOverlay.hidden = false;
    ui.pauseBtn.innerHTML = '<i class="fas fa-pause"></i>';
    ui.saveStatus.textContent = 'Saving progress...';
    ui.saveStatus.style.color = '#ffcc70';
    updateHud();
}

function startGame() {
    if (state.running) {
        return;
    }
    state.running = true;
    state.paused = false;
    ui.startOverlay.hidden = true;
    flashMode();
}

function togglePause() {
    if (!state.running || state.gameOver) {
        return;
    }
    state.paused = !state.paused;
    ui.pauseBtn.innerHTML = state.paused ? '<i class="fas fa-play"></i>' : '<i class="fas fa-pause"></i>';
    setStatus(state.paused ? 'Paused' : (state.boss ? 'Boss active' : 'No boss active'));
}

function shootPlayerProjectile() {
    if (!state.running || state.paused || state.gameOver || state.shotCooldown > 0) {
        return;
    }

    state.playerShots.push({
        x: state.player.x + state.player.width / 2 - 4,
        y: state.player.y - 10,
        width: 8,
        height: 16,
        vy: -540
    });
    state.shotCooldown = 0.22;
}

function rectHit(a, b) {
    return a.x < b.x + b.width && a.x + a.width > b.x && a.y < b.y + b.height && a.y + a.height > b.y;
}

function scoreForCorrectWord() {
    return 16 + Math.min(30, state.combo * 3) + state.wave * 2;
}

function handleWordDecision(word, projectileIndex) {
    state.judgedShots += 1;

    if (word.isTarget) {
        state.correctHits += 1;
        state.combo += 1;
        state.bestCombo = Math.max(state.bestCombo, state.combo);
        state.score += scoreForCorrectWord();
        spawnParticles(word.x + word.width / 2, word.y + word.height / 2, '#67e59f', 14);
    } else {
        state.combo = 0;
        state.lives -= 1;
        state.score = Math.max(0, state.score - 22);
        spawnParticles(word.x + word.width / 2, word.y + word.height / 2, '#ff6c88', 14);
    }

    state.words.splice(projectileIndex.wordIndex, 1);
    state.playerShots.splice(projectileIndex.shotIndex, 1);
}

function updatePlayer(dt) {
    const movement = (Number(state.keys.right) - Number(state.keys.left)) * state.player.speed * dt;
    state.player.x += movement;
    state.player.x = Math.max(0, Math.min(canvas.width - state.player.width, state.player.x));

    if (state.shotCooldown > 0) {
        state.shotCooldown -= dt;
    }
}

function updateProjectiles(dt) {
    for (let i = state.playerShots.length - 1; i >= 0; i -= 1) {
        const shot = state.playerShots[i];
        shot.y += shot.vy * dt;
        if (shot.y + shot.height < 0) {
            state.playerShots.splice(i, 1);
            continue;
        }

        let resolved = false;

        for (let w = state.words.length - 1; w >= 0; w -= 1) {
            if (rectHit(shot, state.words[w])) {
                handleWordDecision(state.words[w], { shotIndex: i, wordIndex: w });
                resolved = true;
                break;
            }
        }

        if (resolved) {
            continue;
        }

        for (let d = state.drones.length - 1; d >= 0; d -= 1) {
            const drone = state.drones[d];
            if (rectHit(shot, drone)) {
                drone.hp -= 1;
                state.playerShots.splice(i, 1);
                spawnParticles(shot.x, shot.y, '#58e1ff', 8);
                if (drone.hp <= 0) {
                    state.drones.splice(d, 1);
                    state.enemiesDefeated += 1;
                    state.score += 40 + state.wave * 6;
                    spawnParticles(drone.x + drone.width / 2, drone.y + drone.height / 2, '#ffcc70', 18);
                }
                resolved = true;
                break;
            }
        }

        if (resolved) {
            continue;
        }

        if (state.boss && rectHit(shot, state.boss)) {
            state.boss.hp -= 1;
            state.playerShots.splice(i, 1);
            spawnParticles(shot.x, shot.y, '#ffcc70', 6);
            setStatus(`Boss HP ${Math.max(0, state.boss.hp)}/${state.boss.maxHp}`);

            if (state.boss.hp <= 0) {
                state.score += 250 + state.wave * 25;
                state.bossesDefeated += 1;
                state.enemiesDefeated += 1;
                spawnParticles(state.boss.x + state.boss.width / 2, state.boss.y + state.boss.height / 2, '#ffcc70', 28);
                state.boss = null;
                state.wave += 1;
                state.waveTimer = 0;
                state.nextBossWave += 4;
                setStatus('Boss defeated');
                flashMode('Boss Defeated');
            }
        }
    }

    for (let i = state.enemyShots.length - 1; i >= 0; i -= 1) {
        const shot = state.enemyShots[i];
        shot.y += shot.vy * dt;
        shot.x += shot.vx * dt;

        if (shot.y > canvas.height + 20 || shot.x < -40 || shot.x > canvas.width + 40) {
            state.enemyShots.splice(i, 1);
            continue;
        }

        if (rectHit(shot, state.player)) {
            state.enemyShots.splice(i, 1);
            state.combo = 0;
            state.lives -= 1;
            spawnParticles(state.player.x + state.player.width / 2, state.player.y + 10, '#ff6c88', 18);
        }
    }
}

function updateWords(dt) {
    for (let i = state.words.length - 1; i >= 0; i -= 1) {
        const word = state.words[i];
        word.y += word.vy * dt;

        if (word.y > canvas.height + 40) {
            if (word.isTarget) {
                state.combo = 0;
                state.lives -= 1;
            }
            state.words.splice(i, 1);
        }
    }
}

function enemyShoot(sourceX, sourceY, targetX, targetY, speed = 240) {
    const dx = targetX - sourceX;
    const dy = targetY - sourceY;
    const mag = Math.hypot(dx, dy) || 1;
    state.enemyShots.push({
        x: sourceX,
        y: sourceY,
        width: 10,
        height: 18,
        vx: (dx / mag) * speed,
        vy: (dy / mag) * speed
    });
}

function updateDrones(dt) {
    for (let i = state.drones.length - 1; i >= 0; i -= 1) {
        const drone = state.drones[i];
        drone.x += drone.vx * dt;
        if (drone.x <= 0 || drone.x + drone.width >= canvas.width) {
            drone.vx *= -1;
        }

        drone.fireCooldown -= dt;
        if (drone.fireCooldown <= 0) {
            enemyShoot(
                drone.x + drone.width / 2,
                drone.y + drone.height,
                state.player.x + state.player.width / 2,
                state.player.y,
                220 + state.wave * 10
            );
            drone.fireCooldown = randomBetween(1.1, 2.1);
        }
    }
}

function updateBoss(dt) {
    if (!state.boss) {
        return;
    }

    state.boss.x += state.boss.vx * dt;
    if (state.boss.x <= 18 || state.boss.x + state.boss.width >= canvas.width - 18) {
        state.boss.vx *= -1;
    }

    state.boss.fireCooldown -= dt;
    state.boss.wordCooldown -= dt;

    if (state.boss.fireCooldown <= 0) {
        const lanes = [-0.5, 0, 0.5];
        lanes.forEach((offset) => {
            enemyShoot(
                state.boss.x + state.boss.width / 2 + offset * 50,
                state.boss.y + state.boss.height,
                state.player.x + state.player.width / 2 + offset * 40,
                state.player.y,
                250 + state.wave * 12
            );
        });
        state.boss.fireCooldown = 1.3;
    }

    if (state.boss.wordCooldown <= 0) {
        const mode = MODES[state.currentModeIndex];
        const word = chooseWord(mode);
        const width = Math.max(110, word.text.length * 12 + 38);
        state.words.push({
            x: state.boss.x + state.boss.width / 2 - width / 2 + randomBetween(-60, 60),
            y: state.boss.y + state.boss.height - 8,
            width,
            height: 34,
            vy: 145 + state.wave * 10,
            text: word.text,
            isTarget: word.isTarget,
            source: 'boss'
        });
        state.boss.wordCooldown = 0.9;
    }
}

function updateParticles(dt) {
    for (let i = state.particles.length - 1; i >= 0; i -= 1) {
        const p = state.particles[i];
        p.x += p.vx * dt;
        p.y += p.vy * dt;
        p.vy += 150 * dt;
        p.life -= dt;
        if (p.life <= 0) {
            state.particles.splice(i, 1);
        }
    }
}

function updateProgression(dt) {
    state.waveTimer += dt;
    state.modeTimer += dt;
    state.spawnTimer += dt;
    state.enemySpawnTimer += dt;

    if (state.modeTimer >= 16) {
        state.modeTimer = 0;
        state.currentModeIndex = chooseMode(state.currentModeIndex);
        flashMode();
    }

    if (state.spawnTimer >= state.spawnInterval && !state.boss) {
        state.spawnTimer = 0;
        spawnWord();
    }

    if (state.enemySpawnTimer >= state.enemySpawnInterval && !state.boss) {
        state.enemySpawnTimer = 0;
        spawnDrone();
    }

    if (!state.boss && state.waveTimer >= 18) {
        state.wave += 1;
        state.waveTimer = 0;
        state.spawnInterval = Math.max(0.45, state.spawnInterval - 0.06);
        state.enemySpawnInterval = Math.max(3.2, state.enemySpawnInterval - 0.2);

        if (state.wave === state.nextBossWave) {
            spawnBoss();
        }
    }
}

function drawBackground() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = '#091023';
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    for (let i = 0; i < 40; i += 1) {
        const x = (i * 193 + state.elapsed * 30) % canvas.width;
        const y = (i * 109) % canvas.height;
        ctx.fillStyle = i % 2 === 0 ? 'rgba(88,225,255,0.30)' : 'rgba(255,255,255,0.12)';
        ctx.fillRect(x, y, 2, 2);
    }

    const floorY = canvas.height - 34;
    const grd = ctx.createLinearGradient(0, floorY, 0, canvas.height);
    grd.addColorStop(0, 'rgba(88,225,255,0.18)');
    grd.addColorStop(1, 'rgba(7,12,24,0.95)');
    ctx.fillStyle = grd;
    ctx.fillRect(0, floorY, canvas.width, canvas.height - floorY);
}

function drawPlayer() {
    const p = state.player;
    ctx.fillStyle = '#283f8d';
    roundRect(ctx, p.x, p.y, p.width, p.height, 18, true, false);

    ctx.fillStyle = '#58e1ff';
    roundRect(ctx, p.x + 6, p.y + 6, p.width - 12, p.height - 12, 14, true, false);

    ctx.fillStyle = '#d1fbff';
    ctx.fillRect(p.x + p.width / 2 - 5, p.y - 14, 10, 16);
}

function drawWords() {
    state.words.forEach((word) => {
        const bg = word.source === 'boss' ? 'rgba(255, 204, 112, 0.88)' : 'rgba(222, 232, 255, 0.88)';
        const border = word.source === 'boss' ? '#ffb347' : '#58e1ff';
        ctx.fillStyle = bg;
        ctx.strokeStyle = border;
        ctx.lineWidth = 2;
        roundRect(ctx, word.x, word.y, word.width, word.height, 14, true, true);

        ctx.fillStyle = '#0b1230';
        ctx.font = 'bold 16px Poppins, sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(word.text, word.x + word.width / 2, word.y + word.height / 2 + 1);
    });
}

function drawPlayerShots() {
    ctx.fillStyle = '#8cffec';
    state.playerShots.forEach((shot) => {
        roundRect(ctx, shot.x, shot.y, shot.width, shot.height, 8, true, false);
    });
}

function drawEnemyShots() {
    ctx.fillStyle = '#ff6c88';
    state.enemyShots.forEach((shot) => {
        roundRect(ctx, shot.x, shot.y, shot.width, shot.height, 8, true, false);
    });
}

function drawDrones() {
    state.drones.forEach((drone) => {
        ctx.fillStyle = '#f97393';
        roundRect(ctx, drone.x, drone.y, drone.width, drone.height, 10, true, false);
        ctx.fillStyle = '#fff2f5';
        ctx.fillRect(drone.x + 10, drone.y + 10, drone.width - 20, 6);
    });
}

function drawBoss() {
    if (!state.boss) {
        return;
    }

    ctx.fillStyle = '#ffb347';
    roundRect(ctx, state.boss.x, state.boss.y, state.boss.width, state.boss.height, 18, true, false);
    ctx.fillStyle = '#612400';
    ctx.font = 'bold 22px Poppins, sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText('SYNTAX TITAN', state.boss.x + state.boss.width / 2, state.boss.y + 30);

    ctx.fillStyle = 'rgba(0,0,0,0.35)';
    roundRect(ctx, state.boss.x + 20, state.boss.y + 52, state.boss.width - 40, 14, 10, true, false);
    ctx.fillStyle = '#67e59f';
    const hpWidth = ((state.boss.hp / state.boss.maxHp) * (state.boss.width - 40));
    roundRect(ctx, state.boss.x + 20, state.boss.y + 52, hpWidth, 14, 10, true, false);
}

function drawParticles() {
    state.particles.forEach((p) => {
        ctx.globalAlpha = Math.max(0, p.life / 0.55);
        ctx.fillStyle = p.color;
        ctx.beginPath();
        ctx.arc(p.x, p.y, 2.6, 0, Math.PI * 2);
        ctx.fill();
        ctx.globalAlpha = 1;
    });
}

function roundRect(context, x, y, width, height, radius, fill, stroke) {
    const r = typeof radius === 'number' ? { tl: radius, tr: radius, br: radius, bl: radius } : radius;
    context.beginPath();
    context.moveTo(x + r.tl, y);
    context.lineTo(x + width - r.tr, y);
    context.quadraticCurveTo(x + width, y, x + width, y + r.tr);
    context.lineTo(x + width, y + height - r.br);
    context.quadraticCurveTo(x + width, y + height, x + width - r.br, y + height);
    context.lineTo(x + r.bl, y + height);
    context.quadraticCurveTo(x, y + height, x, y + height - r.bl);
    context.lineTo(x, y + r.tl);
    context.quadraticCurveTo(x, y, x + r.tl, y);
    context.closePath();
    if (fill) {
        context.fill();
    }
    if (stroke) {
        context.stroke();
    }
}

function render() {
    drawBackground();
    drawPlayer();
    drawWords();
    drawPlayerShots();
    drawEnemyShots();
    drawDrones();
    drawBoss();
    drawParticles();
}

function calculateXp() {
    return state.correctHits * 8 + state.bestCombo * 5 + state.wave * 12 + state.enemiesDefeated * 7 + state.bossesDefeated * 40;
}

async function saveProgress() {
    const payload = {
        score: state.score,
        totalQuestions: Math.max(1, state.judgedShots),
        correctAnswers: state.correctHits,
        maxStreak: state.bestCombo,
        totalTime: Math.floor(state.elapsed),
        xpEarned: calculateXp(),
        wavesCompleted: state.wave,
        enemiesDefeated: state.enemiesDefeated,
        bossesDefeated: state.bossesDefeated
    };

    try {
        const response = await fetch(window.grammarHeroesConfig.saveEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.error || 'Unable to save');
        }

        ui.saveStatus.textContent = `Saved. Mastery Score: ${Number(data.data?.new_gwa || 0).toFixed(2)}`;
        ui.saveStatus.style.color = '#67e59f';
    } catch (error) {
        ui.saveStatus.textContent = `Save failed: ${error.message}`;
        ui.saveStatus.style.color = '#ff6c88';
    }
}

function endGame() {
    state.gameOver = true;
    state.running = false;
    const accuracy = Math.round((state.correctHits / Math.max(1, state.judgedShots)) * 100);

    ui.finalScore.textContent = state.score;
    ui.finalAccuracy.textContent = `${accuracy}%`;
    ui.finalStreak.textContent = state.bestCombo;
    ui.finalXp.textContent = calculateXp();
    ui.finalWave.textContent = state.wave;
    ui.finalHits.textContent = state.correctHits;
    ui.finalEnemies.textContent = state.enemiesDefeated;
    ui.finalBosses.textContent = state.bossesDefeated;
    ui.resultModal.hidden = false;
    saveProgress();
}

function tick(timestamp) {
    if (!state.lastTs) {
        state.lastTs = timestamp;
    }

    const dt = Math.min(0.033, (timestamp - state.lastTs) / 1000);
    state.lastTs = timestamp;

    if (state.running && !state.paused && !state.gameOver) {
        state.elapsed += dt;
        updatePlayer(dt);
        updateProjectiles(dt);
        updateWords(dt);
        updateDrones(dt);
        updateBoss(dt);
        updateParticles(dt);
        updateProgression(dt);
        updateHud();

        if (state.lives <= 0 || state.elapsed >= state.maxDuration) {
            endGame();
        }
    } else if (!state.running) {
        updateParticles(dt);
    }

    render();
    requestAnimationFrame(tick);
}

function bindPointerHold(button, key) {
    const down = () => { state.keys[key] = true; };
    const up = () => { state.keys[key] = false; };
    button.addEventListener('pointerdown', down);
    button.addEventListener('pointerup', up);
    button.addEventListener('pointerleave', up);
    button.addEventListener('pointercancel', up);
}

window.addEventListener('keydown', (event) => {
    const key = event.key.toLowerCase();
    if (key === 'a' || event.key === 'ArrowLeft') {
        state.keys.left = true;
    }
    if (key === 'd' || event.key === 'ArrowRight') {
        state.keys.right = true;
    }
    if (event.key === ' ' || key === 'enter') {
        event.preventDefault();
        shootPlayerProjectile();
    }
    if (key === 'p') {
        togglePause();
    }
});

window.addEventListener('keyup', (event) => {
    const key = event.key.toLowerCase();
    if (key === 'a' || event.key === 'ArrowLeft') {
        state.keys.left = false;
    }
    if (key === 'd' || event.key === 'ArrowRight') {
        state.keys.right = false;
    }
});

bindPointerHold(ui.leftBtn, 'left');
bindPointerHold(ui.rightBtn, 'right');
ui.shootBtn.addEventListener('click', shootPlayerProjectile);
ui.pauseBtn.addEventListener('click', togglePause);
ui.startBtn.addEventListener('click', startGame);
ui.playAgainBtn.addEventListener('click', () => {
    resetState();
    startGame();
});

resetState();
requestAnimationFrame(tick);
