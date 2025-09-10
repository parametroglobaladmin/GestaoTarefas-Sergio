<?php
require_once '../config_bd.php';
session_start();

if (!isset($_SESSION["utilizador_logado"])) {
    header("Location: ../login.php");
    exit();
}

$anoAtual = date('Y');
$anoSelecionado = $anoAtual;


// Buscar os dias bloqueados do ano selecionado
$stmt = $ligacao->prepare("SELECT data FROM dias_nao_permitidos WHERE YEAR(data) = :ano");
$stmt->execute([':ano' => $anoSelecionado]);
$linhas = $stmt->fetchAll(PDO::FETCH_COLUMN);
// Buscar dias de ausência do utilizador logado (ou de todos se necessário)
$stmtAusencias = $ligacao->prepare("SELECT data_falta FROM ausencia_funcionarios WHERE YEAR(data_falta) = :ano");
$stmtAusencias->execute([':ano' => $anoSelecionado]);
$linhasAusencias = $stmtAusencias->fetchAll(PDO::FETCH_COLUMN);

// Limpar espaços e guardar como array
$diasAusenciasBD = array_map('trim', $linhasAusencias);

$diasBloqueadosBD = array_map('trim', $linhas);
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <title>Calendário de Feriados</title>
  <style>
    body {
      font-family: 'Segoe UI', Tahoma, sans-serif;
      background-image: url('../imagens/fundo.jpg');
      background-size: cover;
      background-repeat: no-repeat;
      background-attachment: fixed;
      margin: 0;
      padding: 20px;
    }

    .container {
      max-width: 1300px;
      margin: 0 auto;
      background: white;
      padding: 30px;
      border-radius: 10px;
      box-shadow: 0 0 10px rgba(0,0,0,0.1);
      position: relative;
    }

    h1 {
      margin-bottom: 20px;
      color: #444;
    }

    .grid-ano {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
      gap: 20px;
    }

    .mes-bloco {
      border: 1px solid #ccc;
      border-radius: 10px;
      padding: 10px;
      background: #fff;
    }

    .ausente {
      background-color: #cce5ff;
      color: #004085;
      font-weight: bold;
    }


    .mes-bloco h4 {
      text-align: center;
      margin-top: 0;
    }

    .dias-semana {
      display: grid;
      grid-template-columns: repeat(7, 1fr);
      font-weight: bold;
      text-align: center;
      font-size: 12px;
      color: #555;
    }

    .dias-grid {
      display: grid;
      grid-template-columns: repeat(7, 1fr);
      font-size: 12px;
      gap: 4px;
    }

    .dia {
      text-align: center;
      padding: 5px;
      border: 1px solid #eee;
      border-radius: 6px;
    }

    .nao-permitido {
      background-color: #f8d7da;
      color: #721c24;
      font-weight: bold;
    }

    select {
      padding: 6px;
      border-radius: 6px;
      margin-bottom: 15px;
    }

    .btn-voltar {
      position: absolute;
      top: 20px;
      right: 30px;
      padding: 8px 14px;
      background-color: #cfa728;
      color: black;
      font-weight: bold;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      transition: background-color 0.2s;
      text-decoration: none;
    }

    .btn-voltar:hover {
      background-color: #b69020;
    }
  </style>
</head>
<body>
  <div class="container">
    <a href="painel.php" class="btn-voltar">← Voltar</a>
    <h1>Feriados - Ano <?= htmlspecialchars($anoSelecionado) ?></h1>

    <div class="grid-ano">
      <?php
      $meses = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
      foreach (range(1, 12) as $mes) {
          $diasNoMes = cal_days_in_month(CAL_GREGORIAN, $mes, $anoSelecionado);
          $primeiroDiaSemana = date('N', strtotime("$anoSelecionado-$mes-01"));

          echo "<div class='mes-bloco'>";
          echo "<h4>{$meses[$mes - 1]}</h4>";
          echo "<div class='dias-semana'><div>D</div><div>S</div><div>T</div><div>Q</div><div>Q</div><div>S</div><div>S</div></div>";
          echo "<div class='dias-grid'>";

          // Espaços em branco antes do primeiro dia
          $offset = $primeiroDiaSemana % 7;
          for ($i = 0; $i < $offset; $i++) {
              echo "<div></div>";
          }

          for ($dia = 1; $dia <= $diasNoMes; $dia++) {
              $dataCompleta = sprintf('%04d-%02d-%02d', $anoSelecionado, $mes, $dia);
              $isBloqueado = in_array($dataCompleta, $diasBloqueadosBD);
              $isAusente   = in_array($dataCompleta, $diasAusenciasBD);

              if ($isBloqueado) {
                  $class = 'dia nao-permitido';
              } elseif ($isAusente) {
                  $class = 'dia ausente';
              } else {
                  $class = 'dia';
              }

              echo "<div class='$class'>{$dia}</div>";
          }

          echo "</div></div>";
      }
      ?>
    </div>
  </div>
</body>
</html>
