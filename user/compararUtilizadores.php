<?php
require_once '../config_bd.php';
session_start();
if (!isset($_SESSION["utilizador_logado"])) {
    header("Location: ../index.php");
    exit();
}

$utilizador=$_SESSION["utilizador_logado"];

$stmt = $ligacao->prepare("SELECT utilizador FROM funcionarios ORDER BY utilizador ASC");
$stmt->execute();
$funcionarios = $stmt->fetchAll(PDO::FETCH_ASSOC);


$utilizadoresSelecionados = $_GET['utilizadores'] ?? [];
$dataInicio = $_GET['data_inicio'] ?? null;
$dataFim = $_GET['data_fim'] ?? null;

if (!empty($utilizadoresSelecionados)) {
    foreach ($utilizadoresSelecionados as $user) {
        // Aqui poderás tratar os dados individualmente (carregar tempos, pausas, etc)
        echo "<p>Selecionado: " . htmlspecialchars($user) . "</p>";
        // Usar $dataInicio e $dataFim nos filtros se existirem
    }
}

?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <title>Comparar Utilizadores da Empresa</title>
  <link rel="stylesheet" href="../style.css"> <!-- se quiseres manter separado -->
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
            <div style="max-height: 300px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; border-radius: 6px; background-color: #d0bc78ff;">
              <?php
              $stmt = $ligacao->prepare("SELECT utilizador FROM funcionarios ORDER BY utilizador ASC");
              $stmt->execute();
              $funcionarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
              foreach ($funcionarios as $f): ?>
                <div>
                  <input type="checkbox" id="user_<?= htmlspecialchars($f['utilizador']) ?>"
                        name="utilizadores[]" value="<?= htmlspecialchars($f['utilizador']) ?>">
                  <label for="user_<?= htmlspecialchars($f['utilizador']) ?>">
                    <?= htmlspecialchars($f['utilizador']) ?>
                  </label>
                </div>
              <?php endforeach; ?>
            </div>
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
      </div>
    </div>
  </div>
</body>

<script>

  document.getElementById('formComparar').addEventListener('submit', function(e) {
    const checkboxes = document.querySelectorAll('input[name="utilizadores[]"]:checked');
    if (checkboxes.length < 2) {
      mostrarNotificacao('Por favor, selecione pelo menos dois utilizadores para comparar.','erro');
      e.preventDefault(); // Impede o envio do formulário
    }
  });

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
</html>
