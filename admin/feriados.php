<?php
require_once '../config_bd.php';
session_start();
if (!isset($_SESSION["admin_logado"])) {
    header("Location: login.php");
    exit();
}

$anoAtual = date('Y');
$anoSelecionado = isset($_GET['ano']) && $_GET['ano'] >= $anoAtual ? (int)$_GET['ano'] : $anoAtual;

// Buscar os dias bloqueados do ano selecionado
$stmt = $ligacao->prepare("SELECT data FROM dias_nao_permitidos WHERE YEAR(data) = :ano");
$stmt->execute([':ano' => $anoSelecionado]);
$linhas = $stmt->fetchAll(PDO::FETCH_COLUMN);

$diasBloqueadosBD = array_map('trim', $linhas);


?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <title>Painel de Análise</title>
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

    .topo-botoes {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 30px;
      padding: 0 10px;
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

    .nao-permitido {
        background-color: #f8d7da !important;
        color: #721c24;
        font-weight: bold;
    }
  </style>
</head>
<body>
  <script>
    const diasBloqueadosBD = <?= json_encode($diasBloqueadosBD) ?>;
    const diasSelecionados = new Set();
    diasBloqueadosBD.forEach(data => diasSelecionados.add(data));
  </script>



<div id="notificacao" style="display:none; position:fixed; top:20px; right:20px; background-color:#28a745; color:white; padding:15px 20px; border-left:5px solid #218838; border-radius:6px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index:9999;">
  <span id="notificacao-texto"></span>
</div>

  <div class="container">
    <h1>Agenda Anual de Feriados</h1>

    <div class="topo-botoes">
      <button onclick="window.location.href='painel.php'">← Voltar ao Painel</button>
    </div>

    <div class="layout">
      <!-- Coluna esquerda -->
      <div class="coluna-esquerda">
        <h3 style="margin-bottom: 15px;">Seleção de Ano</h3>

        <form method="get" action="" style="margin-bottom: 20px;">
          <select name="ano" onchange="this.form.submit()" style="width: 100%; padding: 8px; font-size: 15px; border-radius: 6px;">
            <?php for ($a = $anoAtual; $a <= $anoAtual + 2; $a++): ?>
              <option value="<?= $a ?>" <?= $a === $anoSelecionado ? 'selected' : '' ?>><?= $a ?></option>
            <?php endfor; ?>
          </select>
        </form>

        <!-- O botão foi removido porque as alterações agora são guardadas automaticamente -->
        <p style="font-size: 14px; color: #555; margin-top: 10px;">
          Clique nos dias para marcar ou desmarcar feriados. As alterações são guardadas automaticamente.
        </p>
      </div>

      <!-- Coluna direita -->
      <div class="coluna-direita">
        <div id="grade-anual" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 20px;"></div>
      </div>
    </div>
  </div>

<script>
  const meses = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
  const grade = document.getElementById('grade-anual');
  const ano = <?= json_encode($anoSelecionado) ?>;

  diasBloqueadosBD.forEach(data => diasSelecionados.add(data));

  for (let mes = 0; mes < 12; mes++) {
    const bloco = document.createElement('div');
    bloco.style.border = '1px solid #ccc';
    bloco.style.borderRadius = '10px';
    bloco.style.padding = '10px';
    bloco.style.backgroundColor = '#fff';

    const titulo = `<h4 style="text-align:center; margin: 0 0 10px 0;">${meses[mes]}</h4>`;
    const diasSemana = `
      <div style="display: grid; grid-template-columns: repeat(7, 1fr); font-weight: bold; text-align: center; margin-bottom: 5px; font-size: 11px; color: #555;">
        <div>S</div><div>T</div><div>Q</div><div>Q</div><div>S</div><div>S</div><div>D</div>
      </div>`;
    
    const grid = document.createElement('div');
    grid.style.display = 'grid';
    grid.style.gridTemplateColumns = 'repeat(7, 1fr)';
    grid.style.fontSize = '13px';
    grid.style.gap = '4px';

    const primeiroDia = new Date(ano, mes, 1).getDay();
    const offset = (primeiroDia === 0) ? 6 : primeiroDia - 1;
    for (let i = 0; i < offset; i++) {
      const vazio = document.createElement('div');
      vazio.innerHTML = '';
      grid.appendChild(vazio);
    }

    const diasNoMes = new Date(ano, mes + 1, 0).getDate();

    for (let dia = 1; dia <= diasNoMes; dia++) {
      const dataStr = `${ano}-${String(mes + 1).padStart(2, '0')}-${String(dia).padStart(2, '0')}`;
      const isBloqueadoBD = diasSelecionados.has(dataStr);
      const div = document.createElement('div');
      div.textContent = dia;
      div.style.padding = '3px';
      div.style.fontSize = '10px';
      div.style.textAlign = 'center';
      div.style.border = '1px solid #eee';
      div.style.borderRadius = '6px';
      div.style.cursor = 'pointer';
      div.dataset.data = dataStr;

      const jsDate = new Date(ano, mes, dia);
      const isDomingo = jsDate.getDay() === 0;

      if (isBloqueadoBD || isDomingo) {
        div.classList.add('nao-permitido');
        div.style.backgroundColor = '#f8d7da';
        diasSelecionados.add(dataStr);
      }



      div.addEventListener('click', () => {
        const isAtivo = div.classList.contains('nao-permitido');
        const acao = isAtivo ? 'remover' : 'adicionar';

        // Atualiza visual
        if (isAtivo) {
          div.classList.remove('nao-permitido');
          div.style.backgroundColor = '';
          diasSelecionados.delete(dataStr);
        } else {
          div.classList.add('nao-permitido');
          div.style.backgroundColor = '#f8d7da';
          diasSelecionados.add(dataStr);
        }

        // Atualiza no servidor
        fetch('atualizar_dia_bloqueado.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: `data=${encodeURIComponent(dataStr)}&acao=${acao}`
        })
        .then(res => res.json())
        .then(res => {
          if (!res.sucesso) {
            mostrarNotificacao("Erro ao atualizar: " + (res.erro || ""), 'erro');
          } else {
            mostrarNotificacao(`Dia ${acao === 'adicionar' ? 'adicionado' : 'removido'} com sucesso!`);
          }
        })
        .catch(() => {
          mostrarNotificacao("Erro de comunicação com o servidor!", 'erro');
        });
      });



      grid.appendChild(div);
    }

    bloco.innerHTML = titulo + diasSemana;
    bloco.appendChild(grid);
    grade.appendChild(bloco);
  }


window.addEventListener('DOMContentLoaded', () => {
  const params = new URLSearchParams(window.location.search);
  if (params.get('sucesso') === '1') {
    const box = document.getElementById('notificacao');
    const texto = document.getElementById('notificacao-texto');
    texto.textContent = "Dias selecionados guardados com sucesso!";
    box.style.display = 'block';
    setTimeout(() => { box.style.display = 'none'; }, 4000);
  }
});

function mostrarNotificacao(mensagem, tipo = 'sucesso') {
  const box = document.getElementById('notificacao');
  const texto = document.getElementById('notificacao-texto');
  texto.textContent = mensagem;

  if (tipo === 'erro') {
    box.style.backgroundColor = '#dc3545';
    box.style.borderLeft = '5px solid #a71d2a';
  } else {
    box.style.backgroundColor = '#28a745';
    box.style.borderLeft = '5px solid #218838';
  }

  box.style.display = 'block';
  setTimeout(() => { box.style.display = 'none'; }, 4000);
}


</script>
</body>
</html>
