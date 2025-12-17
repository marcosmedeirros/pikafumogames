<?php
/**
 * GAMES/APOSTAS.PHP - GERENCIADOR UNIFICADO DE APOSTAS
 * 
 * Funções:
 * - POST: Processar aposta (JSON ou form)
 * - GET: Listar apostas do usuário (com filtros)
 * - JSON: Retornar dados em formato JSON para AJAX
 */

session_start();
require '../core/conexao.php';
require '../core/funcoes.php';

// Segurança
if (!isset($_SESSION['user_id'])) {
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['json'])) {
        header('Content-Type: application/json');
        echo json_encode(['erro' => 'Você deve estar logado']);
    } else {
        header("Location: ../auth/login.php");
    }
    exit;
}

$user_id = $_SESSION['user_id'];

// --- PROCESSAR APOSTA (POST) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    header('Content-Type: application/json');
    
    try {
        $opcao_id = isset($_POST['opcao_id']) ? (int)$_POST['opcao_id'] : 0;
        $valor_aposta = isset($_POST['valor']) ? floatval($_POST['valor']) : 0;
        
        if ($opcao_id <= 0 || $valor_aposta <= 0) {
            throw new Exception("Dados inválidos fornecidos");
        }

        $pdo->beginTransaction();

        // 1. Verifica saldo (PONTOS)
        $stmtUser = $pdo->prepare("SELECT pontos FROM usuarios WHERE id = :id FOR UPDATE");
        $stmtUser->execute([':id' => $user_id]);
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if (!$user || $user['pontos'] < $valor_aposta) {
            throw new Exception("Saldo insuficiente! Seus pontos: " . ($user['pontos'] ?? 0));
        }

        // 2. Verifica se a aposta está aberta E PEGA A ODD ATUAL
        $stmtCheck = $pdo->prepare("
            SELECT e.id as evento_id, e.status, e.data_limite, o.odd, o.descricao
            FROM opcoes o 
            JOIN eventos e ON o.evento_id = e.id 
            WHERE o.id = :oid
        ");
        $stmtCheck->execute([':oid' => $opcao_id]);
        $dados_aposta = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$dados_aposta) {
            throw new Exception("Opção de aposta inválida.");
        }

        if ($dados_aposta['status'] != 'aberta' || strtotime($dados_aposta['data_limite']) < time()) {
            throw new Exception("Este evento já encerrou ou foi cancelado!");
        }

        // 3. Desconta os pontos
        $stmtDebit = $pdo->prepare("UPDATE usuarios SET pontos = pontos - :val WHERE id = :id");
        $stmtDebit->execute([':val' => $valor_aposta, ':id' => $user_id]);

        // 4. Registra o palpite COM A ODD CONGELADA
        $stmtInsert = $pdo->prepare("
            INSERT INTO palpites (id_usuario, opcao_id, valor, odd_registrada, data_palpite) 
            VALUES (:uid, :oid, :val, :odd_fixa, NOW())
        ");
        $stmtInsert->execute([
            ':uid' => $user_id, 
            ':oid' => $opcao_id, 
            ':val' => $valor_aposta,
            ':odd_fixa' => $dados_aposta['odd']
        ]);

        // 5. Recalcula as odds para o PRÓXIMO apostador
        recalcularOdds($pdo, $dados_aposta['evento_id']);

        $pdo->commit();

        // Fetch novo saldo
        $stmtNewBalance = $pdo->prepare("SELECT pontos FROM usuarios WHERE id = :id");
        $stmtNewBalance->execute([':id' => $user_id]);
        $novo_saldo = $stmtNewBalance->fetch(PDO::FETCH_COLUMN);

        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Aposta realizada com sucesso!',
            'novo_saldo' => $novo_saldo,
            'resultado' => $valor_aposta * $dados_aposta['odd']
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode([
            'erro' => $e->getMessage(),
            'sucesso' => false
        ]);
    }

    exit;
}

// --- LISTAR APOSTAS DO USUÁRIO (GET) ---
$filtro = isset($_GET['filtro']) ? $_GET['filtro'] : 'todas'; // todas, abertas, fechadas, ganhas, perdidas
$opcoes = [];

