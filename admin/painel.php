
<?php
session_start();
if (!isset($_SESSION["admin_logado"])) {
    header("Location: login.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Painel de Administração</title>
    <link rel="stylesheet" href="../style.css">
    <style>
        .container {
            padding-top: 10px;
        }

        h1 {
            margin-top: 65px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Administração</h1>
        <a href="config_email.php" class="botao">Configuração Email</a>
        <a href="departamentos.php" class="botao">Departamentos</a>
        <a href="funcionarios.php" class="botao">Funcionários</a>
        <a href="horarios.php" class="botao">Horários</a>
        <a href="motivos_pausa.php" class="botao">Motivos de Pausa</a>
        <a href="motivos_ausencia.php" class="botao">Motivos de Ausência</a>
        <a href="feriados.php" class="botao">Feriados</a>
        <a href="eliminar_tarefas.php" class="botao" style="background-color: #a32619ff">Eliminar Tarefas</a>
        <br><br>
        <a href="../index.php" class="botao">Voltar</a>
    </div>
</body>
</html>