<?php
require_once '../config_bd.php';
$foiSalvoAntes = isset($_GET['recem_gravado']) && $_GET['recem_gravado'] == "1";


if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['salvar_tempo'], $_POST['id'], $_POST['tempo'])) {
    $id = intval($_POST['id']);
    $tempo = $_POST['tempo'];

    try {
        $stmt = $ligacao->prepare("UPDATE tarefas SET tempo_decorrido_utilizador = ?, data_inicio_cronometro = NULL, ultima_modificacao = NOW() WHERE id = ?");
        $stmt->execute([$tempo, $id]);

        // Verificar se a tarefa ainda está ativa após salvar tempo
        $stmt = $ligacao->prepare("SELECT estado_cronometro FROM tarefas WHERE id = ?");
        $stmt->execute([$id]);
        $estadoAtual = $stmt->fetchColumn();

        if ($estadoAtual === 'ativa') {
            // Reativar o cronómetro com NOW()
            $stmt = $ligacao->prepare("UPDATE tarefas SET data_inicio_cronometro = NOW() WHERE id = ?");
            $stmt->execute([$id]);
        }

        echo "ok";
        exit();
    } catch (PDOException $e) {
        echo "erro: " . $e->getMessage();
        exit();
    }
}


$tarefa = "Tarefa não definida";
$descricao = "";
$data_criacao = "";
$ultima_modificacao = "";



