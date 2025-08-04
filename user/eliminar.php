<?php
session_start();
if (!isset($_SESSION["utilizador_logado"])) {
    header("Location: login.php");
    exit();
}

require_once '../config_bd.php';

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id = $_GET['id'];
    $utilizador = $_SESSION['utilizador_logado'];

    try {
        // Verifica se a tarefa pertence ao utilizador
        $stmt = $ligacao->prepare("SELECT id FROM tarefas WHERE id = ? AND utilizador = ?");
        $stmt->execute([$id, $utilizador]);

        if ($stmt->rowCount() > 0) {
            // 🧹 Eliminar pausas da tarefa
            $ligacao->prepare("DELETE FROM pausas_tarefas WHERE tarefa_id = ?")->execute([$id]);

            // 🧹 Eliminar registos do dia da tarefa
            $ligacao->prepare("DELETE FROM registo_diario WHERE id_tarefa = ?")->execute([$id]);

            // 🗑️ Eliminar a tarefa
            $ligacao->prepare("DELETE FROM tarefas WHERE id = ?")->execute([$id]);

            // ✅ Redirecionar
            header("Location: tarefas.php");
            exit();
        } else {
            echo "❌ Tarefa não encontrada ou não pertence a este utilizador.";
        }

    } catch (PDOException $e) {
        echo "❌ Erro ao eliminar: " . $e->getMessage();
    }
} else {
    echo "❌ ID inválido.";
}
