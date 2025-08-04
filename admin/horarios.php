<?php
include("../config_bd.php");

// Variável de mensagem
$mensagem = '';

// Eliminar horário
if (isset($_GET['eliminar'])) {
    $id = $_GET['eliminar'];
    $stmt = $ligacao->prepare("DELETE FROM horarios WHERE id = ?");
    $stmt->execute([$id]);
    $mensagem = "Horário eliminado com sucesso!"; // <-- aqui
}

// Criar ou editar
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["nome"], $_POST["hora_inicio"], $_POST["tempo_notificacao"])) {
    $nome = $_POST["nome"];
    $hora_inicio = $_POST["hora_inicio"];
    $tempo_notificacao = $_POST["tempo_notificacao"];
    $dias_semana = isset($_POST["dias_semana"]) 
        ? implode(",", array_map('trim', $_POST["dias_semana"])) 
        : "";

    if (!empty($_POST["id_horario"])) {
        // Editar
        $id = $_POST["id_horario"];
        $stmt = $ligacao->prepare("UPDATE horarios SET nome = ?, hora_inicio = ?, tempo_notificacao = ?, dias_semana = ? WHERE id = ?");
        $stmt->execute([$nome, $hora_inicio, $tempo_notificacao, $dias_semana, $id]);
    } else {
        // Criar
        $stmt = $ligacao->prepare("INSERT INTO horarios (nome, hora_inicio, tempo_notificacao, dias_semana) VALUES (?, ?, ?, ?)");
        $stmt->execute([$nome, $hora_inicio, $tempo_notificacao, $dias_semana]);
        $mensagem = "Horário criado com sucesso!";
    }
}

// Buscar horário para edição
$horario_editar = null;
if (isset($_GET["editar"])) {
    $id = $_GET["editar"];
    $stmt = $ligacao->prepare("SELECT * FROM horarios WHERE id = ?");
    $stmt->execute([$id]);
    $horario_editar = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Buscar todos
$todos_horarios = $ligacao->query("SELECT * FROM horarios ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>


<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Gestão de Horários</title>
    <link rel="stylesheet" href="../style.css">
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
            color: #212529;
            position: relative;
            min-height: 100vh;
            overflow-y: auto;
            overflow-x: hidden;
        }

        body::before {
            content: "";
            position: absolute;
            top: 50%;
            left: 50%;
            width: 80%;
            height: 80%;
            background-image: url('../logo_portos.jpg'); /* <-- Ajuste aqui se necessário */
            background-repeat: no-repeat;
            background-position: center;
            background-size: contain;
            opacity: 0.05;
            transform: translate(-50%, -50%);
            z-index: 0;
        }

        .container {
            position: relative;
            z-index: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding-top: 15vh;
        }

        h1 {
            font-size: 2.5em;
            margin-bottom: 50px;
        }

        .botao {
            background-color: #d4af37;
            color: black;
            font-weight: 500;
            border: none;
            border-radius: 10px;
            padding: 15px 40px;
            width: 250px;
            margin: 10px 5px 10px 0;
            font-size: 1.1em;
            cursor: pointer;
            text-decoration: none;
            text-align: center;
            box-shadow: 0px 2px 5px rgba(0,0,0,0.2);
            transition: background-color 0.3s ease;
        }

        .botao:hover {
            background-color: #c19b2a;
        }

        .campo {
            font-size: 1.2em;
            padding: 10px;
            width: 300px;
            border: 1px solid #ccc;
            border-radius: 5px;
            margin-bottom: 10px;
        }

        table {
            width: auto;
            border-collapse: collapse;
            table-layout: auto;
        }

        th, td {
            padding: 10px;
            border-bottom: 1px solid #ccc;
            text-align: center;
        }

        th {
            background-color: #d4af37;
            color: black;
        }

        label {
            font-weight: bold;
        }

        input[type='checkbox'] {
            margin-right: 5px;
        }

        .dias-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-top: 20px;
        }

        .checkboxes {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 10px;
        }

        .mensagem-sucesso {
            color: green;
            font-weight: bold;
            text-align: center;
            margin-top: 15px;
            font-size: 1.1em;
        }

        .table-wrapper {
            display: flex;
            justify-content: flex-start;
            overflow-x: hidden;
            max-width: 950px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Gestão de Horários</h1>

        <form method="post">
            <input type="hidden" name="id_horario" value="<?php echo $horario_editar ? $horario_editar['id'] : ''; ?>">
            <input class="campo" type="text" name="nome" placeholder="Nome do Horário" value="<?php echo $horario_editar ? $horario_editar['nome'] : ''; ?>" required>
            <input class="campo" type="time" name="hora_inicio" value="<?php echo $horario_editar ? $horario_editar['hora_inicio'] : ''; ?>" required>
            <input class="campo" type="number" name="tempo_notificacao" placeholder="Minutos para notificação" value="<?php echo $horario_editar ? $horario_editar['tempo_notificacao'] : ''; ?>" required>

            <div class="dias-container">
                <label style="margin-bottom: 10px;">Dias da Semana:</label>
                <div class="checkboxes">
                    <?php
                    $dias = ['Segunda','Terca','Quarta','Quinta','Sexta','Sabado','Domingo'];
                    $dias_selecionados = $horario_editar ? explode(",", str_replace(" ", "", $horario_editar["dias_semana"])) : [];
                    foreach ($dias as $dia) {
                        $checked = in_array($dia, $dias_selecionados) ? 'checked' : '';
                        echo "<label><input type='checkbox' name='dias_semana[]' value='$dia' $checked> $dia</label> ";
                    }
                    ?>
                </div>
            </div>

            <div style="display: flex; justify-content: center;">
                <button class="botao" type="submit"><?php echo $horario_editar ? "Guardar Alterações" : "Criar Horário"; ?></button>
            </div>

            <?php if (!empty($mensagem)): ?>
                <div class="mensagem-sucesso"><?php echo $mensagem; ?></div>
            <?php endif; ?>
        </form>

        <h2>Horários Existentes</h2>
        <table>
            <tr>
                <th>Nome</th>
                <th>Hora Início</th>
                <th>Tempo Notificação</th>
                <th>Dias da Semana</th>
                <th>Ações</th>
            </tr>
            <?php if (count($todos_horarios) > 0): ?>
                <?php foreach ($todos_horarios as $linha): ?>
                    <tr>
                        <td><?php echo $linha["nome"]; ?></td>
                        <td><?php echo $linha["hora_inicio"]; ?></td>
                        <td><?php echo $linha["tempo_notificacao"] ?? "-"; ?></td>
                        <td><?php echo $linha["dias_semana"]; ?></td>
                        <td>
                            <a href="?editar=<?php echo $linha['id']; ?>" class="botao" style="padding:6px 14px; font-size:0.9em;">Editar</a>
                            <a href="?eliminar=<?php echo $linha['id']; ?>" class="botao" style="padding:6px 14px; font-size:0.9em;" onclick="return confirm('Eliminar este horário?');">Eliminar</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="5">Nenhum horário registado</td></tr>
            <?php endif; ?>
        </table>

        <br>
        <a href="painel.php"><button class="botao">Voltar</button></a>
    </div>
</body>
</html>