if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $ligacao->prepare("SELECT utilizador, tarefa, descricao, data_criacao, ultima_modificacao, tempo_decorrido_utilizador, estado_cronometro, data_inicio_cronometro FROM tarefas WHERE id = :id");
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $linha = $stmt->fetch(PDO::FETCH_ASSOC);
        $utilizador=$linha['utilizador'];
        $tarefa = $linha['tarefa'];
        $descricao = $linha['descricao'];
        $data_criacao = $linha['data_criacao'];
        $ultima_modificacao = $linha['ultima_modificacao'];
        $tempo_decorrido_utilizador = $linha['tempo_decorrido_utilizador'] ?? '00:00:00';


        $estado_cronometro = $linha['estado_cronometro'] ?? 'inativa';
        $data_inicio_cronometro = $linha['data_inicio_cronometro'] ?? null;
    }

    $stmtpausas = $ligacao->prepare("
        SELECT 
            pt.funcionario,
            pt.data_pausa, 
            pt.data_retorno, 
            pt.tempo_pausa, 
            pt.motivo_id,
            mp.descricao AS descricao,
            mp.tipo
        FROM pausas_tarefas pt
        INNER JOIN motivos_pausa mp ON pt.motivo_id = mp.id
        WHERE pt.tarefa_id = :id AND pt.funcionario = :utilizador
        ORDER BY pt.data_pausa ASC
    ");
    $stmtpausas->bindParam(':id', $id, PDO::PARAM_INT);
    $stmtpausas->bindParam(':utilizador', $utilizador, PDO::PARAM_STR);
    $stmtpausas->execute();

    $pausas = $stmtpausas->fetchAll(PDO::FETCH_ASSOC);

    $stmtfuncionarios = $ligacao->prepare("
        SELECT * FROM funcionarios WHERE utilizador != :utilizador
    ");
    $stmtfuncionarios->bindParam(':utilizador', $utilizador, PDO::PARAM_STR);
    $stmtfuncionarios->execute();

    $lista_funcionarios = $stmtfuncionarios->fetchAll(PDO::FETCH_ASSOC);

    // Buscar todos os departamentos (sem parâmetros)
    $stmtDepartamentos = $ligacao->prepare("
        SELECT * FROM departamento
    ");
    $stmtDepartamentos->execute();  // ← importante
    $departamentos = $stmtDepartamentos->fetchAll(PDO::FETCH_ASSOC);

    $stmtTransicoes = $ligacao->prepare("
        SELECT 
            utilizador_antigo, 
            utilizador_novo, 
            dataHora_transicao, 
            duracao_exec
        FROM transicao_tarefas
        WHERE tarefa_id = :id
        ORDER BY dataHora_transicao ASC
    ");
    $stmtTransicoes->bindParam(':id', $id, PDO::PARAM_INT);
    $stmtTransicoes->execute();

    $transicoes = $stmtTransicoes->fetchAll(PDO::FETCH_ASSOC);
}



if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["atualizar_tarefa"])) {
  $novaTarefa = trim($_POST["tarefa"]);
  $novaDescricao = trim($_POST["descricao"]);

  if (!empty($novaTarefa) && !empty($novaDescricao)) {
      try {
          $stmt = $ligacao->prepare("UPDATE tarefas SET tarefa = ?, descricao = ?, ultima_modificacao = NOW() WHERE id = ?");
          $stmt->execute([$novaTarefa, $novaDescricao, $id]);
          header("Location: abrirtarefa.php?id=" . $id);
          exit();
      } catch (PDOException $e) {
          echo "<script>alert('Erro ao atualizar: " . $e->getMessage() . "');</script>";
      }
  } else {
      echo "<script>alert('Preencha todos os campos para atualizar.');</script>";
  }
}


$motivos_pausa = [];
try {
    $stmt = $ligacao->prepare("SELECT id, codigo, descricao FROM motivos_pausa ORDER BY codigo");
    $stmt->execute();
    $motivos_pausa = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "<script>alert('Erro ao carregar motivos de pausa: " . $e->getMessage() . "');</script>";
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <title>Gestor de Tarefas</title>
  <link rel="stylesheet" href="../style.css">
  <style>
    body {
      background-color: #f8f8f8;
      font-family: Arial, sans-serif;
      margin: 0;
      padding: 0;
      color: #333;
    }


   .container {
      width: 100%;
      max-width: 100%;
      margin: 0 auto;
      padding: 20px 40px;
      box-sizing: border-box;
    }


    .caixa-superior {
      background-color: #ffffff;
      padding: 10px 20px;
      border-radius: 10px;
      box-shadow: 0px 0px 8px rgba(0,0,0,0.1);
      margin-bottom: 20px;
      width: 100%;
    }

    .cabecalho {
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .titulo {
      font-size: 20px;
    }

    .botao-voltar {
      background-color: #cfa728;
      color: black;
      font-weight: bold;
      border: none;
      padding: 10px 20px;
      border-radius: 10px;
      font-size: 14px;
      cursor: pointer;
    }

    .botao-voltar:hover {
      background-color: #b89120;
    }


    .task-box {
      width: 100%;
      margin: 0;
      padding: 35px 40px;
      box-sizing: border-box;
    }


    .layout-conteudo {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 60px;
}


    .botoes {
      display: flex;
      flex-direction: column;
      gap: 10px; /* Reduz o espaçamento entre botões */
      justify-content: center; /* Alinha verticalmente no meio */
    }

    .botao {
      background-color: #cfa728;
      color: black; /* Preto como pediu */
      font-weight: bold;
      border: none;
      padding: 10px 16px; /* Botão menor */
      border-radius: 10px;
      width: 180px; /* Um pouco mais estreito */
      font-size: 14px;
      cursor: pointer;
      box-shadow: 2px 2px 6px rgba(0,0,0,0.1);
      transition: background-color 0.2s ease;
    }
    .botao:hover {
      background-color: #b89120;
    }

    .conteudo-direito {
      display: flex;
      flex-direction: column;
      gap: 40px;
      align-items: flex-start;
      justify-content: flex-start;
      flex: 1;
    }

    .info-datas {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .info-datas div {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .label {
      background: #eeeeee;
      padding: 5px 15px;
      border-radius: 20px;
      font-weight: bold;
      font-size: 14px;
    }

    .temporizador {
      background-color: #eeeeee;
      padding: 30px 40px;
      border-radius: 25px;
      min-width: 250px;
  max-width: 100%;
  width: 100%;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      text-align: center;
    }


    .temporizador span {
      font-size: 26px;
      display: block;
      margin-bottom: 10px;
    }

    .tempo {
      font-size: 70px;
      font-weight: bold;
      margin-top: 10px;
    }

    .layout-conteudo {
      display: flex;
      align-items: center; /* Centraliza verticalmente */
      justify-content: space-between;
      gap: 60px;
    }

    @keyframes fadeZoomIn {
      0% {
        opacity: 0;
        transform: scale(0.9);
      }
      100% {
        opacity: 1;
        transform: scale(1);
      }
    }

    @keyframes fadeZoomOut {
      0% {
        opacity: 1;
        transform: scale(1);
      }
      100% {
        opacity: 0;
        transform: scale(0.9);
      }
    }

    #descricaoFlutuante-overlay {
      display: none;
      position: fixed;
      top: 0; left: 0;
      width: 100vw; height: 100vh;
      background-color: rgba(0,0,0,0.3);
      z-index: 1999;
      justify-content: center;
      align-items: center;
    }

    #descricaoFlutuante {
      background-color: #ffffff; /* Fundo branco corrigido */
      padding: 25px 30px 20px;
      border: 1px solid #cfa728;
      border-radius: 14px;
      box-shadow: 0 6px 18px rgba(0,0,0,0.25);
      width: 420px;
      max-width: 90%;
      font-family: Arial, sans-serif;
      position: relative;
      opacity: 0;
      transform: scale(0.9);
    }

    #descricaoFlutuante.fadeIn {
      animation: fadeZoomIn 0.3s ease-out forwards;
    }

    #descricaoFlutuante.fadeOut {
      animation: fadeZoomOut 0.3s ease-in forwards;
    }

    #descricaoFlutuante .fechar-descricao {
      position: absolute;
      top: 10px;
      right: 14px;
      font-size: 20px;
      border: none;
      background: none;
      cursor: pointer;
      color: #444;
      font-weight: bold;
      line-height: 1;
    }

    #descricaoFlutuante .conteudo {
      margin-top: 10px;
      font-size: 15px;
      white-space: pre-wrap;
      color: #333;
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
      overflow: auto;
      padding: 20px;
    }

    .modal-box {
      background: #fff;
      border-radius: 16px;
      padding: 24px 30px;
      width: 520px;
      max-width: 90vw;
      max-height: 70vh;
      overflow-y: auto;
      box-shadow: 0 12px 30px rgba(0,0,0,0.25);
      position: relative; /* <- ESSENCIAL para posicionar o botão corretamente */
      display: flex;
      flex-direction: column;
      gap: 20px;
      animation: fadeZoomIn 0.3s ease-out forwards;
    }

    .modal-box .fechar-descricao {
      position: absolute;
      top: 10px;
      right: 14px;
      font-size: 20px;
      border: none;
      background: none;
      cursor: pointer;
      color: #444;
      font-weight: bold;
      z-index: 2;
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



    .modal-box label {
      display: flex;
      align-items: center;
      font-size: 15px;
      gap: 10px;
      cursor: pointer;
      white-space: nowrap;
    }

    .modal-box input[type="checkbox"] {
      transform: scale(1.2);
      accent-color: #cfa728;
    }


    .modal-box input,
    .modal-box textarea {
      background-color: #f0f0f0;
      border: 1px solid #444;
      border-radius: 8px;
      padding: 10px;
      font-size: 15px;
      width: 100%;
      box-sizing: border-box;
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

    .modal-box.animar {
      animation: fadeInZoom 0.3s ease-out forwards;
    }

    .modal-box.sair {
      animation: fadeOutZoom 0.25s ease-in forwards;
    }

    .titulo-tabela {
      font-size: 17px;
      font-weight: bold;
      margin-bottom: 10px;
      color: #444;
      text-align: center;
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

    .toast {
      position: fixed;
      top: 20px;
      right: 20px;
      background-color: #d4edda; /* verde por defeito */
      color: #155724;
      border-left: 5px solid #28a745;
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
      color: inherit;
    }
    .linha-horizontal {
  display: flex;
  flex-wrap: wrap;
  gap: 40px;
  align-items: flex-start;
  justify-content: flex-start;
  margin-top: 30px;
}


    .tabela-box {
      flex: 1;
      min-width: 350px;
    }

    
.info-cronometro {
  display: flex;
  flex-direction: column;
  gap: 20px;
  min-width: 300px;
}
/*Isto */
.tabela-scroll {
  overflow-x: auto;
  width: 100%;
}

/* */
    .tabela-pausas {
  width: 100%;
  max-width: 420px;
  margin: 20px auto;
  border-collapse: separate;
  border-spacing: 0;
  background-color: #ffffff; /* Fundo branco */
  padding: 25px 30px 20px;
  border: 1px solid #cfa728;
  border-radius: 14px;
  box-shadow: 0 6px 18px rgba(0,0,0,0.25);
  font-family: Arial, sans-serif;
  text-align: center;
  transform: scale(0.9);
  opacity: 0;
  animation: fadeInScale 0.4s ease-in-out forwards;
}



@keyframes fadeInScale {
  to {
    opacity: 1;
    transform: scale(1);
  }
}

.tabela-pausas thead th {
  background-color: #f9f4e5;
  color: #333;
  padding: 12px;
  font-size: 15px;
  border-bottom: 1px solid #cfa728;
}

.tabela-pausas tbody td {
  padding: 12px 10px;
  font-size: 14px;
  color: #444;
  border-bottom: 1px solid #eee;
}

.tabela-pausas tbody tr:last-child td {
  border-bottom: none;
}

.tabela-pausas tbody tr:nth-child(even) {
  background-color: #fdfaf3;
}

.tabela-pausas tbody tr:hover {
  background-color: #f6f1dd;
}

.botoes {
  display: flex;
  flex-direction: column;
  gap: 10px;
  align-self: flex-start;
  padding-bottom: 10px;
}
 /* A partir de aqui */
* {
  box-sizing: border-box;
}

.modal-box {
  width: 520px;
  max-width: 95vw;
}


@media (max-width: 1200px) {
  .layout-conteudo {
    flex-direction: column;
    gap: 40px;
    align-items: stretch;
  }

  .linha-horizontal {
    flex-direction: column;
    gap: 40px;
  }

  .tabela-box, .info-cronometro {
    width: 100%;
    min-width: unset;
  }

  .temporizador {
    min-width: unset;
    width: 100%;
  }

  .botoes {
    flex-direction: row;
    flex-wrap: wrap;
    gap: 10px;
  }

  .botao {
    width: 100%;
    max-width: 220px;
    flex: 1;
  }
}

@media (max-width: 768px) {
  .container {
    padding: 10px 20px;
  }

  .botao-voltar {
    padding: 8px 12px;
    font-size: 13px;
  }

  .titulo {
    font-size: 18px;
  }

  .temporizador .tempo {
    font-size: 50px;
  }

  .modal-box {
    width: 95vw;
    padding: 20px;
  }

  .tabela-pausas {
    padding: 20px;
  }

  .tabela-pausas thead th,
  .tabela-pausas tbody td {
    font-size: 13px;
    padding: 10px 8px;
  }
}
/* ATE AQUIa */


  </style>
</head>
<body>

<div class="container">

  <div class="caixa-superior">
    <div class="cabecalho">
      <div class="titulo"><?php echo htmlspecialchars($tarefa); ?></div>
      <form action="tarefas.php" method="get">
        <button type="button" class="botao-voltar" id="btn-voltar">← Voltar</button>
      </form>
    </div>
  </div>

  <div class="task-box">
    <div class="layout-conteudo">
      <div class="botoes">
        <button class="botao" id="btn-pausar" type="button">Pausar</button>
        <button class="botao" id="btn-concluir" type="button" onclick="confirmarConclusao()">Concluir</button>
        <button class="botao" id="btn-editar" type="button" onclick="abrirModalEdicao()">Editar</button>
       <?php
        $mostrarBotaoTransitar = false;

        if ($estado_cronometro === 'pausa' && !empty($pausas)) {
            $ultima = end($pausas); // Última pausa do array (mais recente, pois está ordenado ASC)

            if (
                empty($ultima['data_retorno']) &&
                isset($ultima['tipo']) &&
                strtolower(trim($ultima['tipo'])) === 'iniciartarefas'
            ) {
                $mostrarBotaoTransitar = true;
            }
        }
        ?>


<?php if ($estado_cronometro === 'pausa'): ?>
    <?php if ($mostrarBotaoTransitar): ?>
        <button class="botao" id="btn-trstarefa" type="button" onclick="abrirModalTransitarTarefa(<?= $id ?>)">
            Transitar Tarefa
        </button>
    <?php else: ?>
        <script>
            mostrarNotificacao("Só pode transitar se a pausa for do tipo 'IniciarTarefas'.", "erro");
        </script>
    <?php endif; ?>
<?php else: ?>
    <script>
        mostrarNotificacao("Só pode transitar tarefas que estão em pausa.", "erro");
    </script>
<?php endif; ?>


        <!--<button class="botao" onclick="confirmarEliminacao()">Eliminar</button>-->
        <button class="botao" onclick="mostrarDescricao(`<?= addslashes(htmlspecialchars($descricao)) ?>`)">Ver descrição</button>

        <div id="box-descricao" class="descricao" style="display: none;">
          <?php echo nl2br(htmlspecialchars($descricao)); ?>
        </div>
      </div>

      <div class="conteudo-direito">
        <div class="linha-horizontal">
          <div class="info-cronometro">
            <div class="info-datas">
              <div><span class="label">Data da criação:</span> <?php echo htmlspecialchars($data_criacao); ?></div>
              <div><span class="label">Última modificação:</span> <?php echo htmlspecialchars($ultima_modificacao); ?></div>
            </div>
            <div class="temporizador">
              <span>Tempo decorrido</span>
              <div class="tempo" id="cronometro">00:00:00</div>
            </div>
          </div>

          <div class="tabela-box">
            <h3 class="titulo-tabela">Histórico de Pausas</h3>
            <?php if (!empty($pausas)): ?>
              <table class="tabela-pausas">
                <thead>
                  <tr>
                    <th>Tipo de Pausa</th>
                    <th>Hora de Início</th>
                    <th>Hora de Fim</th>
                    <th>Duração</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($pausas as $pausa): ?>
                    <?php
                      $inicio = strtotime($pausa['data_pausa']);
                      $fim = strtotime($pausa['data_retorno']);
                      if ($fim) {
                        $duracao = $fim - $inicio;
                        $duracao_formatada = sprintf('%02d:%02d:%02d', floor($duracao / 3600), floor(($duracao % 3600) / 60), $duracao % 60);
                      } else {
                        $duracao_formatada = 'Em execução';
                      }
                    ?>
                    <tr>
                      <td><?= htmlspecialchars($pausa['descricao']) ?></td>
                      <td><?= date('H:i:s', $inicio) ?></td>
                      <td><?= $fim ? date('H:i:s', $fim) : 'Em execução' ?></td>
                      <td><?= $duracao_formatada ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php else: ?>
              <p>Sem pausas registradas.</p>
            <?php endif; ?>
          </div>

          <div class="tabela-box">
            <h3 class="titulo-tabela">Histórico de Transições desta Tarefa</h3>
           <?php if (!empty($transicoes)): ?>
            <table class="tabela-pausas">
              <thead>
                <tr>
                  <th>De</th>
                  <th>Para</th>
                  <th>Dia</th>
                  <th>Hora</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($transicoes as $transicao): ?>
                  <?php
                    $data = strtotime($transicao['dataHora_transicao']);
                    $dia_formatado = date('d/m/Y', $data);
                    $hora_formatada = date('H:i', $data);
                  ?>
                  <tr>
                    <td><?= htmlspecialchars($transicao['utilizador_antigo']) ?></td>
                    <td><?= htmlspecialchars($transicao['utilizador_novo']) ?></td>
                    <td><?= $dia_formatado ?></td>
                    <td><?= $hora_formatada ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php else: ?>
            <p>Sem transições registradas.</p>
          <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
document.getElementById("btn-voltar").addEventListener("click", function () {
  pararCronometro();
  const tempoAtual = document.getElementById("cronometro").textContent;

  fetch("abrirtarefa.php", {
    method: "POST",
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      salvar_tempo: 1,
      id: <?= $id ?>,
      tempo: tempoAtual
    })
  })
  .then(res => res.text())
  .then(resposta => {
    if (resposta.trim() === "ok") {
      window.location.href = "tarefas.php";
    } else {
      mostrarNotificacao("Erro ao guardar tempo: " + resposta, "erro");
    }
  })
  .catch(err => {
    mostrarNotificacao("Erro ao guardar tempo: " + err.message, "erro");
  });
});
</script>


<script>
let tempoSalvo = "<?= $tempo_decorrido_utilizador ?? '00:00:00' ?>";
let estadoCronometro = "<?= $estado_cronometro ?>";
let dataInicioCronometro = "<?= $data_inicio_cronometro ?? '' ?>";
let foiSalvoAntes = <?= $foiSalvoAntes ? 'true' : 'false' ?>;

let retomouAgora = sessionStorage.getItem("retomouAgora") === "1";
sessionStorage.removeItem("retomouAgora");

let partes = tempoSalvo.split(':');
let segundosSalvos = (+partes[0]) * 3600 + (+partes[1]) * 60 + (+partes[2]);
let tempoSalvoHoje = 0;

let cronometro = document.getElementById("cronometro");
let botaoPausar = document.getElementById("btn-pausar");

let ativo = false;
let intervalo = null;
let timestampInicioReal = null;

if (estadoCronometro === "ativa") {
  botaoPausar.textContent = "Pausar";
} else if (estadoCronometro === "pausa") {
  botaoPausar.textContent = "Retomar";
} else {
  botaoPausar.textContent = "Iniciar";
}

// Determina o início do tempo real
if (!foiSalvoAntes && estadoCronometro === "ativa" && dataInicioCronometro && !retomouAgora) {
  let inicio = new Date(dataInicioCronometro.replace(' ', 'T'));
  let agora = new Date();
  let diffSegundos = Math.floor((agora - inicio) / 1000);
  if (diffSegundos > 0) {
    segundosSalvos += diffSegundos;
  }
}

// Atualiza visualmente o tempo
function atualizarDisplay(segundosTotais) {
  let h = String(Math.floor(segundosTotais / 3600)).padStart(2, '0');
  let m = String(Math.floor((segundosTotais % 3600) / 60)).padStart(2, '0');
  let s = String(segundosTotais % 60).padStart(2, '0');
  cronometro.textContent = `${h}:${m}:${s}`;
}

atualizarDisplay(segundosSalvos);

// Inicia o cronómetro real
function iniciarContagem() {
  if (ativo) return;

  ativo = true;
  timestampInicioReal = Date.now();

  intervalo = setInterval(() => {
    let agora = Date.now();
    let diff = Math.floor((agora - timestampInicioReal) / 1000);
    let totalSegundos = segundosSalvos + diff;

    atualizarDisplay(totalSegundos);
    tempoSalvoHoje = diff;
  }, 1000);
}

// Para o cronómetro e guarda tempo atual
function pararCronometro() {
  if (!ativo) return;

  let agora = Date.now();
  let diff = Math.floor((agora - timestampInicioReal) / 1000);
  segundosSalvos += diff;

  ativo = false;
  clearInterval(intervalo);
  atualizarDisplay(segundosSalvos);
  botaoPausar.textContent = "Retomar";
}

let tempoAntesDaPausa = 0;

botaoPausar.addEventListener("click", () => {
  // Se o botão está com texto "Retomar", retomar a tarefa e redirecionar
  if (botaoPausar.textContent === "Retomar") {
    fetch('retomar_tarefa.php?id=<?= $id ?>')
      .then(res => res.text())
      .then(resposta => {
        if (resposta.trim() === "ja_tem_tarefa_ativa") {
          mostrarNotificacao("Já tem uma tarefa em execução. Pare-a antes de retomar esta.", "erro");
          return;
        }

        if (resposta.trim() === "ok") {
          // Regista o início e só depois redireciona
          fetch('registar_inicio.php?id=<?= $id ?>')
            .then(() => {
              sessionStorage.setItem("retomouAgora", "1");
              window.location.href = "tarefas.php";
            });
        } else {
          mostrarNotificacao("Erro ao retomar tarefa: " + resposta, "erro");
        }
      });
    return;
  }

  // Caso o botão esteja em modo "Pausar" (cronómetro ativo)
  if (!ativo) {
    fetch('retomar_tarefa.php?id=<?= $id ?>')
      .then(res => res.text())
      .then(resposta => {
        if (resposta.trim() === "ja_tem_tarefa_ativa") {
          mostrarNotificacao("Já tem uma tarefa em execução. Pare-a antes de retomar esta.", "erro");
          return;
        }

        if (resposta.trim() === "ok") {
          fetch('registar_inicio.php?id=<?= $id ?>');
          sessionStorage.setItem("retomouAgora", "1");
          setTimeout(() => location.reload(), 700);
        } else {
          mostrarNotificacao("Erro ao retomar tarefa: " + resposta, "erro");
        }
      });
  } else {
    tempoAntesDaPausa = segundosSalvos + Math.floor((Date.now() - timestampInicioReal) / 1000);
    abrirModalPausa();
  }
});


// Caso esteja ativa ao carregar a página
if (estadoCronometro === "ativa") {
  iniciarContagem();
}


/*-----------------*/
/*-----------------*/
/*-----------------*/

  function mostrarDescricao(texto) {
    const overlay = document.getElementById("modal-descricao");
    const box     = overlay.querySelector(".modal-box");
    document.getElementById("descricao-text").textContent = texto;
    overlay.style.display = "flex";
    box.classList.remove("fadeOut");
    void box.offsetWidth;
    box.classList.add("fadeIn");
  }

  function fecharDescricao() {
    const overlay = document.getElementById("modal-descricao");
    const box     = overlay.querySelector(".modal-box");
    box.classList.remove("fadeIn");
    box.classList.add("fadeOut");
    setTimeout(() => {
      overlay.style.display = "none";
      box.classList.remove("fadeOut");
    }, 250);
  }

  document.addEventListener("keydown", function(e) {
    if (e.key === "Escape") fecharDescricao();
  });




</script>
  <div id="modal-descricao" class="modal-overlay" onclick="fecharDescricao()">
    <div class="modal-box" onclick="event.stopPropagation()">
      <button class="fechar-descricao" onclick="fecharDescricao()">×</button>
      <h3>Descrição</h3>
      <p id="descricao-text" style="white-space: pre-wrap;"></p>
    </div>
  </div>



<!----  -->

  <script>
    function confirmarEliminacao() {
      document.getElementById("confirmarEliminacao-overlay").style.display = "flex";
    }

    function fecharConfirmarEliminacao() {
      document.getElementById("confirmarEliminacao-overlay").style.display = "none";
    }
  </script>



  <div id="confirmarEliminacao-overlay" onclick="fecharConfirmarEliminacao()" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.3); z-index:2000; justify-content:center; align-items:center;">
    <div style="background:#fff; padding:25px 30px 20px; border:1px solid #cfa728; border-radius:14px; box-shadow:0 6px 18px rgba(0,0,0,0.25); width:420px; max-width:90%; position:relative;" onclick="event.stopPropagation()">
      <strong style="font-size:16px;">Tem a certeza de que quer eliminar esta tarefa?</strong>
      <div style="margin-top:20px; display:flex; justify-content:space-between;">
        <a href="eliminar.php?id=<?php echo $id; ?>" class="botao" style="background:#b93333; color:white; text-align:center;">Sim</a>
        <button onclick="fecharConfirmarEliminacao()" class="botao" style="background:#cccccc;">Não</button>
      </div>
    </div>
  </div>

