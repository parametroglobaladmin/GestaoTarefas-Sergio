<?php
session_start();

if (!isset($_SESSION['utilizador_logado'])) {
    header('Location: login.php');
    exit();
}

$utilizador = $_SESSION["utilizador_logado"];
require_once '../config_bd.php';

$stmt = $ligacao->prepare("SELECT COUNT(*) FROM funcionarios WHERE utilizador = ?");
$stmt->execute([$utilizador]);
if ($stmt->fetchColumn() == 0) {
    session_destroy();
    header("Location: login.php");
    exit();
}

$stmtUtilizadorCriarTarefa = $ligacao->prepare("SELECT * FROM funcionarios WHERE utilizador = ?");
$stmtUtilizadorCriarTarefa->execute([$utilizador]);
$dadosFuncionario = $stmtUtilizadorCriarTarefa->fetch(PDO::FETCH_ASSOC);

if ($dadosFuncionario && !isset($dadosFuncionario['id'])) {
    $numeroFallback = isset($dadosFuncionario['numero']) ? (int) $dadosFuncionario['numero'] : 0;
    $dadosFuncionario['id'] = $numeroFallback > 0 ? $numeroFallback : 1;
}

$mensagem = isset($_GET['mensagem']) ? $_GET['mensagem'] : "";
$erro = isset($_GET['erro']) ? $_GET['erro'] : "";

