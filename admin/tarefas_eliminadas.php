<?php
require_once '../config_bd.php';
session_start();
if (!isset($_SESSION["admin_logado"])) {
    header("Location: login.php");
    exit();
}

$query = "SELECT * FROM tarefas WHERE estado = 'eliminada' ORDER BY data_criacao DESC";
$stmt = $ligacao->prepare($query);
$stmt->execute();
$tarefasEliminadas = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <title>Tarefas Eliminadas</title>
  <link rel="stylesheet" href="../style.css">
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
      color: #a32619ff;
    }

    .actions {
      display: flex;
      justify-content: space-between;
      margin-bottom: 20px;
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

    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 20px;
      border-radius: 10px;
      overflow: hidden;
      box-shadow: 0 0 6px rgba(0, 0, 0, 0.1);
    }

    thead {
      background-color: #fa3c2aff;
    }

    thead th {
      padding: 12px;
      text-align: center;
      color: black;
      font-weight: bold;
      border-bottom: 2px solid #f25138ff;
    }

    tbody tr:nth-child(even) {
      background-color: #fafafa;
    }

    tbody td {
      padding: 10px;
      text-align: center;
      border-bottom: 1px solid #ddd;
    }

    .sem-registos {
      font-weight: bold;
      color: #a32619ff;
      margin-top: 30px;
      text-align: center;
    }
  </style>
</head>
<body>
<div class="container">
  <h1>🗑️ Tarefas Eliminadas</h1>

  <div class="actions">
    <button onclick="window.location.href='eliminar_tarefas.php'">← Voltar às Tarefas Ativas</button>
  </div>

  <?php if (!empty($tarefasEliminadas)): ?>
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Data</th>
          <th>Utilizador</th>
          <th>Tarefa</th>
          <th>Descrição</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($tarefasEliminadas as $t): ?>
          <tr>
            <td><?= htmlspecialchars($t['id']) ?></td>
            <td><?= htmlspecialchars($t['data_criacao']) ?></td>
            <td><?= htmlspecialchars($t['utilizador']) ?></td>
            <td><?= htmlspecialchars($t['tarefa']) ?></td>
            <td><?= htmlspecialchars($t['descricao']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p class="sem-registos">❗ Nenhuma tarefa foi eliminada até ao momento.</p>
  <?php endif; ?>
</div>
</body>
</html>