<!----  -->


  <script>
    function confirmarConclusao() {
      document.getElementById("confirmarConclusao-overlay").style.display = "flex";
    }

    function fecharConfirmarConclusao() {
      document.getElementById("confirmarConclusao-overlay").style.display = "none";
    }

    function confirmarConclusaoFinal() {
      pararCronometro();

      const tempoFinal = document.getElementById("cronometro").textContent;
      const idTarefa = <?= $id ?>;
      const url = `concluir.php?id=${idTarefa}&tempo=${encodeURIComponent(tempoFinal)}`;

      fetch(url)
        .then(res => res.text())
        .then(resposta => {
          if (resposta.trim() === "ja_tem_tarefa_ativa") {
            mostrarNotificacao("Já tem uma tarefa ativa. Termine-a antes de concluir esta.", "erro");
          } else if (resposta.trim() === "") {
            window.location.href = "tarefas.php";
            mostrarNotificacao("A Tarefa foi Concluida!","sucesso");
          } else {
            mostrarNotificacao("Erro ao concluir tarefa: " + resposta, "erro");
          }
        })
        .catch(err => {
          mostrarNotificacao("Erro ao comunicar: " + err.message, "erro");
        });
    }
  </script>




  <div id="confirmarConclusao-overlay" onclick="fecharConfirmarConclusao()" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.3); z-index:2000; justify-content:center; align-items:center;">
  <div style="background:#fff; padding:25px 30px 20px; border:1px solid #28a745; border-radius:14px; box-shadow:0 6px 18px rgba(0,0,0,0.25); width:420px; max-width:90%; position:relative;" onclick="event.stopPropagation()">
    <strong style="font-size:16px;">Tem a certeza de que quer concluir esta tarefa?</strong>
    <div style="margin-top:20px; display:flex; justify-content:space-between;">
      <button class="botao" style="background:#28a745; color:white; text-align:center;" onclick="confirmarConclusaoFinal()">Sim</button>
      <button onclick="fecharConfirmarConclusao()" class="botao" style="background:#cccccc;">Não</button>
    </div>
  </div>
