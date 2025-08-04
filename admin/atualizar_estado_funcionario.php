<?php
require_once '../config_bd.php';

$utilizador = $_POST['utilizador'] ?? '';
$ativo = $_POST['ativo'] ?? '';

if ($utilizador !== '' && $ativo !== '') {
    $estado = $ativo == 1 ? 'ativo' : 'inativo';

    $stmt = $ligacao->prepare("UPDATE funcionarios SET estado = ? WHERE utilizador = ?");
    if ($stmt->execute([$estado, $utilizador])) {
        echo "sucesso";
    } else {
        echo "erro";
    }
} else {
    echo "erro";
}
