<?php
// crypto.php - CRYPTO CRASH (O Foguetinho)
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

    // Criar tabelas se não existirem
    $pdo->exec("CREATE TABLE IF NOT EXISTS crypto_historico (id INT AUTO_INCREMENT PRIMARY KEY, id_usuario INT NOT NULL, aposta INT NOT NULL, ganho INT NOT NULL, multiplicador DECIMAL(5,2), resultado VARCHAR(20), data_jogo DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS crypto_sessoes (id INT AUTO_INCREMENT PRIMARY KEY, id_usuario INT NOT NULL, crash_point DECIMAL(5,2), hash VARCHAR(64), data_criacao DATETIME DEFAULT CURRENT_TIMESTAMP)");

    // Pegar estatísticas
    $stmtStats = $pdo->prepare("SELECT COUNT(*) as total_rodadas, SUM(ganho) as ganho_total, AVG(multiplicador) as multiplicador_medio FROM crypto_historico WHERE id_usuario = :id");
    $stmtStats->execute([':id' => $user_id]);
    $stats = $stmtStats->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro DB: " . $e->getMessage());
}

// AJAX - Gerar nova rodada
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao'])) {
    header('Content-Type: application/json');

    if ($_POST['acao'] == 'nova_rodada') {
        $aposta = intval($_POST['aposta']);
        
        // Validar aposta
        if ($aposta < 1 || $aposta > $meu_perfil['pontos']) {
            echo json_encode(['status' => 'erro', 'msg' => 'Aposta inválida']);
            exit;
        }

        // Gerar crash point (0.5x a 5.0x com distribuição exponencial)
        $crash_point = round(pow(2, rand(1, 25) / 10), 2);
        
        // Gerar hash para verificação (segurança)
        $hash = hash('sha256', $crash_point . $user_id . time());
        
        try {
            // Limpar rodadas antigas desta sessão
            $pdo->prepare("DELETE FROM crypto_sessoes WHERE id_usuario = :id AND DATE_ADD(data_criacao, INTERVAL 1 HOUR) < NOW()")->execute([':id' => $user_id]);
            
            // Criar nova sessão
            $stmt = $pdo->prepare("INSERT INTO crypto_sessoes (id_usuario, crash_point, hash) VALUES (:id, :crash, :hash)");
            $stmt->execute([':id' => $user_id, ':crash' => $crash_point, ':hash' => $hash]);
            $sessao_id = $pdo->lastInsertId();

            echo json_encode([
                'status' => 'ok',
                'sessao_id' => $sessao_id,
                'hash' => $hash,
                'crash_point' => $crash_point,
                'aposta' => $aposta
            ]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'erro', 'msg' => 'Erro ao criar rodada']);
        }
        exit;
    }

    // AJAX - Cash Out
    if ($_POST['acao'] == 'cash_out') {
        $sessao_id = intval($_POST['sessao_id']);
        $multiplicador = round(floatval($_POST['multiplicador']), 2);
        $aposta = intval($_POST['aposta']);

        try {
            // Validar sessão
            $stmt = $pdo->prepare("SELECT crash_point FROM crypto_sessoes WHERE id = :id AND id_usuario = :user");
            $stmt->execute([':id' => $sessao_id, ':user' => $user_id]);
            $sessao = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$sessao) {
                echo json_encode(['status' => 'erro', 'msg' => 'Sessão inválida']);
                exit;
            }

            // Verificar se não passou do crash point
            if ($multiplicador >= $sessao['crash_point']) {
                echo json_encode(['status' => 'perdeu', 'msg' => 'Crashed!']);
                
                // Registrar perda
                $stmtPerdeu = $pdo->prepare("INSERT INTO crypto_historico (id_usuario, aposta, ganho, multiplicador, resultado) VALUES (:id, :aposta, :ganho, :mult, 'CRASH')");
                $stmtPerdeu->execute([':id' => $user_id, ':aposta' => $aposta, ':ganho' => 0, ':mult' => $multiplicador]);
                
                exit;
            }

            // Calcular ganho
            $ganho = intval($aposta * $multiplicador);
            
            // Atualizar pontos do usuário
            $stmtUpdate = $pdo->prepare("UPDATE usuarios SET pontos = pontos - :aposta + :ganho WHERE id = :id");
            $stmtUpdate->execute([':aposta' => $aposta, ':ganho' => $ganho, ':id' => $user_id]);

            // Registrar vitória
            $stmtGanhou = $pdo->prepare("INSERT INTO crypto_historico (id_usuario, aposta, ganho, multiplicador, resultado) VALUES (:id, :aposta, :ganho, :mult, 'WIN')");
            $stmtGanhou->execute([':id' => $user_id, ':aposta' => $aposta, ':ganho' => $ganho, ':mult' => $multiplicador]);

            // Buscar novos pontos
            $stmtPontos = $pdo->prepare("SELECT pontos FROM usuarios WHERE id = :id");
            $stmtPontos->execute([':id' => $user_id]);
            $novo_saldo = $stmtPontos->fetchColumn();

            echo json_encode([
                'status' => 'ok',
                'ganho' => $ganho,
                'lucro' => $ganho - $aposta,
                'novo_saldo' => $novo_saldo
            ]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'erro', 'msg' => 'Erro ao processar']);
        }
        exit;
    }

    // AJAX - Obter histórico de crashes
    if ($_POST['acao'] == 'historico_crashes') {
        try {
            $stmt = $pdo->prepare("
                SELECT DISTINCT multiplicador 
                FROM crypto_historico 
                WHERE id_usuario = :id 
                ORDER BY data_jogo DESC 
                LIMIT 10
            ");
            $stmt->execute([':id' => $user_id]);
            $crashes = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // Formatar para mostrar apenas o número com uma casa decimal
            $crashesFormatados = array_map(function($crash) {
                return number_format($crash, 1, '.', '');
            }, $crashes);

            echo json_encode(['crashes' => $crashesFormatados]);
        } catch (PDOException $e) {
            echo json_encode(['crashes' => []]);
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
    <title>🚀 Crypto Crash - Pikafumo Games</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Courier New', monospace;
            background: linear-gradient(135deg, #0a1e3b 0%, #1a3a6b 50%, #0d2e5f 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            color: #fff;
        }

        .container {
            width: 100%;
            max-width: 900px;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        .header h1 {
            font-size: 2.8em;
            margin-bottom: 10px;
            color: #0099ff;
            text-shadow: 0 0 20px rgba(0, 153, 255, 0.6);
            animation: glow 2s ease-in-out infinite;
        }

        @keyframes glow {
            0%, 100% { text-shadow: 0 0 20px rgba(0, 153, 255, 0.6); }
            50% { text-shadow: 0 0 30px rgba(0, 153, 255, 1); }
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: linear-gradient(135deg, rgba(0, 153, 255, 0.1) 0%, rgba(255, 165, 0, 0.08) 100%);
            border: 2px solid #0099ff;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 0 20px rgba(0, 153, 255, 0.2), inset 0 0 20px rgba(0, 153, 255, 0.05);
            transition: all 0.3s;
        }

        .stat-card:hover {
            box-shadow: 0 0 30px rgba(0, 153, 255, 0.4), inset 0 0 20px rgba(0, 153, 255, 0.1);
            transform: translateY(-2px);
        }

        .stat-label {
            font-size: 0.9em;
            color: #ffa500;
            opacity: 0.8;
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 1.8em;
            font-weight: bold;
            color: #0099ff;
        }

        .game-area {
            background: linear-gradient(135deg, rgba(0, 153, 255, 0.08) 0%, rgba(255, 165, 0, 0.06) 100%);
            border: 2px solid #0099ff;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 0 30px rgba(0, 153, 255, 0.2);
        }

        .canvas-container {
            background: linear-gradient(135deg, #0a1e3b 0%, #1a4d7a 100%);
            border: 2px solid #0099ff;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 20px;
            position: relative;
            box-shadow: inset 0 0 20px rgba(0, 153, 255, 0.1);
        }

        canvas {
            display: block;
            width: 100%;
            height: auto;
        }

        .info-bar {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
            padding: 15px;
            background: rgba(0, 153, 255, 0.08);
            border: 2px solid #0099ff;
            border-radius: 8px;
        }

        .info-item {
            text-align: center;
        }

        .info-label {
            font-size: 0.85em;
            color: #ffa500;
            opacity: 0.8;
            margin-bottom: 5px;
        }

        .info-value {
            font-size: 1.6em;
            font-weight: bold;
            color: #0099ff;
        }

        .multiplier-display {
            font-size: 2em;
            font-weight: bold;
            color: #0099ff;
            text-align: center;
            margin: 10px 0;
            min-height: 40px;
        }

        .control-area {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
            align-items: flex-end;
        }

        .control-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
            min-width: 200px;
        }

        label {
            color: #0099ff;
            font-size: 0.95em;
            font-weight: bold;
        }

        input[type="number"],
        input[type="text"] {
            padding: 12px;
            background: rgba(0, 153, 255, 0.1);
            border: 2px solid #0099ff;
            border-radius: 8px;
            color: #0099ff;
            font-family: 'Courier New', monospace;
            font-size: 1.1em;
        }

        input[type="number"]:focus,
        input[type="text"]:focus {
            outline: none;
            border-color: #0099ff;
            box-shadow: 0 0 10px rgba(0, 153, 255, 0.5);
        }

        button {
            padding: 15px;
            font-size: 1.1em;
            border: none;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s;
            font-family: 'Courier New', monospace;
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        .btn-play {
            background: linear-gradient(135deg, #0099ff 0%, #0077cc 100%);
            color: #fff;
            flex: 0 0 auto;
            padding: 15px 30px;
        }

        .btn-play:hover:not(:disabled) {
            transform: scale(1.05);
            box-shadow: 0 0 20px rgba(0, 153, 255, 0.6);
        }

        .btn-play:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .btn-cashout {
            background: linear-gradient(135deg, #0099ff 0%, #0077cc 100%);
            color: #fff;
            font-size: 1.1em;
            display: none;
            flex: 0 0 auto;
            padding: 15px 30px;
        }

        .btn-cashout.show {
            display: block;
        }

        .btn-cashout:hover:not(:disabled) {
            transform: scale(1.05);
            box-shadow: 0 0 20px rgba(0, 153, 255, 0.6);
        }

        .btn-back {
            background: rgba(0, 153, 255, 0.15);
            color: #0099ff;
            border: 2px solid #0099ff;
            width: 100%;
            margin-top: 10px;
        }

        .btn-back:hover {
            background: rgba(0, 153, 255, 0.25);
        }

        .message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: bold;
            display: none;
        }

        .message.show {
            display: block;
        }

        .message.error {
            background: rgba(255, 0, 0, 0.2);
            color: #ff6b6b;
            border: 2px solid #ff6b6b;
        }

        .message.success {
            background: rgba(0, 153, 255, 0.2);
            color: #0099ff;
            border: 2px solid #0099ff;
        }

        .message.crash {
            background: rgba(255, 107, 0, 0.2);
            color: #ff8800;
            border: 2px solid #ff8800;
        }

        .history-title {
            color: #0099ff;
            font-size: 1.2em;
            margin-top: 30px;
            margin-bottom: 15px;
            text-align: center;
        }

        .crash-history-section {
            margin: 30px 0;
        }

        .crash-history {
            background: linear-gradient(135deg, rgba(0, 153, 255, 0.1) 0%, rgba(255, 165, 0, 0.08) 100%);
            border: 2px solid #0099ff;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
        }

        .crash-history-items {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            justify-content: center;
            align-items: center;
        }

        .crash-item {
            background: linear-gradient(135deg, rgba(255, 165, 0, 0.2) 0%, rgba(0, 153, 255, 0.15) 100%);
            border: 2px solid #ffa500;
            border-radius: 8px;
            padding: 12px 20px;
            color: #0099ff;
            font-weight: bold;
            font-family: 'Courier New', monospace;
            font-size: 1.1em;
            transition: all 0.3s;
            box-shadow: 0 0 15px rgba(255, 165, 0, 0.2);
        }

        .crash-item:hover {
            transform: scale(1.08);
            box-shadow: 0 0 25px rgba(255, 165, 0, 0.4);
            border-color: #0099ff;
        }

        .history-title {
            color: #0099ff;
            font-size: 1.2em;
            margin-top: 30px;
            margin-bottom: 15px;
            text-align: center;
        }

        .history-table {
            width: 100%;
            background: linear-gradient(135deg, rgba(0, 153, 255, 0.1) 0%, rgba(255, 165, 0, 0.08) 100%);
            border: 2px solid #0099ff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 0 20px rgba(0, 153, 255, 0.15);
        }

        .history-table table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9em;
        }

        .history-table th {
            background: linear-gradient(135deg, rgba(0, 153, 255, 0.2) 0%, rgba(255, 165, 0, 0.15) 100%);
            color: #0099ff;
            padding: 12px;
            text-align: left;
            border-bottom: 2px solid #0099ff;
            font-weight: bold;
        }

        .history-table td {
            padding: 10px 12px;
            border-bottom: 1px solid rgba(0, 153, 255, 0.2);
            color: #ffa500;
        }

        .history-table tr:hover {
            background: rgba(0, 153, 255, 0.15);
        }

        .win { 
            color: #4ade80;
            font-weight: bold;
        }
        .loss { 
            color: #ff6b6b;
            font-weight: bold;
        }

        @media (max-width: 768px) {
            .control-area {
                grid-template-columns: 1fr;
            }
            .btn-cashout {
                grid-column: 1 / -1 !important;
            }
            .header h1 {
                font-size: 2em;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚀 CRYPTO CRASH</h1>
            <p style="font-size: 1.2em; color: #00aa00;">O Foguetinho que Explode</p>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">💰 Saldo Atual</div>
                <div class="stat-value" id="saldoAtual"><?php echo $meu_perfil['pontos']; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">📊 Rodadas</div>
                <div class="stat-value"><?php echo $stats['total_rodadas'] ?? 0; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">💸 Ganho Total</div>
                <div class="stat-value" style="color: #0099ff;"><?php echo $stats['ganho_total'] ?? 0; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">📈 Multiplicador Médio</div>
                <div class="stat-value"><?php echo number_format($stats['multiplicador_medio'] ?? 1.0, 2); ?>x</div>
            </div>
        </div>

        <div class="game-area">
            <div class="canvas-container">
                <canvas id="gameCanvas" width="800" height="400"></canvas>
            </div>

            <div id="mensagem" class="message"></div>

            <div class="info-bar">
                <div class="info-item">
                    <div class="info-label">🎯 Aposta</div>
                    <div class="info-value" id="apostaDisplay">-</div>
                </div>
                <div class="info-item">
                    <div class="info-label">📈 Multiplicador</div>
                    <div class="multiplier-display" id="multiplicadorDisplay">--</div>
                </div>
                <div class="info-item">
                    <div class="info-label">💰 Ganho Potencial</div>
                    <div class="info-value" id="ganhoDisplay">-</div>
                </div>
            </div>

            <div class="control-area">
                <div class="control-group">
                    <label for="apostaInput">Valor da Aposta (1 - <?php echo $meu_perfil['pontos']; ?>)</label>
                    <input type="number" id="apostaInput" min="1" max="<?php echo $meu_perfil['pontos']; ?>" value="10" placeholder="Digite o valor">
                </div>
                <button class="btn-play" id="btnPlay" onclick="iniciarRodada()">▶️ JOGAR</button>
                <button class="btn-cashout" id="btnCashout" onclick="cashOut()">💰 SAIR COM LUCRO!</button>
            </div>

            <button class="btn-back" onclick="window.location.href='index.php'">← Voltar ao Menu</button>
        </div>

        <div class="crash-history-section">
            <div class="history-title">🎲 Últimos Crashes</div>
            <div class="crash-history" id="crashHistory">
                <div style="text-align: center; color: #ffa500; padding: 15px;">Carregando histórico...</div>
            </div>
        </div>

        <div class="history-title">📜 Últimas Rodadas</div>
        <div class="history-table" id="historico">
            <table>
                <thead>
                    <tr>
                        <th>Hora</th>
                        <th>Aposta</th>
                        <th>Multiplicador</th>
                        <th>Ganho</th>
                        <th>Resultado</th>
                    </tr>
                </thead>
                <tbody id="historicoBody">
                    <tr><td colspan="5" style="text-align: center; color: #666;">Nenhuma rodada ainda...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        const canvas = document.getElementById('gameCanvas');
        const ctx = canvas.getContext('2d');

        // Estado do jogo
        let gameState = 'idle'; // idle, playing, crashed, win
        let currentRound = null;
        let currentMultiplier = 1.0;
        let animationFrame = 0;
        let userSaldo = <?php echo $meu_perfil['pontos']; ?>;

        // Configuração
        const gameWidth = canvas.width;
        const gameHeight = canvas.height;
        const padding = 50;

        function desenharTela() {
            // Fundo
            ctx.fillStyle = '#0a0e27';
            ctx.fillRect(0, 0, gameWidth, gameHeight);

            // Grid
            ctx.strokeStyle = 'rgba(0, 153, 255, 0.1)';
            ctx.lineWidth = 1;
            for (let i = 0; i < gameWidth; i += 50) {
                ctx.beginPath();
                ctx.moveTo(i, 0);
                ctx.lineTo(i, gameHeight);
                ctx.stroke();
            }
            for (let i = 0; i < gameHeight; i += 50) {
                ctx.beginPath();
                ctx.moveTo(0, i);
                ctx.lineTo(gameWidth, i);
                ctx.stroke();
            }

            // Eixos
            ctx.strokeStyle = '#0099ff';
            ctx.lineWidth = 2;
            ctx.beginPath();
            ctx.moveTo(padding, gameHeight - padding);
            ctx.lineTo(gameWidth - padding, gameHeight - padding);
            ctx.stroke();

            ctx.beginPath();
            ctx.moveTo(padding, padding);
            ctx.lineTo(padding, gameHeight - padding);
            ctx.stroke();

            // Labels
            ctx.fillStyle = '#0099ff';
            ctx.font = '12px Courier New';
            ctx.fillText('TEMPO', gameWidth - 100, gameHeight - 20);
            ctx.fillText('MULTIPLICADOR', 10, 20);

            if (gameState === 'idle') {
                ctx.fillStyle = 'rgba(0, 153, 255, 0.6)';
                ctx.font = 'bold 24px Courier New';
                ctx.textAlign = 'center';
                ctx.fillText('Clique em JOGAR para começar', gameWidth / 2, gameHeight / 2);
                ctx.textAlign = 'left';
            }
        }

        function desenharGrafico() {
            if (!currentRound) return;

            const crashPoint = currentRound.crash_point;
            const maxX = gameWidth - padding;
            const maxY = gameHeight - padding;
            const startX = padding;
            const startY = maxY;

            // Desenhar linha de crash com gradiente
            let gradientLine = ctx.createLinearGradient(startX, startY, maxX, padding);
            gradientLine.addColorStop(0, 'rgba(255, 0, 0, 0.1)');
            gradientLine.addColorStop(1, 'rgba(255, 0, 0, 0.5)');
            ctx.strokeStyle = 'rgba(255, 0, 0, 0.4)';
            ctx.lineWidth = 2;
            ctx.setLineDash([5, 5]);
            ctx.beginPath();
            const crashY = maxY - (crashPoint / 5) * (maxY - padding);
            ctx.moveTo(startX, crashY);
            ctx.lineTo(maxX, crashY);
            ctx.stroke();
            ctx.setLineDash([]);

            // Desenhar texto de crash com sombra
            ctx.fillStyle = 'rgba(255, 0, 0, 0.3)';
            ctx.font = 'bold 14px Courier New';
            ctx.fillText('💥 CRASH: ' + crashPoint.toFixed(2) + 'x', padding + 15, crashY - 15);

            // Desenhar curva do jogo com efeito de brilho
            ctx.shadowColor = gameState === 'crashed' ? 'rgba(0, 153, 255, 0.8)' : 'rgba(0, 153, 255, 0.6)';
            ctx.shadowBlur = 10;
            ctx.shadowOffsetX = 0;
            ctx.shadowOffsetY = 0;

            ctx.strokeStyle = gameState === 'crashed' ? '#ff6b6b' : '#0099ff';
            ctx.lineWidth = 4;
            ctx.beginPath();

            const steps = Math.min(animationFrame, 150);
            for (let i = 0; i <= steps; i++) {
                const t = i / 150;
                const x = startX + (maxX - startX) * t;
                // Crescimento mais suave e realista
                const progress = Math.pow(t, 1.2);
                const y = startY - (currentMultiplier - 1) * (maxY - padding) * progress * 0.9;
                if (i === 0) ctx.moveTo(x, y);
                else ctx.lineTo(x, y);
            }
            ctx.stroke();
            ctx.shadowColor = 'transparent';

            // Desenhar rocket com efeito e chama
            const t = Math.min(animationFrame / 150, 1);
            const rocketX = padding + (maxX - padding) * t;
            const progress = Math.pow(t, 1.2);
            const rocketY = maxY - (currentMultiplier - 1) * (maxY - padding) * progress * 0.9;

            // Chama do foguete
            if (gameState === 'playing') {
                ctx.fillStyle = `rgba(255, ${Math.random() * 100 + 100}, 0, 0.6)`;
                ctx.beginPath();
                ctx.moveTo(rocketX, rocketY + 15);
                ctx.lineTo(rocketX - 5 + Math.random() * 10, rocketY + 30 + Math.random() * 10);
                ctx.lineTo(rocketX + 5 + Math.random() * 10, rocketY + 30 + Math.random() * 10);
                ctx.fill();
            }

            // Foguete principal
            ctx.font = 'bold 35px Arial';
            ctx.textAlign = 'center';
            ctx.shadowColor = 'rgba(255, 136, 0, 0.8)';
            ctx.shadowBlur = 15;
            ctx.fillText('🚀', rocketX, rocketY);
            ctx.shadowColor = 'transparent';
            ctx.textAlign = 'left';

            // Desenhar status com maior destaque
            ctx.fillStyle = gameState === 'crashed' ? '#ff6b6b' : '#0099ff';
            ctx.font = 'bold 18px Courier New';
            ctx.fillText('📈 Multiplicador: ' + currentMultiplier.toFixed(2) + 'x', padding, 40);

            // Crash visual
            if (gameState === 'crashed') {
                ctx.fillStyle = 'rgba(0, 153, 255, 0.95)';
                ctx.font = 'bold 40px Courier New';
                ctx.textAlign = 'center';
                ctx.shadowColor = 'rgba(0, 153, 255, 0.9)';
                ctx.shadowBlur = 20;
                ctx.fillText('💥 CRASHED! 💥', gameWidth / 2, gameHeight / 2);
                ctx.shadowColor = 'transparent';
                ctx.textAlign = 'left';
            }
        }

        function animacaoJogo() {
            desenharTela();

            if (gameState !== 'idle') {
                desenharGrafico();

                if (gameState === 'playing') {
                    animationFrame += 0.25;  // Mais lento (era 0.5)
                    // Crescimento mais suave e equilibrado
                    const t = Math.min(animationFrame / 150, 1);
                    currentMultiplier = 1.0 + Math.pow(t, 1.1) * 4; // Vai até ~5x, mas mais lentamente

                    // Atualizar display
                    document.getElementById('multiplicadorDisplay').textContent = currentMultiplier.toFixed(2) + 'x';
                    document.getElementById('ganhoDisplay').textContent = Math.floor(currentRound.aposta * currentMultiplier);

                    // Verificar crash
                    if (currentMultiplier >= currentRound.crash_point) {
                        gameState = 'crashed';
                        crasharJogo();
                    } else {
                        requestAnimationFrame(animacaoJogo);
                    }
                } else if (gameState === 'crashed') {
                    setTimeout(() => {
                        resetarJogo();
                        desenharTela();
                    }, 2000);
                } else {
                    requestAnimationFrame(animacaoJogo);
                }
            } else {
                requestAnimationFrame(animacaoJogo);
            }
        }

        function iniciarRodada() {
            const aposta = parseInt(document.getElementById('apostaInput').value);

            if (isNaN(aposta) || aposta < 1) {
                mostrarMensagem('Aposta inválida!', 'error');
                return;
            }

            if (aposta > userSaldo) {
                mostrarMensagem('Saldo insuficiente!', 'error');
                return;
            }

            document.getElementById('btnPlay').disabled = true;

            // Chamar servidor para gerar crash point
            fetch('crypto.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'acao=nova_rodada&aposta=' + aposta
            })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'ok') {
                    currentRound = data;
                    gameState = 'playing';
                    animationFrame = 0;
                    currentMultiplier = 1.0;

                    document.getElementById('apostaDisplay').textContent = aposta;
                    document.getElementById('multiplicadorDisplay').textContent = '1.00x';
                    document.getElementById('ganhoDisplay').textContent = aposta;
                    document.getElementById('btnCashout').classList.add('show');

                    animacaoJogo();
                } else {
                    mostrarMensagem(data.msg || 'Erro ao iniciar rodada', 'error');
                    document.getElementById('btnPlay').disabled = false;
                }
            });
        }

        function cashOut() {
            if (!currentRound || gameState !== 'playing') return;

            document.getElementById('btnCashout').disabled = true;
            gameState = 'win';

            fetch('crypto.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `acao=cash_out&sessao_id=${currentRound.sessao_id}&multiplicador=${currentMultiplier}&aposta=${currentRound.aposta}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'ok') {
                    const lucro = data.lucro;
                    const msg = lucro > 0 
                        ? `✅ LUCRO! +${lucro}! (Ganho: ${data.ganho})` 
                        : `✅ Multiplicador: ${currentMultiplier.toFixed(2)}x | Ganho: ${data.ganho}`;
                    
                    mostrarMensagem(msg, 'success');
                    userSaldo = data.novo_saldo;
                    document.getElementById('saldoAtual').textContent = userSaldo;
                } else {
                    mostrarMensagem(data.msg || 'Erro', 'crash');
                    userSaldo -= currentRound.aposta;
                    document.getElementById('saldoAtual').textContent = userSaldo;
                }

                setTimeout(resetarJogo, 3000);
            });
        }

        function crasharJogo() {
            mostrarMensagem('💥 VOCÊ PERDEU A APOSTA! 💥', 'crash');
            userSaldo -= currentRound.aposta;
            document.getElementById('saldoAtual').textContent = userSaldo;

            // Registrar no BD
            fetch('crypto.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `acao=cash_out&sessao_id=${currentRound.sessao_id}&multiplicador=${currentMultiplier}&aposta=${currentRound.aposta}`
            });
        }

        function resetarJogo() {
            gameState = 'idle';
            currentRound = null;
            animationFrame = 0;
            currentMultiplier = 1.0;

            document.getElementById('btnPlay').disabled = false;
            document.getElementById('btnCashout').classList.remove('show');
            document.getElementById('btnCashout').disabled = false;
            document.getElementById('multiplicadorDisplay').textContent = '--';
            document.getElementById('ganhoDisplay').textContent = '-';
            document.getElementById('apostaDisplay').textContent = '-';

            desenharTela();
        }

        function mostrarMensagem(texto, tipo) {
            const el = document.getElementById('mensagem');
            el.textContent = texto;
            el.className = 'message show ' + tipo;

            setTimeout(() => {
                el.classList.remove('show');
            }, 5000);
        }

        // Iniciar animação
        desenharTela();
        animacaoJogo();

        // Carregar histórico de crashes
        function carregarHistoricoCrashes() {
            fetch('crypto.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'acao=historico_crashes'
            })
            .then(r => r.json())
            .then(data => {
                const container = document.getElementById('crashHistory');
                
                if (data.crashes && data.crashes.length > 0) {
                    const items = data.crashes.map(crash => 
                        `<div class="crash-item">${crash}x</div>`
                    ).join('');
                    
                    container.innerHTML = `<div class="crash-history-items">${items}</div>`;
                } else {
                    container.innerHTML = '<div style="text-align: center; color: #ffa500; padding: 15px;">Nenhum crash registrado ainda</div>';
                }
            })
            .catch(e => {
                console.error('Erro ao carregar histórico:', e);
                document.getElementById('crashHistory').innerHTML = '<div style="text-align: center; color: #ff6b6b; padding: 15px;">Erro ao carregar histórico</div>';
            });
        }

        // Carregar histórico ao iniciar a página
        carregarHistoricoCrashes();

        // Atualizar histórico a cada 5 segundos
        setInterval(carregarHistoricoCrashes, 5000);
    </script>
</body>
</html>
