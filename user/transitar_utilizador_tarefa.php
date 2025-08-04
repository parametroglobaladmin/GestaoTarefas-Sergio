<?php
session_start();
require_once '../config_bd.php';


function somarTempos($t1, $t2) {
    $t1_sec = strtotime("1970-01-01 $t1 UTC");
    $t2_sec = strtotime("1970-01-01 $t2 UTC");
    $soma = $t1_sec + $t2_sec;
    return gmdate("H:i:s", $soma);
}

$utilizador = $_SESSION["utilizador_logado"];
$departamento = $_POST['departamento'] ?? null;
$funcionario = $_POST['funcionario'] ?? null;

$stmt=$ligacao->prepare("
    SELECT * FROM funcionarios WHERE utilizador=?
");
$stmt->execute([$utilizador]);
$utilizadorLogado = $stmt->fetch(PDO::FETCH_ASSOC);

if ((!$departamento && $funcionario)  || (!$departamento && !$funcionario)) {
    exit("Dados incompletos.");
}else if($departamento && $funcionario){
    $stmt = $ligacao->prepare("SELECT * FROM funcionarios WHERE departamento=? AND utilizador=?");
    $stmt->execute([$departamento, $funcionario]);
    $utilizadorEncontrado = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$utilizadorEncontrado) {
        exit("O utilizador não pertence a esse departamento!");
    }

}