</div>


<div id="confirmarFinalizarDia-overlay" onclick="fecharConfirmarFinalizarDia()" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.3); z-index:2000; justify-content:center; align-items:center;">
  <div style="background:#fff; padding:25px 30px 20px; border:1px solid #cfa728; border-radius:14px; box-shadow:0 6px 18px rgba(0,0,0,0.25); width:420px; max-width:90%; position:relative;" onclick="event.stopPropagation()">
    <strong style="font-size:16px;">Deseja finalizar o dia?</strong>
    <div style="margin-top:20px; display:flex; justify-content:space-between;">
      <button class="botao" style="background:#28a745; color:white;" onclick="executarFinalizarDia()">Sim</button>
      <button onclick="fecharConfirmarFinalizarDia()" class="botao" style="background:#cccccc;">Não</button>
    </div>
  </div>
</div>

<div id="confirmarFinalizarDia-overlay" onclick="fecharConfirmarFinalizarDia()" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.3); z-index:2000; justify-content:center; align-items:center;">
  <div style="background:#fff; padding:25px 30px 20px; border:1px solid #cfa728; border-radius:14px; box-shadow:0 6px 18px rgba(0,0,0,0.25); width:420px; max-width:90%; position:relative;" onclick="event.stopPropagation()">
    <strong style="font-size:16px;">Deseja finalizar o dia?</strong>
    <div style="margin-top:20px; display:flex; justify-content:space-between;">
      <button class="botao" style="background:#28a745; color:white;" onclick="executarFinalizarDia()">Sim</button>
      <button onclick="fecharConfirmarFinalizarDia()" class="botao" style="background:#cccccc;">Não</button>
    </div>
  </div>