try {
    $sql = "
        SELECT 
            p.id,
            p.id_usuario,
            p.opcao_id,
            p.valor,
            p.odd_registrada,
            p.data_palpite,
            p.resultado,
            e.nome as evento_nome,
            e.status as evento_status,
            o.descricao as opcao_descricao,
            CASE 
                WHEN e.status = 'finalizada' AND p.resultado IS NOT NULL THEN 'finalizada'
                WHEN e.status = 'finalizada' AND p.resultado IS NULL THEN 'perdida'
                WHEN e.status = 'aberta' THEN 'aberta'
                ELSE 'cancelada'
            END as aposta_status
        FROM palpites p
        JOIN eventos e ON (SELECT evento_id FROM opcoes WHERE id = p.opcao_id) = e.id
        JOIN opcoes o ON p.opcao_id = o.id
        WHERE p.id_usuario = :uid
    ";

    // Aplica filtros
    if ($filtro == 'abertas') {
        $sql .= " AND e.status = 'aberta'";
    } elseif ($filtro == 'fechadas') {
        $sql .= " AND e.status = 'finalizada'";
    } elseif ($filtro == 'ganhas') {
        $sql .= " AND e.status = 'finalizada' AND p.resultado IS NOT NULL";
    } elseif ($filtro == 'perdidas') {
        $sql .= " AND e.status = 'finalizada' AND p.resultado IS NULL";
    }

    $sql .= " ORDER BY p.data_palpite DESC LIMIT 50";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid' => $user_id]);
    $apostas = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $apostas = [];
}

// Se for apenas dados (AJAX), retorna JSON
if (isset($_GET['json']) && $_GET['json'] == '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'apostas' => $apostas,
        'filtro' => $filtro,
        'total' => count($apostas)
    ]);
    exit;
}

// Caso contrário, exibe HTML
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Minhas Apostas</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>💰</text></svg>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background-color: #121212; color: #e0e0e0; }
        .navbar-custom { background: linear-gradient(180deg, #1e1e1e 0%, #121212 100%); border-bottom: 1px solid #333; }
        .badge-aberta { background-color: #ffd600; color: #000; }
        .badge-ganhou { background-color: #4caf50; }
        .badge-perdeu { background-color: #f44336; }
    </style>
</head>
<body>
<nav class="navbar navbar-dark navbar-custom sticky-top">
    <div class="container-fluid">
        <span class="navbar-brand">💰 Minhas Apostas</span>
        <a href="../index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Voltar</a>
    </div>
</nav>

<div class="container mt-4">
    <div class="btn-group mb-3" role="group">
        <a href="?filtro=todas" class="btn btn-outline-primary <?= $filtro == 'todas' ? 'active' : '' ?>">Todas</a>
        <a href="?filtro=abertas" class="btn btn-outline-warning <?= $filtro == 'abertas' ? 'active' : '' ?>">Abertas</a>
        <a href="?filtro=ganhas" class="btn btn-outline-success <?= $filtro == 'ganhas' ? 'active' : '' ?>">Ganhas</a>
        <a href="?filtro=perdidas" class="btn btn-outline-danger <?= $filtro == 'perdidas' ? 'active' : '' ?>">Perdidas</a>
    </div>

    <div class="table-responsive">
        <table class="table table-dark table-hover">
            <thead>
                <tr>
                    <th>Evento</th>
                    <th>Opção</th>
                    <th>Valor</th>
                    <th>Odd</th>
                    <th>Possível Ganho</th>
                    <th>Status</th>
                    <th>Data</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($apostas)): ?>
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">
                        <i class="bi bi-inbox" style="font-size: 2rem; opacity: 0.3;"></i><br>
                        Nenhuma aposta encontrada
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($apostas as $aposta): ?>
                    <tr>
                        <td><?= htmlspecialchars($aposta['evento_nome']) ?></td>
                        <td><?= htmlspecialchars($aposta['opcao_descricao']) ?></td>
                        <td>$<?= number_format($aposta['valor'], 2, ',', '.') ?></td>
                        <td><?= number_format($aposta['odd_registrada'], 2) ?></td>
                        <td><strong>$<?= number_format($aposta['valor'] * $aposta['odd_registrada'], 2, ',', '.') ?></strong></td>
                        <td>
                            <?php if ($aposta['aposta_status'] == 'aberta'): ?>
                                <span class="badge badge-aberta">Aberta</span>
                            <?php elseif ($aposta['aposta_status'] == 'ganhou'): ?>
                                <span class="badge badge-ganhou">Ganhou ✓</span>
                            <?php else: ?>
                                <span class="badge badge-perdeu">Perdeu ✗</span>
                            <?php endif; ?>
                        </td>
                        <td><small><?= date('d/m/Y H:i', strtotime($aposta['data_palpite'])) ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>
