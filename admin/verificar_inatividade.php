<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/log_erros_php.txt');

date_default_timezone_set('Europe/Lisbon');
$logPath = __DIR__ . "/log_execucao.txt";
file_put_contents($logPath, date('Y-m-d H:i:s') . " - Script iniciado\n", FILE_APPEND);

require_once __DIR__ . '/../config_bd.php';
require_once 'funcoes_email.php';

$dataHoje = date('Y-m-d');
$horaAgora = date('H:i:s');
$diaSemanaHoje = date('l');

$log = "Data: $dataHoje | Hora agora: $horaAgora | Dia da semana: $diaSemanaHoje\n";

// Buscar todos os funcionários com horário definido
$stmtFuncionarios = $ligacao->query("
    SELECT f.utilizador, f.nome, f.email, h.hora_inicio, h.tempo_notificacao, h.dias_semana
    FROM funcionarios f
    INNER JOIN horarios h ON f.horario_id = h.id
");
$funcionarios = $stmtFuncionarios->fetchAll(PDO::FETCH_ASSOC);

$log .= "Total de funcionários: " . count($funcionarios) . "\n\n";

foreach ($funcionarios as $func) {
    $utilizador = $func['utilizador'];
    $nome = $func['nome'];
    $email = $func['email'];
    $horaInicio = $func['hora_inicio'];
    $tempoNotificacao = (int)$func['tempo_notificacao'];
    $diasSemana = explode(',', str_replace(' ', '', $func['dias_semana']));

    $log .= "--- Verificando funcionário: $nome ($utilizador) ---\n";
    $log .= "Dias permitidos: " . implode(',', $diasSemana) . "\n";

    // Traduzir dia atual para português
    $diasMap = [
        'Monday' => 'Segunda', 'Tuesday' => 'Terca', 'Wednesday' => 'Quarta',
        'Thursday' => 'Quinta', 'Friday' => 'Sexta', 'Saturday' => 'Sabado', 'Sunday' => 'Domingo'
    ];
    $diaHojePT = $diasMap[$diaSemanaHoje] ?? $diaSemanaHoje;

    if (!in_array($diaHojePT, $diasSemana)) {
        $log .= "Hoje ($diaHojePT) não está nos dias permitidos. Saltar funcionário.\n\n";
        continue;
    }

    // Verifica se está ausente hoje
    $stmtAusente = $ligacao->prepare("
        SELECT COUNT(*) 
        FROM ausencia_funcionarios 
        WHERE funcionario_utilizador = ? AND data_falta = ?
    ");
    $stmtAusente->execute([$utilizador, $dataHoje]);
    $ausenteHoje = $stmtAusente->fetchColumn();

    if ($ausenteHoje) {
        $log .= "Funcionário está ausente hoje ($dataHoje). Nenhuma ação será tomada.\n\n";
        continue;
    }

    // Verifica se o dia de hoje NÃO existe na tabela dias_nao_permitidos
    $stmtBloqueado = $ligacao->prepare("SELECT COUNT(*) FROM dias_nao_permitidos WHERE data = ?");
    $stmtBloqueado->execute([$dataHoje]);
    $diaBloqueado = $stmtBloqueado->fetchColumn();

    if ($diaBloqueado > 0) {
        $log .= "Hoje ($dataHoje) está na tabela feriados ou nao dias permitidos. Nenhuma ação será tomada.\n\n";
        continue;
    }

    // Cálculo da hora limite
    $horaLimiteObj = new DateTime($horaInicio);
    $horaLimiteObj->modify("+$tempoNotificacao minutes");
    $horaLimiteMais1Obj = (clone $horaLimiteObj)->modify('+10 minutes');
    $horaAgoraObj = new DateTime($horaAgora);

    $log .= "Hora início configurada: $horaInicio\n";
    $log .= "Hora limite de envio: " . $horaLimiteObj->format('H:i:s') . "\n";
    $log .= "Janela de envio: de " . $horaLimiteObj->format('H:i:s') . " até " . $horaLimiteMais1Obj->format('H:i:s') . "\n";
    $log .= "Hora atual: " . $horaAgoraObj->format('H:i:s') . "\n";

    if ($horaAgoraObj >= $horaLimiteObj && $horaAgoraObj < $horaLimiteMais1Obj) {
        $log .= "Estamos dentro do intervalo de envio.\n";

        $stmtCheck = $ligacao->prepare("
            SELECT COUNT(*) FROM utilizador_entradaesaida 
            WHERE utilizador = ? AND DATE(hora_entrada) = ?
        ");
        $stmtCheck->execute([$utilizador, $dataHoje]);
        $totalInicios = $stmtCheck->fetchColumn();

        $log .= "Registo de tarefas hoje para $utilizador: $totalInicios\n";

        if ($totalInicios == 0) {
            $log .= "Ainda não iniciou nenhuma tarefa hoje.\n";

            $stmtAviso = $ligacao->prepare("
                SELECT COUNT(*) FROM avisos_inatividade 
                WHERE utilizador = ? AND data_envio = ?
            ");
            $stmtAviso->execute([$utilizador, $dataHoje]);
            $avisoEnviado = $stmtAviso->fetchColumn();

            $log .= "Aviso já enviado hoje? " . ($avisoEnviado ? "Sim" : "Não") . "\n";

            if ($avisoEnviado == 0) {
                $assunto = "Aviso de Inatividade";
                $mensagem = "Ola $nome,\n\nVerificamos que ate as " . $horaAgoraObj->format('H:i:s') . " do dia $dataHoje, ainda nao iniciou nenhuma tarefa.\n\nPor favor, verifique a sua agenda de trabalho.";

                $log .= "A enviar email para $email...\n";
                enviar_email_personalizado($email, $assunto, $mensagem);

                $stmtInsert = $ligacao->prepare("
                    INSERT INTO avisos_inatividade (utilizador, data_envio) 
                    VALUES (?, ?)
                ");
                $stmtInsert->execute([$utilizador, $dataHoje]);

                $log .= "Aviso registado na base de dados.\n";
            } else {
                $log .= "Aviso já foi enviado anteriormente. Nenhum novo envio.\n";
            }
        } else {
            $log .= "Já existe tarefa iniciada hoje. Nenhum aviso necessário.\n";
        }
    } else {
        $log .= "Fora da janela de envio. Nenhuma ação será tomada.\n";
    }

    $log .= "\n";
}

$log .= "Processo concluído!\n";
file_put_contents($logPath, $log, FILE_APPEND);