</div>




<!----  -->


<div class="modal-overlay" id="modalEditarOverlay">
  <div class="modal-box">
    <button class="fechar-modal" onclick="fecharModalEdicao()">×</button>
    <form id="form-editar-tarefa">
      <input type="hidden" name="id" value="<?= $id ?>" />
      <input type="hidden" name="atualizar_tarefa" value="1" />
      <label for="tarefa">Tarefa:</label>
      <input type="text" id="editar_tarefa" name="tarefa" value="<?= htmlspecialchars($tarefa) ?>" required />

      <label for="descricao">Descrição:</label>
      <textarea id="editar_descricao" name="descricao" rows="5" required><?= htmlspecialchars($descricao) ?></textarea>

      <button type="submit" class="botao-modal">Atualizar</button>
    </form>
  </div>
</div>



<!----  -->



<script>
  function abrirModalEdicao() {
    const overlay = document.getElementById('modalEditarOverlay');
    const box = overlay.querySelector('.modal-box');
    document.getElementById("tempo_atual_input").value = document.getElementById("cronometro").textContent;
    overlay.style.display = 'flex';
    box.classList.remove('sair');
    void box.offsetWidth;
    box.classList.add('animar');
  }

  function fecharModalEdicao() {
    const overlay = document.getElementById('modalEditarOverlay');
    const box = overlay.querySelector('.modal-box');
    box.classList.remove('animar');
    box.classList.add('sair');
    setTimeout(() => {
      overlay.style.display = 'none';
      box.classList.remove('sair');
    }, 250);
  }

  // Fechar ao clicar fora
  window.addEventListener("click", function (e) {
    const modal = document.getElementById("modalEditarOverlay");
    if (e.target === modal) fecharModalEdicao();
  });
