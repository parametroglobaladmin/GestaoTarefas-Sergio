<?php
require_once '../config_bd.php';
session_start();
if (!isset($_SESSION["utilizador_logado"])) {
    header("Location: ../index.php");
    exit();
}

$utilizador = $_SESSION["utilizador_logado"];
$departamentos = [];

try {
    $stmt = $ligacao->prepare("SELECT id, nome FROM departamento ORDER BY nome");
    $stmt->execute();
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($resultados as $row) {
        $departamentos[$row['id']] = $row['nome'];
    }

} catch (PDOException $e) {
    echo "Erro ao buscar departamentos: " . $e->getMessage();
    exit();
}

$filtros = [];
$parametros = [];

if (!empty($_GET['departamento'])) {
    $filtros[] = 'dt.departamento_id = :departamento';
    $parametros[':departamento'] = $_GET['departamento'];
}
if (!empty($_GET['data'])) {
    $filtros[] = 't.data >= :data';
    $parametros[':data'] = $_GET['data'];
}

$where = $filtros ? 'AND ' . implode(' AND ', $filtros) : '';

$query = "
  SELECT DATE_FORMAT(t.data_criacao, '%Y-%m-%d %H:00:00') as hora, t.estado, COUNT(*) as total
  FROM tarefas t
  JOIN departamento_tarefa dt ON dt.tarefa_id = t.id
    AND t.data_criacao BETWEEN dt.data_entrada AND COALESCE(dt.data_saida, NOW())
  $where
  GROUP BY hora, t.estado
  ORDER BY hora ASC
";

$stmt = $ligacao->prepare($query);
$stmt->execute($parametros);
$registos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Agrupar os dados para o gráfico
$dadosGrafico = [];
foreach ($registos as $linha) {
    $hora = $linha['hora'];
    $estado = strtolower(trim($linha['estado'] ?? 'desconhecido'));
    $total = $linha['total'];

    if (!isset($dadosGrafico[$estado])) {
        $dadosGrafico[$estado] = [];
    }
    $dadosGrafico[$estado][$hora] = $total;

}

$queryPausas = "
  SELECT
    DATE_FORMAT(p.data_pausa, '%Y-%m-%d %H:00:00') AS hora,
    COUNT(*) AS total
  FROM pausas_tarefas p
  JOIN motivos_pausa mp
    ON mp.id = p.motivo_id
   AND (mp.tipo = 'PararContadores'
        OR mp.tipo = 'SemOpcao')
  JOIN departamento_tarefa dt
    ON dt.tarefa_id = p.tarefa_id
   AND p.data_pausa BETWEEN dt.data_entrada AND COALESCE(dt.data_saida, NOW())
  $where
  GROUP BY hora
  ORDER BY hora ASC
";

$stmtPausas = $ligacao->prepare($queryPausas);
$stmtPausas->execute($parametros);
$pausasPorHora = $stmtPausas->fetchAll(PDO::FETCH_ASSOC);

// Preparar para gráfico
$dadosPausas = [];
foreach ($pausasPorHora as $linha) {
    $hora = $linha['hora'];
    $dadosPausas[$hora] = $linha['total'];
}
// Definir o início do mês ou 31 dias antes
$dataReferencia = !empty($_GET['mes'])
  ? date('Y-m-01', strtotime($_GET['mes']))
  : date('Y-m-d', strtotime('-31 days'));

$queryGantt = "
SELECT 
  t.id AS tarefa_id,
  t.tarefa AS nome_tarefa,
  MIN(dt.data_entrada) AS data_inicio,
  MAX(COALESCE(dt.data_saida, NOW())) AS data_fim
FROM tarefas t
JOIN departamento_tarefa dt ON dt.tarefa_id = t.id
WHERE dt.data_entrada >= :inicio
GROUP BY t.id, t.tarefa
ORDER BY data_inicio ASC;
";

