<?php
// index.php - TELA DE LOGIN PRINCIPAL (DARK MODE 🌑)
session_start();
require 'conexao.php';

// 1. Se já estiver logado, joga pro painel direto
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1) {
        header("Location: admin.php");
    } else {
        header("Location: painel.php");
    }
    exit;
}

$erro = "";
$sucesso = "";

// 2. Processa o Login ou Reset
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // A. RESETAR SENHA (SIMPLES)
    if (isset($_POST['acao']) && $_POST['acao'] == 'reset_senha') {
        $email_reset = trim($_POST['email_reset']);
        
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = :email");
        $stmt->execute([':email' => $email_reset]);
        $user_reset = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user_reset) {
            // Em produção, envie um e-mail. Aqui, vamos resetar para '123456' para facilitar.
            $nova_senha_hash = password_hash('123456', PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE usuarios SET senha = :s WHERE id = :id")->execute([':s' => $nova_senha_hash, ':id' => $user_reset['id']]);
            $sucesso = "Sua senha foi redefinida para: <strong>123456</strong>. Entre e troque-a imediatamente!";
        } else {
            $erro = "E-mail não encontrado no sistema.";
        }
    } 
    // B. LOGIN PADRÃO
    else {
        $email = trim($_POST['email']);
        $senha = trim($_POST['senha']);

        if (empty($email) || empty($senha)) {
            $erro = "Preencha todos os campos.";
        } else {
            $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = :email");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Verifica senha (Hash ou Texto Puro para compatibilidade)
            if ($user && (password_verify($senha, $user['senha']) || $user['senha'] == $senha || trim($user['senha']) == $senha)) {
                
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['nome'] = $user['nome'];
                $_SESSION['is_admin'] = $user['is_admin'];
                
                if($user['is_admin'] == 1) {
                    header("Location: admin.php");
                } else {
                    header("Location: painel.php");
                }
                exit;
            } else {
                $erro = "E-mail ou senha incorretos.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Pikafumo Games</title>
    
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🎮</text></svg>">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <style>
        /* PADRÃO DARK MODE */
        body, html { height: 100%; margin: 0; background-color: #121212; color: #e0e0e0; font-family: 'Segoe UI', sans-serif; }
        .row-full { height: 100vh; width: 100%; margin: 0; }
        
        /* Lado Esquerdo (Banner) */
        .left-side {
            background: linear-gradient(135deg, #1e1e1e 0%, #000000 100%);
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 50px;
            border-right: 1px solid #333;
        }
        
        /* Lado Direito (Formulário) */
        .right-side {
            background-color: #121212;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-card {
            width: 100%;
            max-width: 400px;
            padding: 40px;
            background: #1e1e1e;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            border: 1px solid #333;
        }

        .brand-text { font-size: 2.5rem; font-weight: 800; margin-bottom: 20px; color: #ff6d00; text-shadow: 0 0 10px rgba(255, 109, 0, 0.3); }
        .hero-text { font-size: 1.2rem; line-height: 1.6; opacity: 0.8; color: #aaa; }

        /* Inputs Dark */
        .form-control { 
            background-color: #2b2b2b; border: 1px solid #444; color: #fff; 
        }
        .form-control:focus { 
            background-color: #2b2b2b; border-color: #00e676; color: #fff; box-shadow: 0 0 0 0.25rem rgba(0, 230, 118, 0.25); 
        }
        .form-label { color: #ccc; }

        .btn-primary-custom {
            background-color: #00e676; color: #000; font-weight: 800; border: none;
            transition: 0.3s;
        }
        .btn-primary-custom:hover { background-color: #00c853; box-shadow: 0 0 15px rgba(0, 230, 118, 0.4); }

        .link-support { color: #aaa; text-decoration: none; font-size: 0.9em; transition: 0.3s; cursor: pointer; }
        .link-support:hover { color: #ff6d00; }

        @media (max-width: 768px) {
            .row-full { height: auto; }
            .left-side { padding: 40px 20px; text-align: center; border-right: none; border-bottom: 1px solid #333; }
            .right-side { padding: 40px 20px; height: auto; }
        }
        
        /* Modal Dark */
        .modal-content { background-color: #1e1e1e; border: 1px solid #444; color: #e0e0e0; }
        .modal-header { border-bottom: 1px solid #333; }
        .modal-footer { border-top: 1px solid #333; }
    </style>
</head>
<body>

    <div class="row row-full">
        <!-- LADO ESQUERDO: Texto e Boas-vindas -->
        <div class="col-md-6 left-side">
            <div>
                <h1 class="brand-text">Pikafumo Games 🎮</h1>
                <p class="hero-text">
                    Bem-vindo à plataforma oficial de entretenimento da equipe.<br><br>
                    <i class="bi bi-check-circle-fill text-success me-2"></i>Apostas Esportivas<br>
                    <i class="bi bi-check-circle-fill text-success me-2"></i>Xadrez PvP<br>
                    <i class="bi bi-check-circle-fill text-success me-2"></i>Desafios Diários (Termo & Memória)<br><br>
                    <strong>Acerte mais, ganhe pontos e domine o ranking!</strong>
                </p>
            </div>
        </div>

        <!-- LADO DIREITO: Formulário de Login -->
        <div class="col-md-6 right-side">
            <div class="login-card">
                <h3 class="text-center mb-4 fw-bold text-white"><i class="bi bi-person-circle me-2"></i>Acessar Conta</h3>
                
                <?php if($erro): ?>
                    <div class="alert alert-danger text-center p-2 small border-0 bg-danger bg-opacity-25 text-white">
                        <i class="bi bi-exclamation-circle me-1"></i><?= $erro ?>
                    </div>
                <?php endif; ?>

                <?php if($sucesso): ?>
                    <div class="alert alert-success text-center p-2 small border-0 bg-success bg-opacity-25 text-white">
                        <i class="bi bi-check-circle me-1"></i><?= $sucesso ?>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">E-mail Corporativo</label>
                        <input type="email" name="email" class="form-control form-control-lg" placeholder="seu@email.com" required>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Senha</label>
                        <input type="password" name="senha" class="form-control form-control-lg" placeholder="******" required>
                    </div>

                    <button type="submit" class="btn btn-primary-custom btn-lg w-100 mb-3">ENTRAR</button>
                    
                    <div class="text-center border-top border-secondary pt-3 mt-3 d-flex flex-column gap-2">
                        <span class="link-support" data-bs-toggle="modal" data-bs-target="#modalSuporte">
                            <i class="bi bi-question-circle me-1"></i>Esqueci a senha / Trocar E-mail
                        </span>
                        
                        <a href="registrar.php" class="btn btn-outline-light btn-sm fw-bold w-100 mt-2">Criar Conta Nova</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL DE SUPORTE -->
    <div class="modal fade" id="modalSuporte" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-warning"><i class="bi bi-life-preserver me-2"></i>Central de Ajuda</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Opção 1: Resetar Senha -->
                    <h6 class="text-white mb-2"><i class="bi bi-key-fill me-2"></i>Esqueci minha senha</h6>
                    <p class="text-muted small mb-2">Digite seu e-mail abaixo para resetar sua senha para o padrão <strong>123456</strong>.</p>
                    <form method="POST" class="mb-4">
                        <input type="hidden" name="acao" value="reset_senha">
                        <div class="input-group mb-2">
                            <input type="email" name="email_reset" class="form-control" placeholder="Digite seu e-mail" required>
                            <button class="btn btn-warning fw-bold" type="submit">Resetar</button>
                        </div>
                    </form>

                    <hr class="border-secondary">

                    <!-- Opção 2: Trocar Email -->
                    <h6 class="text-white mb-2"><i class="bi bi-envelope-arrow-up-fill me-2"></i>Trocar E-mail de Acesso</h6>
                    <p class="text-secondary small">
                        Por motivos de segurança, a troca de e-mail deve ser solicitada diretamente ao administrador do sistema.
                    </p>
                    <div class="alert alert-dark border-secondary d-flex align-items-center">
                        <i class="bi bi-whatsapp fs-3 text-success me-3"></i>
                        <div>
                            <strong>Falar com Marcos Medeiros</strong><br>
                            <span class="small text-muted">Admin do Sistema</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>