if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $id = $_POST['id'] ?? null;
    $utilizadorNovo=null;
    $utilizadorEncontrado = null;

    if ((!$departamento && $funcionario)  || (!$departamento && !$funcionario)) {
        exit("Dados incompletos.");
    } elseif ($departamento && $funcionario) {
        $stmt = $ligacao->prepare("SELECT * FROM funcionarios WHERE departamento=? AND utilizador=?");
        $stmt->execute([$departamento, $funcionario]);
        $utilizadorEncontrado = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$utilizadorEncontrado) {
            exit("O utilizador não pertence a esse departamento!");
        }
    }else{
        $funcionario=null;
    }

    $utilizadorNovo = $funcionario;

    if($utilizadorNovo===null){
///////////                                                                                     <---Comecar aqui!

        if ($departamento == $utilizadorLogado['departamento']) {
            exit("Não é possível transferir a tarefa para o mesmo departamento do utilizador atual. Para fazer tal operacao é necessario indicar o funcionário.");
        }
        // IR A TAREFAS METER NULL O UTILIZADOR E METER ESTADO EM ESPERA
        // 1. Buscar dados da tarefa
        $stmt = $ligacao->prepare("
            SELECT utilizador, tempo_decorrido, tempo_decorrido_utilizador 
            FROM tarefas 
            WHERE id = ?
        ");
        $stmt->execute([$id]);
        $tarefa = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tarefa) {
            http_response_code(404);
            echo "Tarefa não encontrada.";
            exit;
        }

        $utilizadorAntigo = $tarefa['utilizador'];
        $tempoDecorridoGlobal = $tarefa['tempo_decorrido'] ?? '00:00:00';
        $tempoDecorridoUtilizador = $tarefa['tempo_decorrido_utilizador'] ?? '00:00:00';

        $tempoAtualizadoGlobal = somarTempos($tempoDecorridoGlobal, $tempoDecorridoUtilizador);

        $stmt = $ligacao->prepare("
            UPDATE tarefas 
            SET tempo_decorrido = ?, 
                tempo_decorrido_utilizador = '00:00:00',
                utilizador = NULL,
                estado='espera',
                data_inicio_cronometro = NULL,
                estado_cronometro = 'inativa',
                data_fim = NULL
            WHERE id = ?
        ");
        $stmt->execute([$tempoAtualizadoGlobal, $id]);

        //FINALIZAR A TAREFA DE PAUSA DO ULTIMO UTILIZADOR(UTILIZADOR ANTIGO)
        // 4. Fechar pausas pendentes
        $stmt = $ligacao->prepare("
            UPDATE pausas_tarefas 
            SET data_retorno = NOW() 
            WHERE tarefa_id = ? AND data_retorno IS NULL
        ");
        $stmt->execute([$id]);

        //ATUALIZAR REGISTO INICIAL DO DEPARTAMENTO TAREFA DESTE DEPARTAMENTO
        $stmtAssociar = $ligacao->prepare("
            UPDATE departamento_tarefa
            SET data_saida = NOW()
            WHERE tarefa_id = ? AND data_saida IS NULL
        ");
        $stmtAssociar->execute([$id]);

        //CRIAR REGISTO INICIAL DO DEPARTAMENTO TAREFA DESTE DEPARTAMENTO QUE FOI PASSADO A TAREFA
        $stmtDepartment=$ligacao->prepare("
            INSERT INTO departamento_tarefa (departamento_id,tarefa_id,data_entrada)
            VALUES (?,?,NOW())
        ");
        $stmtDepartment->execute([$departamento,$id]);

        // 5. Registar transição com tempo do utilizador
        $stmt = $ligacao->prepare("
            INSERT INTO transicao_tarefas (tarefa_id, utilizador_antigo, utilizador_novo, dataHora_transicao, duracao_exec, departamento_novo)
            VALUES (?, ?, NULL, NOW(), ?,?)
        ");
        $stmt->execute([$id, $utilizadorAntigo, $tempoDecorridoUtilizador, $departamento]);

        echo "ok";
        exit;
    }else{
        // 1. Verifica se há registo em utilizador_entradaesaida para hoje
        $stmt = $ligacao->prepare("
            SELECT data 
            FROM utilizador_entradaesaida 
            WHERE utilizador = ? AND data = CURDATE()
            LIMIT 1
        ");
        $stmt->execute([$utilizadorNovo]);
        $entradaHoje = $stmt->fetch(PDO::FETCH_ASSOC);

        // 2. Verifica se existe finalização de dia para hoje
        $stmt = $ligacao->prepare("
            SELECT 1 
            FROM finalizar_dia 
            WHERE utilizador = ? AND DATE(datahora_fimdedia) = CURDATE()
            LIMIT 1
        ");
        $stmt->execute([$utilizadorNovo]);
        $finalizouHoje = $stmt->fetchColumn();

        // 3. Determinar se está ativo
        $estaAtivoHoje = $entradaHoje && !$finalizouHoje;
        if(!$estaAtivoHoje){
            if ($id && $utilizadorNovo) {
                try {
                    // 1. Buscar dados da tarefa
                    $stmt = $ligacao->prepare("
                        SELECT utilizador, tempo_decorrido, tempo_decorrido_utilizador 
                        FROM tarefas 
                        WHERE id = ?
                    ");
                    $stmt->execute([$id]);
                    $tarefa = $stmt->fetch(PDO::FETCH_ASSOC);

                    $stmt=$ligacao->prepare("
                        SELECT * FROM departamento_tarefa
                        WHERE tarefa_id=?
                    ");                    
                    $stmt->execute([$id]);
                    $tarefaDep = $stmt->fetch(PDO::FETCH_ASSOC);

                    $stmt=$ligacao->prepare("
                        SELECT departamento FROM funcionarios
                        WHERE utilizador=?
                    ");                    
                    $stmt->execute([$utilizadorNovo]);
                    $novoUtilizadorDep = $stmt->fetch(PDO::FETCH_ASSOC);

                    $novoUtilizadorDepId = $novoUtilizadorDep['departamento'] ?? null;
                    $tarefaDepId = $tarefaDep['departamento_id'] ?? null;   
                    
                        //ATUALIZAR REGISTO INICIAL DO DEPARTAMENTO TAREFA DESTE DEPARTAMENTO
                        $stmtAssociar = $ligacao->prepare("
                            UPDATE departamento_tarefa
                            SET data_saida = NOW()
                            WHERE tarefa_id = ? AND data_saida IS NULL
                        ");
                        $stmtAssociar->execute([$id]);

                        //IR A TABELA DEPARTAMENTO TAREFA
                        //CRIAR REGISTO INICIAL DO DEPARTAMENTO TAREFA DESTE DEPARTAMENTO QUE FOI PASSADO A TAREFA
                        $stmtDepartment=$ligacao->prepare("
                            INSERT INTO departamento_tarefa (utilizador,departamento_id,tarefa_id,data_entrada)
                            VALUES (?,?,?,NOW())
                        ");
                        $stmtDepartment->execute([$utilizadorNovo,$novoUtilizadorDepId,$id]);
                    

                    //LISTAGEM NA NOVA TABELA TEMPO DE ESPERA = 0

                    if (!$tarefa) {
                        http_response_code(404);
                        echo "Tarefa não encontrada.";
                        exit;
                    }

                    $utilizadorAntigo = $tarefa['utilizador'];
                    $tempoDecorridoGlobal = $tarefa['tempo_decorrido'] ?? '00:00:00';
                    $tempoDecorridoUtilizador = $tarefa['tempo_decorrido_utilizador'] ?? '00:00:00';

                    // 2. Somar tempo do utilizador ao tempo total da tarefa
                    $tempoAtualizadoGlobal = somarTempos($tempoDecorridoGlobal, $tempoDecorridoUtilizador);

                    // 3. Atualizar tarefa com novo tempo total e reset do tempo por utilizador
                    $stmt = $ligacao->prepare("
                        UPDATE tarefas 
                        SET tempo_decorrido = ?, 
                            tempo_decorrido_utilizador = '00:00:00',
                            utilizador = ?, 
                            data_inicio_cronometro = NULL,
                            estado_cronometro = 'inativa',
                            data_fim = NULL
                        WHERE id = ?
                    ");
                    $stmt->execute([$tempoAtualizadoGlobal, $utilizadorNovo, $id]);
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
                        WHERE t.id=? AND t.utilizador = ? AND t.estado != 'concluida' AND t.estado != 'eliminada'
                        GROUP BY t.id
                    ");
                    $stmtTarefas->execute([$id,$utilizadorNovo]);
                    $tarefa = $stmtTarefas->fetch(PDO::FETCH_ASSOC);


                    // 4. Fechar pausas pendentes
                    $stmt = $ligacao->prepare("
                        UPDATE pausas_tarefas 
                        SET data_retorno = NOW() 
                        WHERE tarefa_id = ? AND data_retorno IS NULL
                    ");
                    $stmt->execute([$id]);

                    // 3.1. Atualizar registo_diario para o novo utilizador (no dia atual)
                    $stmt = $ligacao->prepare("
                        UPDATE registo_diario
                        SET utilizador = ?
                        WHERE id_tarefa = ? AND data_trabalho = CURDATE()
                    ");
                    $stmt->execute([$utilizadorNovo, $id]);

                    // 4. Fechar pausas pendentes
                    $stmt = $ligacao->prepare("
                        INSERT INTO pausas_temporarias (tarefa_id, utilizador, tipo_original, data_backup)
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $id,
                        $utilizadorNovo,
                        $tarefa['motivo_id'],
                        $tarefa['total_pausa_segundos']
                    ]);

                    // 5. Registar transição com tempo do utilizador
                    $stmt = $ligacao->prepare("
                        INSERT INTO transicao_tarefas (tarefa_id, utilizador_antigo, utilizador_novo, dataHora_transicao, duracao_exec, departamento_novo)
                        VALUES (?, ?, ?, NOW(), ?,?)
                    ");
                    $stmt->execute([$id, $utilizadorAntigo, $utilizadorNovo, $tempoDecorridoUtilizador, $novoUtilizadorDepId]);

                    echo "ok";
                    exit;

                } catch (PDOException $e) {
                    http_response_code(500);
                    echo "Erro ao transitar tarefa: " . $e->getMessage();
                    exit;
                }
            } else {
                http_response_code(400);
                echo "Preencha todos os campos.";
                exit;
            }
        }else{
            if ($id && $utilizadorNovo) {
                try {
                    // 1. Buscar dados da tarefa
                    $stmt = $ligacao->prepare("
                        SELECT utilizador, tempo_decorrido, tempo_decorrido_utilizador 
                        FROM tarefas 
                        WHERE id = ?
                    ");
                    $stmt->execute([$id]);
                    $tarefa = $stmt->fetch(PDO::FETCH_ASSOC);

                    $stmt=$ligacao->prepare("
                        SELECT * FROM departamento_tarefa
                        WHERE tarefa_id=?
                    ");                    
                    $stmt->execute([$id]);
                    $tarefaDep = $stmt->fetch(PDO::FETCH_ASSOC);

                    $stmt=$ligacao->prepare("
                        SELECT departamento FROM funcionarios
                        WHERE utilizador=?
                    ");                    
                    $stmt->execute([$utilizadorNovo]);
                    $novoUtilizadorDep = $stmt->fetch(PDO::FETCH_ASSOC);

                    $novoUtilizadorDepId = $novoUtilizadorDep['departamento'] ?? null;
                    $tarefaDepId = $tarefaDep['departamento_id'] ?? null;   
                    
                        //ATUALIZAR REGISTO INICIAL DO DEPARTAMENTO TAREFA DESTE DEPARTAMENTO
                        $stmtAssociar = $ligacao->prepare("
                            UPDATE departamento_tarefa
                            SET data_saida = NOW()
                            WHERE tarefa_id = ? AND data_saida IS NULL
                        ");
                        $stmtAssociar->execute([$id]);

                        //IR A TABELA DEPARTAMENTO TAREFA
                        //CRIAR REGISTO INICIAL DO DEPARTAMENTO TAREFA DESTE DEPARTAMENTO QUE FOI PASSADO A TAREFA
                        $stmtDepartment=$ligacao->prepare("
                            INSERT INTO departamento_tarefa (utilizador,departamento_id,tarefa_id,data_entrada)
                            VALUES (?,?,?,NOW())
                        ");
                        $stmtDepartment->execute([$utilizadorNovo,$novoUtilizadorDepId,$id]);
                    
                    //LISTAGEM NA NOVA TABELA TEMPO DE ESPERA = 0

                    if (!$tarefa) {
                        http_response_code(404);
                        echo "Tarefa não encontrada.";
                        exit;
                    }

                    $utilizadorAntigo = $tarefa['utilizador'];
                    $tempoDecorridoGlobal = $tarefa['tempo_decorrido'] ?? '00:00:00';
                    $tempoDecorridoUtilizador = $tarefa['tempo_decorrido_utilizador'] ?? '00:00:00';

                    // 2. Somar tempo do utilizador ao tempo total da tarefa
                    $tempoAtualizadoGlobal = somarTempos($tempoDecorridoGlobal, $tempoDecorridoUtilizador);

                    // 3. Atualizar tarefa com novo tempo total e reset do tempo por utilizador
                    $stmt = $ligacao->prepare("
                        UPDATE tarefas 
                        SET tempo_decorrido = ?, 
                            tempo_decorrido_utilizador = '00:00:00',
                            utilizador = ?, 
                            data_inicio_cronometro = NOW(),
                            estado_cronometro = 'pausa',
                            data_fim = NULL
                        WHERE id = ?
                    ");
                    $stmt->execute([$tempoAtualizadoGlobal, $utilizadorNovo, $id]);

                    // 3.1. Atualizar registo_diario para o novo utilizador (no dia atual)
                    $stmt = $ligacao->prepare("
                        UPDATE registo_diario
                        SET utilizador = ?
                        WHERE id_tarefa = ? AND data_trabalho = CURDATE()
                    ");
                    $stmt->execute([$utilizadorNovo, $id]);


                    // 4. Fechar pausas pendentes
                    $stmt = $ligacao->prepare("
                        UPDATE pausas_tarefas 
                        SET data_retorno = NOW() 
                        WHERE tarefa_id = ? AND data_retorno IS NULL
                    ");
                    $stmt->execute([$id]);

                    // 5. Registar transição com tempo do utilizador
                    $stmt = $ligacao->prepare("
                        INSERT INTO transicao_tarefas (tarefa_id, utilizador_antigo, utilizador_novo, dataHora_transicao, duracao_exec, departamento_novo)
                        VALUES (?, ?, ?, NOW(), ?,?)
                    ");
                    $stmt->execute([$id, $utilizadorAntigo, $utilizadorNovo, $tempoDecorridoUtilizador,$novoUtilizadorDepId]);

                    // 6. Copiar último motivo de pausa (opcional)
                    $stmt = $ligacao->prepare("
                        SELECT motivo_id 
                        FROM pausas_tarefas 
                        WHERE tarefa_id = ? AND funcionario = ? 
                        ORDER BY data_pausa DESC 
                        LIMIT 1
                    ");
                    $stmt->execute([$id, $utilizadorAntigo]);
                    $pausa = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($pausa && isset($pausa['motivo_id'])) {
                        $stmt = $ligacao->prepare("
                            INSERT INTO pausas_tarefas (tarefa_id, funcionario, motivo_id, data_pausa)
                            VALUES (?, ?, ?, NOW())
                        ");
                        $stmt->execute([$id, $utilizadorNovo, $pausa['motivo_id']]);
                    }

                    echo "ok";
                    exit;

                } catch (PDOException $e) {
                    http_response_code(500);
                    echo "Erro ao transitar tarefa: " . $e->getMessage();
                    exit;
                }
            } else {
                http_response_code(400);
                echo "Preencha todos os campos.";
                exit;
            }
        }
    }
} else {
    http_response_code(405);
    echo "Método não permitido.";
    exit;
}
