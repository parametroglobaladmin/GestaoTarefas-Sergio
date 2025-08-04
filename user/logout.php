<?php
session_start();

// Apenas destrói a sessão sem tocar na base de dados
$_SESSION = [];
session_destroy();

// Redireciona para a página inicial (index)
header("Location: ../index.php");
exit();
?>