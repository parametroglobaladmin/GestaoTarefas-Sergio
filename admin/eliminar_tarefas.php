
<?php
require_once '../config_bd.php';
session_start();
if (!isset($_SESSION["admin_logado"])) {
    header("Location: login.php");
    exit();
}
$utilizadorLogado = $_SESSION['admin_logado'] ?? '';
$acesso = '';
$utilizadores = [];
$tarefas = [];

$query = "SELECT * FROM tarefas WHERE estado <> 'eliminada'";
$params = [];

if (!empty($_GET['tarefa_nome'])) {
    $query .= " AND tarefa LIKE ?";
    $params[] = '%' . $_GET['tarefa_nome'] . '%';
}

if (!empty($_GET['utilizador'])) {
    $query .= " AND utilizador = ?";
    $params[] = $_GET['utilizador'];
}

if (!empty($_GET['data_inicio'])) {
    $query .= " AND data_criacao >= ?";
    $params[] = $_GET['data_inicio'];
}

if (!empty($_GET['estado_tarefa'])) {
    $query .= " AND estado = ?";
    $params[] = $_GET['estado_tarefa'];
}

$stmt = $ligacao->prepare($query);
$stmt->execute($params);
$resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Construir lista associativa de tarefas para o datalist
$tarefasAssoc = [];
foreach ($resultados as $linha) {
    $nome = $linha['tarefa'];
    $tarefasAssoc[$nome] = $nome;
}


?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Eliminar Tarefas</title>
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
    max-width: 1000px;
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

  input, select {
    padding: 8px;
    border: 1px solid #ccc;
    border-radius: 6px;
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

  .campo-filtro {
    padding: 8px;
    border: 1px solid #ccc;
    border-radius: 6px;
    font-size: 14px;
    width: 220px;
    box-sizing: border-box;
  }

  table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 0 6px rgba(0, 0, 0, 0.1);
  }

  thead {
    background-color: #f3cf53;
  }

  thead th {
    padding: 12px;
    text-align: center;
    color: black;
    font-weight: bold;
    border-bottom: 2px solid #d4af37;
  }

  tbody tr:nth-child(even) {
    background-color: #fafafa;
  }

  tbody td {
    padding: 10px;
    text-align: center;
    border-bottom: 1px solid #ddd;
  }

  .actions {
    display: flex;
    justify-content: space-between;
    margin-bottom: 20px;
  }

  .btn-danger {
    background-color: #a32619ff;
    color: white;
    border: none;
    padding: 6px 12px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: bold;
    transition: background-color 0.3s;
  }

  .btn-danger:hover {
    background-color: #821c14;
  }

  .center-button {
    text-align: center;
    margin-top: 20px;
  }

.modal-overlay {
  position: fixed;
  top: 0; left: 0;
  width: 100vw; height: 100vh;
  background: rgba(0,0,0,0.3);
  display: none;
  justify-content: center;
  align-items: center;
  z-index: 9999;
}

.modal-box {
  background: white;
  padding: 25px 30px;
  border-radius: 14px;
  box-shadow: 0 6px 18px rgba(0,0,0,0.25);
  width: 420px;
  max-width: 90%;
  text-align: center;
  position: relative;
}

.fechar-modal {
  position: absolute;
  top: 10px;
  right: 15px;
  background: none;
  border: none;
  font-size: 20px;
  cursor: pointer;
}

</style>
</head>
<body>
<img id="logoPDF" src="../logo_portos.jpg" style="display: none;" />
<div class="container">
  <h1>Eliminar Tarefas</h1>

  <?php if (!empty($erroUtilizador)): ?>
  <p style="color: red; font-weight: bold;"><?= htmlspecialchars($erroUtilizador) ?></p>
<?php endif; ?>

  <div class="actions">
    <button style="margin-right: 10px;" onclick="window.location.href='painel.php'">← Voltar ao Painel</button>
    <button onclick="window.location.href='tarefas_eliminadas.php'">🗑️ Ver Tarefas Eliminadas</button>
  </div>

  <form method="get">
    <div>
      <label for="tarefa">Tarefa:</label><br>
      <input class="campo-filtro" list="listaTarefas" name="tarefa_nome" id="tarefa" value="<?= htmlspecialchars($tarefaSelecionadaNome ?? '') ?>" placeholder="Digite ou selecione a tarefa">
      <datalist id="listaTarefas">
        <?php foreach ($tarefasAssoc as $nome): ?>
            <option value="<?= htmlspecialchars($nome) ?>"></option>
        <?php endforeach; ?>
      </datalist>
    </div>
    <div>
      <label for="utilizador">Utilizador:</label><br>
      <input class="campo-filtro" list="listaUtilizadores" name="utilizador" id="utilizador"
        value="<?= htmlspecialchars($utilizadorSelecionado ?? '') ?>" 
        placeholder="Digite ou selecione o utilizador">
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
      <label for="estado_tarefa">Estado da Tarefa:</label><br>
      <select name="estado_tarefa" id="estado_tarefa" class="campo-filtro">
        <option value="">Todas</option>
        <option value="pendente" <?= isset($_GET['estado_tarefa']) && $_GET['estado_tarefa'] === 'pendente' ? 'selected' : '' ?>>Pendente</option>
        <option value="concluida" <?= isset($_GET['estado_tarefa']) && $_GET['estado_tarefa'] === 'concluida' ? 'selected' : '' ?>>Concluída</option>
      </select>
    </div>
    <div>
      <button type="submit">Pesquisar</button>
    </div>
  </form>

