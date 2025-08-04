<?php
session_start();
if (!isset($_SESSION["admin_logado"])) {
    header("Location: login.php");
    exit();
}

require_once '../config_bd.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id']) && is_numeric($_POST['id'])) {
    $id = intval($_POST['id']);

    try {
        // Verifica se a tarefa existe
        $stmt = $ligacao->prepare("SELECT id FROM tarefas WHERE id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() > 0) {
            // Atualiza o estado da tarefa para 'eliminada'
            $ligacao->prepare("UPDATE tarefas SET estado = 'eliminada' WHERE id = ?")->execute([$id]);

            header("Location: eliminar_tarefas.php");
            exit();
        } else {
            echo "❌ Tarefa não encontrada.";
        }

    } catch (PDOException $e) {
        echo "❌ Erro ao marcar como eliminada: " . $e->getMessage();
    }
} else {
    echo "❌ Requisição inválida.";
}
