<?php
session_start();
require_once '../config_bd.php';

if (!isset($_SESSION['utilizador_logado'])) {
    echo "Utilizador não autenticado.";
    exit;
}

$utilizador = $_SESSION['utilizador_logado'];
$dataHoje = date('Y-m-d');
$horaAgora = date('H:i:s');
$tempo = $_POST['tempo'] ?? '00:00:00';
$idTarefa = $_POST['id'] ?? null;

if ($idTarefa !== null) {
    // Atualiza o tempo da tarefa antes de mudar o estado
    $stmt = $ligacao->prepare("
        UPDATE tarefas
        SET tempo_decorrido_utilizador = ?,
            data_inicio_cronometro = NULL
        WHERE id = ? AND utilizador = ?
    ");
    $stmt->execute([$tempo, $idTarefa, $utilizador]);
}


// Funções auxiliares
function somarTempos($t1, $t2) {
    $t1_sec = strtotime("1970-01-01 $t1 UTC");
    $t2_sec = strtotime("1970-01-01 $t2 UTC");
    return gmdate("H:i:s", $t1_sec + $t2_sec);
}

function subtrairTempos($t1, $t2) {
    $t1_sec = strtotime("1970-01-01 $t1 UTC");
    $t2_sec = strtotime("1970-01-01 $t2 UTC");
    return gmdate("H:i:s", max(0, $t1_sec - $t2_sec));
}

try {
    // Verifica tarefa em execução
    $stmt = $ligacao->prepare("
        SELECT * FROM tarefas
        WHERE estado_cronometro='ativa' AND utilizador=?
    ");
    $stmt->execute([$utilizador]);
    $tarefaEmExecucao = $stmt->fetch(PDO::FETCH_ASSOC);

    // Carrega todas as tarefas (não concluídas) e últimas pausas
    $stmtTarefas = $ligacao->prepare("
        WITH ultimas_pausas AS (
            SELECT
                pt.tarefa_id,
                mp.tipo,
                mp.id AS motivo_id,
                pt.data_pausa,
                ROW_NUMBER() OVER (PARTITION BY pt.tarefa_id ORDER BY pt.data_pausa DESC) AS rn
            FROM pausas_tarefas pt
            INNER JOIN motivos_pausa mp ON pt.motivo_id = mp.id
        )
        SELECT 
            t.*, 
            COALESCE(SUM(
                CASE 
                    WHEN mp.tipo != 'PararContadores' THEN TIME_TO_SEC(p.tempo_pausa)
                    ELSE 0
                END
            ), 0) AS total_pausa_segundos,
            up.motivo_id AS motivo_id,
            up.tipo AS tipo_motivo,
            up.data_pausa AS inicio_pausa
        FROM tarefas t
        LEFT JOIN pausas_tarefas p ON t.id = p.tarefa_id
        LEFT JOIN motivos_pausa mp ON p.motivo_id = mp.id
        LEFT JOIN ultimas_pausas up ON up.tarefa_id = t.id AND up.rn = 1
        WHERE t.utilizador = ? AND t.estado != 'concluida' AND t.estado != 'eliminada'
        GROUP BY t.id
    ");
    $stmtTarefas->execute([$utilizador]);
    $tarefas = $stmtTarefas->fetchAll(PDO::FETCH_ASSOC);

    // Se não houver nenhuma tarefa, sai
    if (!$tarefaEmExecucao && count($tarefas) === 0) {

        // Verifica se existe registo pendente de finalização
        $stmtVerificacao = $ligacao->prepare("
            SELECT * FROM finalizar_dia
            WHERE datahora_fimdedia IS NULL AND datahora_iniciodiaseguinte IS NULL AND utilizador = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmtVerificacao->execute([$utilizador]);
        $registoPendente = $stmtVerificacao->fetch(PDO::FETCH_ASSOC);

        if ($registoPendente) {
            // Atualiza o registo existente
            $stmtFimDia = $ligacao->prepare("
                UPDATE finalizar_dia 
                SET tarefa_id = NULL,
                    datahora_fimdedia = NOW()
                WHERE id = ?
            ");
            $stmtFimDia->execute([$registoPendente['id']]);
        } else {
            // Cria novo registo
            $stmtFimDia = $ligacao->prepare("
                INSERT INTO finalizar_dia (utilizador, tarefa_id, datahora_fimdedia)
                VALUES (?, NULL, NOW())
            ");
            $stmtFimDia->execute([$utilizador]);
        }

        // Atualiza hora de saída
        $stmtSaida = $ligacao->prepare("
            UPDATE utilizador_entradaesaida
            SET hora_saida = NOW()
            WHERE utilizador = ? AND data = CURDATE()
        ");
        $stmtSaida->execute([$utilizador]);

        echo "sucesso";
        exit;
    }


    // Trata todas as tarefas pausadas
    foreach ($tarefas as $t) {
        $idTarefa = $t['id'];

        // Ignora a tarefa em execução (vai ser tratada abaixo)
        if ($tarefaEmExecucao && $tarefaEmExecucao['id'] == $idTarefa) {
            continue;
        }

        $tempoAnterior = $t['tempo_decorrido'] ?? '00:00:00';
        $tempoAtual = $t['tempo_decorrido_utilizador'] ?? '00:00:00';
        $tempoNovoTotal = somarTempos($tempoAnterior, $tempoAtual);

        // Finaliza qualquer pausa aberta
        $stmtFinalizaPausa = $ligacao->prepare("
            UPDATE pausas_tarefas
            SET data_retorno = NOW()
            WHERE tarefa_id = ? AND funcionario = ? AND data_retorno IS NULL
        ");
        $stmtFinalizaPausa->execute([$idTarefa, $utilizador]);

        // Regista pausa temporária se existir motivo
        if ($t['tipo_motivo']) {
            $stmtPausasTemporarias = $ligacao->prepare("
                INSERT INTO pausas_temporarias (tarefa_id, utilizador, tipo_original, data_backup)
                VALUES (?, ?, ?, ?)
            ");
            $stmtPausasTemporarias->execute([
                $idTarefa,
                $utilizador,
                $t['motivo_id'],
                $t['total_pausa_segundos']
            ]);
        }

        // Atualiza a tarefa
        $stmtUpdateTarefa = $ligacao->prepare("
            UPDATE tarefas
            SET tempo_decorrido = ?,
                tempo_decorrido_utilizador = '00:00:00',
                ultima_modificacao = NOW(),
                estado_cronometro = 'inativa',
                data_fim = NOW(),
                data_inicio_cronometro = NULL
            WHERE id = ?
        ");
        $stmtUpdateTarefa->execute([$tempoNovoTotal, $idTarefa]);
    }

    // Atualiza a tarefa em execução (caso exista)
    if ($tarefaEmExecucao) {
        $idTarefaExec = $tarefaEmExecucao['id'];
        $tempoAnterior = $tarefaEmExecucao['tempo_decorrido'] ?? '00:00:00';
        $tempoNovoTotal = somarTempos($tempoAnterior, $tempo);

        $stmtAtualiza = $ligacao->prepare("
            UPDATE tarefas 
            SET tempo_decorrido = ?,
                tempo_decorrido_utilizador = '00:00:00',
                ultima_modificacao = NOW(),
                estado_cronometro = 'inativa', 
                data_fim = NOW(),
                data_inicio_cronometro = NULL
            WHERE id = ?
        ");
        $stmtAtualiza->execute([$tempoNovoTotal, $idTarefaExec]);

        $tarefaEmExecucao['tempo_decorrido_utilizador'] = '00:00:00';

        // Atualiza o registo diário
        $stmtInicio = $ligacao->prepare("
            SELECT tempo_inicio_tarefa 
            FROM registo_diario 
            WHERE id_tarefa = ? AND utilizador = ? AND data_trabalho = ?
        ");
        $stmtInicio->execute([$idTarefaExec, $utilizador, $dataHoje]);
        $tempoInicioTarefa = $stmtInicio->fetchColumn();

        if ($tempoInicioTarefa) {
            $tempoAcumulado = subtrairTempos($tempoNovoTotal, $tempoInicioTarefa);

            $stmtAtualizaDiario = $ligacao->prepare("
                UPDATE registo_diario 
                SET tempo_fim_tarefa = ?, tempo_acumulado = ?, fim_tarefa = ? 
                WHERE id_tarefa = ? AND utilizador = ? AND data_trabalho = ?
            ");
            $stmtAtualizaDiario->execute([
                $tempoNovoTotal,
                $tempoAcumulado,
                $horaAgora,
                $idTarefaExec,
                $utilizador,
                $dataHoje
            ]);
        }
    }


    // Verifica se há finalização pendente (sem fim nem reabertura)
    $stmtVerificacao = $ligacao->prepare("
        SELECT * FROM finalizar_dia
        WHERE datahora_fimdedia IS NULL AND datahora_iniciodiaseguinte IS NULL AND utilizador = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmtVerificacao->execute([$utilizador]);
    $registoPendente = $stmtVerificacao->fetch(PDO::FETCH_ASSOC);

    if ($registoPendente) {
        // FOI PAUSADA A FINALIZAÇÃO DO DIA ANTERIOR — atualiza esse registo
        $stmtFimDia = $ligacao->prepare("
            UPDATE finalizar_dia 
            SET tarefa_id = ?, 
                datahora_fimdedia = NOW()
            WHERE id = ?
        ");
        $stmtFimDia->execute([$tarefaEmExecucao['id'], $registoPendente['id']]);
    } else {
        // Regista normalmente o fim do dia
        $stmtFimDia = $ligacao->prepare("
            INSERT INTO finalizar_dia (utilizador, tarefa_id, datahora_fimdedia)
            VALUES (?, ?, NOW())
        ");
        $stmtFimDia->execute([$utilizador, $tarefaEmExecucao['id']]);
    }

    $stmtSaida= $ligacao->prepare("
        UPDATE utilizador_entradaesaida
        SET hora_saida=NOW()
        WHERE utilizador=? AND data = CURDATE()
    ");
    $stmtSaida->execute([$utilizador]);

    echo "sucesso";
    exit;


} catch (PDOException $e) {
    echo "Erro ao finalizar tarefas: " . $e->getMessage();
}
