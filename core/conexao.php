<?php
// core/conexao.php

$host = 'localhost';
$dbname = 'u289267434_lumacio';
$user = 'root';
$pass = ''; // <--- Coloque a senha que você criou para esse usuário no painel

try {
    // Conexão com PDO
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    
    // Configura o PDO para lançar exceções em caso de erro (bom para debug)
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
} catch (PDOException $e) {
    die("Erro na conexão: " . $e->getMessage());
}
?>
