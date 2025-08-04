<?php
session_start();
require_once '../config_bd.php';

// Verificar se o utilizador está autenticado
if (!isset($_SESSION['utilizador_logado'])) {
    header('Location: login.php');
    exit();
}

$utilizador = $_SESSION['utilizador_logado'];
$erro = '';
$mensagem = '';

// Receber dados do formulário
$passwordAtual = $_POST['password'] ?? '';
$novaPassword = $_POST['nova_password'] ?? '';
$confirmarPassword = $_POST['confirmar_password'] ?? '';

try {
    // Buscar a password atual na base de dados
    $stmt = $ligacao->prepare("SELECT password FROM funcionarios WHERE utilizador = ?");
    $stmt->execute([$utilizador]);
    $resultado = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$resultado) {
        $erro = "Utilizador não encontrado.";
    } elseif ($resultado['password'] !== $passwordAtual) {
        $erro = "A palavra-passe atual está incorreta.";
    } elseif ($novaPassword !== $confirmarPassword) {
        $erro = "As novas palavras-passe não coincidem.";
    } else {
        // Atualizar a password (texto simples)
        $stmt = $ligacao->prepare("UPDATE funcionarios SET password = ? WHERE utilizador = ?");
        $stmt->execute([$novaPassword, $utilizador]);

        $mensagem = "Palavra-passe alterada com sucesso.";
    }
} catch (Exception $e) {
    $erro = "Erro ao alterar a palavra-passe.";
}

$destino = "tarefas.php";
$queryString = http_build_query([
    'mensagem' => $mensagem,
    'erro' => $erro
]);

header("Location: $destino?$queryString");
exit();
