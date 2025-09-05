<?php
require_once '../config_bd.php';
session_start();
if (!isset($_SESSION["utilizador_logado"])) {
    header("Location: ../index.php");
    exit();
}
$funcionariosEntradasESaidas = [];

// Carregar lista de funcionários
$stmt = $ligacao->prepare("SELECT utilizador FROM funcionarios");
$stmt->execute();
$funcionarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Carregar entradas e saídas
$stmt = $ligacao->prepare("SELECT * FROM utilizador_entradaesaida");
$stmt->execute();
$funcionariosEntradasESaidas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calcular horas trabalhadas por linha
foreach ($funcionariosEntradasESaidas as &$linha) {
    if (!empty($linha['hora_entrada']) && !empty($linha['hora_saida'])) {
        $inicio = new DateTime($linha['hora_entrada']);
        $fim = new DateTime($linha['hora_saida']);


        $segundos = $fim->getTimestamp() - $inicio->getTimestamp();
        $horasTrabalhadas = round($segundos / 3600, 2);

        $linha['horas_trabalhadas'] = $horasTrabalhadas;
    } else {
        $linha['horas_trabalhadas'] = 0;
    }
}

$mediaHorasPorDia = []; // resultado final

$acumuladoPorDia = []; // estrutura temporária

foreach ($funcionariosEntradasESaidas as $linha) {
    $data = $linha['data'];
    $horas = $linha['horas_trabalhadas'];

    if (!isset($acumuladoPorDia[$data])) {
        $acumuladoPorDia[$data] = ['soma' => 0, 'quantidade' => 0];
    }

    $acumuladoPorDia[$data]['soma'] += $horas;
    $acumuladoPorDia[$data]['quantidade'] += 1;
}

// calcular média
foreach ($acumuladoPorDia as $data => $valores) {
    $media = $valores['quantidade'] > 0 ? round($valores['soma'] / $valores['quantidade'], 2) : 0;
    $mediaHorasPorDia[$data] = $media;
}



// Verifica se há utilizador selecionado
$utilizadorSelecionado = isset($_GET['utilizador']) ? $_GET['utilizador'] : null;
$dataInicio = $_GET['data_inicio'] ?? null;
$dataFim = $_GET['data_fim'] ?? null;
$dadosUtilizador = [];
$pausasUtilizador = [];
$trabalhoPorDia = [];

