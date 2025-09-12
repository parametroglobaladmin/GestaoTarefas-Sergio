<?php
session_start();
if (!isset($_SESSION["admin_logado"])) {
    header("Location: login.php");
    exit();
}

require_once "../config_bd.php";

$mensagem = "";
$erro = "";
$motivo_editar = null;

// Editar
if (isset($_GET["editar"])) {
    $id = (int) $_GET["editar"];
    try {
        $stmt = $ligacao->prepare("SELECT * FROM motivos_pausa WHERE id = ?");
        $stmt->execute([$id]);
        $motivo_editar = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $erro = "Erro ao buscar motivo para edição: " . $e->getMessage();
    }
}

// Atualizar
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["atualizar"])) {
    $id = (int) $_POST["editar_id"];
    $codigo = trim($_POST["codigo"]);
    $descricao = trim($_POST["descricao"]);
    $tipo = trim($_POST["tipo"]);

    if ($codigo && $descricao && $tipo) {
        try {
            $stmt = $ligacao->prepare("UPDATE motivos_pausa SET codigo = ?, descricao = ?, tipo = ? WHERE id = ?");
            $stmt->execute([$codigo, $descricao, $tipo, $id]);
            $mensagem = "Motivo atualizado com sucesso!";
        } catch (PDOException $e) {
            $erro = "Erro ao atualizar: " . $e->getMessage();
        }
    } else {
        $erro = "Preencha todos os campos obrigatórios.";
    }
}

// Eliminar
if (isset($_GET["eliminar"])) {
    $id = (int) $_GET["eliminar"];

    try {
        // Verifica se o motivo está a ser usado em pausas
        $stmtCheck = $ligacao->prepare("SELECT COUNT(*) FROM pausas_tarefas WHERE motivo_id = ?");
        $stmtCheck->execute([$id]);
        $usado = $stmtCheck->fetchColumn();

        if ($usado > 0) {
            $erro = "Não é possível eliminar o motivo pois está associado a pausas existentes.";
        } else {
            $stmt = $ligacao->prepare("DELETE FROM motivos_pausa WHERE id = ?");
            $stmt->execute([$id]);
            $mensagem = "Motivo eliminado com sucesso!";
        }

    } catch (PDOException $e) {
        $erro = "Erro ao eliminar: " . $e->getMessage();
    }
}


// Criar
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["criar"])) {
    $codigo = trim($_POST["codigo"]);
    $descricao = trim($_POST["descricao"]);
    $tipo=trim($_POST["tipo"]);

    if ($codigo && $descricao && $tipo) {
        try {
            $stmt = $ligacao->prepare("INSERT INTO motivos_pausa (codigo, descricao, tipo) VALUES (?, ?, ?)");
            $stmt->execute([$codigo, $descricao, $tipo]);
            $mensagem = "Motivo criado com sucesso!";
        } catch (PDOException $e) {
            $erro = "Erro ao criar: " . $e->getMessage();
        }
    } else {
        $erro = "Preencha todos os campos obrigatórios.";
    }
}

