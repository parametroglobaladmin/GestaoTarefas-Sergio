<?php
require_once '../config_bd.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['funcionario_id'])) {
    $funcionarioId = intval($_POST['funcionario_id']);

    try {
        $query = "UPDATE funcionarios SET departamento = 0 WHERE utilizador = ?";
        $stmt = $ligacao->prepare($query);
        $stmt->execute([$funcionarioId]);

        if ($stmt->rowCount() > 0) {
            echo "ok";
        } else {
            echo "nenhuma_alteracao";
        }
    } catch (Exception $e) {
        echo "erro: " . $e->getMessage();
    }
} else {
    echo "requisicao_invalida";
}
