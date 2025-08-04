<?php
require_once '../config_bd.php';
session_start();
date_default_timezone_set('Europe/Lisbon');

$utilizador = $_SESSION['utilizador_logado'] ?? null;

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["id_tarefa"], $_POST["motivo"], $_POST["tempo_atual"])) {
    $idTarefa     = intval($_POST["id_tarefa"]);
    $tempoAtual   = $_POST["tempo_atual"];
    $motivo_id    = intval($_POST["motivo"]);
    $dataHoje     = date('Y-m-d');
    $horaAgora    = date('H:i:s');

    if (!$utilizador || !$idTarefa || !$motivo_id) {
        die("Dados inválidos.");
    }

    function subtrairTempos($t1, $t2) {
        $t1_sec = strtotime("1970-01-01 $t1 UTC");
        $t2_sec = strtotime("1970-01-01 $t2 UTC");
        $diff   = max(0, $t1_sec - $t2_sec);
        return gmdate("H:i:s", $diff);
    }

    try {
        // 1. Atualiza tarefa (pausar e guardar tempo apenas do utilizador)
        $stmt = $ligacao->prepare("
            UPDATE tarefas 
            SET estado_cronometro = 'pausa',
                data_inicio_cronometro = NULL,
                tempo_decorrido_utilizador = ?, 
                ultima_modificacao = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$tempoAtual, $idTarefa]);

        // 2. Registra a pausa
        $stmtPausa = $ligacao->prepare("
            INSERT INTO pausas_tarefas (tarefa_id, funcionario, data_pausa, motivo_id) 
            VALUES (?, ?, NOW(), ?)
        ");
        $stmtPausa->execute([$idTarefa, $utilizador, $motivo_id]);

        // 3. Atualiza o registo diário
        $stmtInicio = $ligacao->prepare("
            SELECT tempo_inicio_tarefa 
            FROM registo_diario 
            WHERE id_tarefa = ? AND utilizador = ? AND data_trabalho = ?
        ");
        $stmtInicio->execute([$idTarefa, $utilizador, $dataHoje]);
        $tempoInicioTarefa = $stmtInicio->fetchColumn() ?? '00:00:00';

        $tempoAcumulado = subtrairTempos($tempoAtual, $tempoInicioTarefa);

        $stmtUpdate1 = $ligacao->prepare("
            UPDATE registo_diario 
            SET tempo_fim_tarefa = ?, tempo_acumulado = ?, fim_tarefa = ?
            WHERE id_tarefa = ? AND utilizador = ? AND data_trabalho = ?
        ");
        $stmtUpdate1->execute([
            $tempoAtual,
            $tempoAcumulado,
            $horaAgora,
            $idTarefa,
            $utilizador,
            $dataHoje
        ]);

        header("Location: abrirtarefa.php?id=$idTarefa");
        exit();

    } catch (PDOException $e) {
        die("Erro ao registrar pausa: " . $e->getMessage());
    }

} else {
    die("Requisição inválida.");
}
