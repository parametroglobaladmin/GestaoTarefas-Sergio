<?php
require_once '../config_bd.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION["admin_logado"])) {
    echo json_encode(['sucesso' => false, 'erro' => 'Acesso não autorizado.']);
    exit();
}

$data = $_POST['data'] ?? null;
$acao = $_POST['acao'] ?? null;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || !in_array($acao, ['adicionar', 'remover'])) {
    echo json_encode(['sucesso' => false, 'erro' => 'Parâmetros inválidos.']);
    exit();
}

try {
    if ($acao === 'adicionar') {
        $stmt = $ligacao->prepare("INSERT IGNORE INTO dias_nao_permitidos (data) VALUES (?)");
        $stmt->execute([$data]);
    } else {
        $stmt = $ligacao->prepare("DELETE FROM dias_nao_permitidos WHERE data = ?");
        $stmt->execute([$data]);
    }

    echo json_encode(['sucesso' => true]);
    exit();

} catch (PDOException $e) {
    echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]);
    exit();
}
