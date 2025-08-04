<?php
session_start();
$erro = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $utilizador = $_POST["utilizador"];
    $password = $_POST["password"];

    $ficheiro_password = "../admin_password.txt";

    if (file_exists($ficheiro_password)) {
        $password_correta = trim(file_get_contents($ficheiro_password));
    } else {
        $password_correta = "123456";
    }

    if ($utilizador === "Administrador" && $password === $password_correta) {
        $_SESSION["admin_logado"] = true;
        header("Location: painel.php");
        exit();
    } else {
        $erro = "Utilizador ou password incorretos.";
    }
}
?>


<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Login Administração</title>
    <link rel="stylesheet" href="../style.css">
    <style>
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            background-color: #f8d7da;
            color: #721c24;
            padding: 15px 20px;
            border-left: 5px solid #dc3545;
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
            color: inherit;
            font-size: 20px;
            line-height: 20px;
            cursor: pointer;
        }

        .container {
            padding-top: 100px;
            text-align: center;
        }

        input {
            font-size: 1.2em;
            padding: 10px;
            width: 250px;
            margin: 5px 0;
        }

        .botao {
            margin-top: 20px;
            padding: 10px 30px;
            background-color: #d4af37;
            border: none;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
            text-decoration: none;
        }

        .botao:hover {
            background-color: #c49b2e;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Administração</h1>

        <?php if (!empty($erro)): ?>
            <div class="toast" id="toast">
                <span><?= htmlspecialchars($erro) ?></span>
                <button class="fechar" onclick="document.getElementById('toast').style.display='none'">×</button>
            </div>
        <?php endif; ?>

        <form method="post">
            <input type="text" name="utilizador" placeholder="Utilizador" required><br><br>
            <input type="password" name="password" placeholder="Password" required><br><br>
            <button type="submit" class="botao">Entrar</button>
        </form>
        <br>
        <a href="../index.php" class="botao">Voltar</a>
    </div>

    <script>
        // Esconde o toast após 5 segundos
        setTimeout(() => {
            const toast = document.getElementById('toast');
            if (toast) toast.style.display = 'none';
        }, 5000);
    </script>
</body>
</html>
