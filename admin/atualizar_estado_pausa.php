<?php
require_once '../config_bd.php';

$pausa = $_POST['codigo'] ?? '';
$estatistica = $_POST['estatistica'] ?? '';

if ($pausa !== '' && $estatistica !== '') {
    $estado = $estatistica == 1 ? 'ativo' : 'inativo';

    $stmt = $ligacao->prepare("UPDATE motivos_pausa SET estatistica = ? WHERE codigo = ?");
    echo $pausa, $estado;
    if ($stmt->execute([$estado, $pausa])) {
        echo "sucesso";
    } else {
        echo "erro";
    }

} else {
    echo "erro";
}
