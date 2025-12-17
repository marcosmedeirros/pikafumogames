<?php
// historico.php - MEU HISTÓRICO DE APOSTAS (CORRIGIDO: ODD HISTÓRICA 🔒)
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
require '../core/conexao.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// 1. Busca dados do usuário para o Header Padronizado
try {
    $stmtMe = $pdo->prepare("SELECT nome, pontos, is_admin FROM usuarios WHERE id = :id");
    $stmtMe->execute([':id' => $user_id]);
    $meu_perfil = $stmtMe->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro ao carregar perfil: " . $e->getMessage());
}

// 2. Busca o Histórico
// CORREÇÃO: Buscamos p.odd_registrada (a odd congelada) em vez de o.odd (odd atual)
$sql = "
    SELECT 
        p.valor, 
        p.data_palpite,
        p.opcao_id, 
        p.odd_registrada, /* <--- AQUI ESTÁ A MÁGICA */
        o.descricao as aposta_feita,
        o.odd as odd_atual, /* Trazemos a atual só por curiosidade, se precisar */
        e.nome as jogo,
        e.status as status_jogo,
        e.vencedor_opcao_id
    FROM palpites p
    JOIN opcoes o ON p.opcao_id = o.id
    JOIN eventos e ON o.evento_id = e.id
    WHERE p.id_usuario = :id
    ORDER BY p.data_palpite DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute([':id' => $user_id]);
$meus_palpites = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Histórico - Pikafumo Games</title>
    
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>📜</text></svg>">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <style>
        /* PADRÃO DARK MODE */
        body { background-color: #121212; color: #e0e0e0; font-family: 'Segoe UI', sans-serif; }
        
        /* Navbar Padronizada */
        .navbar-custom { 
            background: linear-gradient(180deg, #1e1e1e 0%, #121212 100%);
            border-bottom: 1px solid #333;
            padding: 15px; 
        }
        .saldo-badge { 
            background-color: #00e676; color: #000; padding: 8px 15px; 
            border-radius: 20px; font-weight: 800; font-size: 1.1em;
            box-shadow: 0 0 10px rgba(0, 230, 118, 0.3);
        }
        .admin-btn { 
            background-color: #ff6d00; color: white; padding: 5px 15px; 
            border-radius: 20px; text-decoration: none; font-weight: bold; font-size: 0.9em; transition: 0.3s; 
        }
        .admin-btn:hover { background-color: #e65100; color: white; box-shadow: 0 0 8px #ff6d00; }

        /* Card e Tabela Dark */
        .card-dark { background-color: #1e1e1e; border: 1px solid #333; }
        
        .table-dark-custom { --bs-table-bg: #1e1e1e; --bs-table-color: #e0e0e0; --bs-table-border-color: #333; }
        .table-dark-custom th { background-color: #252525; color: #fff; border-bottom: 2px solid #444; }
        .table-dark-custom tr:hover { background-color: #2b2b2b; }

        .badge-status { font-size: 0.9em; padding: 8px 12px; }
    </style>
</head>
<body>

<!-- Header Padronizado -->
<div class="navbar-custom d-flex justify-content-between align-items-center shadow-lg sticky-top mb-4">
    <div class="d-flex align-items-center gap-3">
        <span class="fs-5">Olá, <strong><?= htmlspecialchars($meu_perfil['nome']) ?></strong></span>
        <?php if (!empty($meu_perfil['is_admin']) && $meu_perfil['is_admin'] == 1): ?>
            <a href="../admin/dashboard.php" class="admin-btn"><i class="bi bi-gear-fill me-1"></i> Admin</a>
        <?php endif; ?>
    </div>
    
    <div class="d-flex align-items-center gap-3">
        <a href="../index.php" class="btn btn-outline-secondary btn-sm border-0"><i class="bi bi-arrow-left"></i> Voltar ao Painel</a>
        <span class="saldo-badge me-2"><?= number_format($meu_perfil['pontos'], 0, ',', '.') ?> pts</span>
    </div>
</div>

<div class="container mt-5 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="text-white fw-bold"><i class="bi bi-clock-history me-2 text-warning"></i>Meu Histórico</h3>
    </div>

    <div class="card card-dark shadow-lg">
        <div class="card-body p-0">
            <?php if(empty($meus_palpites)): ?>
                <div class="text-center p-5">
                    <i class="bi bi-ticket-perforated text-secondary" style="font-size: 4rem;"></i>
                    <p class="text-secondary mt-3 fs-5">Você ainda não fez nenhuma aposta.</p>
                    <a href="../index.php" class="btn btn-success mt-2 fw-bold">Fazer minha primeira aposta</a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-dark-custom table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">Data</th>
                                <th>Jogo / Evento</th>
                                <th>Seu Palpite</th>
                                <th>Valor Apostado</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($meus_palpites as $p): ?>
                                <?php
                                    // Determina qual odd usar (Histórica > Atual > 1.0)
                                    $odd_final = $p['odd_registrada'];
                                    if(empty($odd_final)) $odd_final = $p['odd_atual']; // Fallback para apostas antigas

                                    $status_badge = "bg-warning text-dark bg-opacity-75"; 
                                    $status_texto = "<i class='bi bi-hourglass-split me-1'></i>Aguardando";
                                    $linha_style = "";

                                    if ($p['status_jogo'] == 'encerrada') {
                                        
                                        if ($p['vencedor_opcao_id'] === null) {
                                            $status_badge = "bg-secondary";
                                            $status_texto = "Cancelado";
                                        
                                        } elseif ($p['vencedor_opcao_id'] == $p['opcao_id']) {
                                            // GANHOU
                                            $lucro = $p['valor'] * $odd_final;
                                            $status_badge = "bg-success text-white";
                                            $status_texto = "<i class='bi bi-trophy-fill me-1'></i>VENCEU <br><small>Retorno: " . number_format($lucro, 2, ',', '.') . "</small>";
                                            $linha_style = "background-color: rgba(25, 135, 84, 0.15) !important;"; 
                                        
                                        } else {
                                            // PERDEU
                                            $status_badge = "bg-danger text-white";
                                            $status_texto = "<i class='bi bi-x-circle-fill me-1'></i>Perdeu";
                                            $linha_style = "background-color: rgba(220, 53, 69, 0.1) !important;";
                                        }
                                    }
                                ?>
                                <tr style="<?= $linha_style ?>">
                                    <td class="ps-4 text-secondary small">
                                        <?= date('d/m/Y', strtotime($p['data_palpite'])) ?><br>
                                        <?= date('H:i', strtotime($p['data_palpite'])) ?>
                                    </td>
                                    <td><strong class="text-white"><?= htmlspecialchars($p['jogo']) ?></strong></td>
                                    <td>
                                        <?= htmlspecialchars($p['aposta_feita']) ?> <br>
                                        <small class="text-info fw-bold">Odd: <?= number_format($odd_final, 2) ?></small>
                                    </td>
                                    <td class="fw-bold text-success">
                                        <?= number_format($p['valor'], 0, ',', '.') ?> pts
                                    </td>
                                    <td>
                                        <span class="badge rounded-pill badge-status <?= $status_badge ?> shadow-sm">
                                            <?= $status_texto ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
