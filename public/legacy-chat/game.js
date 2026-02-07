// FILE: isolir/chat/game.js
// VERSI: FIX HIGHSCORE SYNC

const canvas = document.getElementById("gameCanvas");
const ctx = canvas.getContext("2d");

const box = 20; 
let snake = [];
let food = {};
let score = 0;
let highScore = 0; // Akan diupdate dari DB
let d; 
let game; 
let isGameRunning = false;

// --- 0. AUDIO SYSTEM ---
const AudioContext = window.AudioContext || window.webkitAudioContext;
let audioCtx = new AudioContext();

function playGameSound(type) {
    if (audioCtx.state === 'suspended') audioCtx.resume();
    const osc = audioCtx.createOscillator();
    const gainNode = audioCtx.createGain();
    osc.connect(gainNode);
    gainNode.connect(audioCtx.destination);
    
    if (type === 'eat') {
        osc.type = 'sine'; osc.frequency.setValueAtTime(600, audioCtx.currentTime); osc.frequency.exponentialRampToValueAtTime(800, audioCtx.currentTime + 0.1);
        gainNode.gain.setValueAtTime(0.1, audioCtx.currentTime); gainNode.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.1);
        osc.start(); osc.stop(audioCtx.currentTime + 0.1);
    } else if (type === 'crash') {
        osc.type = 'sawtooth'; osc.frequency.setValueAtTime(100, audioCtx.currentTime); osc.frequency.exponentialRampToValueAtTime(50, audioCtx.currentTime + 0.3);
        gainNode.gain.setValueAtTime(0.2, audioCtx.currentTime); gainNode.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.3);
        osc.start(); osc.stop(audioCtx.currentTime + 0.3);
    }
}

// --- 1. SVG SKIN ---
const svgData = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128"><defs><linearGradient id="fullGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" style="stop-color:#06b6d4;stop-opacity:1"/><stop offset="50%" style="stop-color:#3b82f6;stop-opacity:1"/><stop offset="100%" style="stop-color:#a855f7;stop-opacity:1"/></linearGradient><mask id="starHole"><rect x="0" y="0" width="128" height="128" fill="white"/><path d="M64 36 C78 50, 78 50, 92 64 C78 78, 78 78, 64 92 C50 78, 50 78, 36 64 C50 50, 50 50, 64 36 Z" fill="black"/></mask></defs><path d="M64 0 C96 32, 96 32, 128 64 C96 96, 96 96, 64 128 C32 96, 32 96, 0 64 C32 32, 32 32, 64 0 Z" fill="url(#fullGrad)" mask="url(#starHole)"/></svg>`;
const imgBlob = new Blob([svgData], {type: 'image/svg+xml'});
const url = URL.createObjectURL(imgBlob);
const skinImg = new Image(); skinImg.src = url;

// --- 2. FITUR FETCH HIGHSCORE (BARU) ---
function fetchMyHighScore() {
    fetch('admin_api.php?action=get_my_high_score')
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                highScore = parseInt(res.score) || 0;
                // Update tampilan High Score di Header
                const hsDisplay = document.getElementById('high-score-display');
                if (hsDisplay) hsDisplay.innerText = highScore;
            }
        });
}

// --- 3. NAVIGASI ---
function openGame() {
    // AMBIL SKOR DARI DATABASE SAAT DIBUKA
    fetchMyHighScore(); 

    document.getElementById('empty-state').classList.add('hidden');
    const gameInterface = document.getElementById('game-interface');
    if(gameInterface) { gameInterface.classList.remove('hidden'); gameInterface.classList.add('flex'); }
    const chatInterface = document.getElementById('chat-interface');
    if(chatInterface) { chatInterface.classList.add('hidden'); chatInterface.classList.remove('flex'); }
    startGame();
    window.addEventListener("keydown", handleKeydown);
}

function closeGame() {
    clearInterval(game); isGameRunning = false;
    const gameInterface = document.getElementById('game-interface');
    if(gameInterface) { gameInterface.classList.add('hidden'); gameInterface.classList.remove('flex'); }
    document.getElementById('empty-state').classList.remove('hidden');
    window.removeEventListener("keydown", handleKeydown);
}

// --- 4. LOGIKA GAME ---
function startGame() {
    document.getElementById('game-over-msg').classList.add('hidden');
    document.getElementById('new-record-msg').classList.add('hidden');
    snake = []; snake[0] = { x: 10 * box, y: 10 * box };
    score = 0;
    const scoreEl = document.getElementById('game-score'); if(scoreEl) scoreEl.innerText = score;
    d = null; createFood();
    if(game) clearInterval(game);
    game = setInterval(draw, 100); 
    isGameRunning = true;
}

function createFood() {
    food = { x: Math.floor(Math.random()*(canvas.width/box))*box, y: Math.floor(Math.random()*(canvas.height/box))*box };
    for(let i=0; i<snake.length; i++){ if(food.x == snake[i].x && food.y == snake[i].y){ createFood(); break; } }
}

