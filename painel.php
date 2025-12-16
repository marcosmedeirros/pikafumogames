<?php
// painel.php - DASHBOARD COMPLETO (CARDS + APOSTAS) 🚀
session_start();
require 'conexao.php'; 

// 1. Segurança
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$msg = isset($_GET['msg']) ? htmlspecialchars($_GET['msg']) : "";
$erro = isset($_GET['erro']) ? htmlspecialchars($_GET['erro']) : "";

// 2. Dados do Usuário
try {
    $stmt = $pdo->prepare("SELECT nome, pontos, is_admin FROM usuarios WHERE id = :id");
    $stmt->execute([':id' => $user_id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro ao carregar usuário: " . $e->getMessage());
}

// 3. Buscar Eventos (Apenas Abertas)
$stmtEventos = $pdo->query("SELECT * FROM eventos WHERE status = 'aberta' ORDER BY data_limite ASC");
$eventos = $stmtEventos->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel de Apostas</title>
    
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🎮</text></svg>">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <style>
        /* --- ESTILO DARK PREMIUM --- */
        body { 
            background-color: #121212; /* Fundo quase preto */
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            color: #e0e0e0;
        }

        /* Navbar com degradê sutil */
        .navbar-custom { 
            background: linear-gradient(180deg, #1e1e1e 0%, #121212 100%);
            border-bottom: 1px solid #333;
            padding: 15px; 
        }

        .saldo-badge { 
            background-color: #00e676; /* Verde Neon */
            color: #000;
            padding: 8px 15px; 
            border-radius: 20px; 
            font-weight: 800; 
            font-size: 1.1em;
            box-shadow: 0 0 10px rgba(0, 230, 118, 0.3); /* Glow */
        }

        .admin-btn { 
            background-color: #ff6d00; 
            color: white; 
            padding: 5px 15px; 
            border-radius: 20px; 
            text-decoration: none; 
            font-weight: bold; 
            font-size: 0.9em; 
            transition: 0.3s; 
        }
        .admin-btn:hover { background-color: #e65100; color: white; box-shadow: 0 0 8px #ff6d00; }
        
        /* --- CARDS DE JOGOS (MENU) --- */
        .card-menu {
            border: 1px solid #333;
            background-color: #1e1e1e;
            border-radius: 15px;
            overflow: hidden;
            transition: all 0.3s ease;
            position: relative;
        }
        
        /* Efeito de Hover com Glow */
        .card-menu:hover {
            transform: translateY(-5px);
            border-color: var(--hover-color);
            box-shadow: 0 5px 20px rgba(0,0,0,0.5), 0 0 15px var(--glow-color);
        }

        .icon-menu { font-size: 2.5rem; margin-bottom: 10px; }
        
        /* Variáveis de cor para hover */
        .hover-warning { --hover-color: #ffc107; --glow-color: rgba(255, 193, 7, 0.2); }
        .hover-coffee  { --hover-color: #d7ccc8; --glow-color: rgba(141, 110, 99, 0.2); }
        .hover-success { --hover-color: #198754; --glow-color: rgba(25, 135, 84, 0.2); }
        .hover-info    { --hover-color: #0dcaf0; --glow-color: rgba(13, 202, 240, 0.2); }
        .hover-dark    { --hover-color: #6c757d; --glow-color: rgba(108, 117, 125, 0.2); }
        .hover-primary { --hover-color: #0d6efd; --glow-color: rgba(13, 110, 253, 0.2); }
        
        /* NOVAS CORES ADICIONADAS */
        .hover-danger  { --hover-color: #dc3545; --glow-color: rgba(220, 53, 69, 0.2); }
        .hover-purple  { --hover-color: #d63384; --glow-color: rgba(214, 51, 132, 0.2); }
        .hover-flappy  { --hover-color: #ff9800; --glow-color: rgba(255, 152, 0, 0.2); } /* Laranja Bird */

        /* --- CARDS DE APOSTAS --- */
        .card-evento { 
            border: 1px solid #333; 
            background-color: #1e1e1e; 
            border-radius: 12px; 
            margin-bottom: 25px; 
            overflow: hidden; 
        }
        .card-header-evento { 
            background-color: #252525; 
            padding: 15px; 
            border-bottom: 1px solid #333; 
            text-align: center; 
        }
        .evento-titulo { font-size: 1.3em; font-weight: 700; color: #fff; margin: 0; }
        .evento-data { font-size: 0.85em; color: #aaa; display: block; margin-top: 5px; }
        
        .opcoes-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); 
            gap: 15px; 
            padding: 20px; 
            background-color: #1e1e1e; 
        }
        .card-opcao { 
            border: 1px solid #444; 
            border-radius: 8px; 
            padding: 15px; 
            text-align: center; 
            transition: transform 0.2s, border-color 0.2s; 
            background: #2b2b2b; 
        }
        .card-opcao:hover { 
            transform: translateY(-3px); 
            border-color: #00e676; 
            background: #333;
        }
        .opcao-nome { font-weight: 600; color: #eee; display: block; margin-bottom: 5px; }
        .opcao-odd { color: #00e676; font-weight: 800; font-size: 1.4em; display: block; margin-bottom: 10px; text-shadow: 0 0 5px rgba(0, 230, 118, 0.2); }
        
        .btn-apostar { width: 100%; margin-top: 8px; font-weight: 700; border-radius: 50px; }
        
        .section-title {
            color: #888;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 15px;
            border-bottom: 1px solid #333;
            padding-bottom: 5px;
        }
    </style>
</head>
<body>

    <div class="navbar-custom d-flex justify-content-between align-items-center shadow-lg sticky-top">
        <div class="d-flex align-items-center gap-3">
            <span class="fs-5">Olá, <strong><?= htmlspecialchars($usuario['nome']) ?></strong></span>
            <?php if (!empty($usuario['is_admin']) && $usuario['is_admin'] == 1): ?>
                <a href="admin.php" class="admin-btn"><i class="bi bi-gear-fill me-1"></i> Admin</a>
            <?php endif; ?>
        </div>
        
        <div class="d-flex align-items-center gap-3">
            <span class="saldo-badge me-2"><?= number_format($usuario['pontos'], 0, ',', '.') ?> pts</span>
            <a href="logout.php" class="btn btn-outline-danger btn-sm border-0"><i class="bi bi-box-arrow-right"></i> Sair</a>
        </div>
    </div>

    <div class="container mt-5 mb-5">
        
        <?php if($msg): ?>
            <div class="alert alert-success shadow-sm border-0 bg-success bg-opacity-10 text-success border-success mb-4"><i class="bi bi-check-circle-fill me-2"></i><?= $msg ?></div>
        <?php endif; ?>
        <?php if($erro): ?>
            <div class="alert alert-danger shadow-sm border-0 bg-danger bg-opacity-10 text-danger border-danger mb-4"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= $erro ?></div>
        <?php endif; ?>

        <h6 class="section-title"><i class="bi bi-grid-fill me-2"></i>Central de Jogos</h6>
        
        <div class="row g-3 mb-5">
            
            <div class="col-6 col-md-3 col-lg-2">
                <a href="ranking.php" class="card text-decoration-none h-100 card-menu hover-warning">
                    <div class="card-body text-center d-flex flex-column align-items-center justify-content-center">
                        <i class="bi bi-trophy-fill icon-menu text-warning"></i>
                        <h6 class="fw-bold m-0 text-white">Ranking</h6>
                        <small class="text-secondary">Quem lidera?</small>
                    </div>
                </a>
            </div>

            <!-- FLAPPY BIRD (NOVO) -->
            <div class="col-6 col-md-3 col-lg-2">
                <a href="flappy.php" class="card text-decoration-none h-100 card-menu hover-flappy">
                    <div class="card-body text-center d-flex flex-column align-items-center justify-content-center">
                        <i class="bi bi-twitter icon-menu" style="color: #ff9800;"></i>
                        <h6 class="fw-bold m-0 text-white">Flappy</h6>
                        <small class="text-secondary">Voe Longe</small>
                    </div>
                </a>
            </div>

            <div class="col-6 col-md-3 col-lg-2">
                <a href="cafe.php" class="card text-decoration-none h-100 card-menu hover-coffee">
                    <div class="card-body text-center d-flex flex-column align-items-center justify-content-center">
                        <i class="bi bi-cup-hot-fill icon-menu" style="color: #d7ccc8;"></i>
                        <h6 class="fw-bold m-0 text-white">Clube do Café</h6>
                        <small class="text-secondary">Registrar</small>
                    </div>
                </a>
            </div>

            <div class="col-6 col-md-3 col-lg-2">
                <a href="termo.php" class="card text-decoration-none h-100 card-menu hover-success">
                    <div class="card-body text-center d-flex flex-column align-items-center justify-content-center">
                        <i class="bi bi-grid-3x3-gap-fill icon-menu text-success"></i>
                        <h6 class="fw-bold m-0 text-white">Termo</h6>
                        <small class="text-secondary">Desafio Diário</small>
                    </div>
                </a>
            </div>

            <div class="col-6 col-md-3 col-lg-2">
                <a href="memoria.php" class="card text-decoration-none h-100 card-menu hover-info">
                    <div class="card-body text-center d-flex flex-column align-items-center justify-content-center">
                        <i class="bi bi-cpu-fill icon-menu text-info"></i>
                        <h6 class="fw-bold m-0 text-white">Memória</h6>
                        <small class="text-secondary">Treino Mental</small>
                    </div>
                </a>
            </div>

            <div class="col-6 col-md-3 col-lg-2">
                <a href="pinguim.php" class="card text-decoration-none h-100 card-menu hover-primary">
                    <div class="card-body text-center d-flex flex-column align-items-center justify-content-center">
                        <i class="bi bi-wind icon-menu text-primary"></i>
                        <h6 class="fw-bold m-0 text-white">Pinguim Run</h6>
                        <small class="text-secondary">Corra e Ganhe</small>
                    </div>
                </a>
            </div>

            <div class="col-6 col-md-3 col-lg-2">
                <a href="xadrez.php" class="card text-decoration-none h-100 card-menu hover-dark">
                    <div class="card-body text-center d-flex flex-column align-items-center justify-content-center">
                        <i class="bi bi-joystick icon-menu text-secondary"></i>
                        <h6 class="fw-bold m-0 text-white">Xadrez PvP</h6>
                        <small class="text-secondary">Aposte Pontos</small>
                    </div>
                </a>
            </div>

        </div>

        <h6 class="section-title"><i class="bi bi-cash-stack me-2"></i>Mercado de Apostas</h6>

        <?php if(empty($eventos)): ?>
            <div class="text-center py-5 rounded shadow-sm" style="background-color: #1e1e1e; border: 1px dashed #333;">
                <h1 class="text-secondary display-4 opacity-25"><i class="bi bi-inbox"></i></h1>
                <h4 class="text-muted">Nenhum evento aberto.</h4>
                <p class="text-secondary small">Aguarde novas oportunidades.</p>
            </div>
        <?php else: ?>
            
            <?php foreach($eventos as $evento): ?>
                <?php 
                    $stmtOpcoes = $pdo->prepare("SELECT * FROM opcoes WHERE evento_id = :eid");
                    $stmtOpcoes->execute([':eid' => $evento['id']]);
                    $opcoes = $stmtOpcoes->fetchAll(PDO::FETCH_ASSOC);
                ?>

                <div class="card card-evento shadow-sm">
                    <div class="card-header-evento">
                        <h4 class="evento-titulo"><?= htmlspecialchars($evento['nome']) ?></h4>
                        <span class="evento-data">
                            <i class="bi bi-clock-history me-1 text-warning"></i>
                            Encerra em: <?= date('d/m/Y às H:i', strtotime($evento['data_limite'])) ?>
                        </span>
                    </div>

                    <div class="opcoes-grid">
                        <?php foreach($opcoes as $opcao): ?>
                            <form action="processar_aposta.php" method="POST" class="card-opcao">
                                <input type="hidden" name="opcao_id" value="<?= $opcao['id'] ?>">
                                
                                <span class="opcao-nome"><?= htmlspecialchars($opcao['descricao']) ?></span>
                                <span class="opcao-odd"><?= number_format($opcao['odd'], 2) ?></span>
                                
                                <div class="input-group input-group-sm mt-3">
                                    <span class="input-group-text bg-dark border-secondary text-secondary">$</span>
                                    <input type="number" name="valor" class="form-control bg-dark border-secondary text-white text-center" placeholder="Valor" min="1" required step="0.01">
                                </div>
                                <button type="submit" class="btn btn-success btn-sm btn-apostar">APOSTAR</button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                </div>

            <?php endforeach; ?>

        <?php endif; ?>
        
        <div class="text-center mt-5 mb-5">
            <a href="historico.php" class="btn btn-outline-secondary rounded-pill px-4">
                <i class="bi bi-clock-history me-2"></i>Meu Histórico
            </a>
        </div>

    </div>

</body>
</html>