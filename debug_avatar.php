<?php
/**
 * DEBUG_AVATAR.PHP - Versão de debug para avatar que não requer login
 * Acesso: https://pikafumogames.tech/debug_avatar.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

// Criar diretório de logs se não existir
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

// Configurar logging
ini_set('error_log', $logDir . '/debug_avatar.log');
error_log("=== DEBUG AVATAR INICIADO ===");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

error_log("Session status: " . session_status());
error_log("POST data: " . json_encode($_POST));
error_log("GET data: " . json_encode($_GET));

require 'core/conexao.php';
require 'core/avatar.php';

error_log("Includes carregados");

// Se não está logado, usa user_id padrão para debug
if (!isset($_SESSION['user_id'])) {
    error_log("Não logado, usando user_id 1 para debug");
    $_SESSION['user_id'] = 1;
}

$user_id = $_SESSION['user_id'];
error_log("User ID: $user_id");

// Carrega dados do usuário
try {
    $stmt = $pdo->prepare("SELECT nome, pontos FROM usuarios WHERE id = :id");
    $stmt->execute([':id' => $user_id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    error_log("Usuário encontrado: " . json_encode($usuario));
} catch (PDOException $e) {
    error_log("ERRO ao carregar usuário: " . $e->getMessage());
    $usuario = ['nome' => 'Debug User', 'pontos' => 9999];
}

// API para abrir caixa (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api']) && $_POST['api'] === 'abrir_caixa') {
    header('Content-Type: application/json; charset=utf-8');
    
    error_log("=== ABRIR CAIXA DEBUG ===");
    error_log("POST data: " . json_encode($_POST));
    error_log("User ID: " . $user_id);
    
    $tipo_caixa = $_POST['tipo_caixa'] ?? '';
    error_log("Tipo caixa: " . $tipo_caixa);
    
    if (empty($tipo_caixa)) {
        $resposta = ['sucesso' => false, 'mensagem' => 'Tipo de caixa não especificado'];
        error_log("Resposta: " . json_encode($resposta));
        echo json_encode($resposta);
        exit;
    }
    
    $resultado = abrirLootBox($pdo, $user_id, $tipo_caixa);
    error_log("Resultado abrirLootBox: " . json_encode($resultado));
    
    echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
    exit;
}

// API para salvar avatar atual (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api']) && $_POST['api'] === 'salvar_avatar') {
    header('Content-Type: application/json');
    $color = $_POST['color'] ?? 'default';
    $hardware = $_POST['hardware'] ?? 'none';
    $clothing = $_POST['clothing'] ?? 'none';
    $footwear = $_POST['footwear'] ?? 'none';
    $elite = $_POST['elite'] ?? 'none';
    $aura = $_POST['aura'] ?? 'none';
    
    error_log("Salvando avatar: color=$color, hardware=$hardware");
    
    $ok = salvarCustomizacaoAvatar($pdo, $user_id, $color, $hardware, $clothing, $footwear, $elite, $aura);
    
    error_log("Avatar salvo: " . ($ok ? 'OK' : 'ERRO'));
    
    echo json_encode(['sucesso' => (bool)$ok]);
    exit;
}

// Obter inventário
$inventario = obterInventario($pdo, $user_id);
error_log("Inventário carregado: " . count($inventario) . " itens");

// Mapa de posse por categoria
$owned_map = [];
foreach ($inventario as $it) {
    if (!isset($owned_map[$it['categoria']])) $owned_map[$it['categoria']] = [];
    $owned_map[$it['categoria']][$it['item_id']] = true;
}

// Converter dados para JSON
$componentes_json = json_encode($AVATAR_COMPONENTES);
$loot_boxes_json = json_encode($LOOT_BOXES);
$customizacao_atual = obterCustomizacaoAvatar($pdo, $user_id);

error_log("Dados preparados para renderização");

?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pikafumo - Debug Avatar</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background-color: #020617; color: #e2e8f0; font-family: 'Inter', sans-serif; }
        .section { background: #1a1a2e; padding: 20px; margin: 10px 0; border-left: 3px solid #00ff00; border-radius: 8px; }
        .debug-info { background: #0f172a; padding: 15px; margin: 10px 0; border: 1px solid #00ff00; border-radius: 8px; }
        .success { color: #00ff00; }
        .error { color: #ff0000; }
    </style>
</head>
<body class="p-4">

<div class="container-fluid">
    <h1 class="mb-4">🎁 Debug Avatar v7</h1>
    
    <div class="section">
        <h2>📊 Status do Sistema</h2>
        <div class="debug-info">
            <p><strong>User ID:</strong> <span class="success"><?= $user_id ?></span></p>
            <p><strong>Nome:</strong> <span class="success"><?= htmlspecialchars($usuario['nome']) ?></span></p>
            <p><strong>Pontos:</strong> <span class="success"><?= number_format($usuario['pontos'], 0, ',', '.') ?></span></p>
            <p><strong>Inventário:</strong> <span class="success"><?= count($inventario) ?> itens</span></p>
        </div>
    </div>

    <div class="section">
        <h2>🧪 Testes Rápidos</h2>
        <p>Clique em um botão para testar:</p>
        
        <button class="btn btn-success m-2" onclick="testarCaixa('basica')">
            📦 Testar Bolicheiro (30 pts)
        </button>
        
        <button class="btn btn-info m-2" onclick="testarCaixa('top')">
            ⭐ Testar Pnip (50 pts)
        </button>
        
        <button class="btn btn-warning m-2" onclick="testarCaixa('premium')">
            💎 Testar PDSA (80 pts)
        </button>

        <div id="resultado" class="mt-3"></div>
    </div>

    <div class="section">
        <h2>📋 Log do Servidor</h2>
        <div class="debug-info">
            <p><strong>Arquivo:</strong> <?= $logDir ?>/debug_avatar.log</p>
            <p><strong>Tamanho:</strong> <?php
                $logFile = $logDir . '/debug_avatar.log';
                if (file_exists($logFile)) {
                    $bytes = filesize($logFile);
                    $sizes = ['Bytes', 'KB', 'MB'];
                    $i = floor(log($bytes, 1024));
                    echo round($bytes / pow(1024, $i), 2) . ' ' . $sizes[$i];
                } else {
                    echo "Arquivo não criado ainda";
                }
            ?></p>
        </div>
    </div>

    <div class="section">
        <h2>📞 Instruções para Debug Completo</h2>
        <ol>
            <li>Abra DevTools (F12)</li>
            <li>Vá até a aba "Console"</li>
            <li>Clique em um botão de teste acima</li>
            <li>Veja os logs no console</li>
            <li>Verifique o arquivo de log do servidor</li>
        </ol>
    </div>
</div>

<script>
    console.log('🎁 Debug Avatar carregado');
    console.log('Componentes:', <?= $componentes_json ?>);
    console.log('Caixas:', <?= $loot_boxes_json ?>);
    console.log('Avatar atual:', <?= json_encode($customizacao_atual) ?>);

    async function testarCaixa(tipo) {
        console.log('🎁 Testando caixa:', tipo);
        
        try {
            const fd = new FormData();
            fd.append('api', 'abrir_caixa');
            fd.append('tipo_caixa', tipo);
            
            console.log('📡 Enviando:', {api: 'abrir_caixa', tipo_caixa: tipo});
            
            const resp = await fetch(window.location.href, {
                method: 'POST',
                body: fd
            });
            
            console.log('✅ HTTP:', resp.status, resp.statusText);
            
            const text = await resp.text();
            console.log('📄 Resposta bruta:', text);
            
            let data;
            try {
                data = JSON.parse(text);
            } catch(e) {
                console.error('❌ Erro ao parsear JSON:', e);
                alert('Erro ao processar resposta\n\nVerifique o console (F12)');
                return;
            }
            
            console.log('🔄 Dados:', data);
            
            let html = '<div class="alert ' + (data.sucesso ? 'alert-success' : 'alert-danger') + '">';
            html += '<strong>' + (data.sucesso ? '✅ Sucesso!' : '❌ Erro!') + '</strong><br>';
            html += '<pre style="background: #000; padding: 10px; border-radius: 4px;">' + JSON.stringify(data, null, 2) + '</pre>';
            html += '</div>';
            
            document.getElementById('resultado').innerHTML = html;
            
            if (data.sucesso) {
                setTimeout(() => location.reload(), 2000);
            }
        } catch(e) {
            console.error('❌ Exceção:', e);
            alert('Erro: ' + e.message + '\n\nVerifique o console (F12)');
        }
    }
</script>

</body>
</html>
<?php
error_log("=== PÁGINA RENDERIZADA COM SUCESSO ===");
?>
