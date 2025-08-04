<?php
session_start();

if (!isset($_SESSION['utilizador_logado'])) {
    header('Location: login.php');
    exit();
}

require_once '../config_bd.php';

$utilizador = $_SESSION["utilizador_logado"];
$mensagem = "";  // ← para notificação de sucesso
$erro = "";

// Obter o departamento do utilizador atual
$stmtDept = $ligacao->prepare("SELECT departamento FROM funcionarios WHERE utilizador = ?");
$stmtDept->execute([$utilizador]);
$departamentoId = $stmtDept->fetchColumn();

// --- 1) Tratar “Associar uma tarefa” via POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['associar'])) {
    try {

        $stmtTarefas = $ligacao->prepare("
            SELECT * FROM tarefas 
            WHERE utilizador = ? 
              AND estado = 'pendente'
        ");
        $stmtTarefas->execute([$utilizador]);
        $tarefas = $stmtTarefas->fetchAll(PDO::FETCH_ASSOC);

        $verificacao=false;
        $verificacaoInatividade=false;

        $stmt = $ligacao->prepare("
            SELECT * 
            FROM utilizador_entradaesaida 
            WHERE utilizador = ? 
            AND data = CURDATE()
            AND hora_saida IS NULL
            LIMIT 1
        ");
        $stmt->execute([$utilizador]);
        $registoEntrada = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($registoEntrada) {
            foreach($tarefas as $t){
                if($t['estado_cronometro'] === 'ativa'){
                    $verificacao=true;
                }else if($t['estado_cronometro']=== 'inativa'){
                    $verificacaoInatividade=true;
                }
            }
        } else {
            $verificacaoInatividade=true;
        }

        if ($verificacao === true) {
            $erro = "Não é permitido executar uma nova tarefa visto que tem tarefas em execução";
        }else if($verificacaoInatividade===true){
            $erro = "Não é permitido executar uma nova tarefa visto que não tem iniciado o dia";
        } else {
            $stmtAssociar1 = $ligacao->prepare("
                UPDATE tarefas 
                SET estado = 'pendente',
                    data_inicio_cronometro = NOW(),
                    estado_cronometro = 'ativa',
                    ultima_modificacao = NOW(),
                    utilizador=?
                WHERE id = ?
            ");
            $stmtAssociar1->execute([$utilizador,$_POST['id'] ]);

            $stmtAssociar = $ligacao->prepare("
                UPDATE departamento_tarefa
                SET tempo_em_espera = TIMEDIFF(NOW(), data_entrada),
                  utilizador=?
                WHERE tarefa_id = ? AND data_saida IS NULL
            ");
            $stmtAssociar->execute([$utilizador ,$_POST['id']]);

            $stmtUpdate = $ligacao->prepare("
                UPDATE transicao_tarefas
                SET hora_de_associacao=NOW(),
                  utilizador_novo=?
                WHERE tarefa_id = ? AND utilizador_novo IS NULL AND departamento_novo=?
            ");
            $stmtUpdate->execute([$utilizador, $_POST['id'], $departamentoId]);
            
            $mensagem = "✅ Tarefa associada a " . $utilizador . " com sucesso!";
        }
    } catch (PDOException $e) {
        $erro = "Erro ao reabrir tarefa: " . $e->getMessage();
    }
}

try {
    $pesquisa = isset($_GET["pesquisa"]) ? trim($_GET["pesquisa"]) : '';
    $param = "%$pesquisa%";

    $sqlBase = "
      SELECT 
          t.id, t.tarefa, t.descricao, t.tempo_decorrido,
          dt.tempo_em_espera, dt.data_entrada, dt.data_saida
      FROM tarefas t
      INNER JOIN (
          SELECT dt1.*
          FROM departamento_tarefa dt1
          INNER JOIN (
              SELECT tarefa_id, MAX(data_entrada) AS max_data
              FROM departamento_tarefa
              GROUP BY tarefa_id
          ) dt2 ON dt1.tarefa_id = dt2.tarefa_id AND dt1.data_entrada = dt2.max_data
          WHERE dt1.departamento_id = ?
      ) dt ON t.id = dt.tarefa_id
      WHERE t.utilizador IS NULL
        AND t.estado = 'espera'
    ";

    if ($pesquisa !== '') {
        $sqlBase .= " AND (t.tarefa LIKE ? OR t.descricao LIKE ?)";
        $sqlBase .= " ORDER BY dt.data_entrada DESC";
        $stmt = $ligacao->prepare($sqlBase);
        $stmt->execute([$departamentoId, $param, $param]);
    } else {
        $sqlBase .= " ORDER BY dt.data_entrada DESC";
        $stmt = $ligacao->prepare($sqlBase);
        $stmt->execute([$departamentoId]);
    }

    $tarefasEspera = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $erro = "Erro ao carregar tarefas em espera: " . $e->getMessage();
}

?>


<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Tarefas Para Executar</title>
  <link rel="stylesheet" href="../style.css" />
  <style>
    body {
      margin: 0;
      height: 80vh;
      display: flex;
      justify-content: center;
      align-items: center;
      background-color: #f4f4f4;
    }

    .container {
  display: flex;
  flex-direction: column;
  align-items: center;
  width: 100%;
  padding: 40px 20px 60px;
  box-sizing: border-box;
  margin-top: 80px; /* Evita sobreposição da barra superior fixa */
}


    .top-buttons {
      display: flex;
      gap: 15px;
      margin-bottom: 30px;
      margin-top:100px;
    }

    .botao {
      background-color: #d4aa2f;
      color: #000;
      font-size: 16px;
      font-weight: bold;
      z-index: 1;
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
      border-collapse: collapse;       /* já estava */
      border: 1px solid #ccc;          /* borda externa da tabela */
      table-layout: fixed;
      box-shadow: 0 2px 5px rgba(0,0,0,0.1);
      border-radius: 10px;
      overflow: hidden;
    }

    th, td {
      padding: 14px 10px;
      text-align: center;
      vertical-align: middle;
      border: 1px solid #ccc;          /* borda em todas as células */
    }

    th {
      background-color: #d4af37;
      color: black;
    }

    th:first-child, td:first-child {
      width: 50%;
    }
    th:nth-child(2), td:nth-child(2) { width: 15%; }
    th:nth-child(3), td:nth-child(3),
    th:nth-child(4), td:nth-child(4) { width: 17.5%; }
    th:nth-child(5), td:nth-child(5) {width: 17.5%;}

    .botao-pequeno {
      padding: 6px 14px;
      font-size: 0.9em;
      border: none;
      background-color: #d4aa2f;
      color: black;
      border-radius: 8px;
      cursor: pointer;
    }


    @keyframes fadeZoomIn {
      from {
        opacity: 0;
        transform: scale(0.9);
      }
      to {
        opacity: 1;
        transform: scale(1);
      }
    }

    @keyframes fadeZoomOut {
      from {
        opacity: 1;
        transform: scale(1);
      }
      to {
        opacity: 0;
        transform: scale(0.9);
      }
    }


    .modal-box {
      background: #fff;
      border-radius: 16px;
      padding: 24px 30px;
      width: 520px;
      max-width: 90vw;
      max-height: 70vh;              /* reduzido para deixar mais espaçado */
      overflow-y: auto;              /* ativa scroll */
      box-shadow: 0 12px 30px rgba(0,0,0,0.25);
      position: relative;
      display: flex;
      flex-direction: column;
      gap: 20px;
      transform: scale(0.95);
      animation: fadeZoomIn 0.3s ease-out forwards;
      scrollbar-gutter: stable;
    }

    .modal-box::-webkit-scrollbar {
      width: 8px;
    }
    .modal-box::-webkit-scrollbar-thumb {
      background-color: #bbb;
      border-radius: 10px;
    }
    .modal-box::-webkit-scrollbar-track {
      background: #f1f1f1;
    }

    .modal-box.fadeIn {
      animation: fadeZoomIn 0.3s ease-out forwards;
    }
    .modal-box.fadeOut {
      animation: fadeZoomOut 0.25s ease-in forwards;
    }

    .modal-box .conteudo,
    #descricao-text {
      line-height: 1.6;
      font-size: 15.5px;
      color: #333;
    }

    .fechar-descricao {
      position: absolute;
      top: 10px; right: 14px;
      font-size: 20px;
      border: none; background: none;
      cursor: pointer; color: #444;
    }

    .modal-box .conteudo {
      white-space: pre-wrap;
      font-size: 15px; color: #333;
    }

    .modal-overlay {
      position: fixed;
      top: 0; left: 0;
      width: 100vw;
      height: 100vh;
      background: rgba(0,0,0,0.4);
      display: none;
      justify-content: center;
      align-items: center;
      z-index: 9999;
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

    .barra-superior {
      width: 96%;
      display: flex;
      justify-content: flex-end;
      padding: 10px 20px;
      background-color: #ffffff;
      box-shadow: 0 1px 4px rgba(0,0,0,0.1);
      position: fixed;
      border-radius: 10px;
      z-index: 10;
      top: 10px;
      left: 20px;
      right:20px;
    }

    .utilizador-info {
      display: flex;
      align-items: center;
      gap: 25px;
      font-weight: bold;
      font-size: 18px;
      margin-right: 30px;
      padding: 6px 14px;
    }

    .tabela-wrapper {
      max-height: 500px; /* ajusta conforme necessário */
      overflow-y: auto;
      width: 80%;
      max-width: 900px;
      background: white;
      border-radius: 10px;
      box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }

    table {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
    }

    thead th {
      position: sticky;
      top: 0;
      background-color: #d4af37;
      z-index: 2;
    }

  </style>
</head>
<body>
  <div class="barra-superior">
    <div class="utilizador-info">
      <span class="nome-utilizador"><?= htmlspecialchars($utilizador) ?></span>
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
      <a href="tarefas.php" class="botao">← Voltar para tarefas</a>
    </div>

    <h2>Tarefas Para Executar</h2>

    <form method="GET" style="margin-bottom: 20px;">
      <input 
        type="text" 
        name="pesquisa" 
        placeholder="Pesquisar tarefa ou descrição..." 
        value="<?= htmlspecialchars($_GET['pesquisa'] ?? '') ?>" 
        style="padding: 8px 12px; border-radius: 8px; border: 1px solid #ccc; width: 250px;"
      />
      <button 
        type="submit" 
        class="botao-pequeno"
        style="margin-left: 10px;"
      >Procurar</button>
    </form>

    
    <?php if ($erro): ?>
      <p style="color: red;"><?= htmlspecialchars($erro) ?></p>
    <?php endif; ?>

    <div class="tabela-wrapper">
      <table>
        <thead>
          <tr>
            <th>Tarefa</th>
            <th>Tempo Gasto</th>
            <th>Associar</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($tarefasEspera)): ?>
          <tr><td colspan="3">Nenhuma tarefa para executar encontrada.</td></tr>
        <?php else: ?>
          <?php foreach ($tarefasEspera as $t): 
            $timezone = new DateTimeZone('Europe/Lisbon');
            $dataEntrada = new DateTime($t['data_entrada'], $timezone);
            $agora = new DateTime('now', $timezone);


            $intervalo = $agora->getTimestamp() - $dataEntrada->getTimestamp(); // diferença em segundos

            // Converter para HH:MM:SS
            $h = str_pad(floor($intervalo / 3600), 2, '0', STR_PAD_LEFT);
            $m = str_pad(floor(($intervalo % 3600) / 60), 2, '0', STR_PAD_LEFT);
            $s = str_pad($intervalo % 60, 2, '0', STR_PAD_LEFT);

            $tempoDecorrido = "$h:$m:$s";
          ?>
          <tr>
            <td><?= htmlspecialchars($t['tarefa']) ?></td>
            <td><?= $tempoDecorrido ?></td>
            <td> <!-- ESTA COLUNA FALTAVA!! -->
              <form method="post" style="display:inline">
                <input type="hidden" name="id" value="<?= $t['id'] ?>" />
                <button 
                  type="submit" 
                  name="associar" 
                  class="botao-pequeno"
                >Associar tarefa</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>

      </table>
    </div>
  </div>

  <!-- Modal simples para exibir a descrição -->
  <div id="modal-descricao" class="modal-overlay" onclick="fecharModal()">
  <div class="modal-box" onclick="event.stopPropagation()">
    <button class="fechar-descricao" onclick="fecharModal()">×</button>
    <h3>Descrição</h3>
    <p id="descricao-text" style="white-space: pre-wrap;"></p>
  </div>
</div>


<script>
  function mostrarDescricao(texto) {
    const overlay = document.getElementById("modal-descricao");
    const box     = overlay.querySelector(".modal-box");
    document.getElementById("descricao-text").textContent = texto;
    overlay.style.display = "flex";
    box.classList.remove("fadeOut");
    void box.offsetWidth;
    box.classList.add("fadeIn");
  }

  function fecharModal() {
    const overlay = document.getElementById("modal-descricao");
    const box     = overlay.querySelector(".modal-box");
    box.classList.remove("fadeIn");
    box.classList.add("fadeOut");
    setTimeout(() => {
      overlay.style.display = "none";
      box.classList.remove("fadeOut");
    }, 200);
  }

  document.addEventListener("keydown", function(e) {
    if (e.key === "Escape") fecharModal();
  });
</script>


<script>
  setTimeout(() => {
    const toast = document.getElementById('toast');
    if (toast) toast.style.display = 'none';
  }, 5000);
</script>


</body>
</html>
