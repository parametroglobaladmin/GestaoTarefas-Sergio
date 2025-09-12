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
if (!empty($_GET['data_inicio'])) {
    $filtros[] = 't.data_inicio >= :data_inicio';
    $parametros[':data_inicio'] = $_GET['data_inicio'];
}
if (!empty($_GET['data_fim'])) {
    $filtros[] = 't.data_inicio <= :data_fim';
    $parametros[':data_fim'] = $_GET['data_fim'];
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
        <label for="departamento">Departamento:</label><br>
        <select name="departamento" id="departamento" class="campo-filtro">
          <option value="">Todos</option>
          <?php foreach ($departamentos as $id => $nome): ?>
            <option value="<?= $id ?>" <?= isset($_GET['departamento']) && $_GET['departamento'] == $id ? 'selected' : '' ?>>
              <?= htmlspecialchars($nome) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
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
        <label for="data_inicio">Data Início:</label><br>
        <input type="date" name="data_inicio" id="data_inicio" value="<?= htmlspecialchars($dataInicio) ?>">
      </div>
      <div>
        <label for="data_fim">Data Fim:</label><br>
        <input type="date" name="data_fim" id="data_fim" value="<?= htmlspecialchars($dataFim) ?>">
      </div>
      <div>
        <button type="submit">Pesquisar</button>
      </div>
    </form>

  </div>


</body>
</html>
