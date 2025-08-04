<?php
require_once '../config_bd.php';
session_start();
date_default_timezone_set('Europe/Lisbon');

if (!isset($_SESSION['utilizador_logado']) || !isset($_SESSION['nome_logado'])) {
    die("Utilizador não autenticado.");
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Dados inválidos.");
}

$idTarefa = intval($_GET['id']);
$utilizador = $_SESSION['utilizador_logado'];
$dataHoje = date('Y-m-d');
$horaAgora = date('H:i:s');

try {
    // ✅ Atualiza estado para "ativa" e define o início da contagem do cronômetro
    $stmtEstado = $ligacao->prepare("
        UPDATE tarefas 
        SET estado_cronometro = 'ativa', 
            data_inicio_cronometro = NOW()
        WHERE id = ?
    ");
    $stmtEstado->execute([$idTarefa]);

    // ✅ Atualiza data_inicio da tarefa se ainda for NULL
    $stmtInicio = $ligacao->prepare("
        UPDATE tarefas 
        SET data_inicio = NOW()
        WHERE id = ? AND data_inicio IS NULL
    ");
    $stmtInicio->execute([$idTarefa]);

    // ✅ Atualiza sempre a última modificação
    $stmtMod = $ligacao->prepare("
        UPDATE tarefas 
        SET ultima_modificacao = NOW()
        WHERE id = ?
    ");
    $stmtMod->execute([$idTarefa]);

    // ✅ Verifica se já existe registo diário para esta tarefa
    $verifica = $ligacao->prepare("
        SELECT id FROM registo_diario 
        WHERE id_tarefa = ? AND utilizador = ? AND data_trabalho = ?
    ");
    $verifica->execute([$idTarefa, $utilizador, $dataHoje]);

    if ($verifica->rowCount() === 0) {
        // Obtém o tempo já decorrido
        $stmtTempo = $ligacao->prepare("SELECT tempo_decorrido FROM tarefas WHERE id = ?");
        $stmtTempo->execute([$idTarefa]);
        $tempoAtual = $stmtTempo->fetchColumn() ?? '00:00:00';

        // Cria novo registo diário
        $inserir = $ligacao->prepare("
            INSERT INTO registo_diario 
            (id_tarefa, utilizador, data_trabalho, hora_inicio, tempo_inicio_tarefa)
            VALUES (?, ?, ?, ?, ?)
        ");
        $inserir->execute([$idTarefa, $utilizador, $dataHoje, $horaAgora, $tempoAtual]);
    } else {
        // Atualiza hora_inicio se estiver nula
        $stmtAtualizaHora = $ligacao->prepare("
            UPDATE registo_diario 
            SET hora_inicio = ?
            WHERE id_tarefa = ? AND utilizador = ? AND data_trabalho = ? AND hora_inicio IS NULL
        ");
        $stmtAtualizaHora->execute([$horaAgora, $idTarefa, $utilizador, $dataHoje]);
    }

    header("Location: abrirtarefa.php?id=$idTarefa");
    exit;

} catch (PDOException $e) {
    die("Erro ao registar início: " . $e->getMessage());
}
