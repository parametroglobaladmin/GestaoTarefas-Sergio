<?php
session_start();
if (!isset($_SESSION["admin_logado"])) {
    header("Location: login.php");
    exit();
}

require_once '../config_bd.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'eliminar') {
    if (!empty($_POST['tarefas_selecionadas']) && is_array($_POST['tarefas_selecionadas'])) {
        $ids = array_map('intval', $_POST['tarefas_selecionadas']); // segurança

        foreach ($ids as $id) {
            try {
                // Verificar se a tarefa existe
                $stmt = $ligacao->prepare("SELECT id FROM tarefas WHERE id = ?");
                $stmt->execute([$id]);

                if ($stmt->rowCount() > 0) {
                    // Em vez de eliminar os registos, alteramos apenas o estado da tarefa
                    $ligacao->prepare("UPDATE tarefas SET estado = 'eliminada' WHERE id = ?")->execute([$id]);
                }

            } catch (PDOException $e) {
                echo "Erro ao marcar como eliminada a tarefa ID $id: " . $e->getMessage();
            }
        }

        header("Location: eliminar_tarefas.php");
        exit();
    } else {
        echo "❌ Nenhuma tarefa selecionada.";
    }
} else {
    echo "❌ Requisição inválida.";
}
