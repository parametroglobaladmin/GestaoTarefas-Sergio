<?php
require_once '../config_bd.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $departamentoId = $_POST['departamento_id'] ?? 0;
    $utilizadores = $_POST['funcionarios'] ?? [];

    if ($departamentoId > 0 && !empty($utilizadores)) {
        $placeholders = implode(',', array_fill(0, count($utilizadores), '?'));
        $query = "UPDATE funcionarios SET departamento = ? WHERE utilizador IN ($placeholders)";
        $stmt = $ligacao->prepare($query);
        $params = array_merge([$departamentoId], $utilizadores);
        $stmt->execute($params);
    }
}

header("Location: departamentos.php");
exit();
