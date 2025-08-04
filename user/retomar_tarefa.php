<?php
require_once '../config_bd.php';
session_start();
date_default_timezone_set('Europe/Lisbon');
unset($_SESSION['tarefa_pausada_por_semopcao']);

$idTarefa   = $_GET['id'] ?? null;
$utilizador = $_SESSION['utilizador_logado'] ?? null;

if (!$idTarefa || !$utilizador) {
    http_response_code(400);
    exit('Dados inválidos');
}

$dataHoje   = date('Y-m-d');
$horaAgora  = date('Y-m-d H:i:s');

try {
    // ⚠️ Verificar se o utilizador já tem outra tarefa ativa (exceto esta),
    // ou com pausa do tipo 'SemOpcao'
    $stmtVerifica = $ligacao->prepare("
        WITH ultimas_pausas AS (
            SELECT
                pt.tarefa_id,
                mp.tipo AS tipo_motivo,
                ROW_NUMBER() OVER (PARTITION BY pt.tarefa_id ORDER BY pt.data_pausa DESC) AS rn
            FROM pausas_tarefas pt
            INNER JOIN motivos_pausa mp ON pt.motivo_id = mp.id
            WHERE pt.data_retorno IS NULL
        )
        SELECT COUNT(*) 
        FROM tarefas t
        LEFT JOIN ultimas_pausas up ON up.tarefa_id = t.id AND up.rn = 1
        WHERE t.utilizador = ?
        AND t.id != ?
        AND t.estado = 'pendente'
        AND (
                t.estado_cronometro = 'ativa'
            OR (
                t.estado_cronometro = 'pausa'
                AND up.tipo_motivo = 'SemOpcao'
            )
        )
    ");
    $stmtVerifica->execute([$utilizador, $idTarefa]);
    $temOutraTarefa = $stmtVerifica->fetchColumn();

    if ($temOutraTarefa > 0) {
        echo "ja_tem_tarefa_ativa";
        exit();
    }


    // 1. Obter pausa sem retorno
    $stmt = $ligacao->prepare("
        SELECT id, data_pausa 
        FROM pausas_tarefas 
        WHERE tarefa_id = ? AND funcionario = ? AND data_retorno IS NULL 
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$idTarefa, $utilizador]);
    $pausa = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($pausa) {
        $idPausa     = $pausa['id'];
        $dataPausa   = new DateTime($pausa['data_pausa'], new DateTimeZone('Europe/Lisbon'));
        $dataRetorno = new DateTime($horaAgora, new DateTimeZone('Europe/Lisbon'));

        // 2. Calcular duração da pausa
        $intervalo   = $dataPausa->diff($dataRetorno);
        $tempoPausa  = $intervalo->format('%H:%I:%S');

        // 3. Finalizar pausa
        $stmtUpdate = $ligacao->prepare("
            UPDATE pausas_tarefas 
            SET data_retorno = ?, tempo_pausa = ?
            WHERE id = ?
        ");
        $stmtUpdate->execute([$horaAgora, $tempoPausa, $idPausa]);

        // 4. Somar tempo de pausa no registo diário
        $stmtRD = $ligacao->prepare("
            SELECT tempo_pausa 
            FROM registo_diario 
            WHERE id_tarefa = ? AND utilizador = ? AND data_trabalho = ?
        ");
        $stmtRD->execute([$idTarefa, $utilizador, $dataHoje]);
        $tempoAnterior = $stmtRD->fetchColumn() ?? '00:00:00';

        // Função para somar tempos
        function somarTempos($t1, $t2) {
            $segundos = strtotime("1970-01-01 $t1 UTC") + strtotime("1970-01-01 $t2 UTC");
            return gmdate("H:i:s", $segundos);
        }

        $tempoTotal = somarTempos($tempoAnterior, $tempoPausa);

        // 5. Atualizar registo diário
        $stmtUpdateRD = $ligacao->prepare("
            UPDATE registo_diario 
            SET tempo_pausa = ? 
            WHERE id_tarefa = ? AND utilizador = ? AND data_trabalho = ?
        ");
        $stmtUpdateRD->execute([$tempoTotal, $idTarefa, $utilizador, $dataHoje]);
    }

    // 6. Atualiza a tarefa para reativar cronômetro
    $stmtTarefa = $ligacao->prepare("
        UPDATE tarefas 
        SET estado_cronometro = 'ativa', 
            data_inicio_cronometro = NOW(), 
            ultima_modificacao = NOW()
        WHERE id = ?
    ");
    $stmtTarefa->execute([$idTarefa]);

    echo "ok";

} catch (PDOException $e) {
    http_response_code(500);
    echo "Erro ao retomar tarefa: " . $e->getMessage();
}