</script>

<!----  -->

<script>
document.getElementById("form-editar-tarefa").addEventListener("submit", function(e) {
  e.preventDefault();
  const form = e.target;
  const dados = new FormData(form);

  fetch("atualizar_tarefa.php", {
    method: "POST",
    body: dados
  })
  .then(res => res.text())
  .then(resposta => {
    if (resposta.trim() === "ok") {
      fecharModalEdicao();
      // Atualiza o botão "Ver descrição" com o novo texto
      const novaTarefa = form.querySelector('input[name="tarefa"]').value;
      const novaDescricao = form.querySelector('textarea[name="descricao"]').value;

      // Atualiza o texto do título da tarefa
      document.querySelector('.titulo').textContent = novaTarefa;

      // Atualiza o botão de descrição
      const btnDescricao = document.querySelector('button[onclick^="mostrarDescricao"]');
      btnDescricao.setAttribute("onclick", `mostrarDescricao(\`${novaDescricao.replace(/`/g, "\\`")}\`)`);

      // Fecha modal e mostra toast
      fecharModalEdicao();
      mostrarNotificacao("Tarefa atualizada com sucesso!", "sucesso");

    } else {
      mostrarNotificacao("Erro ao atualizar tarefa: " + resposta, "erro");
    }

  })
  .catch(erro => {
    mostrarNotificacao("Erro de comunicação: " + erro.message, "erro");
  });
});
</script>



