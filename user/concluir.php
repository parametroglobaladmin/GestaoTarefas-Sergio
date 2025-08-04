<?php
require_once '../config_bd.php';
session_start();

if (!isset($_SESSION['utilizador_logado'], $_GET['id'], $_GET['tempo']) || empty($_GET['tempo'])) {
    header("Location: tarefas.php?erro=Dados%20incompletos");
    exit;
}


$idTarefa = intval($_GET['id']);
$tempoAtual = $_GET['tempo']; // Tempo vindo do cronômetro (ex: 01:25:10)
$utilizador = $_SESSION['utilizador_logado'];
$dataHoje = date('Y-m-d');
$horaAgora = date('H:i:s');

function somarTempos($t1, $t2) {
    $t1_sec = strtotime("1970-01-01 $t1 UTC");
    $t2_sec = strtotime("1970-01-01 $t2 UTC");
    return gmdate("H:i:s", $t1_sec + $t2_sec);
}

function subtrairTempos($t1, $t2) {
    $t1_sec = strtotime("1970-01-01 $t1 UTC");
    $t2_sec = strtotime("1970-01-01 $t2 UTC");
    $diff = max(0, $t1_sec - $t2_sec);
    return gmdate("H:i:s", $diff);
}

try {
    
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
        exit;
    }

    // Atualiza tempo do utilizador com o tempo mais recente do cronômetro
    $stmtUpdateTempUser = $ligacao->prepare("
        UPDATE tarefas 
        SET tempo_decorrido_utilizador = ?, ultima_modificacao = NOW(), data_inicio_cronometro = NULL 
        WHERE id = ?
    ");
    $stmtUpdateTempUser->execute([$tempoAtual, $idTarefa]);

    // Buscar tempos atuais da tarefa
    $stmt = $ligacao->prepare("SELECT tempo_decorrido, tempo_decorrido_utilizador FROM tarefas WHERE id = ?");
    $stmt->execute([$idTarefa]);
    $tarefa = $stmt->fetch(PDO::FETCH_ASSOC);

    $tempoGlobalAnterior = $tarefa['tempo_decorrido'] ?? '00:00:00';
    $tempoUtilizador = $tarefa['tempo_decorrido_utilizador'] ?? '00:00:00';

    // Somar tempo do utilizador ao total
    $tempoFinalTotal = somarTempos($tempoGlobalAnterior, $tempoUtilizador);

    // Finalizar pausa ativa (se existir)
    $stmtVerificaEstado = $ligacao->prepare("SELECT estado_cronometro FROM tarefas WHERE id = ?");
    $stmtVerificaEstado->execute([$idTarefa]);
    $estadoCronometro = $stmtVerificaEstado->fetchColumn();

    if ($estadoCronometro === 'pausa') {
        $stmtFinalizaPausa = $ligacao->prepare("
            UPDATE pausas_tarefas
            SET data_retorno = NOW()
            WHERE tarefa_id = ? AND funcionario = ? AND data_retorno IS NULL
        ");
        $stmtFinalizaPausa->execute([$idTarefa, $utilizador]);
    }

    // Atualiza estado da tarefa para concluída
    $update = $ligacao->prepare("
        UPDATE tarefas 
        SET estado = 'concluida',
            tempo_decorrido = :tempo,
            tempo_decorrido_utilizador = '00:00:00',
            data_fim = NOW(),
            ultima_modificacao = NOW(),
            estado_cronometro = 'inativa',
            data_inicio_cronometro = NULL
        WHERE id = :id
    ");
    $update->execute([
        ':tempo' => $tempoFinalTotal,
        ':id' => $idTarefa
    ]);
    
    //ATUALIZAR REGISTO INICIAL DO DEPARTAMENTO TAREFA DESTE DEPARTAMENTO
    $stmtAssociar = $ligacao->prepare("
        UPDATE departamento_tarefa
        SET data_saida = NOW()
        WHERE tarefa_id = ? AND data_saida IS NULL
    ");
    $stmtAssociar->execute([$idTarefa]);

    // Atualiza registo diário se existir
    $stmtInicio = $ligacao->prepare("
        SELECT tempo_inicio_tarefa 
        FROM registo_diario 
        WHERE id_tarefa = ? AND utilizador = ? AND data_trabalho = ?
    ");
    $stmtInicio->execute([$idTarefa, $utilizador, $dataHoje]);
    $tempoInicioTarefa = $stmtInicio->fetchColumn();

    if ($tempoInicioTarefa) {
        $tempoAcumulado = subtrairTempos($tempoAtual, $tempoInicioTarefa);

        $stmtUpdateReg = $ligacao->prepare("
            UPDATE registo_diario 
            SET tempo_fim_tarefa = ?, tempo_acumulado = ?, fim_tarefa = ?
            WHERE id_tarefa = ? AND utilizador = ? AND data_trabalho = ?
        ");
        $stmtUpdateReg->execute([
            $tempoAcumulado,
            $tempoAcumulado,
            $horaAgora,
            $idTarefa,
            $utilizador,
            $dataHoje
        ]);
    }
    echo "";
    exit;

} catch (PDOException $e) {
    die("Erro ao concluir tarefa: " . $e->getMessage());
}

header('Location: tarefas.php');
exit();
