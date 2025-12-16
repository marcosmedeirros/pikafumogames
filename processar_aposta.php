<?php
// processar_aposta.php - CORRIGIDO: SALVA A ODD DO MOMENTO 🔒
session_start();
require 'conexao.php';
require 'funcoes.php';

if (!isset($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] != 'POST') {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$opcao_id = $_POST['opcao_id'];
$valor_aposta = floatval($_POST['valor']);

if ($valor_aposta <= 0) {
    header("Location: painel.php?erro=Valor inválido");
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Verifica saldo (PONTOS)
    $stmtUser = $pdo->prepare("SELECT pontos FROM usuarios WHERE id = :id FOR UPDATE");
    $stmtUser->execute([':id' => $user_id]);
    $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if ($user['pontos'] < $valor_aposta) {
        throw new Exception("Saldo insuficiente! Seus pontos: " . $user['pontos']);
    }

    // 2. Verifica se a aposta está aberta E PEGA A ODD ATUAL
    // ADICIONEI: o.odd na seleção
    $stmtCheck = $pdo->prepare("
        SELECT e.id as evento_id, e.status, e.data_limite, o.odd 
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
        throw new Exception("Este evento já encerrou!");
    }

    // 3. Desconta os pontos
    $stmtDebit = $pdo->prepare("UPDATE usuarios SET pontos = pontos - :val WHERE id = :id");
    $stmtDebit->execute([':val' => $valor_aposta, ':id' => $user_id]);

    // 4. Registra o palpite COM A ODD CONGELADA
    // ADICIONEI: odd_registrada no INSERT
    $stmtInsert = $pdo->prepare("INSERT INTO palpites (id_usuario, opcao_id, valor, odd_registrada, data_palpite) VALUES (:uid, :oid, :val, :odd_fixa, NOW())");
    $stmtInsert->execute([
        ':uid' => $user_id, 
        ':oid' => $opcao_id, 
        ':val' => $valor_aposta,
        ':odd_fixa' => $dados_aposta['odd'] // Salva a odd que veio do SELECT acima
    ]);

    // 5. Recalcula as odds para o PRÓXIMO apostador
    // (A aposta atual já garantiu a odd antiga, agora o mercado se ajusta)
    recalcularOdds($pdo, $dados_aposta['evento_id']);

    $pdo->commit();
    header("Location: painel.php?msg=Aposta realizada com sucesso!");

} catch (Exception $e) {
    $pdo->rollBack();
    header("Location: painel.php?erro=" . urlencode($e->getMessage()));
}
?>