function handleKeydown(event) {
    const key = event.keyCode;
    if (key === 27) { closeGame(); return; } 
    if (!isGameRunning) {
        const gameOverMsg = document.getElementById('game-over-msg');
        if (key === 32 && gameOverMsg && !gameOverMsg.classList.contains('hidden')) { startGame(); } return;
    }
    if([32, 37, 38, 39, 40].indexOf(key) > -1) event.preventDefault();
    if((key == 37 || key == 65) && d != "RIGHT") d = "LEFT";
    else if((key == 38 || key == 87) && d != "DOWN") d = "UP";
    else if((key == 39 || key == 68) && d != "LEFT") d = "RIGHT";
    else if((key == 40 || key == 83) && d != "UP") d = "DOWN";
}

function draw() {
    const isDark = document.documentElement.classList.contains('dark');
    ctx.fillStyle = isDark ? "#0b141a" : "#efeae2"; ctx.fillRect(0, 0, canvas.width, canvas.height);

    for (let i = 0; i < snake.length; i++) {
        ctx.save(); 
        if(i == 0) { ctx.shadowColor = "rgba(6, 182, 212, 0.5)"; ctx.shadowBlur = 15; }
        ctx.drawImage(skinImg, snake[i].x, snake[i].y, box, box);
        ctx.restore();
    }

    ctx.save(); ctx.shadowColor = "rgba(168, 85, 247, 0.8)"; ctx.shadowBlur = 20; const pad = 2; 
    ctx.drawImage(skinImg, food.x + pad, food.y + pad, box - (pad*2), box - (pad*2)); ctx.restore();

    let snakeX = snake[0].x; let snakeY = snake[0].y;
    if (!d) return; 

    if (d == "LEFT") snakeX -= box; if (d == "UP") snakeY -= box; if (d == "RIGHT") snakeX += box; if (d == "DOWN") snakeY += box;

    if (snakeX == food.x && snakeY == food.y) {
        score++; playGameSound('eat');
        const scoreEl = document.getElementById('game-score'); if(scoreEl) scoreEl.innerText = score;
        createFood();
    } else { snake.pop(); }

    let newHead = { x: snakeX, y: snakeY };
    if (snakeX < 0 || snakeX >= canvas.width || snakeY < 0 || snakeY >= canvas.height || collision(newHead, snake)) {
        playGameSound('crash'); gameOver(); return;
    }
    snake.unshift(newHead);
}

function collision(head, array) {
    for (let i = 0; i < array.length; i++) { if (head.x == array[i].x && head.y == array[i].y) return true; } return false;
}

// --- 5. GAME OVER & SYNC ---
function gameOver() {
    clearInterval(game); isGameRunning = false;
    
    // UPDATE UI SEGERA
    document.getElementById('final-score').innerText = score;
    document.getElementById('game-over-msg').classList.remove('hidden');

    // CEK LOKAL DULU AGAR CEPAT
    if (score > highScore) {
        highScore = score;
        document.getElementById('high-score-display').innerText = highScore;
        document.getElementById('new-record-msg').classList.remove('hidden');
    }

    // KIRIM KE DATABASE
    const fd = new FormData();
    fd.append('action', 'save_game_score');
    fd.append('score', score);

    fetch('admin_api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            // Setelah save, update leaderboard
            loadLeaderboard();
        });
}

function loadLeaderboard() {
    const listEl = document.getElementById('leaderboard-list');
    if(!listEl) return;
    listEl.innerHTML = '<div class="text-center text-[10px] text-slate-400 animate-pulse">Memuat ranking...</div>';

    fetch('admin_api.php?action=get_leaderboard')
        .then(r => r.json())
        .then(res => {
            if(res.status === 'success' && res.data.length > 0) {
                let html = '';
                res.data.forEach(item => {
                    const isMeClass = item.is_me ? "bg-blue-100 dark:bg-green-900/40 border-blue-200 dark:border-green-800" : "bg-white dark:bg-white/5 border-transparent";
                    const textColor = item.is_me ? "text-blue-700 dark:text-green-400 font-bold" : "text-slate-600 dark:text-slate-300";
                    let rankIcon = `<span class="w-5 h-5 flex items-center justify-center text-[10px] font-mono text-slate-400 bg-slate-100 dark:bg-black/30 rounded">${item.rank}</span>`;
                    if(item.rank === 1) rankIcon = '🥇';
                    if(item.rank === 2) rankIcon = '🥈';
                    if(item.rank === 3) rankIcon = '🥉';

                    html += `<div class="flex items-center justify-between px-3 py-2 rounded-lg border ${isMeClass} transition-colors text-xs"><div class="flex items-center gap-2">${rankIcon}<span class="${textColor} truncate max-w-[100px]">${item.name}</span></div><span class="font-mono font-bold text-slate-700 dark:text-white">${item.score}</span></div>`;
                });
                listEl.innerHTML = html;
            } else { listEl.innerHTML = '<div class="text-center text-[10px] text-slate-400">Belum ada data ranking.</div>'; }
        });
}