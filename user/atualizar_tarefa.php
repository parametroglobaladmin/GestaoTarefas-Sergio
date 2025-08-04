<?php
require_once '../config_bd.php';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $id = $_POST['id'] ?? null;
    $tarefa = trim($_POST['tarefa'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');

    if ($id && $tarefa && $descricao) {
        try {
            $stmt = $ligacao->prepare("UPDATE tarefas SET tarefa = ?, descricao = ?, ultima_modificacao = NOW() WHERE id = ?");
            $stmt->execute([$tarefa, $descricao, $id]);
            echo "ok";
            exit;
        } catch (PDOException $e) {
            http_response_code(500);
            echo "Erro ao atualizar: " . $e->getMessage();
            exit;
        }
    } else {
        http_response_code(400);
        echo "Preencha todos os campos.";
        exit;
    }
} else {
    http_response_code(405);
    echo "Método não permitido.";
    exit;
}
