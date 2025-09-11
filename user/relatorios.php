<?php
require_once '../config_bd.php';
session_start();

$utilizadorLogado = $_SESSION['utilizador_logado'] ?? '';
$acesso = '';
$utilizadores = [];
$tarefas = [];

// Verifica o tipo de acesso
$stmt = $ligacao->prepare("SELECT relatorio_acesso FROM funcionarios WHERE utilizador = ?");
$stmt->execute([$utilizadorLogado]);
$acesso = $stmt->fetchColumn();

// Se tiver acesso a todos, lista todos os utilizadores, senão apenas ele
// Corrigido: listar os "numeros" (ID funcional) no select
if ($acesso === 'todos') {
    $stmt = $ligacao->query("SELECT DISTINCT utilizador FROM funcionarios");
    $utilizadores = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Aplica filtro por utilizador se definido
    if (!empty($_GET['utilizador']) && in_array($_GET['utilizador'], $utilizadores)) {
        $stmt = $ligacao->prepare("
            SELECT DISTINCT t.id, t.tarefa
            FROM tarefas t
            INNER JOIN registo_diario rd ON rd.id_tarefa = t.id
            WHERE rd.utilizador = ?
        ");
        $stmt->execute([$_GET['utilizador']]);
    } else {
        $stmt = $ligacao->query("SELECT id, tarefa FROM tarefas");
    }

    $tarefasAssoc = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $tarefas = array_keys($tarefasAssoc);
} else {
    $stmt = $ligacao->prepare("
        SELECT DISTINCT t.id, t.tarefa
        FROM tarefas t
        INNER JOIN registo_diario rd ON rd.id_tarefa = t.id
        WHERE rd.utilizador = ?
    ");
    $stmt->execute([$utilizadorLogado]);
    $tarefasAssoc = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $tarefas = array_keys($tarefasAssoc);


    $utilizadores = [$utilizadorLogado];
}

//Dados User
$stmtUser = $ligacao->prepare("
  SELECT nome, email, relatorio_acesso FROM funcionarios WHERE utilizador = ?
");
$stmtUser->execute([$utilizadorLogado]);
$userData = $stmtUser->fetch(PDO::FETCH_ASSOC);



// Filtros
$tarefaSelecionadaNome = $_GET['tarefa_nome'] ?? '';
$tarefaSelecionada = '';
$utilizadorSelecionado = $_GET['utilizador'] ?? '';
$erroUtilizador = '';
$estadoSelecionado = $_GET['estado_tarefa'] ?? '';
$cronometroSelecionado = $_GET['estado_cronometro'] ?? '';

if ($estadoSelecionado === 'concluida') {
    $cronometroSelecionado = 'inativa'; // força filtro
}


// Validação do utilizador (apenas se acesso total)
if ($acesso === 'todos' && !empty($utilizadorSelecionado)) {
    // Se o valor vier no formato "acronimo - nome", extrai só o acrónimo
    if (strpos($utilizadorSelecionado, ' - ') !== false) {
        [$utilizadorSelecionado, ] = explode(' - ', $utilizadorSelecionado, 2);
    }

    // Verifica se o acrónimo existe na lista de utilizadores
    if (!in_array($utilizadorSelecionado, $utilizadores)) {
        $erroUtilizador = 'O utilizador inserido não existe.';
    }
}

$dataInicio = $_GET['data_inicio'] ?? '';
$dataFim = $_GET['data_fim'] ?? '';

// Inverter o array [id => nome] para [nome => id]
$nomeParaId = array_flip($tarefasAssoc);

// Procurar o ID com base no nome escrito
if (!empty($tarefaSelecionadaNome) && isset($nomeParaId[$tarefaSelecionadaNome])) {
    $tarefaSelecionada = $nomeParaId[$tarefaSelecionadaNome];
}

if ($acesso !== 'todos') {
    $utilizadorSelecionado = $utilizadorLogado;
}

$resultados = [];

if (empty($erroUtilizador) && ($acesso === 'todos' || !empty($utilizadorSelecionado))) {
    $query = "SELECT 
          rd.data_trabalho, 
          rd.id_tarefa, 
          rd.tempo_pausa, 
          t.id, 
          t.tarefa AS nome_tarefa,
          t.descricao,
          t.estado,
          t.estado_cronometro,
          t.utilizador AS responsavel_atual,
          t.data_criacao,
          t.data_fim,
          t.tempo_decorrido,
          t.tempo_decorrido_utilizador,
          f.nome AS nome_utilizador
      FROM registo_diario rd
      LEFT JOIN tarefas t ON rd.id_tarefa = t.id
      LEFT JOIN funcionarios f ON rd.utilizador = f.utilizador
      WHERE 1 = 1
      AND t.estado != 'eliminada'";
    $params = [];
    
    // Filtra por utilizador se selecionado e válido
    if (!empty($utilizadorSelecionado) && in_array($utilizadorSelecionado, $utilizadores)) {
        $query .= " AND rd.utilizador = ?";
        $params[] = $utilizadorSelecionado;
    }

    // Filtra por tarefa se selecionada e válida
    if (!empty($tarefaSelecionada)) {
        $query .= " AND rd.id_tarefa = ?";
        $params[] = $tarefaSelecionada;
    }

    if (!empty($estadoSelecionado)) {
        $query .= " AND t.estado = ?";
        $params[] = $estadoSelecionado;
    }

    if (!empty($cronometroSelecionado)) {
        $query .= " AND t.estado_cronometro = ?";
        $params[] = $cronometroSelecionado;
    }

    if (!empty($dataInicio)) {
        $query .= " AND rd.data_trabalho >= ?";
        $params[] = $dataInicio;
    }

    if (!empty($dataFim)) {
        $query .= " AND rd.data_trabalho <= ?";
        $params[] = $dataFim;
    }

    $query .= " ORDER BY rd.data_trabalho DESC";

    $stmt = $ligacao->prepare($query);
    $stmt->execute($params);
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
}


$detalhesPorTarefa = [];

$tarefaIDs = array_unique(array_column($resultados, 'id_tarefa'));


foreach ($tarefaIDs as $idTarefa) {
    $stmtTarefa = $ligacao->prepare("
      SELECT 
        t.tarefa, 
        t.descricao, 
        t.estado, 
        t.utilizador, 
        t.data_criacao, 
        t.data_fim, 
        t.tempo_decorrido,
        COALESCE(SUM(
          CASE 
            WHEN mp.tipo != 'PararContadores' THEN TIME_TO_SEC(p.tempo_pausa)
            ELSE 0
          END
        ), 0) AS total_pausa_segundos
      FROM tarefas t
      LEFT JOIN pausas_tarefas p ON t.id = p.tarefa_id
      LEFT JOIN motivos_pausa mp ON p.motivo_id = mp.id
      WHERE t.id = ? AND t.estado != 'eliminada'
      GROUP BY t.id
    ");
    $stmtTarefa->execute([$idTarefa]);
    $dados = $stmtTarefa->fetch(PDO::FETCH_ASSOC);

    if (!$dados) continue;

    $nomeTarefa = $dados['tarefa'];

    $stmtNome = $ligacao->prepare("SELECT nome FROM funcionarios WHERE utilizador = ?");
    $stmtNome->execute([$dados['utilizador']]);
    $nomeUtil = $stmtNome->fetchColumn() ?: $dados['utilizador'];

    $detalhesPorTarefa[$nomeTarefa] = [
        'descricao' => $dados['descricao'] ?? '',
        'utilizador' => $dados['utilizador'] ?? '',
        'nome_utilizador' => $nomeUtil,
        'estado' => $dados['estado'] ?? '',
        'data_criacao' => $dados['data_criacao'] ?? '',
        'data_fim' => $dados['data_fim'] ?? '',
        'tempo_total' => $dados['tempo_decorrido'] ?? '00:00:00',
        'tempo_total_pausa' => gmdate("H:i:s", $dados['total_pausa_segundos'] ?? 0)
    ];
}


$pausasPorTarefaJS = [];
$transicoesPorTarefaJS = [];
$esperasPorDepartamentoPorTarefa = [];
function normalizarNome($nome) {
    // Remove acentos, põe tudo em minúsculas e remove espaços extra
    $nome = iconv('UTF-8', 'ASCII//TRANSLIT', $nome); // remove acentos
    $nome = strtolower($nome); // tudo minúsculas
    $nome = preg_replace('/\s+/', '', $nome); // remove espaços
    return $nome;
}


foreach ($resultados as $linha) {
  $idTarefa = $linha['id_tarefa'];
  $nomeTarefa = $linha['nome_tarefa'] ?? 'N/D';

  $stmtEsperas = $ligacao->prepare("
      SELECT dt.*, d.nome 
      FROM departamento_tarefa dt
      JOIN departamento d ON dt.departamento_id = d.id
      WHERE dt.tarefa_id = ?
  ");
  $stmtEsperas->execute([$idTarefa]);
  $esperas = $stmtEsperas->fetchAll(PDO::FETCH_ASSOC);

  $esperasPorDepartamento = [];

  usort($esperas, function($a, $b) {
      return strtotime($a['data_entrada']) - strtotime($b['data_entrada']);
  });

  foreach ($esperas as $espera) {
      $departamento = $espera['nome'];
      $dataInicio = $espera['data_entrada'];
      $dataFim = $espera['data_saida'];
      $dataInicioI = new DateTime($espera['data_entrada']);
      $dataFimI = new DateTime($espera['data_saida']);

      $segundos = $dataFimI->getTimestamp() - $dataInicioI->getTimestamp();
      $horas = floor($segundos / 3600);
      $minutos = floor(($segundos % 3600) / 60);
      $segundosRestantes = $segundos % 60;

      $duracao = sprintf('%02d:%02d:%02d', $horas, $minutos, $segundosRestantes);
      $user=$espera['utilizador'];
      $tempoFormatado = $espera['tempo_em_espera'] ?? '00:00:00';

      if (!isset($esperasPorDepartamento[$departamento])) {
          $esperasPorDepartamento[$departamento] = [];
      }

      $esperasPorDepartamento[$departamento][] = [
          'utilizador' => $user,
          'inicio' => $dataInicio,
          'fim' => $dataFim,
          'tempo' => $tempoFormatado,
          'demora' => $duracao
      ];
  }

  // Guarda no array principal, por nome de tarefa
  $esperasPorDepartamentoPorTarefa[$nomeTarefa] = $esperasPorDepartamento;

  // Também podes guardar dentro de $detalhesPorTarefa se quiseres:
  $detalhesPorTarefa[$nomeTarefa]['esperas_por_departamento'] = $esperasPorDepartamento;

  if (!isset($pausasPorTarefaJS[$nomeTarefa])) {
    $pausasPorTarefaJS[$nomeTarefa] = [];
  }

  if ($acesso === 'todos') {
        $stmtUtilizadores = $ligacao->prepare("
      SELECT DISTINCT pt.funcionario, f.nome
      FROM pausas_tarefas pt
      INNER JOIN funcionarios f ON pt.funcionario = f.utilizador
      WHERE pt.tarefa_id = :id
    ");
    $stmtUtilizadores->execute(['id' => $idTarefa]);
    $utilizadoresDaTarefa = $stmtUtilizadores->fetchAll(PDO::FETCH_ASSOC);

    foreach ($utilizadoresDaTarefa as $rowUser) {
      $userAcronimo = $rowUser['funcionario'];
      $userNome = $rowUser['nome'];
      
      $stmtPausas = $ligacao->prepare("
        SELECT 
          mp.descricao AS tipo_pausa,
          pt.data_pausa,
          pt.data_retorno
        FROM pausas_tarefas pt
        INNER JOIN motivos_pausa mp ON pt.motivo_id = mp.id
        WHERE pt.tarefa_id = :id AND pt.funcionario = :user
        ORDER BY pt.data_pausa ASC
      ");
      $stmtPausas->execute([
        'id' => $idTarefa,
        'user' => $userAcronimo
      ]);

      $pausas = [];
      foreach ($stmtPausas->fetchAll(PDO::FETCH_ASSOC) as $pausa) {
          $inicio = strtotime($pausa['data_pausa']);
          $fim = strtotime($pausa['data_retorno']);
          $duracao = $fim ? $fim - $inicio : 0;

          $pausas[] = [
              $pausa['tipo_pausa'],
              date('H:i:s', $inicio),
              $fim ? date('H:i:s', $fim) : 'Em execução',
              $fim ? sprintf('%02d:%02d:%02d', $duracao / 3600, ($duracao % 3600) / 60, $duracao % 60) : 'Em execução'
          ];
      }

      $pausasPorTarefaJS[$nomeTarefa][$userAcronimo] = $pausas;
    }


  } else {
    // Apenas o utilizador atual
    $stmtPausas = $ligacao->prepare("
      SELECT 
        mp.descricao AS tipo_pausa,
        pt.data_pausa,
        pt.data_retorno
      FROM pausas_tarefas pt
      INNER JOIN motivos_pausa mp ON pt.motivo_id = mp.id
      WHERE pt.tarefa_id = :id AND pt.funcionario = :util
      ORDER BY pt.data_pausa ASC
    ");
    $stmtPausas->execute([
      'id' => $idTarefa,
      'util' => $utilizadorSelecionado
    ]);

    $pausas = [];
    foreach ($stmtPausas->fetchAll(PDO::FETCH_ASSOC) as $pausa) {
      $inicio = strtotime($pausa['data_pausa']);
      $fim = strtotime($pausa['data_retorno']);
      $duracao = $fim ? $fim - $inicio : 0;

      $pausas[] = [
        $pausa['tipo_pausa'],
        date('H:i:s', $inicio),
        $fim ? date('H:i:s', $fim) : 'Em execução',
        $fim ? sprintf('%02d:%02d:%02d', $duracao / 3600, ($duracao % 3600) / 60, $duracao % 60) : 'Em execução'
      ];
    }

    $pausasPorTarefaJS[$nomeTarefa][$userData['nome']] = $pausas;
  }

  $temposExecucao = [];
  foreach ($esperasPorDepartamento as $entradas) {
      foreach ($entradas as $entrada) {
          $nome = $entrada['utilizador'];
          $norm = normalizarNome($nome);
          $segundos = 0;
          if (!empty($entrada['demora'])) {
              sscanf($entrada['demora'], "%d:%d:%d", $h, $m, $s);
              $segundos = ($h * 3600) + ($m * 60) + $s;
          }
          if (!isset($temposExecucao[$norm])) {
              $temposExecucao[$norm] = ['nome_original' => $nome, 'segundos' => 0];
          }
          $temposExecucao[$norm]['segundos'] += $segundos;
      }
  }

  foreach ($pausasPorTarefaJS[$nomeTarefa] ?? [] as $user => $pausas) {
      $norm = normalizarNome($user);
      foreach ($pausas as $p) {
          $duracao = $p[3] ?? '00:00:00';
          sscanf($duracao, "%d:%d:%d", $h, $m, $s);
          $segundos = ($h * 3600) + ($m * 60) + $s;
          if (!isset($temposExecucao[$norm])) {
              $temposExecucao[$norm] = ['nome_original' => $user, 'segundos' => 0];
          }
          $temposExecucao[$norm]['segundos'] -= $segundos;
      }
  }

  foreach ($temposExecucao as $norm => $info) {
      $seg = max(0, $info['segundos']);
      $tempoFormatado = gmdate("H:i:s", $seg);
      $tempoLiquidoPorTarefa[$nomeTarefa][$info['nome_original']] = $tempoFormatado;
  }


  // TRANSIÇÕES por tarefa (sem alteração)
  $stmtTrans = $ligacao->prepare("
    SELECT utilizador_antigo, utilizador_novo, dataHora_transicao
    FROM transicao_tarefas
    WHERE tarefa_id = :id
    ORDER BY dataHora_transicao ASC
  ");
  $stmtTrans->execute(['id' => $idTarefa]);

  $transicoes = [];
  foreach ($stmtTrans->fetchAll(PDO::FETCH_ASSOC) as $trans) {
    $data = strtotime($trans['dataHora_transicao']);
    $transicoes[] = [
      $trans['utilizador_antigo'],
      $trans['utilizador_novo'],
      date('d/m/Y', $data),
      date('H:i', $data)
    ];
  }

  $transicoesPorTarefaJS[$nomeTarefa] = $transicoes;
}
?>


<script>
  const pausasPorTarefa = <?= json_encode($pausasPorTarefaJS, JSON_UNESCAPED_UNICODE) ?>;
  const transicoesPorTarefa = <?= json_encode($transicoesPorTarefaJS, JSON_UNESCAPED_UNICODE) ?>;
  const esperasPorTarefa = <?= json_encode($esperasPorDepartamentoPorTarefa, JSON_UNESCAPED_UNICODE) ?>;
  const tempoLiquidoPorTarefa = <?= json_encode($tempoLiquidoPorTarefa) ?>;
</script>


<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <title>Relatórios</title>
  <link rel="stylesheet" href="../style.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.29/jspdf.plugin.autotable.min.js"></script>
  <style>
    body {
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      background-color: #f5f5f5;
      padding: 20px;
    }
    .container {
      max-width: 1500px;
      margin: 0 auto;
      background: white;
      padding: 30px;
      border-radius: 10px;
      box-shadow: 0 0 12px rgba(0,0,0,0.1);
    }
    h1 {
      margin-bottom: 20px;
      color: #333;
    }
    form {
      display: flex;
      flex-wrap: wrap;
      gap: 15px;
      margin-bottom: 30px;
      align-items: flex-end;
    }
    label {
      font-weight: bold;
    }
    select, input[type="date"] {
      padding: 8px;
      border: 1px solid #ccc;
      border-radius: 6px;
    }
    button {
      padding: 10px 20px;
      background-color: #cfa728;
      color: black;
      font-weight: bold;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      transition: background-color 0.2s;
    }
    button:hover {
      background-color: #b69020;
    }

    * {
  box-sizing: border-box;
}

    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 20px;
    }
    th, td {
      border: 1px solid #ddd;
      padding: 10px;
      text-align: left;
    }
    th {
      background-color: #f2f2f2;
    }
    .actions {
      display: flex;
      justify-content: space-between;
      margin-bottom: 20px;
    }

    input, select {
      padding: 8px;
      border: 1px solid #ccc;
      border-radius: 6px;
    }

    .campo-filtro {
      padding: 8px;
      border: 1px solid #ccc;
      border-radius: 6px;
      font-size: 14px;
      width: 220px;
      box-sizing: border-box;
    }


  </style>
</head>
<body>
<img id="logoPDF" src="../logo_portos.jpg" style="display: none;" />
<div class="container">
  <h1>Relatório de Tarefas</h1>

  <?php if (!empty($erroUtilizador)): ?>
  <p style="color: red; font-weight: bold;"><?= htmlspecialchars($erroUtilizador) ?></p>
<?php endif; ?>

  <div class="actions">
    <button style="margin-right: 10px;" onclick="window.location.href='painel.php'">← Voltar ao Painel</button>
    <?php if (!empty($resultados)): ?>
      <button onclick="gerarPDF()">Baixar PDF</button>
    <?php endif; ?>
  </div>

  <form method="get">
    <div>
      <label for="tarefa">Tarefa:</label><br>
      <input class="campo-filtro" list="listaTarefas" name="tarefa_nome" id="tarefa" value="<?= htmlspecialchars($tarefaSelecionadaNome ?? '') ?>" placeholder="Digite ou selecione a tarefa">
      <datalist id="listaTarefas">
        <?php foreach ($tarefasAssoc as $id => $nome): ?>
          <option data-id="<?= $id ?>" value="<?= htmlspecialchars($nome) ?>"></option>
        <?php endforeach; ?>
      </datalist>
    </div>
    <div>
      <label for="utilizador">Utilizador:</label><br>
      <input class="campo-filtro" list="listaUtilizadores" name="utilizador" id="utilizador"
            value="<?= htmlspecialchars($utilizadorSelecionado ?? '') ?>" placeholder="Digite ou selecione o utilizador" <?= $acesso !== 'todos' ? 'required' : '' ?>>
      <datalist id="listaUtilizadores">
        <?php foreach ($utilizadores as $user): ?>
          <option value="<?= htmlspecialchars($user) ?>"></option>
        <?php endforeach; ?>
      </datalist>
    </div>
    <div>
      <label for="data_inicio">Data Início:</label><br>
      <input type="date" name="data_inicio" id="data_inicio" value="<?= htmlspecialchars($dataInicio) ?>">
    </div>
    <div>
      <label for="data_fim">Data Fim:</label><br>
      <input type="date" name="data_fim" id="data_fim" value="<?= htmlspecialchars($dataFim) ?>">
    </div>
    <div>
      <label for="estado_tarefa">Estado da Tarefa:</label><br>
      <select name="estado_tarefa" id="estado_tarefa" class="campo-filtro">
        <option value="">Todas</option>
        <option value="pendente" <?= isset($_GET['estado_tarefa']) && $_GET['estado_tarefa'] === 'pendente' ? 'selected' : '' ?>>Pendente</option>
        <option value="concluida" <?= isset($_GET['estado_tarefa']) && $_GET['estado_tarefa'] === 'concluida' ? 'selected' : '' ?>>Concluída</option>
      </select>
    </div>

    <div>
      <label for="estado_cronometro">Estado Atual:</label><br>
      <select name="estado_cronometro" id="estado_cronometro" class="campo-filtro">
        <option value="">Todos</option>
        <option value="ativa" <?= isset($_GET['estado_cronometro']) && $_GET['estado_cronometro'] === 'ativa' ? 'selected' : '' ?>>Em execução</option>
        <option value="pausa" <?= isset($_GET['estado_cronometro']) && $_GET['estado_cronometro'] === 'pausa' ? 'selected' : '' ?>>Em pausa</option>
        <option value="inativa" <?= isset($_GET['estado_cronometro']) && $_GET['estado_cronometro'] === 'inativa' ? 'selected' : '' ?>>Finalizada/Em espera</option>
      </select>
    </div>
    <div>
      <button type="submit">Pesquisar</button>
    </div>
  </form>

  <?php if (!empty($resultados)): ?>
    <table id="tabelaRelatorio">
      <thead>
        <tr>
          <th>Data</th>
          <th>Utilizador</th>
          <th>Tarefa</th>
          <th>Estado Atual</th>
          <th>Tempo Gasto</th>
          <th>Tempo Pausa</th>
          <th>Tempo em Listas de Espera</th>
          <th style="text-align: center;">PDF</th>
        </tr>
      </thead>
      <tbody>
      <?php
      // Agrupar por id_tarefa e manter o mais recente
      $tarefasMaisRecentes = [];
      $tempoLiquidoPorTarefa = [];


      foreach ($resultados as $linha) {
          $idTarefa = $linha['id_tarefa'];
          $data = $linha['data_trabalho'];

          // Só substitui se a tarefa ainda não está no array OU se a data for mais recente
          if (!isset($tarefasMaisRecentes[$idTarefa]) || $data > $tarefasMaisRecentes[$idTarefa]['data_trabalho']) {
              $tarefasMaisRecentes[$idTarefa] = $linha;

              // Buscar estado da tarefa atual
              $stmtEstado = $ligacao->prepare("SELECT estado FROM tarefas WHERE id = ?");
              $stmtEstado->execute([$idTarefa]);
              $estadoTarefa = $stmtEstado->fetchColumn();

              $tarefasMaisRecentes[$idTarefa]['estado_tarefa'] = $estadoTarefa ?: 'N/D';
          }
      }
      ?>

      <?php foreach ($tarefasMaisRecentes as $linha): ?>
          <?php
            $nomeTarefa = $linha['nome_tarefa'] ?? 'N/D';
            $tempoPausaTotal = $detalhesPorTarefa[$nomeTarefa]['tempo_total_pausa'] ?? '00:00:00';
          ?>
          <tr>
            <td><?= htmlspecialchars($linha['data_trabalho']) ?></td>
            <td><?= htmlspecialchars($linha['nome_utilizador']) ?></td>
            <td>
              <?= htmlspecialchars($nomeTarefa) ?><br>
              <small><em>(<?= htmlspecialchars($linha['estado_tarefa']) ?>)</em></small>
            </td>
            <?php
              // Caso contrário, lógica normal
              $tempoGasto = '00:00:00';
              $estadoTarefa = $linha['estado_tarefa'] ?? '';
              $tempoDecorrido = $linha['tempo_decorrido_utilizador'] ?? '00:00:00';

              if ($estadoTarefa === 'pendente') {
                  // Obter data de criação da tarefa
                  $stmtCriacao = $ligacao->prepare("SELECT data_criacao FROM tarefas WHERE id = ?");
                  $stmtCriacao->execute([$linha['id_tarefa']]);
                  $dataCriacao = $stmtCriacao->fetchColumn();

                  if ($dataCriacao) {
                      $inicio = new DateTime($dataCriacao, new DateTimeZone('Europe/Lisbon'));
                      $agora = new DateTime('now', new DateTimeZone('Europe/Lisbon'));

                      // Pausas
                      $stmtPausas = $ligacao->prepare("
                          SELECT TIME_TO_SEC(tempo_pausa) AS segundos
                          FROM pausas_tarefas
                          WHERE tarefa_id = ?
                      ");
                      $stmtPausas->execute([$linha['id_tarefa']]);
                      $segundosTotalPausa = array_sum($stmtPausas->fetchAll(PDO::FETCH_COLUMN));

                      $stmtFinalizarDia = $ligacao->prepare("
                        SELECT * FROM finalizar_dia 
                        WHERE tarefa_id=? AND datahora_fimdedia>=?
                      ");
                      $stmtFinalizarDia->execute([$linha['id_tarefa'], $inicio->format('Y-m-d H:i:s')]);

                      $registosFinalizacao = $stmtFinalizarDia->fetchAll(PDO::FETCH_ASSOC);

                      $segundosInterrompidos = 0;

                      foreach ($registosFinalizacao as $registo) {
                          if (!empty($registo['datahora_iniciodiaseguinte'])) {
                              $fim = !empty($registo['datahora_fimdedia'])
                                  ? new DateTime($registo['datahora_fimdedia'], new DateTimeZone('Europe/Lisbon'))
                                  : new DateTime('now', new DateTimeZone('Europe/Lisbon'));

                              $inicioSeguinte = new DateTime($registo['datahora_iniciodiaseguinte'], new DateTimeZone('Europe/Lisbon'));

                              // Soma a diferença real entre fim e início do dia seguinte
                              $intervalo = $inicioSeguinte->getTimestamp() - $fim->getTimestamp();
                              $segundosInterrompidos += max(0, $intervalo);
                          }
                      }

                      // Tempo útil = tempo total - pausas - interrupções
                      $segundosTotal = $agora->getTimestamp() - $inicio->getTimestamp() - $segundosTotalPausa - $segundosInterrompidos;

                      $tempoGasto = gmdate('H:i:s', max(0, $segundosTotal));

                  }
              } else {
                  // Se não for pendente, usa tempo_decorrido_utilizador ou tenta buscar da BD
                  if (!empty($tempoDecorrido) && $tempoDecorrido !== '00:00:00') {
                      $tempoGasto = $tempoDecorrido;
                  } else {
                      $stmtTempo = $ligacao->prepare("SELECT tempo_decorrido FROM tarefas WHERE id = ?");
                      $stmtTempo->execute([$linha['id_tarefa']]);
                      $tempoAlternativo = $stmtTempo->fetchColumn();

                      if (!empty($tempoAlternativo) && $tempoAlternativo !== '00:00:00') {
                          $tempoGasto = $tempoAlternativo;
                      }
                  }
              }

              $stmt = $ligacao->prepare("
                  SELECT SUM(TIME_TO_SEC(tempo_em_espera)) 
                  FROM departamento_tarefa 
                  WHERE tarefa_id = ?
              ");
              $stmt->execute([$linha['id_tarefa']]);
              $totalSegundos = (int) $stmt->fetchColumn();

              $horas = str_pad(floor($totalSegundos / 3600), 2, '0', STR_PAD_LEFT);
              $minutos = str_pad(floor(($totalSegundos % 3600) / 60), 2, '0', STR_PAD_LEFT);
              $segundos = str_pad($totalSegundos % 60, 2, '0', STR_PAD_LEFT);

              $tempoEmEspera = "{$horas}:{$minutos}:{$segundos}";

            ?>
            <td>
              <?php
              $estadoCronometro = $linha['estado_cronometro'] ?? '';
              echo match ($estadoCronometro) {
                'ativa'   => 'Em execução',
                'inativa' => 'Finalizada/Em espera',
                'pausa'   => 'Em pausa',
                default   => 'Desconhecido'
              };
              ?>
            </td>
            <td><?= htmlspecialchars($tempoGasto) ?></td>
            <td><?= htmlspecialchars($tempoPausaTotal) ?></td>
            <td><?= htmlspecialchars($tempoEmEspera)?></td>
            <td style="text-align: center;">
              <?php $tarefaJS = htmlspecialchars(json_encode($nomeTarefa), ENT_QUOTES, 'UTF-8'); ?>
              <?php 
                $estado = $linha['estado_tarefa'] ?? '';
                if ($estado === 'concluida'): ?>
                  <button onclick="gerarPDFTarefa(<?= $tarefaJS ?>)" style="background-color: #05ac05ff; color: white; font-weight: bold;">PDF</button>
              <?php elseif ($estado === 'espera'): ?>
                  <button onclick="gerarPDFTarefa(<?= $tarefaJS ?>)" style="background-color: #2980b9; color: white; font-weight: bold;">PDF</button>
              <?php else: ?>
                  <button onclick="gerarPDFTarefa(<?= $tarefaJS ?>)" style="background-color: #c0392b; color: white; font-weight: bold;">PDF</button>
              <?php endif; ?>
            </td>
          </tr>
      <?php endforeach; ?>

      </tbody>
    </table>

  <?php elseif ($_GET): ?>
    <p><strong>Nenhum registo encontrado</strong> para os filtros aplicados.</p>
  <?php endif; ?>

</div>

<script>
document.getElementById('estado_tarefa').addEventListener('change', function () {
  const estadoTarefa = this.value;
  const estadoCronometro = document.getElementById('estado_cronometro');

  if (estadoTarefa === 'concluida') {
    estadoCronometro.value = 'inativa';
    estadoCronometro.disabled = true;
  } else {
    estadoCronometro.disabled = false;
  }
});

// Ao carregar a página, se já estiver "concluida", força também
window.addEventListener('DOMContentLoaded', function () {
  const estadoTarefa = document.getElementById('estado_tarefa').value;
  const estadoCronometro = document.getElementById('estado_cronometro');
  if (estadoTarefa === 'concluida') {
    estadoCronometro.value = 'inativa';
    estadoCronometro.disabled = true;
  }
});
</script>


<script>


function desenharCabecalho(doc, logoBase64, nomeTarefa) {
  // Logotipo
  doc.addImage(logoBase64, 'JPEG', 160, 10, 35, 15);

  // Título
  doc.setFont("helvetica", "bold");
  doc.setFontSize(14);
  doc.setTextColor(30, 30, 30);
  doc.text("Relatório de Execução da Tarefa", 14, 20);

  // Nome da tarefa (subtítulo)
  doc.setFontSize(11);
  doc.setFont("helvetica", "normal");
  doc.setTextColor(80, 80, 80);
  doc.text(`Tarefa: ${nomeTarefa}`, 14, 28);

  // Linha divisória inferior
  doc.setDrawColor(180);
  doc.line(14, 32, 196, 32);
}

function gerarPDF() {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF();

  const logoImg = document.getElementById("logoPDF");

  if (!logoImg.complete) {
    logoImg.onload = () => gerarPDF();
    return;
  }

  // Converter imagem para base64
  const canvas = document.createElement("canvas");
  canvas.width = logoImg.naturalWidth;
  canvas.height = logoImg.naturalHeight;
  const ctx = canvas.getContext("2d");
  ctx.drawImage(logoImg, 0, 0);
  const logoBase64 = canvas.toDataURL("image/jpeg");

  const nomeUtilizador = <?= json_encode($userData['nome']) ?>;

  // Cabeçalho
  doc.addImage(logoBase64, 'JPEG', 160, 10, 35, 15);
  doc.setFont("helvetica", "bold");
  doc.setFontSize(18);
  doc.setTextColor(30, 30, 30);
  doc.text("Informações de Tarefas", 14, 26);
  doc.setDrawColor(180);
  doc.setLineWidth(0.5);
  doc.line(14, 32, 196, 32);

  let y = 40;
  const margemInferior = 25;

  document.querySelectorAll("#tabelaRelatorio tbody tr").forEach((tr, index) => {
    const colunas = tr.querySelectorAll("td");
    const tarefa = colunas[2].innerText.trim();
    const estado = colunas[3].innerText.trim();
    const tempoExec = colunas[4].innerText.trim();
    const tempoPausa = colunas[5].innerText.trim();
    const data = colunas[0].innerText.trim();
    const utilizador = colunas[1].innerText.trim();

    const bloco = [
      `Utilizador: ${utilizador}`,
      `Data: ${data}`,
      `Estado Geral da Tarefa: ${estado}`,
      `Tempo de Execução: ${tempoExec}`,
      `Tempo de Pausa: ${tempoPausa}`
    ];


    const alturaBloco = bloco.length * 7 + 15;

    if (y + alturaBloco > doc.internal.pageSize.height - margemInferior) {
      doc.addPage();
      y = 20;
    }

    // Título da tarefa com cor
    doc.setFont("helvetica", "bold");
    doc.setFontSize(12);
    doc.setTextColor(35, 65, 140); // Azul escuro
    doc.text(`Tarefa ${index + 1}: ${tarefa}`, 14, y);
    y += 8;
    y += 4; // Espaço extra entre título e conteúdo

    // Texto do bloco em preto
    doc.setFont("helvetica", "normal");
    doc.setFontSize(11);
    doc.setTextColor(0, 0, 0);
    bloco.forEach((linha) => {
      doc.text(linha, 20, y);
      y += 7;
    });

    // Separador
    y += 3;
    doc.setDrawColor(180);
    doc.setLineWidth(0.3);
    doc.line(14, y, 196, y);
    y += 7;
  });

  // Rodapé com nome e página
  const totalPages = doc.internal.getNumberOfPages();
  for (let i = 1; i <= totalPages; i++) {
    doc.setPage(i);
    const pageHeight = doc.internal.pageSize.height;

    doc.setDrawColor(180);
    doc.line(14, pageHeight - 16, 196, pageHeight - 16);

    doc.setFontSize(9);
    doc.setTextColor(100);
    doc.text(nomeUtilizador, 14, pageHeight - 10.5);

    const paginaTexto = `Página ${i}`;
    const textoWidth = doc.getTextWidth(paginaTexto);
    const logoX = 196 - 12;
    const logoY = pageHeight - 13;
    doc.addImage(logoBase64, 'JPEG', logoX, logoY, 12, 5);
    doc.text(paginaTexto, logoX - textoWidth - 2, pageHeight - 10.5);
  }

  doc.save("Relatorio_Tarefas_Total.pdf");
}




function formatarDataHora(dateTimeStr) {
  if (!dateTimeStr || dateTimeStr === 'N/D') return 'N/D';
  const data = new Date(dateTimeStr);
  if (isNaN(data)) return dateTimeStr;

  const dia = String(data.getDate()).padStart(2, '0');
  const mes = String(data.getMonth() + 1).padStart(2, '0');
  const ano = data.getFullYear();

  const horas = String(data.getHours()).padStart(2, '0');
  const minutos = String(data.getMinutes()).padStart(2, '0');

  return `${dia}/${mes}/${ano} às ${horas}h${minutos}`;
}



async function gerarPDFTarefa(nomeTarefa) {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF();

  const logoImg = document.getElementById("logoPDF");

  // Aguarda o carregamento do logo
  if (!logoImg.complete) {
    await new Promise(resolve => logoImg.onload = resolve);
  }

  // Desenhar logo à direita
  const canvas = document.createElement("canvas");
  canvas.width = logoImg.naturalWidth;
  canvas.height = logoImg.naturalHeight;
  const ctx = canvas.getContext("2d");
  ctx.drawImage(logoImg, 0, 0);
  const logoBase64 = canvas.toDataURL("image/jpeg");
  doc.addImage(logoBase64, 'JPEG', 160, 10, 35, 15); // canto superior direito

  // Dados da tarefa e do utilizador
  const utilizador = <?= json_encode($userData['nome']) ?>;
  const emailUtilizador = <?= json_encode($userData['email']) ?>;
  const relatorioAcesso = <?= json_encode($userData['relatorio_acesso']) ?>;
  const detalhesTarefas = <?= json_encode($detalhesPorTarefa) ?>;
  const acessoTotal = relatorioAcesso === 'todos';

  const tarefaData = detalhesTarefas[nomeTarefa] || {};
  const nomeResponsavel = tarefaData.nome_utilizador || tarefaData.utilizador || "N/D";
  const descricao = tarefaData.descricao || "N/D";
  const estado = tarefaData.estado || "N/D";
  const dataCriacao = formatarDataHora(tarefaData.data_criacao || "N/D");
  const dataFim = formatarDataHora(tarefaData.data_fim || "N/D");
  const tempoTotal = tarefaData.tempo_total || "00:00:00";
  const tempoPausaTotal = tarefaData.tempo_total_pausa || "00:00:00";


  // Título e Subtítulo (alinhados à esquerda)
  doc.setFontSize(18);
  doc.setFont("helvetica", "bold");
  doc.setTextColor(30, 30, 30);
  doc.text("Relatório de Execução da Tarefa", 14, 20);

  doc.setFontSize(13);
  doc.setFont("helvetica", "normal");
  doc.setTextColor(80, 80, 80);
  doc.text(`Tarefa: ${nomeTarefa}`, 14, 28);

  // Info do utilizador
  doc.setFontSize(11);
  doc.setTextColor(0, 0, 0);
  doc.text(`Relatório gerado por: ${utilizador} (${emailUtilizador})`, 14, 36);

  // Linha divisória
  doc.setDrawColor(180);
  doc.line(14, 40, 196, 40);

  // Secção: Detalhes da tarefa
  doc.setFont("helvetica", "bold");
  doc.setFontSize(12);
  doc.setTextColor(50, 50, 50);
  doc.text("Resumo da Tarefa", 14, 50);
  doc.line(14, 52, 196, 52);

  doc.setFont("helvetica", "normal");
  doc.setFontSize(11);
  doc.setLineHeightFactor(1.5);
  let y = 60;

  // Descrição com quebra automática de linha
  const descricaoFormatada = doc.splitTextToSize(`Descrição: ${descricao}`, 180);
  doc.text(descricaoFormatada, 14, y);
  y += descricaoFormatada.length * 6 + 4; // espaço extra após a descrição

  // Restantes linhas (estado, criação, etc.)
  const detalhes = [
    `Responsável atual: ${nomeResponsavel}`,
    `Estado atual: ${estado}`,
    `Criada em: ${dataCriacao}`,
    `Finalizada em: ${dataFim}`,
    `Tempo total de Execução da Tarefa: ${tempoTotal}`,
    `Tempo total de Pausas: ${tempoPausaTotal}`
  ];
  detalhes.forEach(linha => {
    doc.text(linha, 14, y);
    y += 6;
  });

  y += 12; 
    
  if (acessoTotal && tempoLiquidoPorTarefa[nomeTarefa]) {
  const liquidos = tempoLiquidoPorTarefa[nomeTarefa];

  const nomesOrdenados = Object.keys(liquidos).sort((a, b) => a.localeCompare(b));

  const corpoTabela = nomesOrdenados.map(nome => [nome, liquidos[nome]]);

  if (corpoTabela.length > 0) {
    doc.setFont("helvetica", "bold");
    doc.setFontSize(12);
    doc.setTextColor(50, 50, 50);
    doc.text("Tempo Líquido por Utilizador", 14, y);
    doc.line(14, y + 2, 196, y + 2);
    y += 8;

    doc.autoTable({
      startY: y,
      head: [['Utilizador', 'Tempo Líquido']],
      body: corpoTabela,
      theme: 'striped',
      styles: { fontSize: 10, cellPadding: 3 },
      headStyles: {
        fillColor: [0, 128, 0],
        textColor: 255,
        fontStyle: 'bold',
        halign: 'center'
      },
      bodyStyles: {
        valign: 'middle',
        halign: 'center'
      },
      margin: { left: 14, right: 14, bottom: 20 }
    });

    y = doc.lastAutoTable.finalY + 10;
  }
}


  const pausasTarefa = pausasPorTarefa[nomeTarefa] || {};
  const esperasTarefa = esperasPorTarefa[nomeTarefa] || {};

  if (acessoTotal) {

    // ------------------------
    // 1. Tempo em Listas de Espera por Departamento
    // ------------------------
    if (Object.keys(esperasTarefa).length > 0) {
      y += 12;
      doc.setFont("helvetica", "bold");
      doc.setFontSize(12);
      doc.setTextColor(50, 50, 50);
      doc.text("Tempo em Listas de Espera por Departamento", 14, y);
      doc.line(14, y + 2, 196, y + 2);
      y += 8;

      for (const [departamento, entradas] of Object.entries(esperasTarefa)) {
        const margemInferior = 20;
        const linhas = entradas.length || 1;
        const altura = 6 + 4 + (linhas * 8) + 10;

        if (y + altura >= doc.internal.pageSize.height - margemInferior) {
          doc.addPage();
          y = 20;
        } else {
          y += 6;
        }

        doc.setFont("helvetica", "bold");
        doc.setFontSize(11);
        doc.setTextColor(60, 60, 60);
        doc.text(`Departamento: ${departamento}`, 14, y);
        y += 6;

        const corpoTabela = entradas.map(item => [
          item.utilizador, item.inicio || '-', item.fim || 'Em curso', item.tempo || '00:00:00', item.demora || '00:00:00'
        ]);

        doc.autoTable({
          startY: y,
          head: [['Utilizador','Início', 'Fim', 'Tempo de Espera', 'Tempo Total de Tarefa']],
          body: corpoTabela,
          theme: 'striped',
          styles: { fontSize: 10, cellPadding: 3 },
          headStyles: {
            fillColor: [100, 150, 200],
            textColor: 20,
            fontStyle: 'bold',
            halign: 'center'
          },
          bodyStyles: {
            valign: 'middle',
            halign: 'center'
          },
          margin: { left: 14, right: 14, bottom: 20 }
        });

        y = doc.lastAutoTable.finalY + 10;
      }
    }

    // ------------------------
    // 2. Pausas (todos os utilizadores)
    // ------------------------
    y += 12;
    doc.setFont("helvetica", "bold");
    doc.setFontSize(12);
    doc.setTextColor(50, 50, 50);
    doc.text("Pausas (todos os utilizadores)", 14, y);
    doc.line(14, y + 2, 196, y + 2);
    y += 8;

    for (const [user, pausasArray] of Object.entries(pausasTarefa)) {
      const margemInferior = 20;
      const linhasTabela = pausasArray.length || 1;
      const alturaNecessaria = 6 + 4 + (linhasTabela * 8) + 10;

      if (y + alturaNecessaria >= doc.internal.pageSize.height - margemInferior) {
        doc.addPage();
        y = 20;
      } else {
        y += 10;
      }

      doc.setFont("helvetica", "bold");
      doc.setFontSize(11);
      doc.setTextColor(60, 60, 60);
      doc.text(`Utilizador: ${user}`, 14, y);
      y += 6;

      doc.autoTable({
        startY: y,
        head: [['Tipo de Pausa', 'Hora de Início', 'Hora de Fim', 'Duração']],
        body: pausasArray,
        theme: 'striped',
        styles: { fontSize: 10, cellPadding: 3 },
        headStyles: {
          fillColor: [207, 167, 40],
          textColor: 20,
          fontStyle: 'bold',
          halign: 'center'
        },
        bodyStyles: {
          valign: 'middle',
          halign: 'center'
        },
        margin: { left: 14, right: 14, bottom: 20 }
      });

      y = doc.lastAutoTable.finalY + 10;
    }

  } else {
    // ------------------------
    // Pausas (apenas do próprio utilizador)
    // ------------------------
    const pausasArray = pausasTarefa[utilizador] || [];
    const margemInferior = 20;
    const linhasTabela = pausasArray.length || 1;
    const alturaNecessaria = 6 + 4 + (linhasTabela * 8) + 10;

    if (y + alturaNecessaria >= doc.internal.pageSize.height - margemInferior) {
      doc.addPage();
      y = 20;
    } else {
      y += 12;
    }

    doc.setFont("helvetica", "bold");
    doc.setFontSize(12);
    doc.setTextColor(50, 50, 50);
    doc.text("Pausas (apenas deste utilizador)", 14, y);
    doc.line(14, y + 2, 196, y + 2);
    y += 8;

    doc.setFont("helvetica", "bold");
    doc.setFontSize(11);
    doc.setTextColor(60, 60, 60);
    doc.text(`Utilizador: ${utilizador}`, 14, y);
    y += 6;

    doc.autoTable({
      startY: y,
      head: [['Tipo de Pausa', 'Hora de Início', 'Hora de Fim', 'Duração']],
      body: pausasArray,
      theme: 'striped',
      styles: { fontSize: 10, cellPadding: 3 },
      headStyles: {
        fillColor: [207, 167, 40],
        textColor: 20,
        fontStyle: 'bold',
        halign: 'center'
      },
      bodyStyles: {
        valign: 'middle',
        halign: 'center'
      },
      margin: { left: 14, right: 14, bottom: 20 }
    });

    y = doc.lastAutoTable.finalY + 10;
  }



  // Secção: Transições
  y = doc.lastAutoTable.finalY + 12;
  const transicoes = transicoesPorTarefa[nomeTarefa] || [];

  document.querySelectorAll("h4").forEach((h4) => {
    if (h4.textContent.trim() === nomeTarefa.trim()) {
      const table = h4.nextElementSibling;
      if (table && table.tagName === "TABLE") {
        table.querySelectorAll("tbody tr").forEach(tr => {
          const cells = tr.querySelectorAll("td");
          transicoes.push([
            cells[0].innerText,
            cells[1].innerText,
            cells[2].innerText,
            cells[3].innerText
          ]);
        });
      }
    }
  });

  const alturaNecessariaTransicoes = 20 + (transicoes.length * 8); // título + tabela estimada
  if (y + alturaNecessariaTransicoes > doc.internal.pageSize.height - 20) {
    doc.addPage();
    y = 20;
  }


  if (transicoes.length > 0) {
    doc.setFont("helvetica", "bold");
    doc.setFontSize(12);
    doc.text("Histórico de Transições", 14, y);
    doc.line(14, y + 2, 196, y + 2);

    doc.autoTable({
      startY: y + 6,
      head: [['De', 'Para', 'Dia', 'Hora']],
      body: transicoes,
      theme: 'striped',
      styles: { fontSize: 10, cellPadding: 3 },
      headStyles: {
        fillColor: [207, 167, 40],
        textColor: 20,
        fontStyle: 'bold'
      },
      margin: { left: 14, right: 14, bottom: 20 }, // ← RESERVA ESPAÇO PARA O RODAPÉ
      didDrawPage: function (data) {
        const pageHeight = doc.internal.pageSize.height;

        doc.setDrawColor(180);
        doc.line(14, pageHeight - 16, 196, pageHeight - 16);

        doc.setFontSize(9);
        doc.setTextColor(100);
        doc.text(utilizador, 14, pageHeight - 10.5);

        const pageNum = doc.internal.getNumberOfPages();
        const paginaTexto = `Página ${pageNum}`;
        const textoWidth = doc.getTextWidth(paginaTexto);

        const logoX = 196 - 12;
        const logoY = pageHeight - 13;
        doc.addImage(logoBase64, 'JPEG', logoX, logoY, 12, 5);
        doc.text(paginaTexto, logoX - textoWidth - 2, pageHeight - 10.5);
      }
    });
  }

  // Garantir que o rodapé é desenhado em todas as páginas
  const totalPages = doc.internal.getNumberOfPages();
  for (let i = 1; i <= totalPages; i++) {
    doc.setPage(i);

    const pageHeight = doc.internal.pageSize.height;

    // Linha separadora
    doc.setDrawColor(180);
    doc.line(14, pageHeight - 16, 196, pageHeight - 16);

    // Nome do utilizador
    doc.setFontSize(9);
    doc.setTextColor(100);
    doc.text(utilizador, 14, pageHeight - 10.5);

    // Número da página e logotipo
    const paginaTexto = `Página ${i}`;
    const textoWidth = doc.getTextWidth(paginaTexto);
    const logoX = 196 - 12;
    const logoY = pageHeight - 13;
    doc.addImage(logoBase64, 'JPEG', logoX, logoY, 12, 5);
    doc.text(paginaTexto, logoX - textoWidth - 2, pageHeight - 10.5);
  }

  const nomeSanitizado = nomeTarefa.replace(/[^a-zA-Z0-9-_]/g, "_");
  doc.save(`relatorio_${nomeSanitizado}.pdf`);
}

document.querySelectorAll(".btnGerarPDF").forEach(btn => {
  btn.addEventListener("click", () => {
    const nomeTarefa = btn.dataset.tarefa;
    gerarPDFTarefa(nomeTarefa);
  });
});


</script>

</body>
</html>
