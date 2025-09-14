<?php
require_once '../config_bd.php';
session_start();
if (!isset($_SESSION["utilizador_logado"])) {
    header("Location: ../index.php");
    exit();
}

$utilizador = $_SESSION["utilizador_logado"];

// Carregar utilizadores e departamentos
$stmt = $ligacao->prepare("
  SELECT utilizador, nome AS nome_exibir, departamento
  FROM funcionarios
  ORDER BY nome_exibir ASC
");
$stmt->execute();
$funcionarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmtDept = $ligacao->prepare("
  SELECT id AS departamento_id, nome AS nome_departamento
  FROM departamento
  ORDER BY nome_departamento ASC
");
$stmtDept->execute();
$departamentos = $stmtDept->fetchAll(PDO::FETCH_ASSOC);

// Seleções por GET
$utilizadoresSelecionados = $_GET['utilizadores'] ?? [];
$dataInicio = $_GET['data_inicio'] ?? null;
$dataFim = $_GET['data_fim'] ?? null;
$departamentoSelecionado = $_GET['departamento'] ?? '';

if (!empty($departamentoSelecionado)) {
    $utilizadoresSelecionados = [];
}

if (empty($dataInicio) && empty($dataFim)) {
    $dataFim = date('Y-m-d');
    $dataInicio = date('Y-m-d', strtotime('-59 days'));
}

$whereClauses = [];
$params = [];

if (!empty($departamentoSelecionado)) {
    $whereClauses[] = 'f.departamento = ?';
    $params[] = $departamentoSelecionado;
} elseif (!empty($utilizadoresSelecionados)) {
    $placeholders = implode(',', array_fill(0, count($utilizadoresSelecionados), '?'));
    $whereClauses[] = "ue.utilizador IN ($placeholders)";
    $params = array_merge($params, $utilizadoresSelecionados);
}
if ($dataInicio) { $whereClauses[] = 'DATE(ue.hora_entrada) >= ?'; $params[] = $dataInicio; }
if ($dataFim)    { $whereClauses[] = 'DATE(ue.hora_entrada) <= ?'; $params[] = $dataFim; }

$whereSQL = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

$query = "
  SELECT
    ue.utilizador,
    f.nome AS nome_funcionario,
    DATE(ue.hora_entrada) AS dia,
    TIME(MIN(ue.hora_entrada)) AS hora_entrada,
    TIME(MAX(ue.hora_saida)) AS hora_saida,
    SUM(TIMESTAMPDIFF(SECOND, ue.hora_entrada, ue.hora_saida)) AS jornada_bruta_segundos
  FROM utilizador_entradaesaida ue
  JOIN funcionarios f ON f.utilizador = ue.utilizador
  $whereSQL
  GROUP BY ue.utilizador, DATE(ue.hora_entrada), f.nome
  ORDER BY dia DESC, f.nome ASC
";

$stmtResultados = $ligacao->prepare($query);
$stmtResultados->execute($params);
$resultados = $stmtResultados->fetchAll(PDO::FETCH_ASSOC);

// Função para fundir pausas sobrepostas
function fundirIntervalosSobrepostos($pausas) {
    usort($pausas, fn($a, $b) => strtotime($a['inicio']) <=> strtotime($b['inicio']));
    $fundidas = [];

    foreach ($pausas as $p) {
        $inicio = strtotime($p['inicio']);
        $fim    = strtotime($p['fim']);

        if (empty($fundidas)) {
            $fundidas[] = ['inicio' => $inicio, 'fim' => $fim];
            continue;
        }

        $ultimo = &$fundidas[count($fundidas) - 1];

        if ($inicio <= $ultimo['fim']) {
            $ultimo['fim'] = max($ultimo['fim'], $fim);
        } else {
            $fundidas[] = ['inicio' => $inicio, 'fim' => $fim];
        }
    }

    $total = 0;
    foreach ($fundidas as $f) {
        $total += $f['fim'] - $f['inicio'];
    }

    return $total;
}

// Carregar todas as pausas válidas
$pausasBrutas = $ligacao->query("
  SELECT
    pt.funcionario,
    DATE(pt.data_pausa) AS dia,
    pt.data_pausa AS inicio,
    pt.data_retorno AS fim,
    mp.tipo
  FROM pausas_tarefas pt
  JOIN motivos_pausa mp ON mp.id = pt.motivo_id
  WHERE pt.data_retorno IS NOT NULL
    AND mp.estatistica <> 'inativo'
")->fetchAll(PDO::FETCH_ASSOC);

// Agrupar pausas por utilizador e dia
$pausasPorUserDia = [];
foreach ($pausasBrutas as $p) {
    $u = $p['funcionario'];
    $dia = $p['dia'];
    if (!isset($pausasPorUserDia[$u])) $pausasPorUserDia[$u] = [];
    if (!isset($pausasPorUserDia[$u][$dia])) $pausasPorUserDia[$u][$dia] = [];
    $pausasPorUserDia[$u][$dia][] = ['inicio' => $p['inicio'], 'fim' => $p['fim']];
}

// Aplicar pausas fundidas por linha de resultado
foreach ($resultados as &$linha) {
    $u = $linha['utilizador'];
    $dia = $linha['dia'];
    $fundidas = $pausasPorUserDia[$u][$dia] ?? [];
    $linha['total_pausa_segundos'] = fundirIntervalosSobrepostos($fundidas);
}
unset($linha);

// Cálculos finais por linha
foreach ($resultados as &$linha) {
    $jornada   = (int)$linha['jornada_bruta_segundos'];
    $pausas    = (int)$linha['total_pausa_segundos'];
    $liquido   = max(0, $jornada - $pausas);
    $percentual= $jornada > 0 ? round(($liquido / $jornada) * 100, 2) : 0;

    $linha['tempo_jornada']  = gmdate("H:i:s", $jornada);
    $linha['tempo_pausa']    = gmdate("H:i:s", $pausas);
    $linha['tempo_liquido']  = gmdate("H:i:s", $liquido);
    $linha['percentual_util']= $percentual;
}
unset($linha);

// Agregado por utilizador
$agregado = [];
foreach ($resultados as $linha) {
    $u = $linha['utilizador'];
    if (!isset($agregado[$u])) {
        $agregado[$u] = ['nome'=>$linha['nome_funcionario'], 'jornada'=>0, 'pausas'=>0, 'dias'=>0];
    }
    $agregado[$u]['jornada'] += (int)$linha['jornada_bruta_segundos'];
    $agregado[$u]['pausas']  += (int)$linha['total_pausa_segundos'];
    $agregado[$u]['dias']    += 1;
}

// Cálculo final agregado
foreach ($agregado as &$d) {
    $liq = max(0, $d['jornada'] - $d['pausas']);
    $d['tempo_jornada']     = gmdate("H:i:s", $d['jornada']);
    $d['tempo_pausa']       = gmdate("H:i:s", $d['pausas']);
    $d['tempo_liquido']     = gmdate("H:i:s", $liq);
    $d['percentual_util']   = $d['jornada']>0 ? round(($liq/$d['jornada'])*100, 2) : 0;
    $d['media_liquido_dia'] = gmdate("H:i:s", (int) round($liq / max(1,$d['dias'])));
}
unset($d);
?>

<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <title>Comparar Utilizadores da Empresa</title>
  <link rel="stylesheet" href="../style.css">
  <style>
    body {
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      background-color: #f5f5f5;
      margin: 0;
      padding: 20px;
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
    min-width: 220px;
    max-width: 300px;
    }

    .coluna-direita {
    flex: 1;
    padding-right: 10px;
    }

    @media (max-width: 768px) {
    .layout {
        flex-direction: column;
    }

    .coluna-esquerda,
    .coluna-direita {
        width: 100%;
    }
    }

    #notificacao {
      position: fixed;
      top: 20px;
      right: 20px;
      background-color: #ffc107; /* amarelo por defeito */
      color: white;
      font-weight: bold;
      font-size: 15px;
      padding: 12px 20px;
      border-radius: 10px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.2);
      display: none;
      z-index: 9999;
    }

    .caixa-funcionarios{
      max-height: 300px; 
      overflow-y: auto; 
      border: 1px solid #bfa55f;          /* tom do dourado */
      padding: 10px; 
      border-radius: 6px; 
      background-color: #d0bc78;          /* cor da caixa */
      box-shadow: inset 0 0 0 1px rgba(0,0,0,.03);
    }
    .caixa-funcionarios .linha {
      padding: 6px 8px;
      border-radius: 6px;
    }
    .caixa-funcionarios .linha:hover {
      background: rgba(255,255,255,0.35);
    }
    table th,
table td {
  text-align: center;
  vertical-align: middle;
  border-bottom: 1px solid #939292ff;
  border-right: 1px solid #dedcdcff;
  border-left: 1px solid #e4e3e3ff;
}

table {
  border-collapse: collapse;
}


  </style>
</head>
<body>

  <div class="toast" id="notificacao" style="display:none;">
    <span id="notificacao-texto"></span>
    <button class="fechar" style="color: red; background-color: #f8d7da; border-color: #f8d7da" onclick="document.getElementById('notificacao').style.display='none'"> × </button>
  </div>
  <div class="container">
    <h1>Comparar Utilizadores da Empresa</h1>

    <div class="topo-botoes">
      <button onclick="window.location.href='analisar_dados.php?utilizador=<?= urlencode($utilizador) ?>'">← Voltar ao Painel</button>
    </div>

    <div class="layout">
      <!-- Coluna esquerda -->
      <div class="coluna-esquerda">
        <form method="get" action="compararUtilizadores.php" id="formComparar">
          <div style="margin-bottom: 20px;">
            <label><strong>Selecionar Funcionários:</strong></label>
            <div style="max-height: 220px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; border-radius: 6px; background-color: #bfa55f;">
              <?php foreach ($funcionarios as $f):
                $u    = $f['utilizador'];
                $nome = $f['nome_exibir'];
                $dep  = isset($f['departamento']) ? (string)$f['departamento'] : '';
                $isChecked = in_array($u, $utilizadoresSelecionados, true) ? 'checked' : '';
              ?>
                <div>
                  <input type="checkbox"
                        id="user_<?= htmlspecialchars($u) ?>"
                        name="utilizadores[]"
                        value="<?= htmlspecialchars($u) ?>"
                        data-departamento="<?= htmlspecialchars($dep) ?>"
                        <?= $isChecked ?>>
                  <label for="user_<?= htmlspecialchars($u) ?>" title="<?= htmlspecialchars($u) ?>">
                    <?= htmlspecialchars($nome) ?>
                  </label>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div style="margin-bottom: 20px;">
            <label><strong>Selecionar Departamento:</strong></label>
            <div style="max-height: 220px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; border-radius: 6px; background-color: #bfa55f;">
              <?php foreach ($departamentos as $d): 
                $id = (string)$d['departamento_id'];
                $nome = $d['nome_departamento'];
                $checked = ($departamentoSelecionado !== '' && $departamentoSelecionado == $id) ? 'checked' : '';
              ?>
                <div>
                  <input type="radio"
                        id="dep_<?= htmlspecialchars($id) ?>"
                        name="departamento"      
                        value="<?= htmlspecialchars($id) ?>"
                        <?= $checked ?>>
                  <label for="dep_<?= htmlspecialchars($id) ?>">
                    <?= htmlspecialchars($nome) ?>
                  </label>
                </div>
              <?php endforeach; ?>
            </div>
            <small style="display:block; margin-top:6px; color:#555;">
              Ao escolher um departamento, os funcionários serão desmarcados.
            </small>
          </div>

          <div style="margin-bottom: 20px;">
            <label for="data_inicio"><strong>Data Início:</strong></label><br>
            <input type="date" name="data_inicio" id="data_inicio"
                   value="<?= htmlspecialchars($_GET['data_inicio'] ?? '') ?>"
                   style="padding: 6px; border: 1px solid #ccc; border-radius: 6px; width: 100%;">
          </div>

          <div style="margin-bottom: 20px;">
            <label for="data_fim"><strong>Data Fim:</strong></label><br>
            <input type="date" name="data_fim" id="data_fim"
                   value="<?= htmlspecialchars($_GET['data_fim'] ?? '') ?>"
                   style="padding: 6px; border: 1px solid #ccc; border-radius: 6px; width: 100%;">
          </div>

          <button type="submit"
                  style="padding: 10px 16px; background-color: #cfa728; color: black;
                         font-weight: bold; border: none; border-radius: 8px; cursor: pointer; width: 100%;">
            Comparar Selecionados
          </button>
        </form>
      </div>

      <!-- Coluna direita -->
      <div class="coluna-direita">
        <?php if (!empty($resultados)): ?>
          <h2>Estatísticas Diárias</h2>
          <table cellpadding="6" cellspacing="0" style="width: 100%; border-collapse: collapse;">
            <thead style="background-color: #f2f2f2;">
              <tr style="background-color: #ffc107;">
                <th>Data</th>
                <th>Funcionário</th>
                <th>Entrada</th>
                <th>Saída</th>
                <th>Jornada Bruta</th>
                <th>Total de Pausas</th>
                <th>Tempo Líquido</th>
                <th>% Tempo Útil (+70%)</th>
              </tr>
            </thead>
            <tbody>
              <?php
                // Gerar cores únicas por data
                $coresPorData = [];
                $coresDisponiveis = ['#fef9e7', '#f0f8ff', '#f6f8e2', '#eef5fb', '#d8f1eeff', '#fce4d6', '#e6f2e6', '#ddedd7ff', '#e3f2fd', '#f3e5f5'];
                $i = 0;

                foreach ($resultados as $linha) {
                    $data = $linha['dia'];
                    if (!isset($coresPorData[$data])) {
                        $coresPorData[$data] = $coresDisponiveis[$i % count($coresDisponiveis)];
                        $i++;
                    }
                }
                ?>
              <?php foreach ($resultados as $linha): ?>
                <tr style="background-color: <?= $coresPorData[$linha['dia']] ?>;">
                  <td><?= htmlspecialchars($linha['dia']) ?></td>
                  <td><?= htmlspecialchars($linha['nome_funcionario']) ?></td>
                  <?php
                    $horaEntrada = $linha['hora_entrada'];
                    $styleEntrada = '';

                    if ($horaEntrada > '08:10:00' && $horaEntrada <= '09:00:00') {
                        $styleEntrada = 'background-color: #f8d7da; color: #721c24; font-weight: bold;';
                    }else if($horaEntrada < '08:00:00'){
                        $styleEntrada = 'background-color: #f2cb54ff; color: #4f3c02ff; font-weight: bold;';
                    }
                  ?>
                  <td style="<?= $styleEntrada ?>"><?= htmlspecialchars($horaEntrada) ?></td>
                  <td><?= htmlspecialchars($linha['hora_saida']) ?></td>
                  <td><?= htmlspecialchars($linha['tempo_jornada']) ?></td>
                  <td><?= htmlspecialchars($linha['tempo_pausa']) ?></td>
                  <td><?= htmlspecialchars($linha['tempo_liquido']) ?></td>
                  <?php
                    $percentual = $linha['percentual_util'];

                    if ($percentual > 80) {
                        $corFundo = '#fff3cd';  // dourado claro (tipo Bootstrap alert-warning)
                        $corTexto = '#856404';  // dourado escuro
                    } elseif ($percentual > 70) {
                        $corFundo = '#d4edda';  // verde claro
                        $corTexto = '#155724';  // verde escuro
                    } else {
                        $corFundo = '#f8d7da';  // vermelho claro
                        $corTexto = '#721c24';  // vermelho escuro
                    }
                  ?>
                  <td style="background-color: <?= $corFundo ?>; color: <?= $corTexto ?>; font-weight: bold;">
                    <?= htmlspecialchars($percentual) ?>%
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
        <?php if (!empty($agregado)): ?>
          <h2 style="margin-top: 40px;">Resumo Agregado por Funcionário</h2>
          <table cellpadding="6" cellspacing="0" style="width: 100%; border-collapse: collapse;">
            <thead style="background-color: #f2f2f2;">
              <tr>
                <th>Funcionário</th>
                <th>Total Jornada</th>
                <th>Total Pausas</th>
                <th>Total Líquido</th>
                <th>% Tempo Útil</th>
                <th>Média Diária de Trabalho</th>
              </tr>
            </thead>
            <tbody>
              <?php
                // Gerar cores únicas por data
                $coresPorDat = [];
                $coresDisponiveis = ['#fef9e7', '#f0f8ff', '#f6f8e2', '#eef5fb', '#d8f1eeff', '#fce4d6', '#e6f2e6', '#cdecc3ff', '#e3f2fd', '#f3e5f5'];
                $i = 0;

                foreach ($agregado as $linha) {
                    $data = $linha['nome'];
                    if (!isset($coresPorDat[$data])) {
                        $coresPorDat[$data] = $coresDisponiveis[$i % count($coresDisponiveis)];
                        $i++;
                    }
                }
                ?>
              <?php foreach ($agregado as $dados): ?>
                <tr style="background-color: <?= $coresPorDat[$dados['nome']] ?>;">
                  <td><?= htmlspecialchars($dados['nome']) ?></td>
                  <td><?= htmlspecialchars($dados['tempo_jornada']) ?></td>
                  <td><?= htmlspecialchars($dados['tempo_pausa']) ?></td>
                  <td><?= htmlspecialchars($dados['tempo_liquido']) ?></td>
                  <?php
                    $percentual = $dados['percentual_util'];

                    if ($percentual > 80) {
                        $corFundo = '#fff3cd';  // dourado claro
                        $corTexto = '#856404';  // dourado escuro
                    } elseif ($percentual > 70) {
                        $corFundo = '#d4edda';  // verde claro
                        $corTexto = '#155724';  // verde escuro
                    } else {
                        $corFundo = '#f8d7da';  // vermelho claro
                        $corTexto = '#721c24';  // vermelho escuro
                    }
                  ?>
                  <td style="background-color: <?= $corFundo ?>; color: <?= $corTexto ?>; font-weight: bold;">
                    <?= htmlspecialchars($percentual) ?>%
                  </td>
                  <td><?= htmlspecialchars($dados['media_liquido_dia']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>

      </div>
    </div>
  </div>

<script>
  const form            = document.getElementById('formComparar');
  const userCheckboxes  = document.querySelectorAll('input[name="utilizadores[]"]');
  const depRadios       = document.querySelectorAll('input[name="departamento"]');
  const depSelecionado  = "<?= htmlspecialchars($departamentoSelecionado) ?>"; // do PHP

  function selecionarUtilizadoresDoDepartamento(depId) {
    userCheckboxes.forEach(cb => {
      const depUser = cb.dataset.departamento || '';
      cb.checked = (depId && depUser === depId); // marca só os que pertencem ao dept
    });
  }

  // Quando escolheres um departamento -> marcar/desmarcar utilizadores
  depRadios.forEach(radio => {
    radio.addEventListener('change', () => {
      if (radio.checked) selecionarUtilizadoresDoDepartamento(radio.value);
    });
  });

  // Se marcares um utilizador manualmente -> limpar a seleção de departamento
  userCheckboxes.forEach(cb => {
    cb.addEventListener('change', () => {
      if (cb.checked) depRadios.forEach(r => r.checked = false);
    });
  });

  // Se vier um departamento já selecionado por GET, aplicar a seleção ao carregar
  document.addEventListener('DOMContentLoaded', () => {
    if (depSelecionado) selecionarUtilizadoresDoDepartamento(depSelecionado);
  });

  // Validação: OU (≥2 utilizadores) OU (1 departamento)
  form.addEventListener('submit', function(e) {
    const selectedUsers = Array.from(userCheckboxes).filter(cb => cb.checked).length;
    const selectedDept  = Array.from(depRadios).some(r => r.checked);

    if (!selectedDept && selectedUsers < 2) {
      mostrarNotificacao('Selecione pelo menos dois utilizadores OU um departamento.','erro');
      e.preventDefault();
    }
  });

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
