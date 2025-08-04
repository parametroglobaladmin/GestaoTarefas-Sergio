<?php
require_once '../config_bd.php';
session_start();

// Verifica se o funcionário está autenticado
if (!isset($_SESSION["utilizador_logado"])) {
    header("Location: ../index.php");
    exit();
}

$utilizador=$_SESSION["utilizador_logado"];

$stmt = $ligacao->prepare("SELECT relatorio_acesso FROM funcionarios WHERE utilizador = ?");
$stmt->execute([$utilizador]);
$relatorioAcesso = $stmt->fetchColumn();
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Painel de Administração</title>
    <link rel="stylesheet" href="../style.css">
    <style>
        .container {
            padding-top: 100px;
        }

        h1 {
            margin-top: 65px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Olá, <?php echo htmlspecialchars($_SESSION["nome_logado"]); ?> 👋</h1>
        <a href="tarefas.php" class="botao">Tarefas</a>
        <a href="ausencias.php" class="botao">Ausências</a>
        <a href="relatorios.php" class="botao">Relatórios</a>
        <a href="calendario.php" class="botao">Calendário</a>
        <?php if ($relatorioAcesso === 'todos'): ?>
            <a href="analisar_dados.php?utilizador=<?= urlencode($utilizador) ?>" class="botao">Analisar Dados</a>
        <?php endif; ?>
        <br><br>
        <a href="logout.php" class="botao">Voltar</a>
    </div>
</body>
</html>