// Buscar motivos
$motivos = [];
try {
    $stmt = $ligacao->query("SELECT * FROM motivos_pausa ORDER BY codigo ASC");
    $motivos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $erro = "Erro ao buscar motivos: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Gestão de Motivos de Pausa</title>
    <link rel="stylesheet" href="../style.css">
    <style>
        .top-buttons {
            display: flex;
            justify-content: center;
            gap: 30px;
            margin: 30px 0;
        }
        .modal-overlay {
            position: fixed;
            top: 0; left: 0;
            width: 100vw; height: 100vh;
            background: rgba(0, 0, 0, 0.4);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 3000;
        }
        .modal-box {
            background-color: #ffffff;
            border-radius: 20px;
            padding: 30px;
            width: 350px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 15px;
            animation: fadeInZoom 0.3s ease-out;
        }
        .fechar-modal {
            position: absolute;
            top: 10px;
            right: 15px;
            font-size: 20px;
            background: none;
            border: none;
            cursor: pointer;
        }
        .botao-modal {
            background-color: #d4aa2f;
            color: black;
            font-weight: bold;
            border: none;
            border-radius: 8px;
            padding: 10px;
            font-size: 14px;
            cursor: pointer;
        }
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            padding: 15px 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            font-size: 14px;
            z-index: 9999;
            animation: slideIn 0.5s ease, fadeOut 0.5s ease 3.5s forwards;
        }
        .toast.erro {
            background-color: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }
        @keyframes fadeInZoom {
            0% { opacity: 0; transform: scale(0.9); }
            100% { opacity: 1; transform: scale(1); }
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        @keyframes fadeOut {
            to { opacity: 0; transform: translateX(20px); }
        }
        .tabela {
            margin: 0 auto;
            border-collapse: collapse;
            background: #fff;
            width: fit-content;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            overflow: hidden;
            font-size: 16px;
            text-align: left;
        }
        .tabela thead {
            background-color: #d4af37;
            color: black;
        }
        .tabela th, .tabela td {
            padding: 12px 18px;
            border-bottom: 1px solid #ccc;
        }
        .tabela td:last-child {
            white-space: nowrap;
        }
        .tabela tr:last-child td {
            border-bottom: none;
        }
        .botao {
            background-color: #d4aa2f;
            color: black;
            font-weight: bold;
            border: none;
            border-radius: 8px;
            padding: 12px;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.2s ease-in-out;
        }

        .modal-box input,
        .modal-box textarea {
            background-color: #f0f0f0;
            border: 1px solid #444;
            border-radius: 8px;
            padding: 10px;
            font-size: 15px;
            width: 100%;
            box-sizing: border-box;
        }

    </style>
</head>

<div id="notificacao" style="
    display: none;
    position: fixed;
    top: 20px;
    right: 20px;
    background-color: #d4edda;
    color: #155724;
    padding: 12px 18px;
    border-radius: 5px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.2);
    z-index: 9999;
    align-items: center;
    font-size: 14px;
    border-left: 5px solid #28a745;
">
  <span id="notificacao-texto">Mensagem</span>
</div>
<body>
<div class="container">
    <?php if (!empty($mensagem)): ?>
        <div class="toast"><?= $mensagem ?></div>
    <?php elseif (!empty($erro)): ?>
        <div class="toast erro"><?= $erro ?></div>
    <?php endif; ?>

    <h1>Gestão de Motivos de Pausa</h1>

    <div class="top-buttons">
        <button onclick="abrirModalNovoMotivo()" class="botao" style="width: 230px;">Novo Motivo</button>
        <a href="painel.php" class="botao" style="width: 230px;">Voltar</a>
    </div>

    <h2>Motivos Existentes</h2>
    <table class="tabela">
        <thead>
        <tr>
            <th>Código</th>
            <th>Descrição</th>
            <th>Tipo</th>
            <th>Ações</th>
            <th>Conta como Pausa para Estatistica?</th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($motivos)): ?>
            <tr><td colspan="3">Nenhum motivo registado.</td></tr>
        <?php else: ?>
            <?php foreach ($motivos as $motivo): ?>
                <tr>
                    <td><?= htmlspecialchars($motivo['codigo']) ?></td>
                    <td><?= htmlspecialchars($motivo['descricao']) ?></td>
                    <td><?= htmlspecialchars($motivo['tipo']) ?></td>
                    <td>
                        <a href="#" class="botao" style="padding:6px 14px; font-size:0.9em;"
                        onclick="abrirModalEditar('<?= $motivo['id'] ?>', '<?= htmlspecialchars($motivo['codigo'], ENT_QUOTES) ?>', '<?= htmlspecialchars($motivo['descricao'], ENT_QUOTES) ?>')">Editar</a>

                        <a href="#" class="botao" style="background:#c0392b; color:white; padding:6px 14px; font-size:0.9em;"
                        onclick="abrirModalEliminar(<?= $motivo['id'] ?>)">Eliminar</a>
                    </td>
                    <td style="text-align:center;">
                        <input 
                            type="checkbox" 
                            <?php echo (isset($motivo['estatistica']) && $motivo['estatistica'] === 'ativo' ? 'checked' : ''); ?>
                            onchange="atualizarEstadoPausa(this, '<?php echo $motivo['codigo']; ?>')"
                        >
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Modal Novo -->
<div class="modal-overlay" id="modalNovoMotivoOverlay">
    <div class="modal-box">
        <button class="fechar-modal" onclick="fecharModalNovoMotivo()">×</button>
        <form method="POST" action="">
            <input type="hidden" name="criar" value="1" />
            <label for="novo_codigo">Código:</label>
            <input type="text" id="novo_codigo" name="codigo" required />
            <label for="novo_descricao">Descrição:</label>
            <textarea id="novo_descricao" name="descricao" rows="4" required></textarea>
            <label for="novo_tipo">Tipo de Pausa:</label>
            <select id="novo_tipo" name="tipo" required>
                <option value="SemOpcao">Sem opção</option>
                <option value="PararContadores"> Parar todos os contadores</option>
                <option value="IniciarTarefas">Permite Iniciar Nova Tarefa</option>
            </select>

            <button type="submit" class="botao-modal" style="margin-top: 10px;">Criar Motivo</button>
        </form>
    </div>