<?php if (!empty($resultados)): ?>
  <form method="POST" action="eliminar.php" id="formEliminarSelecionadas">
    <input type="hidden" name="acao" value="eliminar">
    <table id="tabelaRelatorio">
      <thead>
        <tr>
            <th></th>
          <th>Data</th>
          <th>Utilizador</th>
          <th>Tarefa</th>
          <th>Estado</th>
          <th>Descrição</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($resultados as $linha): ?>
          <tr>
            <td>
              <input type="checkbox" name="tarefas_selecionadas[]" value="<?= htmlspecialchars($linha['id']) ?>">
            </td>
            <td><?= htmlspecialchars($linha['data_criacao']) ?></td>
            <td><?= htmlspecialchars($linha['utilizador']) ?></td>
            <td><?= htmlspecialchars($linha['tarefa']) ?></td>
            <td><?= htmlspecialchars($linha['estado']) ?></td>
            <td><?= htmlspecialchars($linha['descricao']) ?></td>
            <td style="text-align: center;">
              <button type="button" class="btn-danger" onclick="abrirModalEliminarUnica(<?= $linha['id'] ?>)">Eliminar</button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div style="display: flex; justify-content: center; margin-top: 30px;">
      <button type="button" class="btn-danger" onclick="abrirConfirmarEliminacaoSelecionadas()">Eliminar Selecionadas</button>
    </div>

  </form>
<?php elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET) && count($_GET) > 0): ?>
  <p style="font-weight: bold; color: #a32619ff;">❗ Não existem tarefas a serem eliminadas com os filtros aplicados.</p>
<?php else: ?>
  <p style="font-weight: bold; color: #666;">Nenhuma tarefa foi carregada. Utilize os filtros acima para pesquisar.</p>
<?php endif; ?>
</div>
<div id="modalConfirmarEliminacaoSelecionadas" class="modal-overlay" style="display:none;">
  <div class="modal-box">
    <button class="fechar-modal" onclick="fecharModalEliminacaoSelecionadas()">×</button>
    <p style="font-weight: bold;">Deseja eliminar as tarefas selecionadas?</p>
    <div style="margin-top: 20px; display: flex; justify-content: space-between;">
      <button class="botao-modal" style="background:#a32619ff; color:white;" onclick="confirmarEliminacaoSelecionadas()">Sim</button>
      <button class="botao-modal" onclick="fecharModalEliminacaoSelecionadas()">Não</button>
    </div>
  </div>
</div>
<div id="modalConfirmarEliminacaoUnica" class="modal-overlay" style="display:none;">
  <div class="modal-box">
    <button class="fechar-modal" onclick="fecharModalEliminacaoUnica()">×</button>
    <p style="font-weight: bold;">Deseja eliminar esta tarefa?</p>
    <div style="margin-top: 20px; display: flex; justify-content: space-between;">
      <button class="botao-modal" style="background:#a32619ff; color:white;" onclick="confirmarEliminacaoUnica()">Sim</button>
      <button class="botao-modal" onclick="fecharModalEliminacaoUnica()">Não</button>
    </div>
  </div>
</div>


<div id="notificacao" style="display:none; position:fixed; top:20px; right:20px; background:#f8d7da; color:#721c24; padding:12px 20px; border-left:5px solid #dc3545; border-radius:5px; z-index:10000;"></div>

<script>

  let idParaEliminar = null;

  function abrirModalEliminarUnica(id) {
    idParaEliminar = id;
    document.getElementById("modalConfirmarEliminacaoUnica").style.display = "flex";
  }

  function fecharModalEliminacaoUnica() {
    document.getElementById("modalConfirmarEliminacaoUnica").style.display = "none";
    idParaEliminar = null;
  }

  function confirmarEliminacaoUnica() {
    if (idParaEliminar !== null) {
      document.getElementById('idTarefaUnica').value = idParaEliminar;
      document.getElementById('formEliminarUnica').submit();
    }
  }

  window.addEventListener("click", function(e) {
    const modal1 = document.getElementById("modalConfirmarEliminacaoSelecionadas");
    const modal2 = document.getElementById("modalConfirmarEliminacaoUnica");
    if (e.target === modal1) fecharModalEliminacaoSelecionadas();
    if (e.target === modal2) fecharModalEliminacaoUnica();
  });



  function mostrarNotificacao(msg, tipo = 'erro') {
    const noti = document.getElementById('notificacao');
    noti.textContent = msg;
    noti.style.display = 'block';
    noti.style.opacity = '1';
    setTimeout(() => {
      noti.style.transition = 'opacity 0.5s';
      noti.style.opacity = '0';
      setTimeout(() => {
        noti.style.display = 'none';
        noti.style.transition = '';
      }, 500);
    }, 3000);
  }
</script>


<script>
  function abrirConfirmarEliminacaoSelecionadas() {
    const checkboxes = document.querySelectorAll('input[name="tarefas_selecionadas[]"]:checked');
    if (checkboxes.length === 0) {
      mostrarNotificacao("Nenhuma tarefa foi selecionada.", "erro");
      return;
    }

    document.getElementById("modalConfirmarEliminacaoSelecionadas").style.display = "flex";
  }

  function fecharModalEliminacaoSelecionadas() {
    document.getElementById("modalConfirmarEliminacaoSelecionadas").style.display = "none";
  }

  function confirmarEliminacaoSelecionadas() {
    fecharModalEliminacaoSelecionadas();
    document.getElementById("formEliminarSelecionadas").submit();
  }

  // Fechar ao clicar fora do modal
  window.addEventListener("click", function(e) {
    const modal = document.getElementById("modalConfirmarEliminacaoSelecionadas");
    if (e.target === modal) fecharModalEliminacaoSelecionadas();
  });
</script>

<form id="formEliminarUnica" method="POST" action="eliminar_tarefa.php" style="display:none;">
  <input type="hidden" name="id" id="idTarefaUnica">
</form>

</body>
</html>