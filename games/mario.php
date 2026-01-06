<?php
// mario.php - MARIO JUMP (Protótipo)
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
require '../core/conexao.php';

// Segurança
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
$user_id = $_SESSION['user_id'];

// Dados do Usuário
try {
    $stmtMe = $pdo->prepare("SELECT nome, pontos FROM usuarios WHERE id = :id");
    $stmtMe->execute([':id' => $user_id]);
    $meu_perfil = $stmtMe->fetch(PDO::FETCH_ASSOC);

    // Criar tabela de histórico se não existir
    $pdo->exec("CREATE TABLE IF NOT EXISTS mario_historico (id INT AUTO_INCREMENT PRIMARY KEY, id_usuario INT NOT NULL, pontuacao INT NOT NULL, data_jogo DATETIME DEFAULT CURRENT_TIMESTAMP)");

    // Pegar recorde
    $stmtRecorde = $pdo->prepare("SELECT MAX(pontuacao) FROM mario_historico WHERE id_usuario = :id");
    $stmtRecorde->execute([':id' => $user_id]);
    $recorde = $stmtRecorde->fetchColumn() ?: 0;
} catch (PDOException $e) {
    die("Erro DB: " . $e->getMessage());
}

// AJAX - Salvar Score
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao'])) {
    header('Content-Type: application/json');

    if ($_POST['acao'] == 'salvar_score') {
        $score = intval($_POST['score']);
        try {
            $stmt = $pdo->prepare("INSERT INTO mario_historico (id_usuario, pontuacao) VALUES (:id, :score)");
            $stmt->execute([':id' => $user_id, ':score' => $score]);
            echo json_encode(['status' => 'ok', 'score' => $score]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'erro', 'msg' => $e->getMessage()]);
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🍄 Mario Jump - Pikafumo Games</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Arial', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .container {
            width: 100%;
            max-width: 400px;
        }

        .header {
            text-align: center;
            color: white;
            margin-bottom: 20px;
        }

        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
        }

        .stats {
            display: flex;
            justify-content: space-around;
            gap: 10px;
            margin-bottom: 20px;
        }

        .stat-box {
            background: rgba(255,255,255,0.2);
            padding: 15px;
            border-radius: 10px;
            color: white;
            text-align: center;
            flex: 1;
            backdrop-filter: blur(10px);
        }

        .stat-box h3 {
            font-size: 0.9em;
            opacity: 0.8;
            margin-bottom: 5px;
        }

        .stat-box .value {
            font-size: 1.8em;
            font-weight: bold;
        }

        .game-canvas-container {
            background: linear-gradient(180deg, #87ceeb 0%, #e0f6ff 100%);
            border: 4px solid #333;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 8px 20px rgba(0,0,0,0.3);
            margin-bottom: 20px;
        }

        canvas {
            display: block;
            width: 100%;
            height: auto;
        }

        .controls {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        button {
            flex: 1;
            padding: 15px;
            font-size: 1.1em;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
            transition: all 0.3s;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .btn-start {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
        }

        .btn-start:hover {
            transform: scale(1.05);
            box-shadow: 0 5px 15px rgba(245, 87, 108, 0.4);
        }

        .btn-start:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .btn-back {
            background: rgba(255,255,255,0.2);
            color: white;
            backdrop-filter: blur(10px);
        }

        .btn-back:hover {
            background: rgba(255,255,255,0.3);
        }

        .info-box {
            background: rgba(255,255,255,0.1);
            padding: 15px;
            border-radius: 8px;
            color: white;
            text-align: center;
            margin-bottom: 15px;
            backdrop-filter: blur(10px);
        }

        .info-box p {
            margin: 5px 0;
            font-size: 0.95em;
        }

        @media (max-width: 600px) {
            .header h1 {
                font-size: 2em;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🍄 Mario Jump</h1>
            <p style="font-size: 0.95em; opacity: 0.9;">Protótipo em Desenvolvimento</p>
        </div>

        <div class="stats">
            <div class="stat-box">
                <h3>Score Atual</h3>
                <div class="value" id="currentScore">0</div>
            </div>
            <div class="stat-box">
                <h3>Seu Recorde</h3>
                <div class="value" id="recordScore"><?php echo $recorde; ?></div>
            </div>
            <div class="stat-box">
                <h3>Jogador</h3>
                <div class="value" style="font-size: 1.2em;"><?php echo htmlspecialchars(substr($meu_perfil['nome'], 0, 8)); ?></div>
            </div>
        </div>

        <div class="info-box">
            <p>⬅️ ➡️ Use as setas ou A/D para se mover</p>
            <p>Pule nos inimigos para ganhar pontos!</p>
        </div>

        <div class="game-canvas-container">
            <canvas id="gameCanvas" width="400" height="600"></canvas>
        </div>

        <div class="controls">
            <button class="btn-start" id="startBtn" onclick="startGame()">▶️ Iniciar Jogo</button>
            <button class="btn-back" onclick="window.location.href='index.php'">← Voltar</button>
        </div>
    </div>

    <script>
        const canvas = document.getElementById('gameCanvas');
        const ctx = canvas.getContext('2d');
        
        // Variáveis do jogo
        let gameRunning = false;
        let gameOver = false;
        let score = 0;
        let gameStarted = false;

        // Mario
        const mario = {
            x: canvas.width / 2,
            y: canvas.height - 80,
            width: 30,
            height: 40,
            velocityY: 0,
            velocityX: 0,
            jumping: false,
            speed: 5,
            jumpPower: 12
        };

        // Plataformas
        let platforms = [];
        let enemies = [];

        // Controles
        const keys = {
            left: false,
            right: false
        };

        // Event Listeners
        window.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowLeft' || e.key.toLowerCase() === 'a') keys.left = true;
            if (e.key === 'ArrowRight' || e.key.toLowerCase() === 'd') keys.right = true;
        });

        window.addEventListener('keyup', (e) => {
            if (e.key === 'ArrowLeft' || e.key.toLowerCase() === 'a') keys.left = false;
            if (e.key === 'ArrowRight' || e.key.toLowerCase() === 'd') keys.right = false;
        });

        // Inicializar plataformas
        function initPlatforms() {
            platforms = [];
            for (let i = 0; i < 6; i++) {
                platforms.push({
                    x: Math.random() * (canvas.width - 60),
                    y: i * 100,
                    width: 60,
                    height: 12,
                    color: '#ff6b9d'
                });
            }
            mario.y = canvas.height - 80;
        }

        // Inicializar inimigos
        function initEnemies() {
            enemies = [];
            for (let i = 0; i < 3; i++) {
                enemies.push({
                    x: Math.random() * (canvas.width - 30),
                    y: 150 + i * 100,
                    width: 30,
                    height: 30,
                    velocityX: (Math.random() - 0.5) * 4
                });
            }
        }

        function startGame() {
            if (!gameStarted) {
                gameStarted = true;
                gameRunning = true;
                gameOver = false;
                score = 0;
                mario.y = canvas.height - 80;
                mario.velocityY = 0;
                mario.velocityX = 0;
                initPlatforms();
                initEnemies();
                document.getElementById('startBtn').disabled = true;
                document.getElementById('startBtn').textContent = 'Jogo em andamento...';
                gameLoop();
            }
        }

        function gameLoop() {
            update();
            draw();

            if (gameRunning && !gameOver) {
                requestAnimationFrame(gameLoop);
            }
        }

        function update() {
            if (!gameRunning) return;

            // Movimento horizontal
            if (keys.left && mario.x > 0) {
                mario.x -= mario.speed;
            }
            if (keys.right && mario.x < canvas.width - mario.width) {
                mario.x += mario.speed;
            }

            // Gravidade
            mario.velocityY += 0.4;
            mario.y += mario.velocityY;

            // Colisão com plataformas
            platforms.forEach((platform) => {
                if (mario.velocityY > 0 &&
                    mario.y + mario.height >= platform.y &&
                    mario.y + mario.height <= platform.y + platform.height + 10 &&
                    mario.x + mario.width > platform.x &&
                    mario.x < platform.x + platform.width) {
                    mario.velocityY = -mario.jumpPower;
                    score++;
                    document.getElementById('currentScore').textContent = score;
                }
            });

            // Colisão com inimigos
            enemies.forEach((enemy) => {
                if (mario.velocityY > 0 &&
                    mario.y + mario.height >= enemy.y &&
                    mario.y + mario.height <= enemy.y + enemy.height + 10 &&
                    mario.x + mario.width > enemy.x &&
                    mario.x < enemy.x + enemy.width) {
                    mario.velocityY = -mario.jumpPower;
                    score += 5;
                    document.getElementById('currentScore').textContent = score;
                    enemy.x = Math.random() * (canvas.width - 30);
                    enemy.y = 100;
                }
            });

            // Movimento dos inimigos
            enemies.forEach((enemy) => {
                enemy.x += enemy.velocityX;
                if (enemy.x < 0 || enemy.x > canvas.width - 30) {
                    enemy.velocityX *= -1;
                }
            });

            // Game Over
            if (mario.y > canvas.height) {
                endGame();
            }

            // Mover câmera
            if (mario.y < canvas.height / 3) {
                let diff = canvas.height / 3 - mario.y;
                mario.y = canvas.height / 3;
                platforms.forEach(p => p.y += diff);
                enemies.forEach(e => e.y += diff);

                // Gerar novas plataformas
                platforms.forEach((platform) => {
                    if (platform.y > canvas.height) {
                        platform.y = -10;
                        platform.x = Math.random() * (canvas.width - 60);
                    }
                });

                enemies.forEach((enemy) => {
                    if (enemy.y > canvas.height) {
                        enemy.y = -30;
                        enemy.x = Math.random() * (canvas.width - 30);
                    }
                });
            }
        }

        function draw() {
            // Fundo
            ctx.fillStyle = '#87ceeb';
            ctx.fillRect(0, 0, canvas.width, canvas.height);

            // Nuvens
            ctx.fillStyle = 'rgba(255, 255, 255, 0.6)';
            ctx.beginPath();
            ctx.arc(80, 50, 30, 0, Math.PI * 2);
            ctx.arc(120, 40, 25, 0, Math.PI * 2);
            ctx.fill();

            // Plataformas
            platforms.forEach((platform) => {
                ctx.fillStyle = platform.color;
                ctx.fillRect(platform.x, platform.y, platform.width, platform.height);
                ctx.strokeStyle = '#ff3366';
                ctx.lineWidth = 2;
                ctx.strokeRect(platform.x, platform.y, platform.width, platform.height);
            });

            // Inimigos
            enemies.forEach((enemy) => {
                ctx.fillStyle = '#ff6b6b';
                ctx.beginPath();
                ctx.arc(enemy.x + 15, enemy.y + 15, 15, 0, Math.PI * 2);
                ctx.fill();
                ctx.fillStyle = '#fff';
                ctx.beginPath();
                ctx.arc(enemy.x + 10, enemy.y + 10, 5, 0, Math.PI * 2);
                ctx.fill();
                ctx.beginPath();
                ctx.arc(enemy.x + 20, enemy.y + 10, 5, 0, Math.PI * 2);
                ctx.fill();
            });

            // Mario
            ctx.fillStyle = '#ff3333';
            ctx.fillRect(mario.x, mario.y, mario.width, mario.height);
            ctx.fillStyle = '#ffd700';
            ctx.fillRect(mario.x + 5, mario.y + 5, mario.width - 10, 15);
            ctx.fillStyle = '#000';
            ctx.beginPath();
            ctx.arc(mario.x + 10, mario.y + 8, 3, 0, Math.PI * 2);
            ctx.fill();
            ctx.beginPath();
            ctx.arc(mario.x + 20, mario.y + 8, 3, 0, Math.PI * 2);
            ctx.fill();

            // Score
            ctx.fillStyle = '#000';
            ctx.font = 'bold 16px Arial';
            ctx.fillText('Score: ' + score, 10, 30);
        }

        function endGame() {
            gameRunning = false;
            gameOver = true;
            document.getElementById('startBtn').disabled = false;
            document.getElementById('startBtn').textContent = '🔄 Jogar Novamente';
            gameStarted = false;

            // Salvar score
            if (score > 0) {
                fetch('mario.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'acao=salvar_score&score=' + score
                });
            }

            alert('Game Over! Score: ' + score);
        }

        // Desenho inicial
        draw();
        document.getElementById('startBtn').focus();
    </script>
</body>
</html>
