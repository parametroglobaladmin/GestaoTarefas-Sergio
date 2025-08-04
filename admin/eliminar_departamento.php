<?php
require_once '../config_bd.php';
session_start();

if (!isset($_SESSION["admin_logado"])) {
    header("Location: login.php");
    exit();
}

if (isset($_GET['id'])) {
    $departamentoId = intval($_GET['id']);

    // 1. Atualizar os funcionários: remover vínculo com o departamento
    $queryUpdateFunc = "UPDATE funcionarios SET departamento = 0 WHERE departamento = ?";
    $stmtUpdate = $ligacao->prepare($queryUpdateFunc);
    $stmtUpdate->execute([$departamentoId]);

    // 2. Eliminar o departamento da tabela
    $queryDeleteDep = "DELETE FROM departamento WHERE id = ?";
    $stmtDelete = $ligacao->prepare($queryDeleteDep);
    $stmtDelete->execute([$departamentoId]);

    // 3. Redirecionar de volta à página de departamentos
    header("Location: departamentos.php");
    exit();
} else {
    echo "ID do departamento não fornecido.";
}
?>