</div>

<!-- Modal Editar -->
<div class="modal-overlay" id="modalEditarOverlay">
    <div class="modal-box">
        <button class="fechar-modal" onclick="fecharModalEditar()">×</button>
        <form method="POST" action="">
            <input type="hidden" name="atualizar" value="1" />
            <input type="hidden" name="editar_id" id="editar_id" />
            <label for="editar_codigo">Código:</label>
            <input type="text" id="editar_codigo" name="codigo" required />
            <label for="editar_descricao">Descrição:</label>
            <textarea id="editar_descricao" name="descricao" rows="4" required></textarea>
            <label for="editar_tipo">Tipo de Pausa:</label>
            <select id="editar_tipo" name="tipo" required>
                <option value="SemOpcao">Sem opção</option>
                <option value="PararContadores"> Parar todos os contadores</option>
                <option value="IniciarTarefas">Permite Iniciar Tarefas</option>
            </select>
            <button type="submit" class="botao-modal">Atualizar Motivo</button>
        </form>
    </div>
</div>

<!-- Modal Eliminar -->
<div class="modal-overlay" id="modalEliminarOverlay">
    <div class="modal-box">
        <button class="fechar-modal" onclick="fecharModalEliminar()">×</button>
        <p>Tem certeza que deseja eliminar este motivo?</p>
        <div style="display: flex; justify-content: space-between; gap: 15px;">
            <a href="#" id="linkEliminar" class="botao-modal" style="background: #c0392b; color: white;">Sim</a>
            <button onclick="fecharModalEliminar()" class="botao-modal" style="background: #ccc;">Não</button>
        </div>
    </div>
</div>

<script>
    function abrirModalNovoMotivo() {
        document.getElementById('modalNovoMotivoOverlay').style.display = 'flex';
    }
    function fecharModalNovoMotivo() {
        document.getElementById('modalNovoMotivoOverlay').style.display = 'none';
    }
    function abrirModalEditar(id, codigo, descricao, tipo) {
        document.getElementById("editar_id").value = id;
        document.getElementById("editar_codigo").value = codigo;
        document.getElementById("editar_descricao").value = descricao;
        document.getElementById("editar_tipo").value = tipo;
        document.getElementById("modalEditarOverlay").style.display = 'flex';
    }
    function fecharModalEditar() {
        document.getElementById("modalEditarOverlay").style.display = 'none';
    }
    function abrirModalEliminar(id) {
        document.getElementById("linkEliminar").href = "?eliminar=" + id;
        document.getElementById("modalEliminarOverlay").style.display = 'flex';
    }
    function fecharModalEliminar() {
        document.getElementById("modalEliminarOverlay").style.display = 'none';
    }
    window.addEventListener("click", function(e) {
        ['modalNovoMotivoOverlay', 'modalEditarOverlay', 'modalEliminarOverlay'].forEach(id => {
            if (e.target === document.getElementById(id)) {
                document.getElementById(id).style.display = 'none';
            }
        });
    });
</script>
<script>
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

    function atualizarEstadoPausa(checkbox, codigo) {
        const novoEstado = checkbox.checked ? 1 : 0;

        fetch('atualizar_estado_pausa.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `codigo=${encodeURIComponent(codigo)}&estatistica=${novoEstado}`
        })
        .then(res => res.text())
        .then(res => {
            if (res.includes('sucesso')) {
                mostrarNotificacao("Estado atualizado com sucesso.", 'sucesso');
            } else {
                mostrarNotificacao("Erro ao atualizar estado.", 'erro');
                checkbox.checked = !checkbox.checked; // Reverter estado visual
            }
        })
        .catch(() => {
            mostrarNotificacao("Erro ao comunicar com o servidor.", 'erro');
            checkbox.checked = !checkbox.checked; // Reverter
        });
    }
</script>
</body>
</html>