$stmtDia = $ligacao->prepare("
    SELECT fe.datahora_fimdedia IS NULL AS dia_ativo
    FROM utilizador_entradaesaida ue
    LEFT JOIN finalizar_dia fe 
      ON fe.utilizador = ue.utilizador 
     AND DATE(fe.datahora_fimdedia) = CURDATE()
    WHERE ue.utilizador = ? AND ue.data = CURDATE()
    LIMIT 1
");
$stmtDia->execute([$utilizador]);
$diaIniciado = $stmtDia->fetchColumn(); // Será 1 se o dia estiver em aberto, 0 se finalizado


// Criar nova tarefa
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $tarefa = trim($_POST["tarefa"]);
    $descricao = trim($_POST["descricao"]);

    if (!empty($tarefa) && !empty($descricao)) {
        try {
            $stmt = $ligacao->prepare("SELECT departamento FROM funcionarios WHERE utilizador = ?");
            $stmt->execute([$utilizador]);
            $departamento = $stmt->fetchColumn();

            if (!$departamento) {
                $erro="O utilizador não tem departamento associado";
            }else{

              $stmtVerifica = $ligacao->prepare("SELECT COUNT(*) FROM tarefas WHERE tarefa = ?");
              $stmtVerifica->execute([$tarefa]);
              $existe = $stmtVerifica->fetchColumn();

              if ($existe > 0) {
                  // Já existe uma tarefa com o mesmo código
                  $erro="❌ Já existe uma tarefa com esse nome.";
              }else{

              $stmt = $ligacao->prepare("INSERT INTO tarefas (tarefa, descricao, utilizador) VALUES (?, ?, ?)");
              $stmt->execute([$tarefa, $descricao, $utilizador]);
              $idTarefa = $ligacao->lastInsertId();

              $stmtDepartment=$ligacao->prepare("
                  INSERT INTO departamento_tarefa (utilizador,departamento_id,tarefa_id,data_entrada,tempo_em_espera)
                  VALUES (?,?,?,NOW(),NULL)
              ");
              $stmtDepartment->execute([$utilizador,$departamento,$idTarefa]);

              $stmtEstado = $ligacao->prepare("
                  UPDATE tarefas 
                  SET estado_cronometro = 'ativa', 
                      data_inicio_cronometro = NOW()
                  WHERE id = ?
              ");
              $stmtEstado->execute([$idTarefa]);

              $stmtRegisto = $ligacao->prepare("
                  INSERT INTO registo_diario (
                      id_tarefa,
                      utilizador,
                      data_trabalho,
                      tempo_pausa,
                      hora_inicio,
                      tempo_inicio_tarefa,
                      inicio_tarefa
                  ) VALUES (?, ?, CURDATE(), '00:00:00', CURTIME(), CURTIME(), CURTIME())
              ");
              $stmtRegisto->execute([$idTarefa, $utilizador]);

              header("Location: abrirtarefa.php?id=" . $idTarefa);
              exit();
              }
            }
        } catch (PDOException $e) {
            $erro = "❌ Erro ao criar tarefa: " . $e->getMessage();
        }
    } else {
        $erro = "❌ Preenche todos os campos.";
    }
}

// Carregar tarefas
$funcionarios = [];
$pesquisa = isset($_GET["pesquisa"]) ? trim($_GET["pesquisa"]) : '';
$paramPesquisa = "%$pesquisa%";

$consultaBase = "
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
        up.motivo_id as motivo_id,
        up.tipo AS tipo_motivo,
        up.data_pausa AS inicio_pausa
    FROM tarefas t
    LEFT JOIN pausas_tarefas p ON t.id = p.tarefa_id
    LEFT JOIN motivos_pausa mp ON p.motivo_id = mp.id
    LEFT JOIN ultimas_pausas up ON up.tarefa_id = t.id AND up.rn = 1
    WHERE t.utilizador = ? AND t.estado != 'concluida' AND t.estado != 'eliminada'";

if ($pesquisa !== '') {
    $consultaBase .= " AND (t.tarefa LIKE ? OR t.descricao LIKE ?)";
    $consultaBase .= " GROUP BY t.id ORDER BY t.data_criacao DESC";
    $stmt = $ligacao->prepare($consultaBase);
    $stmt->execute([$utilizador, $paramPesquisa, $paramPesquisa]);
} else {
    $consultaBase .= " GROUP BY t.id ORDER BY t.data_criacao DESC";
    $stmt = $ligacao->prepare($consultaBase);
    $stmt->execute([$utilizador]);
}

$funcionarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($funcionarios as $tarefa) {
    if (
        $tarefa['estado_cronometro'] === 'ativa' &&
        empty($tarefa['data_inicio_cronometro'])
    ) {
        $stmtReativar = $ligacao->prepare("
            UPDATE tarefas
            SET data_inicio_cronometro = NOW()
            WHERE id = ?
        ");
        $stmtReativar->execute([$tarefa['id']]);
    }
}

// Verificar se alguma tarefa está em pausa por "PararContadores"
$pararTudo = false;
$idTarefaParada = null;
$idTarefaPrincipalPararContadores = null;
$idTarefaPrincipalSemOpcao = null;

foreach ($funcionarios as $tarefa) {
    if (
        isset($tarefa['estado_cronometro'], $tarefa['tipo_motivo']) &&
        $tarefa['estado_cronometro'] === 'pausa' &&
        $tarefa['tipo_motivo'] === 'PararContadores'
    ) {
      $pararTudo = true;
      if($idTarefaPrincipalPararContadores===null){
        $idTarefaPrincipalPararContadores = $tarefa['id'];
        if (!isset($_SESSION['tarefa_pausada_por_pararcontadores'])) {
            $_SESSION['tarefa_pausada_por_pararcontadores'] = $tarefa['id'];
        }
        break; // já encontrámos a principal, mas não vamos usar mais este foreach para tratar as restantes
      }
    }
    if (
        isset($tarefa['estado_cronometro'], $tarefa['tipo_motivo']) &&
        $tarefa['estado_cronometro'] === 'pausa' &&
        $tarefa['tipo_motivo'] === 'SemOpcao'
    ) {
      if($idTarefaPrincipalSemOpcao===null){
        $idTarefaPrincipalSemOpcao = $tarefa['id'];
        if (!isset($_SESSION['tarefa_pausada_por_semopcao'])) {
            $_SESSION['tarefa_pausada_por_semopcao'] = $tarefa['id'];
        }
        break; // já encontrámos a principal, mas não vamos usar mais este foreach para tratar as restantes
      }
    }
}

if ($pararTudo) {
    // Obter ID do motivo "PararContadores"
    $motivoPararId = null;
    if ($idTarefaPrincipalPararContadores !== null) {
        $stmtMotivo = $ligacao->prepare("
            SELECT pt.motivo_id
            FROM pausas_tarefas pt
            WHERE pt.tarefa_id = ? AND pt.data_retorno IS NULL
            ORDER BY pt.data_pausa DESC
            LIMIT 1
        ");
        $stmtMotivo->execute([$idTarefaPrincipalPararContadores]);
        $motivo = $stmtMotivo->fetch(PDO::FETCH_ASSOC);
        $motivoPararId = $motivo['motivo_id'] ?? null;
    }

    foreach ($funcionarios as $tarefa) {
        if (
            $tarefa['estado_cronometro'] === 'pausa' &&
            $tarefa['tipo_motivo'] !== 'PararContadores' && $tarefa['estado'] === 'pendente'
        ) {

            $stmt=$ligacao->prepare("
              SELECT id FROM motivos_pausa WHERE tipo = ?
            ");
            $stmt->execute([$tarefa['tipo_motivo']]);
            $motivoId=$stmt->fetch(PDO::FETCH_ASSOC);

            // Inserir na tabela pausas_temporarias
            $stmt = $ligacao->prepare("
                INSERT INTO pausas_temporarias (tarefa_id, utilizador, tipo_original, data_backup)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$tarefa['id'], $utilizador, $tarefa['motivo_id'], $tarefa['total_pausa_segundos']]);

            // Finalizar a pausa atual
            $stmt = $ligacao->prepare("
                UPDATE pausas_tarefas
                SET data_retorno = NOW()
                WHERE id = (
                    SELECT id FROM (
                        SELECT id FROM pausas_tarefas
                        WHERE tarefa_id = ? AND funcionario = ? AND data_retorno IS NULL
                        ORDER BY data_pausa DESC LIMIT 1
                    ) AS temp
                )
            ");
            $stmt->execute([$tarefa['id'], $utilizador]);

            // Iniciar nova pausa com motivo "PararContadores"
            if ($motivoPararId) {
                $stmt = $ligacao->prepare("
                    INSERT INTO pausas_tarefas (tarefa_id, motivo_id, funcionario, data_pausa, tempo_pausa)
                    VALUES (?, ?, ?, NOW(), '00:00:00')
                ");
                $stmt->execute([$tarefa['id'], $motivoPararId, $utilizador]);
            }

            // Atualiza estado da tarefa (garantido)
            $stmt = $ligacao->prepare("UPDATE tarefas SET estado_cronometro = 'pausa' WHERE id = ?");
            $stmt->execute([$tarefa['id']]);
        }else if($tarefa['estado'] === 'concluida'){
            continue;
        }else{
          if($idTarefaParada===null){
            $idTarefaParada=$tarefa['id'];
          }
        }
    }
    $pararTudo=false;
}

// Verificar se a tarefa PararContadores foi retomada
// Verificar se existe tarefa ativa para restaurar pausas temporárias
$stmtTarefaAtiva = $ligacao->prepare("
    SELECT id FROM tarefas
    WHERE utilizador = ? AND estado_cronometro = 'ativa'
    ORDER BY data_inicio_cronometro DESC LIMIT 1
");
$stmtTarefaAtiva->execute([$utilizador]);
$tarefaAtiva = $stmtTarefaAtiva->fetch(PDO::FETCH_ASSOC);

if ($tarefaAtiva) {
    // Verifica se existem pausas temporárias
    $stmt = $ligacao->prepare("SELECT * FROM pausas_temporarias WHERE utilizador = ?");
    $stmt->execute([$utilizador]);
    $pausasTemporarias = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($pausasTemporarias) > 0) {
        foreach ($pausasTemporarias as $p) {
            // Finaliza a pausa PararContadores
            $stmt = $ligacao->prepare("
                UPDATE pausas_tarefas
                SET data_retorno = NOW()
                WHERE tarefa_id = ? AND funcionario = ? AND data_retorno IS NULL
            ");
            $stmt->execute([$p['tarefa_id'], $utilizador]);

            if (!empty($p['tipo_original'])) {
                // Recria pausa original com tempo ajustado
                $stmt = $ligacao->prepare("
                    INSERT INTO pausas_tarefas (tarefa_id, motivo_id, funcionario, data_pausa)
                    VALUES (?, ?, ?, NOW() - INTERVAL ? SECOND)
                ");
                $stmt->execute([
                    $p['tarefa_id'],
                    $p['tipo_original'],
                    $utilizador,
                    (int)$p['data_backup']
                ]);
            }

            // Atualiza estado da tarefa para "pausa"
            $stmt = $ligacao->prepare("UPDATE tarefas SET estado_cronometro = 'pausa' WHERE id = ?");
            $stmt->execute([$p['tarefa_id']]);
        }

        // Limpar tabela de pausas temporárias
        $stmt = $ligacao->prepare("DELETE FROM pausas_temporarias WHERE utilizador = ?");
        $stmt->execute([$utilizador]);

        // Garantir que a tarefa atual continua ativa
        $stmt = $ligacao->prepare("UPDATE tarefas SET estado_cronometro = 'ativa', data_inicio_cronometro = NOW() WHERE id = ?");
        $stmt->execute([$tarefaAtiva['id']]);
    }
    $idTarefaPrincipalPararContadores=null;
    unset($_SESSION['tarefa_pausada_por_pararcontadores']);
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Tarefas</title>
  <link rel="stylesheet" href="../style.css" />
  <style>
    body {
      margin: 0;
      height: 100vh;
      min-height: 100vh;
      display: flex;
      justify-content: center;
      align-items: center;
      align-items: flex-start;
      overflow-y: auto;
      background-color: #f4f4f4;
    }

    .container {
      display: flex;
      flex-direction: column;
      align-items: center;
      width: 100%;
      margin-top: 20vh;
    }

    .top-buttons {
      display: flex;
      gap: 15px;
      margin-bottom: 30px;
    }

    .botao {
      background-color: #d4aa2f;
      color: #000;
      font-size: 16px;
      font-weight: bold;
      padding: 12px 24px;
      border-radius: 10px;
      text-decoration: none;
      box-shadow: 0 2px 4px rgba(0,0,0,0.2);
      text-align: center;
    }

    table {
      width: 80%;
      max-width: 900px;
      background: #fff;
      border-collapse: collapse;
      box-shadow: 0 2px 5px rgba(0,0,0,0.1);
      border-radius: 10px;
      overflow: hidden;
      border: 1px solid #ccc; /* NOVO: borda geral da tabela */
    }

    th, td {
      padding: 14px 10px;
      text-align: center;
      vertical-align: middle;
      border: 1px solid #ccc; /* NOVO: ativa todas as linhas */
    }

    th {
      background-color: #d4af37;
      color: black;
    }


    th:first-child,
    td:first-child {
    width: 50%;
    border-right: 1px solid #ccc; /* separador vertical */
    }

    th:last-child,
    td:last-child {
    width: 50%;
    }


    .botao-pequeno {
      padding: 6px 14px;
      font-size: 0.9em;
      border: none;
      background-color: #d4aa2f;
      color: black;
      border-radius: 8px;
      cursor: pointer;
    }

    .toast {
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 15px 20px;
        border-radius: 5px;
        box-shadow: 0 3px 6px rgba(0,0,0,0.1);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
        z-index: 9999;
        min-width: 280px;
    }

    .toast .fechar {
        background: none;
        border: none;
        font-size: 20px;
        line-height: 20px;
        cursor: pointer;
    }

    .barra-pesquisa {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 30px;
    }

    .campo-busca {
        padding: 6px 10px;
        font-size: 15px;
        height: 26px;
        border: 1px solid #ccc;
        border-radius: 6px;
        width: 230px;
    }

    .botao-buscar {
        padding: 6px 20px;
        height: 36px;
        font-size: 15px;
        font-weight: bold;
        background-color: #d4aa2f;
        color: black;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    }

    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.4);
        display: none;
        justify-content: center;
        align-items: center;
        z-index: 1000;
    }

    .botao-modal {
        background-color: #d4aa2f;
        color: black;
        font-weight: bold;
        border: none;
        border-radius: 8px;
        padding: 10px;
        font-size: 14px;
        cursor: pointer;
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        transition: 0.3s;
    }

    .modal-box {
        background-color: #ffffff; /* branco */
        border-radius: 20px;
        padding: 30px;
        width: 350px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        position: relative;
        display: flex;
        flex-direction: column;
        gap: 15px;
    }


    .modal-box label {
        font-weight: bold;
    }

    .modal-box input,
    .modal-box textarea {
        background-color: #f0f0f0;         /* cinza claro */
        border: 1px solid #444444;         /* borda preta discreta */
        border-radius: 8px;
        padding: 10px;
        font-size: 15px;
        width: 100%;
        box-sizing: border-box;
        resize: none;
        font-family: Arial, sans-serif;
    }



    .botao-modal {
        background-color: #d4aa2f; /* mesmo amarelo */
        color: black;
        font-weight: bold;
        border: none;
        border-radius: 8px;
        padding: 10px;
        font-size: 14px;
        cursor: pointer;
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        transition: 0.3s;
        margin-top: 15px;
    }

    .botao-modal:hover {
        opacity: 0.9;
    }


    .fechar-modal {
        position: absolute;
        top: 10px;
        right: 15px;
        font-size: 20px;
        background: none;
        border: none;
        cursor: pointer;
    }

    @keyframes fadeInZoom {
      0% {
        opacity: 0;
        transform: scale(0.9);
      }
      100% {
        opacity: 1;
        transform: scale(1);
      }
    }

    .modal-box.animar {
      animation: fadeInZoom 0.3s ease-out forwards;
    }

    @keyframes fadeOutZoom {
      0% {
        opacity: 1;
        transform: scale(1);
      }
      100% {
        opacity: 0;
        transform: scale(0.9);
      }
    }

    .modal-box.sair {
      animation: fadeOutZoom 0.25s ease-in forwards;
    }
    
     .barra-superior {
      width: 96%;
      display: flex;
      justify-content: space-between;
      padding: 10px 20px;
      background-color: #ffffff;
      box-shadow: 0 1px 4px rgba(0,0,0,0.1);
      position: fixed;
      border-radius: 10px;
      top: 10px;
      left: 20px;
      right:20px;
      z-index: 100;
    }

    .lado-esquerdo {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .utilizador-info {
      display: flex;
      align-items: center;
      gap: 25px;
      font-weight: bold;
      font-size: 18px;
      margin-right: 30px;
    }

    .botao-iniciar {
      background-color: #28a745; /* verde */
      color: white;
      font-size: 15px;
      font-weight: bold;
      padding: 8px 16px;
      border-radius: 8px;
      border: none;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 8px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    }

    .botao-finalizar {
      background-color: #dc3545; /* vermelho */
      color: white;
      font-size: 15px;
      font-weight: bold;
      padding: 8px 16px;
      border-radius: 8px;
      border: none;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 8px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    }
  </style>
</head>
<body>
<!-- Caixa de notificação -->
<div id="notificacao" style="display:none; position:fixed; bottom:20px; right:20px; padding:15px; border-left:5px solid; border-radius:5px; z-index:9999;">
  <span id="notificacao-texto"></span>
</div>


<div class="barra-superior">
    <div class="lado-esquerdo">
      <?php if ($diaIniciado): ?>
        <button type="button" class="botao-finalizar" onclick="finalizarDiaComTempo()">
          <span style="font-size: 18px;">■</span> Finalizar Dia
        </button>
      <?php else: ?>
        <button type="button" class="botao-iniciar" onclick="verificarSaidaPendentes(<?= $dadosFuncionario['id'] ?? 'null' ?>)">
          <span style="font-size: 18px;">▶</span> Iniciar Dia
        </button>
      <?php endif; ?>
    </div>


    <div class="utilizador-info">
      <span class="nome-utilizador"><?= htmlspecialchars($utilizador) ?></span>
      <button class="botao-pequeno" type="button" onclick="abrirModalAlterarPassword()">Alterar Password</button>
    </div>
</div>
<div class="container">

  <?php if (!empty($mensagem) || !empty($erro)): ?>
    <div class="toast" id="toast" style="background-color: <?= $erro ? '#f8d7da' : '#d4edda' ?>; color: <?= $erro ? '#721c24' : '#155724' ?>; border-left: 5px solid <?= $erro ? '#dc3545' : '#28a745' ?>;">
        <span><?= htmlspecialchars($erro ?: $mensagem) ?></span>
        <button class="fechar" onclick="document.getElementById('toast').style.display='none'">×</button>
    </div>
  <?php endif; ?>

  
  <div class="top-buttons">
    <?php

    if($dadosFuncionario['criar_tarefa']==='ativo'){
      $mostrarBotaoCriar = $diaIniciado;

      if ($mostrarBotaoCriar && !empty($funcionarios)) {
          foreach ($funcionarios as $f) {
              $estado = strtolower(trim($f['estado_cronometro'] ?? ''));
              $motivo = strtolower(trim($f['tipo_motivo'] ?? ''));

              if (($estado !== 'pausa' || $motivo !== 'iniciartarefas')) {
                  $mostrarBotaoCriar = false;
                  break;
              }
          }
      }
    }else{
      $mostrarBotaoCriar=false;
    }

    ?>



    <?php if ($mostrarBotaoCriar): ?>
        <button type="button" class="botao" onclick="abrirModal()">Criar tarefa</button>
    <?php endif; ?>

    <a href="tarefasparaexecutar.php" class="botao">Tarefas para Executar</a>
    <a href="tarefasconcluidas.php" class="botao">Ver tarefas concluídas</a>
    <a href="painel.php" class="botao">← Voltar</a>
  </div>


  <form method="GET" action="" class="barra-pesquisa">
    <input type="text" name="pesquisa" placeholder="Pesquisar tarefa..." class="campo-busca" value="<?= htmlspecialchars($_GET['pesquisa'] ?? '') ?>" />
    <button type="submit" class="botao-buscar">Procurar</button>
  </form>

  <table style="margin-bottom: 20px;">
    <thead>
      <tr>
        <th style="width: 30%;">Tarefa</th>
        <th style="width: 20%;">Tempo decorrido</th>
        <th style="width: 20%;">Tempo de pausa</th>
        <th style="width: 30%;">Ações</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($funcionarios as $f): 
      $tempo_tarefa = $f['tempo_decorrido_utilizador'] ?? '00:00:00';
      $pausa_seg = $f['total_pausa_segundos'];
      $h = str_pad(floor($pausa_seg / 3600), 2, '0', STR_PAD_LEFT);
      $m = str_pad(floor(($pausa_seg % 3600) / 60), 2, '0', STR_PAD_LEFT);
      $s = str_pad($pausa_seg % 60, 2, '0', STR_PAD_LEFT);
      $tempo_pausa = "$h:$m:$s";

      // Novos campos
      $estado = $f['estado_cronometro'] ?? 'inativa';
      $inicio = $f['data_inicio_cronometro'] ?? '';
      $inicio_pausa = $f['inicio_pausa'] ?? '';
    ?>
      <tr>
        <td><?= htmlspecialchars($f['tarefa']) ?></td>
        <td class="tempo-decorrido"
            data-id="<?= $f['id'] ?>"
            data-tempo="<?= $tempo_tarefa ?>"
            data-estado="<?= $estado ?>"
            data-inicio="<?= $inicio ?>">
            <?= htmlspecialchars($tempo_tarefa) ?>
        </td>
        <td class="tempo-pausa"
            data-pausa="<?= $tempo_pausa ?>"
            data-estado="<?= $estado ?>"
            data-inicio="<?= $inicio_pausa ?>">
        </td>

        <td>
          <form method="GET" action="abrirtarefa.php">
            <?php
  $estadoCrono = strtolower(trim($f['estado_cronometro']));
  $cor = '#6c757d'; // cinza padrão
  $bloqueado = true;
  $mensagem = '⚠️ Tarefa inativa.';
  $tarefaAtualPararContadores = $_SESSION['tarefa_pausada_por_pararcontadores'] ?? null;
$tarefaAtualSemopcao = $_SESSION['tarefa_pausada_por_semopcao'] ?? null;

$pausaRestritivaAtiva = $pararTudo || $tarefaAtualPararContadores !== null || $tarefaAtualSemopcao !== null;
$tarefaAtualId = $f['id'];

if (!$diaIniciado) {
  $mensagem = '⚠️ O dia ainda não foi iniciado.';
} elseif ($estadoCrono === 'ativa') {
  if ($pausaRestritivaAtiva && !in_array($tarefaAtualId, [$tarefaAtualPararContadores, $tarefaAtualSemopcao])) {
    $mensagem = '⚠️ Só podes abrir a tarefa com pausa PararContadores ou Semopcao.';
  } else {
    $cor = '#28a745'; // verde
    $bloqueado = false;
  }
} elseif ($estadoCrono === 'pausa') {
  if ($pausaRestritivaAtiva && !in_array($tarefaAtualId, [$tarefaAtualPararContadores, $tarefaAtualSemopcao])) {
    $mensagem = '⚠️ Só podes abrir a tarefa com pausa PararContadores ou Semopcao.';
  } else {
    $cor = '#dc3545'; // vermelho
    $bloqueado = false;
  }
}
?>


<button
  type="button"
  class="botao-pequeno"
  style="background-color: <?= $cor ?>; <?= $bloqueado ? 'opacity: 0.7; cursor: not-allowed;' : '' ?>"
  onclick="<?= $bloqueado ? "alert('$mensagem')" : "abrirTarefaComTempo({$f['id']})" ?>"
  <?= $bloqueado ? 'disabled' : '' ?>>
  Abrir
</button>



          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="modal-overlay" id="modalOverlay">
  <div class="modal-box">
    <button class="fechar-modal" onclick="fecharModal()">×</button>

    <form method="POST" action="">
      <label for="tarefa">Tarefa:</label>
      <input type="text" id="tarefa" name="tarefa" required>

      <label for="descricao">Descrição:</label>
      <textarea id="descricao" name="descricao" rows="5" required></textarea>

      <button type="submit" class="botao-modal">Criar tarefa</button>
    </form>
  </div>
</div>

<script>
  function confirmarFinalizacao() {
  const id = <?= json_encode($tarefaActiva ?? null) ?>;
  if (!id) return true;

  const td = document.querySelector(`.tempo-decorrido[data-id="${id}"]`);
  if (!td) return true;

  const tempoAtual = td.textContent.trim();
  document.getElementById("tempoTarefaFinalizar").value = tempoAtual;

  return true;
}
</script>

<script>
  setTimeout(() => {
    const toast = document.getElementById('toast');
    if (toast) toast.style.display = 'none';
  }, 5000);
</script>

<script>
  function atualizarPausasTabela() {
  const linhas = document.querySelectorAll(".tempo-pausa");

  linhas.forEach(td => {
    const base = td.dataset.pausa || "00:00:00";
    const estado = td.dataset.estado?.toLowerCase().trim();
    const inicioStr = td.dataset.inicio;

    let [h, m, s] = base.split(":").map(n => parseInt(n, 10));
    let totalSeg = h * 3600 + m * 60 + s;

    if (estado === "pausa" && inicioStr) {
      const inicio = new Date(inicioStr.replace(" ", "T"));
      const agora = new Date();
      const diff = Math.floor((agora - inicio) / 1000);
      totalSeg = Math.max(0, diff); 
      //totalSeg += diff;
    }

    const hh = String(Math.floor(totalSeg / 3600)).padStart(2, "0");
    const mm = String(Math.floor((totalSeg % 3600) / 60)).padStart(2, "0");
    const ss = String(totalSeg % 60).padStart(2, "0");

    td.textContent = `${hh}:${mm}:${ss}`;
  });
}
</script>

<script>
function atualizarTemposTabela() {
  const linhas = document.querySelectorAll(".tempo-decorrido");

  linhas.forEach(td => {
    const base = td.dataset.tempo || "00:00:00";
    const estado = td.dataset.estado;
    const inicioStr = td.dataset.inicio;

    let [h, m, s] = base.split(":").map(n => parseInt(n, 10));
    let totalSeg = h * 3600 + m * 60 + s;

    if (estado === "ativa" && inicioStr) {
      const inicio = new Date(inicioStr.replace(" ", "T"));
      const agora = new Date();
      const diff = Math.floor((agora - inicio) / 1000);
      totalSeg += diff;
    }

    const hh = String(Math.floor(totalSeg / 3600)).padStart(2, "0");
    const mm = String(Math.floor((totalSeg % 3600) / 60)).padStart(2, "0");
    const ss = String(totalSeg % 60).padStart(2, "0");

    td.textContent = `${hh}:${mm}:${ss}`;
  });
}

setInterval(() => {
  atualizarTemposTabela();
  atualizarPausasTabela();
}, 1000);
atualizarPausasTabela();
atualizarTemposTabela();
</script>

<script>
function finalizarDiaComTempo() {

  const pausaPararContadores = <?= json_encode(!empty($_SESSION['tarefa_pausada_por_pararcontadores'])) ?>;
  const pausaSemOpcao       = <?= json_encode(!empty($_SESSION['tarefa_pausada_por_semopcao'])) ?>;

  if (pausaPararContadores || pausaSemOpcao) {
    alert("Não podes finalizar o dia com tarefas em pausa que não sejam Almoço, Lanche ou WC.");
    return;
  }
  
  const id = <?= json_encode($tarefaAtiva['id'] ?? null) ?>;

  const td = document.querySelector(`.tempo-decorrido[data-id="${id}"]`);

  const tempoAtual = td?.textContent?.trim() || "";

  fetch("finalizar_dia_tarefa.php", {
    method: "POST",
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      id: id,
      tempo: tempoAtual
    })
  })
  .then(res => res.text())
  .then(resposta => {
    if (resposta.includes("sucesso")) {
      window.location.href = "painel.php?mensagem=Dia finalizado com sucesso";
    } else {
      alert("Erro ao finalizar o dia: " + resposta);
    }
  })
  .catch(err => {
    alert("Erro ao enviar dados: " + err.message);
  });
}
</script>


<div class="modal-overlay" id="modalSaidaPendente" style="display:none;">
  <div class="modal-box">
    <button class="fechar-modal" onclick="fecharModalSaida()">×</button>
    <h3>Registar Hora de Saída</h3>
    <p>Foi encontrado um dia anterior sem hora de saída.<br>Por favor, indica a hora que saíste:</p>
    <form id="formSaidaPendente">
      <input type="time" id="horaSaidaPendente" required style="width:100%; padding:8px; margin-top:10px;">
      <div style="margin-top:15px; text-align:right;">
        <button type="button" class="botao-pequeno" onclick="submeterSaidaPendente()">Guardar e Iniciar Dia</button>
      </div>
    </form>
  </div>
</div>


</body>

<div class="modal-overlay" id="modalAlterarPassword">
  <div class="modal-box">
    <button class="fechar-modal" onclick="fecharModalAlterarPassword()">×</button>
    <h3>Alterar Palavra-passe</h3>
    <form method="POST" action="alterar_password.php">
      <label for="password_atual">Antiga palavra-passe:</label>
      <input type="password" id="password_atual" name="password" required>

      <label for="nova_password">Nova palavra-passe:</label>
      <input type="text" id="nova_password" name="nova_password" required>

      <label for="confirmar_password">Confirmar palavra-passe:</label>
      <input type="text" id="confirmar_password" name="confirmar_password" required>

      <button type="submit" class="botao-modal">Alterar</button>
    </form>
  </div>
</div>

<script>
  function abrirModalAlterarPassword() {
    const overlay = document.getElementById('modalAlterarPassword');
    const box = overlay.querySelector('.modal-box');

    overlay.style.display = 'flex';

    // Reiniciar a animação
    box.classList.remove('animar', 'sair');
    void box.offsetWidth; // Força reflow
    box.classList.add('animar');
  }

  function fecharModalAlterarPassword() {
    const overlay = document.getElementById('modalAlterarPassword');
    const box = overlay.querySelector('.modal-box');

    box.classList.remove('animar');
    box.classList.add('sair');

    setTimeout(() => {
      overlay.style.display = 'none';
      box.classList.remove('sair');
    }, 250);
  }

  window.addEventListener('click', function(e) {
    const overlay = document.getElementById('modalAlterarPassword');
    const box = overlay.querySelector('.modal-box');

    if (e.target === overlay) {
      fecharModalAlterarPassword();
    }
  });
</script>


<script>
  function abrirModal() {
    document.getElementById('modalOverlay').style.display = 'flex';
  }

  function fecharModal() {
    document.getElementById('modalOverlay').style.display = 'none';
  }

  // Fechar ao clicar fora da caixa
  window.onclick = function(e) {
    const modal = document.getElementById('modalOverlay');
    if (e.target === modal) fecharModal();
  };

  function abrirModal() {
    const overlay = document.getElementById('modalOverlay');
    const box = overlay.querySelector('.modal-box');
    overlay.style.display = 'flex';
    box.classList.remove('animar'); // Reinicia se já tinha
    void box.offsetWidth; // Força reflow para reiniciar a animação
    box.classList.add('animar');
  }

  function fecharModal() {
    const overlay = document.getElementById('modalOverlay');
    const box = overlay.querySelector('.modal-box');

    box.classList.remove('animar');
    box.classList.add('sair');

    // Espera a animação terminar antes de esconder
    setTimeout(() => {
      overlay.style.display = 'none';
      box.classList.remove('sair');
    }, 250); // tempo igual ao da animação (0.25s)
  }

  
  
  //----------------- 



  let segundos = 0;
  let cronometro = document.getElementById("cronometro");
  let ativo = false;
  let intervalo;
  const btn = document.getElementById("btn-pausar");

  btn.addEventListener("click", () => {
    if (!ativo) {
      // Começar o cronômetro
      ativo = true;
      btn.textContent = "Em Execução";
      intervalo = setInterval(() => {
        segundos++;
        let h = String(Math.floor(segundos / 3600)).padStart(2, '0');
        let m = String(Math.floor((segundos % 3600) / 60)).padStart(2, '0');
        let s = String(segundos % 60).padStart(2, '0');
        cronometro.textContent = `${h}:${m}:${s}`;
      }, 1000);
    } else {
      // Pausar o cronômetro
      ativo = false;
      btn.textContent = "Começar";
      clearInterval(intervalo);
    }
  });


function abrirTarefaComTempo(id) {
  const diaIniciado = <?= json_encode($diaIniciado) ?>;
  if (!diaIniciado) {
    alert("⚠️ Não podes abrir uma tarefa antes de iniciar o dia.");
    return;
  }

  const td = document.querySelector(`.tempo-decorrido[data-id="${id}"]`);
  if (!td) {
    alert("Erro: tarefa não encontrada.");
    return;
  }

  const tempoTexto = td.textContent.trim();

  fetch("abrirtarefa.php", {
    method: "POST",
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      salvar_tempo: 1,
      id: id,
      tempo: tempoTexto
    })
  })
  .then(res => res.text())
  .then(resposta => {
    if (resposta.trim() === "ok") {
      window.location.href = `abrirtarefa.php?id=${id}`;
    } else {
      alert("Erro ao sincronizar tempo: " + resposta);
    }
  })
  .catch(err => {
    alert("Erro ao sincronizar tempo: " + err.message);
  });
}



</script>




<script>
let utilizadorIdPendente = null;

function verificarSaidaPendentes(idUtilizador) {
  if (!idUtilizador) {
    alert("Utilizador inválido.");
    return;
  }

  utilizadorIdPendente = idUtilizador;

  fetch("verificar_saida_pendente.php?id=" + idUtilizador)
    .then(res => res.json())
    .then(data => {
      if (data.temPendentes) {
        abrirModalSaida();
      } else {
        iniciarDia();
      }
    })
    .catch(err => alert("Erro ao verificar saídas pendentes: " + err.message));
}

function abrirModalSaida() {
  document.getElementById("modalSaidaPendente").style.display = "flex";
}

function fecharModalSaida() {
  document.getElementById("modalSaidaPendente").style.display = "none";
}

function submeterSaidaPendente() {
  const hora = document.getElementById("horaSaidaPendente").value;
  if (!hora) {
    alert("Por favor, insere a hora de saída.");
    return;
  }

  fetch("registar_saida_pendente.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: "id=" + encodeURIComponent(utilizadorIdPendente) + "&hora=" + encodeURIComponent(hora)
  })
  .then(res => res.text())
  .then(() => {
    fecharModalSaida();
    iniciarDia();
  })
  .catch(err => alert("Erro ao registar hora de saída: " + err.message));
}

function iniciarDia() {
  const form = document.createElement("form");
  form.method = "POST";
  form.action = "iniciar_dia.php";
  document.body.appendChild(form);
  form.submit();
}
</script>

</html>
