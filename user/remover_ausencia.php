<?php
require_once '../config_bd.php';
session_start();

if (!isset($_SESSION['nome_logado'])) {
    http_response_code(403);
    exit("Utilizador não autenticado.");
}

$utilizador = $_SESSION['nome_logado'];

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['data'])) {
    $data = $_POST['data'];

    try {
        $stmt = $ligacao->prepare("
            DELETE FROM ausencia_funcionarios 
            WHERE data_falta = ? AND funcionario_utilizador = ?
        ");
        $stmt->execute([$data, $utilizador]);

        if ($stmt->rowCount() > 0) {
            echo "Removido com sucesso.";
        } else {
            echo "Nenhum registo removido.";
        }

    } catch (PDOException $e) {
        http_response_code(500);
        echo "Erro: " . $e->getMessage();
    }
} else {
    http_response_code(400);
    echo "Dados inválidos.";
}