$stmt = $ligacao->prepare($queryGantt);
$stmt->execute([':inicio' => $dataReferencia]);
$resultadosGantt = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Preparar dados para JS (usar epoch ms para evitar datas inválidas)
$tarefasGantt = [];
foreach ($resultadosGantt as $linha) {
    $id   = (string)$linha['tarefa_id'];
    $nome = (string)$linha['nome_tarefa'];

    $startTs = strtotime((string)$linha['data_inicio']);
    $endTs   = strtotime((string)$linha['data_fim']);

    // Se não houver início válido, ignora a linha
    if (!$startTs) {
        continue;
    }
    // Se o fim for inválido ou anterior ao início, força +60s
    if (!$endTs || $endTs < $startTs) {
        $endTs = $startTs + 60;
    }

    // Converte para milissegundos (inteiros)
    $startMs = $startTs * 1000;
    $endMs   = $endTs * 1000;

    $tarefasGantt[] = [$id, $nome, '', $startMs, $endMs];
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <title>Análise das Tarefas</title>
  <link rel="stylesheet" href="../style.css"> <!-- se quiseres manter separado -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    * {
      box-sizing: border-box;
    }

    body {
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      background-color: #f5f5f5;
      padding: 20px;
      margin: 0;
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

    .topo-botoes {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 30px;
      padding: 0 10px;
    }

    .topo-botoes button {
      padding: 10px 16px;
      background-color: #cfa728;
      color: black;
      font-weight: bold;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      transition: background-color 0.2s;
    }

    .topo-botoes button:hover {
      background-color: #b69020;
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
  </style>
</head>
<body>
  <div class="container">
    <h1>Análise das Tarefas</h1>
    <div class="topo-botoes">
      <button onclick="window.location.href='analisar_dados.php?utilizador=<?= urlencode($utilizador) ?>'">← Voltar ao Painel</button>
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
        <label for="mes">Selecione o Mês da tarefa:</label><br>
        <input type="month" name="mes" id="mes" 
              value="<?= htmlspecialchars(date('Y-m', strtotime($dataReferencia))) ?>" 
              style="width: 250px;">
      </div>
      <div>
        <button type="submit">Pesquisar</button>
      </div>
    </form>
    <h2>Gráfico de Gantt – Últimos 31 dias</h2>
    <div id="gantt_chart" style="width: 100%; min-height: 400px;"></div>
    
  </div>
  
  <script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
<script>
  google.charts.load('current', {'packages':['gantt']});
  google.charts.setOnLoadCallback(drawGanttChart);

  function drawGanttChart() {
    const data = new google.visualization.DataTable();
    data.addColumn('string', 'ID');
    data.addColumn('string', 'Tarefa');
    data.addColumn('string', 'Departamento');
    data.addColumn('date', 'Início');
    data.addColumn('date', 'Fim');
    data.addColumn('number', 'Duração');
    data.addColumn('number', '% Concluído');
    data.addColumn('string', 'Dependência');
    data.addColumn({ type: 'string', role: 'style' }); // <- nova coluna para cores

    data.addRows([
      <?php foreach ($tarefasGantt as $index => $linha): 
        // $linha[5] deve conter o estado da tarefa vindo do PHP
        $estado = strtolower($linha[5] ?? '');
        $cor = 'gray';
        if ($estado === 'concluida') $cor = 'green';
        elseif ($estado === 'pendente') $cor = 'blue';
        elseif ($estado === 'espera') $cor = 'red';
      ?>
        ['<?= $linha[0] ?>', '<?= addslashes($linha[1]) ?>', '<?= addslashes($linha[2]) ?>',
        new Date(<?= $linha[3] ?>), new Date(<?= $linha[4] ?>),
        null, 0, null, '<?= $cor ?>']<?= $index < count($tarefasGantt) - 1 ? ',' : '' ?>

      <?php endforeach; ?>
    ]);

    const options = {
      height: <?= max(400, count($tarefasGantt) * 40 + 100) ?>,
      gantt: {
        trackHeight: 30,
        labelStyle: { fontSize: 14 },
        sortTasks: false
      }
    };

    const chart = new google.visualization.Gantt(document.getElementById('gantt_chart'));
    chart.draw(data, options);

    google.visualization.events.addListener(chart, 'select', function() {
      const sel = chart.getSelection();
      if (sel.length > 0) {
        const tarefaId = data.getValue(sel[0].row, 0);
        const nomeTarefa = data.getValue(sel[0].row, 1);
        abrirOverlay(tarefaId, nomeTarefa);
      }
    });
  }
</script>


<!-- Overlay fullscreen -->
<div id="overlay" style="
  display:none;
  position:fixed;
  top:0;
  left:0;
  width:100%;
  height:100%;
  background:rgba(0,0,0,0.8);
  z-index:9999;
  justify-content:center;
  align-items:center;
">
  <div style="
    background:#fff;
    width:90%;
    height:90%;
    padding:20px;
    border-radius:10px;
    overflow:auto;
    position:relative;
  ">
    <button onclick="fecharOverlay()" style="
      position:absolute;
      top:10px;
      right:20px;
      padding:5px 10px;
      background:red;
      color:white;
      border:none;
      border-radius:5px;
      cursor:pointer;
    ">Fechar</button>

    <h2 id="overlayTitulo"></h2>
    <div id="overlayGantt" style="width:100%; height:35%;"></div>
    <div id="overlayResumo" style="margin-top:20px; font-weight:bold;"></div>
  </div>
</div>
<script>
  function abrirOverlay(tarefaId, nomeTarefa) {
  document.getElementById("overlay").style.display = "flex";
  document.getElementById("overlayTitulo").innerText = "Departamentos da tarefa: " + nomeTarefa;

  // Buscar os dados via AJAX (chama PHP que devolve departamentos dessa tarefa)
  fetch("obterDepartamentosTarefa.php?tarefa_id=" + tarefaId)
    .then(r => r.json())
    .then(dados => {
      drawOverlayGantt(dados);
    });
}

function fecharOverlay() {
  document.getElementById("overlay").style.display = "none";
}

</script>
<script>
  function drawOverlayGantt(dados) {
  google.charts.load('current', {'packages':['gantt']});
  google.charts.setOnLoadCallback(() => {
    const data = new google.visualization.DataTable();
    data.addColumn('string', 'ID');
    data.addColumn('string', 'Departamento');
    data.addColumn('string', 'Dummy');
    data.addColumn('date', 'Início');
    data.addColumn('date', 'Fim');
    data.addColumn('number', 'Duração');
    data.addColumn('number', '% Concluído');
    data.addColumn('string', 'Dependência');
    data.addColumn({type: 'string', role: 'tooltip', p: {html: true}});

    let rows = [];
    let resumo = "<h3>Resumo de tempos por departamento</h3><ul>";

    dados.forEach((d, idx) => {
      const start = new Date(d.data_entrada);
      const end   = new Date(d.data_saida);

      // Converter segundos em formato legível
      const seg = parseInt(d.duracao_segundos, 10);
      const horas = Math.floor(seg / 3600);
      const minutos = Math.floor((seg % 3600) / 60);
      const segundos = seg % 60;
      const tempoFmt = `${horas}h ${minutos}m ${segundos}s`;

      resumo += `<li><b>${d.nome_departamento}</b>: ${tempoFmt}</li>`;

      const tooltip = `
        <div style="padding:5px">
          <b>${d.nome_departamento}</b><br>
          ${d.data_entrada} → ${d.data_saida}<br>
          Tempo: ${tempoFmt}
        </div>
      `;

      rows.push([
        'dep'+idx,
        d.nome_departamento,
        d.nome_departamento,
        start, end,
        null, 0, null,
        tooltip
      ]);
    });

    resumo += "</ul>";
    document.getElementById("overlayResumo").innerHTML = resumo;

    data.addRows(rows);
    const chart = new google.visualization.Gantt(document.getElementById('overlayGantt'));
    chart.draw(data, {height: Math.max(100, rows.length*40+100), gantt: { trackHeight: 30 }});
  });
}


</script>

</body>
</html>