if ($utilizadorSelecionado) {
    $stmt = $ligacao->prepare("
        SELECT f.*, d.nome AS nome_departamento
        FROM funcionarios f
        JOIN departamento d ON f.departamento = d.id
        WHERE f.utilizador = ?
    ");
    $stmt->execute([$utilizadorSelecionado]);
    $dadosUtilizador = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $ligacao->prepare("
        SELECT pt.*, mp.descricao AS tipo_pausa
        FROM pausas_tarefas pt
        JOIN motivos_pausa mp ON pt.motivo_id = mp.id
        WHERE pt.funcionario = ? AND mp.descricao!='Intergabinete'
    ");
    $stmt->execute([$utilizadorSelecionado]);
    $rawPausas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $pausasUtilizador = [];

    foreach ($rawPausas as $p) {
        $tipo = $p['tipo_pausa'];
        if (!isset($pausasUtilizador[$tipo])) {
            $pausasUtilizador[$tipo] = 0;
        }
        $pausasUtilizador[$tipo]++;
    }


    // 1. Obter entradas e saídas por dia
    $queryES = "
        SELECT data, hora_entrada, hora_saida
        FROM utilizador_entradaesaida
        WHERE utilizador = ?
          AND hora_entrada IS NOT NULL
          AND hora_saida IS NOT NULL
    ";

    $paramsES = [$utilizadorSelecionado];

    if ($dataInicio) {
        $queryES .= " AND data >= ?";
        $paramsES[] = $dataInicio;
    }
    if ($dataFim) {
        $queryES .= " AND data <= ?";
        $paramsES[] = $dataFim;
    }

    $queryES .= " ORDER BY hora_entrada";

    $stmt = $ligacao->prepare($queryES);
    $stmt->execute($paramsES);
    $diasEntradaSaida = $stmt->fetchAll(PDO::FETCH_ASSOC);


    foreach ($diasEntradaSaida as $registo) {
        $dia = $registo['data'];

        $inicio = new DateTime($registo['hora_entrada']);
        $fim    = new DateTime($registo['hora_saida']);
        $segundos = $fim->getTimestamp() - $inicio->getTimestamp();  // fim - inicio
        $horasTotais = round($segundos / 3600, 2);

        $trabalhoPorDia[$dia] = [
            'total' => $horasTotais,
            'pausas' => 0,
            'efetivas' => $horasTotais, // será corrigido a seguir
        ];
    }

    $queryPausas = "
        SELECT DATE(pt.data_pausa) AS dia,
              SUM(TIME_TO_SEC(pt.tempo_pausa)) AS total_pausa_segundos
        FROM pausas_tarefas pt
        JOIN motivos_pausa mp ON pt.motivo_id = mp.id
        WHERE pt.funcionario = ?
          AND pt.data_pausa IS NOT NULL
          AND mp.tipo IN ('PararContadores', 'Semopcao')
    ";

    $paramsPausas = [$utilizadorSelecionado];

    if ($dataInicio) {
        $queryPausas .= " AND DATE(pt.data_pausa) >= ?";
        $paramsPausas[] = $dataInicio;
    }
    if ($dataFim) {
        $queryPausas .= " AND DATE(pt.data_pausa) <= ?";
        $paramsPausas[] = $dataFim;
    }

    $queryPausas .= " GROUP BY DATE(pt.data_pausa)";

    $stmt = $ligacao->prepare($queryPausas);
    $stmt->execute($paramsPausas);
    $pausas = $stmt->fetchAll(PDO::FETCH_ASSOC);


    foreach ($pausas as $p) {
        $dia = $p['dia'];
        $duracaoHoras = round($p['total_pausa_segundos'] / 3600, 2);

        if (!isset($trabalhoPorDia[$dia])) {
            $trabalhoPorDia[$dia] = [
                'total' => 0,
                'pausas' => 0,
                'efetivas' => 0
            ];
        }

        $trabalhoPorDia[$dia]['pausas'] = $duracaoHoras;
        $trabalhoPorDia[$dia]['efetivas'] = max(
            $trabalhoPorDia[$dia]['total'] - $duracaoHoras,
            0
        );
    }
}

// Tempo médio por tipo de pausa do utilizador
$tempoMedioPausaUtilizador = [];
$contagemPorTipo = [];

$stmt = $ligacao->prepare("
    SELECT 
    motivo_id, 
    TIME_TO_SEC(tempo_pausa) AS segundos, 
    mp.descricao AS tipo_pausa,
    mp.tipo AS tipo
FROM 
    pausas_tarefas pt
JOIN 
    motivos_pausa mp ON pt.motivo_id = mp.id
WHERE 
    funcionario = ?
    AND tempo_pausa IS NOT NULL
    AND mp.tipo IN ('PararContadores', 'Semopcao');

");
$stmt->execute([$utilizadorSelecionado]);
$pausasDetalhadas = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($pausasDetalhadas as $pausa) {
    $tipo = $pausa['tipo_pausa'];
    $tempo = $pausa['segundos'];
    if (!isset($tempoMedioPausaUtilizador[$tipo])) {
        $tempoMedioPausaUtilizador[$tipo] = 0;
        $contagemPorTipo[$tipo] = 0;
    }
    $tempoMedioPausaUtilizador[$tipo] += $tempo;
    $contagemPorTipo[$tipo]++;
}

foreach ($tempoMedioPausaUtilizador as $tipo => &$totalSegundos) {
    $totalSegundos = round(($totalSegundos / $contagemPorTipo[$tipo]) / 60, 1); // minutos
}
unset($totalSegundos);

// Tempo médio global por tipo de pausa (todos os utilizadores)
$tempoMedioPausaGlobal = [];
$contagemGlobal = [];

$stmt = $ligacao->prepare("
    SELECT motivo_id, TIME_TO_SEC(tempo_pausa) AS segundos, mp.descricao AS tipo_pausa
    FROM pausas_tarefas pt
    JOIN motivos_pausa mp ON pt.motivo_id = mp.id
    WHERE tempo_pausa IS NOT NULL
");
$stmt->execute();
$pausasGlobais = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($pausasGlobais as $pausa) {
    $tipo = $pausa['tipo_pausa'];
    $tempo = $pausa['segundos'];
    if (!isset($tempoMedioPausaGlobal[$tipo])) {
        $tempoMedioPausaGlobal[$tipo] = 0;
        $contagemGlobal[$tipo] = 0;
    }
    $tempoMedioPausaGlobal[$tipo] += $tempo;
    $contagemGlobal[$tipo]++;
}

foreach ($tempoMedioPausaGlobal as $tipo => &$totalSegundos) {
    $totalSegundos = round(($totalSegundos / $contagemGlobal[$tipo]) / 60, 1); // minutos
}
unset($totalSegundos);


$dadosEntradaSaida = [];

foreach ($diasEntradaSaida as $dia) {
    $data = $dia['data'];
    $entrada = new DateTime($dia['hora_entrada']);
    $saida = new DateTime($dia['hora_saida']);
    $inicioMin = (int) $entrada->format('H') * 60 + (int) $entrada->format('i');
    $fimMin = (int) $saida->format('H') * 60 + (int) $saida->format('i');

    $dadosEntradaSaida[] = [
        'data' => $data,
        'inicio' => $inicioMin,
        'duracao' => $fimMin - $inicioMin
    ];
}
$pausasPorTipoPorDia = [];

// janela = hoje-6 ... hoje  (7 dias no total; se quiser "hoje-7 ... hoje", muda -6 para -7 e o ciclo para 8)
$hojeStr = date('Y-m-d');
$inicioStr = date('Y-m-d', strtotime('-6 days'));

$diasJanela = [];
$cursor = new DateTime($inicioStr);
for ($i = 0; $i < 7; $i++) {
  $diasJanela[] = $cursor->format('Y-m-d');
  $cursor->modify('+1 day');
}

if ($utilizadorSelecionado) {
  $sql = "
    SELECT
      mp.descricao AS tipo_pausa,
      DATE(pt.data_pausa) AS dia,
      SUM(
        COALESCE(
          TIME_TO_SEC(pt.tempo_pausa),
          TIMESTAMPDIFF(SECOND, pt.data_pausa, COALESCE(pt.data_retorno, NOW()))
        )
      ) AS segundos
    FROM pausas_tarefas pt
    JOIN motivos_pausa mp ON mp.id = pt.motivo_id
    WHERE pt.funcionario = ?
      AND pt.data_pausa IS NOT NULL
      AND (mp.descricao <> 'Intergabinete') -- remove se quiseres incluir
      AND DATE(pt.data_pausa) BETWEEN ? AND ?
    GROUP BY mp.descricao, DATE(pt.data_pausa)
    ORDER BY mp.descricao, dia
  ";

  $stmt = $ligacao->prepare($sql);
  $stmt->execute([$utilizadorSelecionado, $inicioStr, $hojeStr]);
  $pausasDiariasRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

  foreach ($pausasDiariasRaw as $r) {
    $tipo = $r['tipo_pausa'] ?? 'Sem tipo';
    $dia  = $r['dia'];
    $seg  = (int)$r['segundos'];
    if (!isset($pausasPorTipoPorDia[$tipo])) {
      $pausasPorTipoPorDia[$tipo] = array_fill_keys($diasJanela, 0);
    }
    // garante chave do dia, mesmo que a query só devolva alguns dias
    if (!isset($pausasPorTipoPorDia[$tipo][$dia])) {
      $pausasPorTipoPorDia[$tipo][$dia] = 0;
    }
    $pausasPorTipoPorDia[$tipo][$dia] += $seg;
  }

  // garante que todos os tipos têm todos os dias da janela com 0 quando faltam
  foreach ($pausasPorTipoPorDia as $tipo => $linhaDias) {
    $pausasPorTipoPorDia[$tipo] = array_replace(array_fill_keys($diasJanela, 0), $linhaDias);
    ksort($pausasPorTipoPorDia[$tipo]); // ordenar por data
  }
}

?>

<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <title>Análise de Dados</title>
  <link rel="stylesheet" href="../style.css">
  <style>
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
    padding: 30px 15px;
    border-radius: 10px;
    box-shadow: 0 0 12px rgba(0, 0, 0, 0.1);
  }

  h1 {
    margin-bottom: 20px;
    color: #333;
  }

  button {
    padding: 10px 16px;
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

  .layout {
    display: flex;
    justify-content: flex-start;
    align-items: flex-start;
    gap: 30px;
    margin-top: 30px;
    flex-wrap: wrap;
    width: 100%;
  }

  .coluna-esquerda {
    flex: 0 0 auto;
    min-width: 200px;
    margin-left: 0;
    padding-left: 0;
  }

  .coluna-direita {
    flex: 1;
    padding-right: 10px;
  }

  .tabela-funcionarios {
    width: 100%;
    border-collapse: collapse;
    border: 1px solid #ddd;
    background-color: #fff;
    margin: 0;
  }

  .tabela-funcionarios th {
    background-color: #cfa728;
    padding: 12px;
    text-align: center;
    font-weight: bold;
    color: #333;
    border-bottom: 1px solid #ccc;
  }

  .tabela-funcionarios td {
    padding: 0;
    border-bottom: 1px solid #eee;
  }

  .selecionar-btn {
    display: block;
    width: 100%;
    background-color: #d0bc78ff;
    color: white;
    padding: 12px;
    font-size: 14px;
    text-align: left;
    border: none;
    border-radius: 0;
    text-decoration: none;
    transition: background-color 0.3s;
    box-sizing: border-box;
  }

  .selecionar-btn:hover {
    background-color: #605329ff;
  }

  @media (max-width: 768px) {
    .layout {
      flex-direction: column;
    }

    .coluna-esquerda {
      width: 100%;
    }
  }

  #notificacao {
      position: fixed;
      top: 20px;
      right: 20px;
      background-color: #ffc107;
      color: white;
      font-weight: bold;
      font-size: 15px;
      padding: 12px 20px;
      border-radius: 10px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.2);
      display: none;
      z-index: 9999;
  }
  </style>
</head>

<body>
  <div class="container">
    <h1>Análise de Dados</h1>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; padding: 0 10px;">
      <button onclick="window.location.href='painel.php'">← Voltar ao Painel</button>
      <button onclick="window.location.href='analisarTarefasDados.php'" style="margin-left:10px;">Analisar tarefas</button>
      <button onclick="window.location.href='compararUtilizadores.php'" style="margin-left:10px;">Comparar Utilizadores</button>
    </div>

    <div class="layout">
      <!-- Coluna esquerda -->
      <div class="coluna-esquerda">
        <!-- Tabela com scroll -->
        <div style="max-height: 400px; overflow-y: auto;">
          <table class="tabela-funcionarios">
            <thead>
              <tr><th>Funcionário</th></tr>
            </thead>
            <tbody>
              <?php foreach ($funcionarios as $f): ?>
                <tr>
                  <td>
                    <a class="selecionar-btn" href="analisar_dados.php?utilizador=<?= urlencode($f['utilizador']) ?>">
                      <?= htmlspecialchars($f['utilizador']) ?>
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Formulário de filtro por datas -->
        <form method="get" style="margin-top: 20px;">
          <input type="hidden" name="utilizador" value="<?= htmlspecialchars($utilizadorSelecionado ?? '') ?>">
          
          <div>
            <label for="data_inicio">Data Início:</label><br>
            <input type="date" name="data_inicio" id="data_inicio"
                  value="<?= htmlspecialchars($_GET['data_inicio'] ?? '') ?>"
                  style="padding: 6px; border: 1px solid #ccc; border-radius: 6px; width: 100%;">
          </div>
          
          <div style="margin-top: 10px;">
            <label for="data_fim">Data Fim:</label><br>
            <input type="date" name="data_fim" id="data_fim"
                  value="<?= htmlspecialchars($_GET['data_fim'] ?? '') ?>"
                  style="padding: 6px; border: 1px solid #ccc; border-radius: 6px; width: 100%;">
          </div>
          
          <div style="margin-top: 15px;">
            <button type="submit"
                    style="padding: 8px 14px; background-color: #cfa728; color: black; font-weight: bold;
                          border: none; border-radius: 8px; cursor: pointer; width: 100%;">
              Filtrar
            </button>
          </div>
        </form>
      </div>

      <!-- Coluna direita -->
      <div class="coluna-direita">
        <?php if ($utilizadorSelecionado && $dadosUtilizador): ?>
          <h2>Dados de <?= htmlspecialchars($dadosUtilizador['nome'] ?? 'N/A') ?></h2>
          <p><strong>Email:</strong> <?= htmlspecialchars($dadosUtilizador['email'] ?? 'N/A') ?></p>
          <p><strong>Departamento do Funcionário:</strong> <?= htmlspecialchars($dadosUtilizador['nome_departamento'] ?? 'N/A') ?></p>

          <!-- Gráficos lado a lado -->
          <div style="display: flex; flex-wrap: wrap; gap: 30px; margin-top: 20px;">
            <div style="flex: 1; min-width: 300px;">
              <canvas id="graficoHoras" width="300" height="250"></canvas>
            </div>

            <div style="flex: 1; min-width: 300px;">
              <canvas id="graficoPausas" width="300" height="250"></canvas>
            </div>

            <div style="flex: 1; min-width: 300px;">
              <canvas id="graficoTrabalho" width="300" height="250"></canvas>
            </div>
          </div>

          <!-- Segunda linha de gráficos -->
          <div style="display: flex; flex-wrap: wrap; gap: 30px; margin-top: 30px;">
            <div style="flex: 1; min-width: 300px;">
              <canvas id="graficoEntradaSaida" width="400" height="400"></canvas>
            </div>

            <div style="flex: 1.2; min-width: 520px; max-height: 400px; overflow: auto;">
              <table class="tabela-pausas-mensal" style="border-collapse: collapse; width: 100%;">
                <thead>
                  <tr>
                    <th style="position: sticky; left: 0; background: #fff; z-index: 2; border: 1px solid #ddd; padding: 8px; white-space:nowrap;">
                      Tipo de Pausa (últimos 7 dias)<br>
                      <small><?= htmlspecialchars(date('d/m/Y', strtotime($inicioStr))) ?> – <?= htmlspecialchars(date('d/m/Y', strtotime($hojeStr))) ?></small>
                    </th>
                    <?php foreach ($diasJanela as $d): ?>
                      <th style="border: 1px solid #ddd; padding: 8px; text-align:center; white-space:nowrap;">
                        <?= htmlspecialchars(date('d/m', strtotime($d))) ?>
                      </th>
                    <?php endforeach; ?>
                    <th style="border: 1px solid #ddd; padding: 8px; text-align:center; white-space:nowrap;">Total</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($pausasPorTipoPorDia as $tipo => $linhaDias): ?>
                    <?php $totalTipo = array_sum($linhaDias); ?>
                    <tr>
                      <td style="position: sticky; left: 0; background: #fff; z-index: 1; border: 1px solid #ddd; padding: 8px; font-weight:600;">
                        <?= htmlspecialchars($tipo) ?>
                      </td>
                      <?php foreach ($diasJanela as $d): ?>
                        <?php $seg = (int)($linhaDias[$d] ?? 0); ?>
                        <td style="border: 1px solid #ddd; padding: 8px; text-align:center; font-variant-numeric: tabular-nums;">
                          <?= fmt_hms($seg) ?>
                        </td>
                      <?php endforeach; ?>
                      <td style="border: 1px solid #ddd; padding: 8px; text-align:center; font-weight:600;">
                        <?= fmt_hms($totalTipo) ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

        <?php elseif ($utilizadorSelecionado): ?>
          <p>Utilizador não encontrado.</p>
        <?php else: ?>
          <p>Selecione um funcionário à esquerda para visualizar os dados.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Notificação -->
  <div id="notificacao"><span id="notificacao-texto">Mensagem</span></div>

  <!-- Chart.js -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <?php if ($utilizadorSelecionado): ?>
  <script>
    const tiposPausa = <?= json_encode(array_keys($tempoMedioPausaUtilizador)) ?>;
    const tempoMedioUser = <?= json_encode(array_values($tempoMedioPausaUtilizador)) ?>;
    const tempoMedioGlobal = [];

    const mapaGlobal = <?= json_encode($tempoMedioPausaGlobal) ?>;
    tiposPausa.forEach(tipo => {
      tempoMedioGlobal.push(mapaGlobal[tipo] ?? 0);
    });

    function formatTimeFromMinutes(mins) {
      const totalSeconds = Math.round((Number(mins) || 0) * 60);
      const h = Math.floor(totalSeconds / 3600);
      const m = Math.floor((totalSeconds % 3600) / 60);
      const s = totalSeconds % 60;
      const pad = n => n.toString().padStart(2, '0');
      return `${pad(h)}:${pad(m)}:${pad(s)}`;
    }

    new Chart(document.getElementById('graficoHoras'), {
      type: 'bar',
      data: {
        labels: tiposPausa,
        datasets: [
          {
            label: 'Utilizador',
            data: tempoMedioUser, // minutos
            backgroundColor: '#0116fbff'
          },
          {
            label: 'Todos os Utilizadores',
            data: tempoMedioGlobal, // minutos
            backgroundColor: '#f40000ff'
          }
        ]
      },
      options: {
        responsive: true,
        plugins: {
          title: {
            display: true,
            text: 'Tempo Médio por Tipo de Pausa'
          },
          tooltip: {
            callbacks: {
              label: function (context) {
                const mins = context.raw; // valor em minutos
                return `${context.dataset.label}: ${formatTimeFromMinutes(mins)}`;
              }
            }
          },
          legend: {
            labels: {
              // opcional: mostrar exemplo do formato na legenda
              generateLabels(chart) {
                const labels = Chart.defaults.plugins.legend.labels.generateLabels(chart);
                return labels.map(l => ({ ...l, text: l.text }));
              }
            }
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            title: { display: true, text: 'Tempo (hh:mm:ss)' },
            ticks: {
              callback: function (value /* em minutos */) {
                return formatTimeFromMinutes(value);
              }
            }
          }
        }
      }
    });

    // Gráfico de distribuição de tipos de pausa
    new Chart(document.getElementById('graficoPausas'), {
      type: 'pie',
      data: {
        labels: <?= json_encode(array_keys($pausasUtilizador)) ?>,
        datasets: [{
          label: 'Tipos de Pausa',
          data: <?= json_encode(array_values($pausasUtilizador)) ?>,
          backgroundColor: ['#cfa728', '#0941faff', '#ff6361', '#1be514ff', '#28a745', '#ffc107', '#ff0707ff']
        }]
      },
      options: {
        plugins: {
          title: {
            display: true,
            text: 'Distribuição de Tipos de Pausa',
            font: { size: 18 },
            padding: { top: 10, bottom: 30 }
          }
        }
      }
    });

    // Gráfico de pausas vs horas efetivas por dia
    const dias = <?= json_encode(array_keys($trabalhoPorDia)) ?>;
    const pausas = <?= json_encode(array_column($trabalhoPorDia, 'pausas')) ?>;
    const efetivas = <?= json_encode(array_column($trabalhoPorDia, 'efetivas')) ?>;

    new Chart(document.getElementById('graficoTrabalho'), {
      type: 'bar',
      data: {
        labels: dias,
        datasets: [
          { label: 'Pausas', data: pausas, backgroundColor: '#999' },
          { label: 'Horas Efetivas', data: efetivas, backgroundColor: '#28a745' }
        ]
      },
      options: {
        responsive: true,
        plugins: {
          title: { display: true, text: 'Horas Trabalhadas vs Pausas por Dia' },
          tooltip: {
            callbacks: {
              label: function(context) {
                const horasDecimais = context.raw;
                const totalMinutos = Math.round(horasDecimais * 60);

                const horas = Math.floor(totalMinutos / 60);
                const minutos = totalMinutos % 60;

                const horasFormatadas = String(horas).padStart(2, '0');
                const minutosFormatados = String(minutos).padStart(2, '0');

                return `${context.dataset.label}: ${horasFormatadas}:${minutosFormatados}`;
              }
            }
          }
        },
        scales: {
          x: { stacked: true, title: { display: true, text: 'Data' } },
          y: { stacked: true, beginAtZero: true, title: { display: true, text: 'Horas' } }
        }
      }
    });

    // Gráfico de entrada e saída com pontos sobre a barra
    const entradaSaida = <?= json_encode($dadosEntradaSaida) ?>;
    const labelsEntrada = entradaSaida.map(e => e.data);
    const entradaOffset = entradaSaida.map(e => e.inicio);
    const entradaDuracao = entradaSaida.map(e => e.duracao);

    // Dados para pontos diretamente sobre as barras
    const pontoBaseBarra = entradaOffset;
    const pontoTopoBarra = entradaOffset.map((inicio, i) => inicio + entradaDuracao[i]);

    // Escala dinâmica
    const minY = Math.min(...entradaOffset) - 30;
    const maxY = Math.max(...pontoTopoBarra) + 30;

    new Chart(document.getElementById('graficoEntradaSaida'), {
      type: 'bar',
      data: {
        labels: labelsEntrada,
        datasets: [
          {
            label: 'Hora de Entrada',
            data: entradaOffset,
            backgroundColor: 'rgba(0,0,0,0)',
            stack: 'trabalho'
          },
          {
            label: 'Tempo de Trabalho',
            data: entradaDuracao,
            backgroundColor: '#28a745',
            stack: 'trabalho'
          },
          {
            type: 'line',
            label: 'Ponto de Entrada',
            data: pontoBaseBarra,
            borderColor: 'rgba(0,0,0,0)',
            backgroundColor: 'rgba(0,0,0,0)',
            pointRadius: 5,
            pointStyle: 'circle',
            fill: false,
            showLine: false,
            yAxisID: 'y'
          },
          {
            type: 'line',
            label: 'Ponto de Saída',
            data: pontoTopoBarra,
            borderColor: 'rgba(0,0,0,0)',
            backgroundColor: 'rgba(0,0,0,0)',
            pointRadius: 5,
            pointStyle: 'circle',
            fill: false,
            showLine: false,
            yAxisID: 'y'
          }
        ]
      },
      options: {
        responsive: false,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            labels: {
              filter: function(legendItem, chartData) {
                return !['Hora de Entrada', 'Ponto de Saída', 'Ponto de Entrada'].includes(legendItem.text);
              }
            }
          },
          title: {
            display: true,
            text: 'Intervalo de Entrada e Saída por Dia',
            font: { size: 18 }
          },
          tooltip: {
            callbacks: {
              label: function(context) {
                const idx = context.dataIndex;
                const offset = entradaOffset[idx];
                const duracao = entradaDuracao[idx];
                const fim = offset + duracao;

                const hEntrada = Math.floor(offset / 60);
                const mEntrada = offset % 60;
                const hFim = Math.floor(fim / 60);
                const mFim = fim % 60;

                if (context.dataset.label === 'Ponto de Entrada') {
                  return `Entrada: ${hEntrada.toString().padStart(2, '0')}:${mEntrada.toString().padStart(2, '0')}`;
                }

                if (context.dataset.label === 'Ponto de Saída') {
                  return `Saída: ${hFim.toString().padStart(2, '0')}:${mFim.toString().padStart(2, '0')}`;
                }

                if (context.dataset.label === 'Tempo de Trabalho') {
                  const hDur = Math.floor(duracao / 60);
                  const mDur = duracao % 60;
                  return `Entrada: ${hEntrada.toString().padStart(2, '0')}:${mEntrada.toString().padStart(2, '0')} — Saída: ${hFim.toString().padStart(2, '0')}:${mFim.toString().padStart(2, '0')} | Duração: ${hDur}h ${mDur.toString().padStart(2, '0')}min`;
                }

                return null;
              }
            }
          }
        },
        scales: {
          y: {
            stacked: true,
            min: minY < 300 ? 300 : minY,
            max: maxY > 1320 ? maxY : 1320,
            ticks: {
              stepSize: 30,
              callback: function(value) {
                const h = Math.floor(value / 60);
                const m = value % 60;
                return `${h.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')}`;
              }
            },
            title: { display: true, text: 'Hora do Dia' }
          },
          x: {
            stacked: true,
            title: { display: true, text: 'Data' }
          }
        }
      }
    });
  </script>
  <?php endif; ?>

  <script>
  function mostrarNotificacao(mensagem, tipo = 'sucesso') {
    const toast = document.getElementById("notificacao");
    const span  = document.getElementById("notificacao-texto");

    let bg, corTexto, borda;

    if (tipo === 'erro') {
      bg = "#f8d7da"; corTexto = "#721c24"; borda = "#dc3545";
    } else {
      bg = "#d4edda"; corTexto = "#155724"; borda = "#28a745";
    }

    toast.style.backgroundColor = bg;
    toast.style.color = corTexto;
    toast.style.borderLeft = `5px solid ${borda}`;
    span.textContent = mensagem;
    toast.style.display = "flex";
    toast.style.opacity = "1";

    setTimeout(() => {
      toast.style.transition = 'opacity 0.5s';
      toast.style.opacity = "0";
      setTimeout(() => {
        toast.style.display = "none";
        toast.style.transition = '';
      }, 500);
    }, 4000);
  }
  </script>
</body>
</html>
