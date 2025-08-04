<?php
session_start();

// Termina login de utilizador (funcionário)
if (isset($_SESSION['utilizador_logado'])) {
    unset($_SESSION['utilizador_logado']);
    unset($_SESSION['nome_logado']); // remove nome do funcionário se existir
}

// Termina login de administrador
if (isset($_SESSION['admin_logado'])) {
    unset($_SESSION['admin_logado']);
    unset($_SESSION['nome_admin']); // remove nome do admin se existir
}

// Opcional: também podes limpar mensagens temporárias
if (isset($_SESSION['mensagem_sucesso'])) {
    unset($_SESSION['mensagem_sucesso']);
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Gestão de Tarefas</title>
    <link rel="stylesheet" href="style.css">
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
        <h1>Gestão de Tarefas</h1>
        <a href="user/login.php" class="botao">Login</a>
        <a href="admin/login.php" class="botao">Administração</a>
    </div>
</body>
</html>
