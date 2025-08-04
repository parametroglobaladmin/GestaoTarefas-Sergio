<?php
require_once '../config_bd.php';

$utilizador = $_POST['utilizador'] ?? '';
$ativo = $_POST['ativo'] ?? '';

if ($utilizador !== '' && $ativo !== '') {
    $criarTarefa = $ativo == 1 ? 'ativo' : 'inativo';

    $stmt = $ligacao->prepare("UPDATE funcionarios SET criar_tarefa = ? WHERE utilizador = ?");
    if ($stmt->execute([$criarTarefa, $utilizador])) {
        echo "sucesso";
    } else {
        echo "erro";
    }
} else {
    echo "erro";
}
