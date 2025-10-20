<?php
session_start();
require_once '../config_bd.php';

// Verifica se o utilizador está autenticado
if (!isset($_SESSION['utilizador_logado'])) {
    echo "Utilizador não autenticado.";
    exit;
}

$utilizador = $_SESSION['utilizador_logado'];

try {

    // ✅ 1. VERIFICA se o dia já foi finalizado hoje e precisa ser reaberto
    $stmtFinalizadoHoje = $ligacao->prepare("
        SELECT id, tarefa_id 
        FROM finalizar_dia 
        WHERE utilizador = ? 
        AND DATE(datahora_fimdedia) = CURDATE()
        ORDER BY datahora_fimdedia DESC
        LIMIT 1
    ");
    $stmtFinalizadoHoje->execute([$utilizador]);
    $registoHoje = $stmtFinalizadoHoje->fetch(PDO::FETCH_ASSOC);

    if ($registoHoje) {
        // 1.1 Anula hora de saída
        $stmtApagaSaida = $ligacao->prepare("
            UPDATE utilizador_entradaesaida
            SET hora_saida = NULL
            WHERE utilizador = ? AND data = CURDATE()
        ");
        $stmtApagaSaida->execute([$utilizador]);

        // 1.2 Regista reabertura do dia
        $stmtReabreFinalizar = $ligacao->prepare("
            UPDATE finalizar_dia
            SET datahora_fimdedia = NULL
            WHERE utilizador=? AND DATE(datahora_fimdedia)=CURDATE()
        ");
        $stmtReabreFinalizar->execute([$utilizador]);

        // 1.3 Reativa a tarefa anterior (se existir)
        if (!empty($registoHoje['tarefa_id'])) {
            $stmtUpdateTarefa = $ligacao->prepare("
                UPDATE tarefas
                SET estado_cronometro = 'ativa',
                    data_inicio_cronometro = NOW(),
                    ultima_modificacao = NOW()
                WHERE id = ?
            ");
            $stmtUpdateTarefa->execute([$registoHoje['tarefa_id']]);
        }

        // 1.4 Restaura pausas temporárias
        $stmtTemporarias = $ligacao->prepare("SELECT * FROM pausas_temporarias WHERE utilizador = ?");
        $stmtTemporarias->execute([$utilizador]);
        $temporarias = $stmtTemporarias->fetchAll(PDO::FETCH_ASSOC);

        foreach ($temporarias as $pt) {
            $idTarefaTemp = $pt['tarefa_id'];
            $motivoOriginal = $pt['tipo_original'];
            $segundosBackup = $pt['data_backup'];

            $stmtNovaPausa = $ligacao->prepare("
                INSERT INTO pausas_tarefas (tarefa_id, motivo_id, funcionario, data_pausa)
                VALUES (?, ?, ?, NOW() - INTERVAL ? SECOND)
            ");
            $stmtNovaPausa->execute([$idTarefaTemp, $motivoOriginal, $utilizador, $segundosBackup]);

            $stmtAtualizaEstado = $ligacao->prepare("
                UPDATE tarefas
                SET estado_cronometro = 'pausa',
                    estado = 'pendente',
                    ultima_modificacao = NOW()
                WHERE id = ?
            ");
            $stmtAtualizaEstado->execute([$idTarefaTemp]);
        }

        // 1.5 Limpa pausas temporárias
        $stmtLimpaTemporarias = $ligacao->prepare("DELETE FROM pausas_temporarias WHERE utilizador = ?");
        $stmtLimpaTemporarias->execute([$utilizador]);


        // 1.6 Limpa informação de pausas restritivas da sessão para evitar bloqueios indevidos
        unset($_SESSION['tarefa_pausada_por_pararcontadores']);
        unset($_SESSION['tarefa_pausada_por_semopcao']);

        // 1.7 Redireciona com mensagem de sucesso
        header("Location: tarefas.php?mensagem=Dia reaberto com sucesso");
        exit;
    }


    // 1. Verifica se já existe um registo de entrada hoje
    $stmtVerifica = $ligacao->prepare("
        SELECT COUNT(*) 
        FROM utilizador_entradaesaida 
        WHERE utilizador = ? AND data = CURDATE()
    ");
    $stmtVerifica->execute([$utilizador]);
    $existe = $stmtVerifica->fetchColumn();

    // Se ainda não iniciou o dia
    if ($existe == 0) {
        // 2. Inserir nova entrada com data e hora atuais
        $stmtInserir = $ligacao->prepare("
            INSERT INTO utilizador_entradaesaida (utilizador, data, hora_entrada)
            VALUES (?, CURDATE(), CURTIME())
        ");
        $stmtInserir->execute([$utilizador]);


        $stmtUpdate=$ligacao->prepare("
            UPDATE finalizar_dia
            SET datahora_iniciodiaseguinte=NOW()
            WHERE utilizador=? AND datahora_iniciodiaseguinte IS NULL
        ");
        $stmtUpdate->execute([$utilizador]);

        // 3. Verifica se o último dia foi finalizado e ainda não reaberto
        $stmtDiaFechadoNaoReaberto = $ligacao->prepare("
            SELECT * FROM finalizar_dia
            WHERE utilizador = ?
            ORDER BY datahora_fimdedia DESC
            LIMIT 1
        ");
        $stmtDiaFechadoNaoReaberto->execute([$utilizador]);
        $registoAnterior = $stmtDiaFechadoNaoReaberto->fetch(PDO::FETCH_ASSOC);

        // Se encontrou registo do dia anterior com tarefa associada
        if ($registoAnterior && !empty($registoAnterior['tarefa_id'])) {
            $idTarefa = $registoAnterior['tarefa_id'];

            // 4. Reativar a tarefa anterior (restaura o cronómetro com base no tempo anterior)
            $stmtUpdateTarefas = $ligacao->prepare("
                UPDATE tarefas
                SET estado_cronometro = 'ativa', 
                    data_inicio_cronometro = NOW() - INTERVAL TIME_TO_SEC(tempo_decorrido_utilizador) SECOND, 
                    ultima_modificacao = NOW()
                WHERE id = ?
            ");
            $stmtUpdateTarefas->execute([$idTarefa]);

            // 5. Recuperar pausas temporárias para este utilizador
            $stmtTemporarias = $ligacao->prepare("SELECT * FROM pausas_temporarias WHERE utilizador = ?");
            $stmtTemporarias->execute([$utilizador]);
            $temporarias = $stmtTemporarias->fetchAll(PDO::FETCH_ASSOC);

            foreach ($temporarias as $pt) {
                $idTarefaTemp = $pt['tarefa_id'];
                $motivoOriginal = $pt['tipo_original'];
                $segundosBackup = $pt['data_backup'];

                // 5.1 Recria a pausa original com hora de início ajustada ao backup
                $stmtNovaPausa = $ligacao->prepare("
                    INSERT INTO pausas_tarefas (tarefa_id, motivo_id, funcionario, data_pausa)
                    VALUES (?, ?, ?, NOW() - INTERVAL ? SECOND)
                ");
                $stmtNovaPausa->execute([$idTarefaTemp, $motivoOriginal, $utilizador, $segundosBackup]);

                // 5.2 Atualiza estado da tarefa para 'pausa' e 'pendente'
                $stmtAtualizaEstado = $ligacao->prepare("
                    UPDATE tarefas
                    SET estado_cronometro = 'pausa',
                        estado = 'pendente',
                        ultima_modificacao = NOW()
                    WHERE id = ?
                ");
                $stmtAtualizaEstado->execute([$idTarefaTemp]);

                $stmt = $ligacao->prepare("
                    SELECT id 
                    FROM departamento_tarefa
                    WHERE data_saida IS NULL AND tarefa_id = ?
                ");
                $stmt->execute([$idTarefaTemp]);
                $idTarefaEsp = $stmt->fetch(PDO::FETCH_ASSOC);

                // Registar tempo_em_espera se existir registo em departamento_tarefa com data_saida NULL
                if ($idTarefaEsp) {
                    $stmtAssociar = $ligacao->prepare("
                        UPDATE departamento_tarefa
                        SET tempo_em_espera = TIMEDIFF(NOW(), data_entrada)
                        WHERE id = ? AND data_saida IS NULL
                    ");
                    $stmtAssociar->execute([$idTarefaEsp['id']]);
                }
            }

            // 6. Limpa as entradas da tabela de pausas temporárias
            $stmtLimpa = $ligacao->prepare("DELETE FROM pausas_temporarias WHERE utilizador = ?");
            $stmtLimpa->execute([$utilizador]);

            // 7. Regista o início do novo dia no mesmo registo da tabela finalizar_dia
            $registaInicioDia = $ligacao->prepare("
                UPDATE finalizar_dia
                SET tarefa_id = ?,
                    datahora_iniciodiaseguinte = NOW()
                WHERE utilizador = ? AND datahora_iniciodiaseguinte IS NULL
            ");
            $registaInicioDia->execute([$registoAnterior['tarefa_id'], $utilizador]);

            unset($_SESSION['tarefa_pausada_por_pararcontadores']);
            unset($_SESSION['tarefa_pausada_por_semopcao']);
        }else if(empty($registoAnterior['tarefa_id'])){
            // 5. Recuperar pausas temporárias para este utilizador
            $stmtTemporarias = $ligacao->prepare("SELECT * FROM pausas_temporarias WHERE utilizador = ?");
            $stmtTemporarias->execute([$utilizador]);
            $temporarias = $stmtTemporarias->fetchAll(PDO::FETCH_ASSOC);

            foreach ($temporarias as $pt) {
                $idTarefaTemp = $pt['tarefa_id'];
                $motivoOriginal = $pt['tipo_original'];
                $segundosBackup = $pt['data_backup'];

                // 5.1 Recria a pausa original com hora de início ajustada ao backup
                $stmtNovaPausa = $ligacao->prepare("
                    INSERT INTO pausas_tarefas (tarefa_id, motivo_id, funcionario, data_pausa)
                    VALUES (?, ?, ?, NOW() - INTERVAL ? SECOND)
                ");
                $stmtNovaPausa->execute([$idTarefaTemp, $motivoOriginal, $utilizador, $segundosBackup]);

                // 5.2 Atualiza estado da tarefa para 'pausa' e 'pendente'
                $stmtAtualizaEstado = $ligacao->prepare("
                    UPDATE tarefas
                    SET estado_cronometro = 'pausa',
                        estado = 'pendente',
                        ultima_modificacao = NOW()
                    WHERE id = ?
                ");
                $stmtAtualizaEstado->execute([$idTarefaTemp]);

                $stmt = $ligacao->prepare("
                    SELECT id 
                    FROM departamento_tarefa
                    WHERE data_saida IS NULL AND tarefa_id = ?
                ");
                $stmt->execute([$idTarefaTemp]);
                $idTarefaEsp = $stmt->fetch(PDO::FETCH_ASSOC);

                // Registar tempo_em_espera se existir registo em departamento_tarefa com data_saida NULL
                if ($idTarefaEsp) {
                    $stmtAssociar = $ligacao->prepare("
                        UPDATE departamento_tarefa
                        SET tempo_em_espera = TIMEDIFF(NOW(), data_entrada)
                        WHERE id = ? AND data_saida IS NULL
                    ");
                    $stmtAssociar->execute([$idTarefaEsp['id']]);
                }
                }

            // 6. Limpa as entradas da tabela de pausas temporárias
            $stmtLimpa = $ligacao->prepare("DELETE FROM pausas_temporarias WHERE utilizador = ?");
            $stmtLimpa->execute([$utilizador]);

            unset($_SESSION['tarefa_pausada_por_pararcontadores']);
            unset($_SESSION['tarefa_pausada_por_semopcao']);
        }
    } else {
        // 1. Buscar tarefa_id antes de limpar
        $stmt = $ligacao->prepare("
            SELECT tarefa_id 
            FROM finalizar_dia
            WHERE utilizador = ? 
            AND DATE(datahora_iniciodiaseguinte) = CURDATE()
            LIMIT 1
        ");
        $stmt->execute([$utilizador]);
        $tarefaId = $stmt->fetchColumn();

        // 2. Reverte data de reabertura de hoje
        $stmt = $ligacao->prepare("
            UPDATE finalizar_dia
            SET datahora_iniciodiaseguinte = NULL
            WHERE utilizador = ? 
            AND DATE(datahora_iniciodiaseguinte) = CURDATE()
        ");
        $stmt->execute([$utilizador]);

        // 3. Reverte hora de saída de hoje
        $stmt = $ligacao->prepare("
            UPDATE utilizador_entradaesaida
            SET hora_saida = NULL
            WHERE utilizador = ? 
            AND data = CURDATE()
        ");
        $stmt->execute([$utilizador]);

        // 4. Recupera e aplica as pausas temporárias
        $stmtTemporarias = $ligacao->prepare("SELECT * FROM pausas_temporarias WHERE utilizador = ?");
        $stmtTemporarias->execute([$utilizador]);
        $temporarias = $stmtTemporarias->fetchAll(PDO::FETCH_ASSOC);

        foreach ($temporarias as $pt) {
            $idTarefaTemp = $pt['tarefa_id'];
            $motivoOriginal = $pt['tipo_original'];
            $segundosBackup = $pt['data_backup'];

            $stmtNovaPausa = $ligacao->prepare("
                INSERT INTO pausas_tarefas (tarefa_id, motivo_id, funcionario, data_pausa)
                VALUES (?, ?, ?, NOW() - INTERVAL ? SECOND)
            ");
            $stmtNovaPausa->execute([$idTarefaTemp, $motivoOriginal, $utilizador, $segundosBackup]);

            $stmtAtualizaEstado = $ligacao->prepare("
                UPDATE tarefas
                SET estado_cronometro = 'pausa',
                    estado = 'pendente',
                    ultima_modificacao = NOW()
                WHERE id = ?
            ");
            $stmtAtualizaEstado->execute([$idTarefaTemp]);
        }

        // 5. Limpa a tabela de pausas temporárias
        $stmtLimpa = $ligacao->prepare("DELETE FROM pausas_temporarias WHERE utilizador = ?");
        $stmtLimpa->execute([$utilizador]);

        // 6. Registra novamente o início do dia se a tarefa estiver definida
        if ($tarefaId) {
            $registaInicioDia = $ligacao->prepare("
                UPDATE finalizar_dia
                SET tarefa_id = ?,
                    datahora_iniciodiaseguinte = NOW()
                WHERE utilizador = ?
                AND datahora_iniciodiaseguinte IS NULL
            ");
            $registaInicioDia->execute([$tarefaId, $utilizador]);
        }
    }

    unset($_SESSION['tarefa_pausada_por_pararcontadores']);
    unset($_SESSION['tarefa_pausada_por_semopcao']);
    header("Location: tarefas.php?mensagem=Dia iniciado com sucesso");
    exit;
} catch (PDOException $e) {
    echo "Erro ao iniciar o dia: " . $e->getMessage();
}
