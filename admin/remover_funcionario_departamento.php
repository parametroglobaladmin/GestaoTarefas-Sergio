<?php
require_once '../config_bd.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $funcionarioId = $_POST['funcionario_id'] ?? null;

    if ($funcionarioId) {
        try {
            $stmt = $ligacao->prepare("UPDATE funcionarios SET departamento = 0 WHERE utilizador = ?");
            $stmt->execute([$funcionarioId]);

            echo "ok";
        } catch (PDOException $e) {
            echo "Erro ao atualizar base de dados: " . $e->getMessage();
        }
    } else {
        echo "ID inválido.";
    }
    
} else {
    echo "Método não permitido.";
}