<!----  -->

<script>
  function abrirModalTransitarTarefa() {
    const overlay = document.getElementById('modalTransitarOverlay');
    const box = overlay.querySelector('.modal-box');
    overlay.style.display = 'flex';
    box.classList.remove('sair');
    void box.offsetWidth;
    box.classList.add('animar');
  }

  function fecharModalTransitarTarefa() {
    const overlay = document.getElementById('modalTransitarOverlay');
    const box = overlay.querySelector('.modal-box');
    box.classList.remove('animar');
    box.classList.add('sair');
    setTimeout(() => {
      overlay.style.display = 'none';
      box.classList.remove('sair');
    }, 250);
  }


  window.addEventListener("click", function (e) {
    const modal = document.getElementById("modalTransitarOverlay");
    if (e.target === modal) fecharModalTransitarTarefa();
  });
</script>

<div class="modal-overlay" id="modalTransitarOverlay">
  <div class="modal-box" style="
    max-width: 500px;
    min-height: 480px;
    padding: 40px 45px;
    overflow-y: auto;
  ">
    <button class="fechar-modal" onclick="fecharModalTransitarTarefa()">×</button>
    <form id="form-transitar-tarefa" style="display: flex; flex-direction: column; gap: 20px;">
      <input type="hidden" name="id" value="<?= $id ?>" />
      <input type="hidden" name="atualizar_tarefa" value="1" />

      <h2 style="margin-top: 0; color: #333;">Transitar Tarefa</h2>

      <!-- Departamento -->
      <div>
        <label for="departamento" style="font-weight: bold; margin-bottom:10px;">Departamento:</label>
        <select id="departamento" name="departamento" required onchange="atualizarFuncionarios()" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ccc;">
          <option value="">-- Escolha um departamento --</option>
          <?php foreach ($departamentos as $dep): ?>
            <option value="<?= $dep['id'] ?>"><?= htmlspecialchars($dep['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <br></br>

      <!-- Funcionário -->
      <div>
        <label for="funcionario" style="font-weight: bold; margin-bottom:10px;">Funcionário:</label>
        <select id="funcionario" name="funcionario" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ccc;">
          <option value="">-- Selecione um funcionário --</option>
          <?php foreach ($lista_funcionarios as $f): ?>
            <option value="<?= $f['utilizador'] ?>"><?= htmlspecialchars($f['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="item-align: center; margin-top: 30px;">
        <button type="submit" class="botao-modal" style="
          background-color: #d4aa2f;
          color: #000;
          font-weight: bold;
          padding: 10px 24px;
          border-radius: 8px;
          border: none;
          cursor: pointer;
          transition: background-color 0.3s;
        ">Atualizar</button>
      </div>
    </form>
  </div>
</div>



<!----  -->

<script>
  // Mapa de funcionários para seus departamentos
  const mapaFuncionariosDepartamentos = {
    <?php foreach ($lista_funcionarios as $f): ?>
      "<?= $f['utilizador'] ?>": "<?= $f['departamento'] ?>",
    <?php endforeach; ?>
  };
</script>


<script>
  document.getElementById('funcionario').addEventListener('change', function () {
    const funcionarioId = this.value;
    const departamentoSelect = document.getElementById('departamento');
    const departamentoId = mapaFuncionariosDepartamentos[funcionarioId];

    if (departamentoId) {
      departamentoSelect.value = departamentoId;
    }
  });
</script>


<script>
document.getElementById("form-transitar-tarefa").addEventListener("submit", function(e) {
  e.preventDefault();
  const form = e.target;
  const departamento = document.getElementById('departamento').value;
  const funcionario = document.getElementById('funcionario').value;

  if (funcionario && !departamento) {
    alert('Por favor selecione um departamento para o funcionário escolhido.');
    return;
  }

  const dados = new FormData(form);

  fetch("transitar_utilizador_tarefa.php", {
    method: "POST",
    body: dados
  })
  .then(res => res.text())
  .then(resposta => {
    if (resposta.trim() === "ok") {
      window.location.href = "tarefas.php";
    } else {
      mostrarNotificacao("Erro ao transitar tarefa: " + resposta, "erro");
    }
  })
  .catch(erro => {
    mostrarNotificacao("Erro de comunicação: " + erro.message, "erro");
  });
});

</script>




<!----  -->




<div class="modal-overlay" id="modalPausaOverlay">
  <div class="modal-box">
    <button class="fechar-modal" onclick="fecharModalPausa()">×</button>
    <form method="POST" action="registrar_pausa.php">
      <input type="hidden" name="id_tarefa" value="<?= $id ?>" />
      <input type="hidden" name="tempo_atual" id="tempo_atual_input" />
      <strong>Motivo(s) da pausa:</strong>
      <div style="margin-top: 15px; display: flex; flex-direction: column; gap: 10px; align-items: flex-start;">
      <?php foreach ($motivos_pausa as $motivo): ?>
        <label>
          <input type="radio" name="motivo" value="<?= $motivo['id'] ?>" required />
          <?= htmlspecialchars($motivo['descricao']) ?>
        </label>
      <?php endforeach; ?>
      </div>
      <button type="submit" class="botao-modal" style="margin-top: 20px;">Confirmar pausa</button>
    </form>
  </div>
</div>

<!-- -->


<script>
  function abrirModalPausa() {
    document.getElementById("tempo_atual_input").value = cronometro.textContent;

    const overlay = document.getElementById('modalPausaOverlay');
    const box = overlay.querySelector('.modal-box');
    overlay.style.display = 'flex';
    box.classList.remove('sair');
    void box.offsetWidth;
    box.classList.add('animar');
  }

  function fecharModalPausa() {
    const overlay = document.getElementById('modalPausaOverlay');
    const box = overlay.querySelector('.modal-box');
    box.classList.remove('animar');
    box.classList.add('sair');
    setTimeout(() => {
      overlay.style.display = 'none';
      box.classList.remove('sair');
    }, 250);
  }

  // Fechar ao clicar fora
  window.addEventListener("click", function (e) {
    const modal = document.getElementById("modalPausaOverlay");
    if (e.target === modal) fecharModalPausa();
  });
</script>

<!-----> 


<script>
  document.querySelector('#modalPausaOverlay form').addEventListener('submit', function(e) {
  const motivoSelecionado = this.querySelector('input[name="motivo"]:checked');

  if (!motivoSelecionado) {
    e.preventDefault();

    const noti = document.getElementById('notificacao');
    noti.style.display = 'block';
    noti.style.opacity = '1';

    setTimeout(() => {
      noti.style.transition = 'opacity 0.5s';
      noti.style.opacity = '0';
      setTimeout(() => noti.style.display = 'none', 500);
    }, 2500);
  } else {
    // Atualiza o tempo atual com o valor mais recente antes de submeter
    document.getElementById("tempo_atual_input").value = cronometro.textContent;
    pararCronometro();
  }
});


  function pararCronometro() {
    if (typeof intervalo !== "undefined") {
      ativo = false;
      clearInterval(intervalo);
      botaoPausar.textContent = "Retomar";
    }
  }
</script>


<!-----> 

<div class="toast" id="notificacao" style="display:none;">
  <span id="notificacao-texto"></span>
  <button class="fechar" onclick="document.getElementById('notificacao').style.display='none'">×</button>
</div>


<script>
  function mostrarNotificacao(mensagem, tipo = 'sucesso') {
    const toast = document.getElementById("notificacao");
    const span  = document.getElementById("notificacao-texto");

    let bg, corTexto, borda;

    if (tipo === 'erro') {
      bg = "#f8d7da";
      corTexto = "#721c24";
      borda = "#dc3545";
    } else {
      bg = "#d4edda";
      corTexto = "#155724";
      borda = "#28a745";
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

<script>
function finalizarDia() {
  pararCronometro(); // para o cronômetro

  const tempoAtual = document.getElementById("cronometro").textContent;

  fetch('finalizar_dia.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      id: <?= $id ?>,
      tempo: tempoAtual
    })
  })
  .then(res => res.text())
  .then(resposta => {
    if (resposta.trim() === "ok") {
      mostrarNotificacao("Dia finalizado e tempo salvo com sucesso!");
    } else {
      mostrarNotificacao("Erro ao finalizar o dia: " + resposta, "erro");
    }
  })
  .catch(erro => {
    mostrarNotificacao("Erro de comunicação: " + erro.message, "erro");
  });
}
</script>

<script>
function abrirConfirmarFinalizarDia() {
  document.getElementById("confirmarFinalizarDia-overlay").style.display = "flex";
}

function fecharConfirmarFinalizarDia() {
  document.getElementById("confirmarFinalizarDia-overlay").style.display = "none";
}

function fecharDiaFinalizado() {
  document.getElementById("diaFinalizado-overlay").style.display = "none";
}

function executarFinalizarDia() {
  fecharConfirmarFinalizarDia();
  pararCronometro();

  let tempoHoje = tempoSalvoHoje;
  let h = String(Math.floor(tempoHoje / 3600)).padStart(2, '0');
  let m = String(Math.floor((tempoHoje % 3600) / 60)).padStart(2, '0');
  let s = String(tempoHoje % 60).padStart(2, '0');
  const tempoAtual = `${h}:${m}:${s}`;


  fetch('finalizar_dia.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      id: <?= $id ?>,
      tempo: tempoAtual
    })
  })
  .then(res => res.text())
  .then(resposta => {
    if (resposta.trim() === "ok") {
      document.getElementById("diaFinalizado-overlay").style.display = "flex";
    } else {
      mostrarNotificacao("Erro ao finalizar o dia: " + resposta, "erro");
    }
  })
  .catch(erro => {
    mostrarNotificacao("Erro de comunicação: " + erro.message, "erro");
  });
}
</script>

<!-- Modal de dia finalizado -->
<div id="diaFinalizado-overlay" onclick="fecharDiaFinalizado()" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.3); z-index:2000; justify-content:center; align-items:center;">
<div style="background:#fff; padding:25px 30px 20px; border:1px solid #28a745; border-radius:14px; box-shadow:0 6px 18px rgba(0,0,0,0.25); width:420px; max-width:90%; position:relative;" onclick="event.stopPropagation()">
  <strong style="font-size:16px;">Dia finalizado com sucesso!</strong>
  <div style="margin-top:20px; display:flex; justify-content:center;">
    <a href="painel.php" class="botao" style="background:#28a745; color:white; text-align:center;">Voltar para o painel</a>
  </div>
</div>
</div>

</body>
</html>