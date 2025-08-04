<?php
require_once '../config_bd.php';

// Captura de mensagens para exibição com notificação visual
$mensagem = $_GET['mensagem'] ?? null;
$tipo_mensagem = $_GET['tipo'] ?? 'sucesso';

try {
    $stmt = $ligacao->query("SELECT f.*, h.nome AS horario_nome FROM funcionarios f LEFT JOIN horarios h ON f.horario_id = h.id");
    $funcionarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $funcionarios = [];
}

if (isset($_POST['confirmar_eliminar']) && !empty($_POST['utilizador_a_eliminar'])) {
    $utilizador = $_POST['utilizador_a_eliminar'];
    try {
        $ligacao->beginTransaction();

        $stmtTarefas = $ligacao->prepare("SELECT id FROM tarefas WHERE utilizador = ?");
        $stmtTarefas->execute([$utilizador]);
        $tarefas = $stmtTarefas->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($tarefas)) {
            $inQuery = implode(',', array_fill(0, count($tarefas), '?'));
            $stmt = $ligacao->prepare("DELETE FROM pausas_tarefas WHERE tarefa_id IN ($inQuery)");
            $stmt->execute($tarefas);
        }

        $stmt = $ligacao->prepare("DELETE FROM pausas_tarefas WHERE funcionario = ?");
        $stmt->execute([$utilizador]);

        $tabelas = [
            "ausencia_funcionarios" => "funcionario_utilizador",
            "tarefas" => "utilizador",
            "registo_diario" => "utilizador",
            "avisos_inatividade" => "utilizador"
        ];

        foreach ($tabelas as $tabela => $campo) {
            $stmt = $ligacao->prepare("DELETE FROM $tabela WHERE $campo = ?");
            $stmt->execute([$utilizador]);
        }

        $stmt = $ligacao->prepare("DELETE FROM funcionarios WHERE utilizador = ?");
        $stmt->execute([$utilizador]);

        $ligacao->commit();

        header("Location: funcionarios.php?mensagem=" . urlencode("Funcionário eliminado com sucesso.") . "&tipo=sucesso");
        exit;
    } catch (Exception $e) {
        $ligacao->rollBack();
        header("Location: funcionarios.php?mensagem=" . urlencode("Erro ao eliminar funcionário: " . $e->getMessage()) . "&tipo=erro");
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['confirmar_eliminar'])) {
    $numero = $_POST['numero'] ?? '';
    $nome = $_POST['nome'] ?? '';
    $email = $_POST['email'] ?? '';
    $horario_id = $_POST['horario'] ?? null;
    $departamento = $_POST['departamento'] ?? null;
    $relatorio = $_POST['relatorio'] ?? 'utilizador';
    $utilizador = $_POST['utilizador'] ?? '';
    $password = $_POST['password'] ?? '';
    $editar = $_POST['editar_utilizador'] ?? '';

    if (!empty($editar)) {
        // Verificar se o novo utilizador já existe (diferente do antigo)
        if ($utilizador !== $editar) {
            $stmtCheck = $ligacao->prepare("SELECT COUNT(*) FROM funcionarios WHERE utilizador = ?");
            $stmtCheck->execute([$utilizador]);
            if ($stmtCheck->fetchColumn() > 0) {
                header("Location: funcionarios.php?mensagem=" . urlencode("Já existe um funcionário com esse nome de utilizador.") . "&tipo=erro");
                exit;
            }
        }

        // Verificar duplicação de número (exceto o atual)
        $stmtCheckNumero = $ligacao->prepare("SELECT COUNT(*) FROM funcionarios WHERE numero = ? AND utilizador != ?");
        $stmtCheckNumero->execute([$numero, $editar]);
        if ($stmtCheckNumero->fetchColumn() > 0) {
            header("Location: funcionarios.php?mensagem=" . urlencode("Já existe um funcionário com esse número.") . "&tipo=erro");
            exit;
        }

        // Atualizar funcionário existente
        $sql = "UPDATE funcionarios SET numero = ?, nome = ?, email = ?, horario_id = ?,departamento=?, relatorio_acesso = ?, utilizador = ?" .
               (!empty($password) ? ", password = ?" : "") .
               " WHERE utilizador = ?";
        $stmt = $ligacao->prepare($sql);

        if (!empty($password)) {
            $stmt->execute([
                $numero,
                $nome,
                $email,
                $horario_id ?: null,
                $departamento ?: null,
                $relatorio,
                $utilizador,
                $password,
                $editar
            ]);
        } else {
            $stmt->execute([
                $numero,
                $nome,
                $email,
                $horario_id ?: null,
                $departamento ?: null,
                $relatorio,
                $utilizador,
                $editar
            ]);
        }

        header("Location: funcionarios.php?mensagem=" . urlencode("Funcionário atualizado com sucesso.") . "&tipo=sucesso");
        exit;
    } else {
        // Verificar duplicação de utilizador
        $stmtCheck = $ligacao->prepare("SELECT COUNT(*) FROM funcionarios WHERE utilizador = ?");
        $stmtCheck->execute([$utilizador]);
        if ($stmtCheck->fetchColumn() > 0) {
            header("Location: funcionarios.php?mensagem=" . urlencode("Já existe um funcionário com esse nome de utilizador.") . "&tipo=erro");
            exit;
        }

        // Verificar duplicação de número
        $stmtCheckNumero = $ligacao->prepare("SELECT COUNT(*) FROM funcionarios WHERE numero = ?");
        $stmtCheckNumero->execute([$numero]);
        if ($stmtCheckNumero->fetchColumn() > 0) {
            header("Location: funcionarios.php?mensagem=" . urlencode("Já existe um funcionário com esse número.") . "&tipo=erro");
            exit;
        }

        $stmt = $ligacao->prepare("INSERT INTO funcionarios (numero, nome, email, horario_id,departamento, relatorio_acesso, utilizador, password) VALUES (?, ?, ?, ?,?, ?, ?, ?)");
        $stmt->execute([
            $numero,
            $nome,
            $email,
            $horario_id ?: null,
            $departamento ?: null,
            $relatorio,
            $utilizador,
            $password
        ]);

        header("Location: funcionarios.php?mensagem=" . urlencode("Funcionário criado com sucesso.") . "&tipo=sucesso");
        exit;
    }
}

try {
    $stmt = $ligacao->query("SELECT id, nome FROM horarios ORDER BY nome");
    $todosHorarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $todosHorarios = [];
}

try {
    $stmt = $ligacao->query("SELECT id, nome FROM departamento ORDER BY nome");
    $todosDepartamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $todosDepartamentos = [];
}
?>



<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Funcionários</title>
    <link rel="stylesheet" href="../style.css">
<style>
    body {
        margin: 0;
        font-family: 'Segoe UI', sans-serif;
        background: #fdfdfd;
        color: #333;
    }

    h1 {
        text-align: center;
        margin-top: 30px;
        font-size: 1.8em;
    }

    h3 {
        margin-top: 40px;
        text-align: center;
        font-size: 1.2em;
    }

    .container {
        max-width: 1000px;
        margin: 0 auto;
        padding: 20px;
    }

    .centered {
        display: flex;
        justify-content: center;
        gap: 16px;
        margin: 24px 0;
    }

    .botao {
        background-color: #d4af37;
        color: black;
        border: none;
        padding: 6px 14px;
        font-size: 0.85em;
        cursor: pointer;
        border-radius: 6px;
        box-shadow: 1px 1px 4px rgba(0, 0, 0, 0.1);
        transition: background-color 0.2s;
    }

    .botao:hover {
        background-color: #c49e2f;
    }

    .botao-danger {
        background-color: darkred;
        color: white;
    }

    .botao-danger:hover {
        background-color: #a30000;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 30px;
        font-size: 0.95em;
    }

    th, td {
        text-align: left;
        padding: 10px;
        border-bottom: 1px solid #eee;
    }

    th {
        background-color: #d4af37;
    }

    td {
        background-color: #fff;
    }

    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.6);
        justify-content: center;
        align-items: center;
        z-index: 10000;
    }

    .modal-content {
        background: white;
        padding: 24px;
        border-radius: 10px;
        width: 100%;
        max-width: 420px;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
        position: relative;
    }

    .modal-content input,
    .modal-content select {
        width: 100%;
        padding: 10px;
        margin: 8px 0;
        font-size: 1em;
        border-radius: 6px;
        border: 1px solid #ccc;
    }

    .modal .close {
        position: absolute;
        top: 10px;
        right: 15px;
        font-size: 1.5em;
        cursor: pointer;
        color: #666;
    }

    .form-pesquisa {
        display: flex;
        justify-content: center;
        gap: 10px;
        margin-top: 10px;
    }

    .form-pesquisa input[type="text"] {
        width: 300px;
        padding: 10px;
        font-size: 1em;
    }

    /* Botões de ações (dentro da tabela) */
    .acoes-botoes {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 6px;
    }

    .acoes-botoes .botao,
    .acoes-botoes .botao-danger {
        padding: 4px 10px;
        font-size: 0.75em;
        min-width: auto;
        width: auto;
        border-radius: 4px;
        white-space: nowrap;
    }
</style>

    <script>
        const horarios = <?php echo json_encode($todosHorarios); ?>;
        const departamentos = <?php echo json_encode($todosDepartamentos); ?>;

        const opcoesRelatorio = [
            { value: 'utilizador', label: 'Utilizador' },
            { value: 'todos', label: 'Todos' }
        ];
        
        const utilizadoresExistentes = <?php echo json_encode(array_column($funcionarios, 'utilizador')); ?>;

        function abrirModal() {
            // Preencher horários
            const selectHorario = document.querySelector('select[name="horario"]');
            selectHorario.innerHTML = '<option value="">-- Selecionar Horário --</option>';
            horarios.forEach(h => {
                const opt = document.createElement('option');
                opt.value = h.id;
                opt.textContent = h.nome;
                selectHorario.appendChild(opt);
            });

            // Preencher departamentos
            const selectDepartamento = document.querySelector('select[name="departamento"]');
            selectDepartamento.innerHTML = '<option value="">-- Selecionar Departamento --</option>';
            departamentos.forEach(d => {
                const opt = document.createElement('option');
                opt.value = d.id;
                opt.textContent = d.nome;
                selectDepartamento.appendChild(opt);
            });

            // Limpar formulário
            document.querySelector('input[name="editar_utilizador"]').value = '';
            document.querySelector('input[name="numero"]').value = '';
            document.querySelector('input[name="nome"]').value = '';
            document.querySelector('input[name="email"]').value = '';
            document.querySelector('input[name="utilizador"]').value = '';
            document.querySelector('input[name="password"]').value = '';

            // Preencher tipo de relatório
            const selectRelatorio = document.querySelector('select[name="relatorio"]');
            selectRelatorio.innerHTML = '<option value="" disabled selected>-- Selecionar Qual Tipo de Relatório --</option>';
            opcoesRelatorio.forEach(op => {
                const opt = document.createElement('option');
                opt.value = op.value;
                opt.textContent = op.label;
                selectRelatorio.appendChild(opt);
            });

            // Abrir modal
            document.getElementById("modalFuncionario").style.display = "flex";
        }

        function fecharModal() {
            document.getElementById("modalFuncionario").style.display = "none";
        }

        function editarFuncionario(numero, nome, departamento, email, horario_id, relatorio, utilizador) {
            // Preencher campos básicos
            document.querySelector('input[name="editar_utilizador"]').value = utilizador;
            document.querySelector('input[name="numero"]').value = numero;
            document.querySelector('input[name="nome"]').value = nome;
            document.querySelector('input[name="email"]').value = email;
            document.querySelector('input[name="utilizador"]').value = utilizador;
            document.querySelector('input[name="password"]').value = '';

            // Preencher select de Relatório
            const selectRelatorio = document.querySelector('select[name="relatorio"]');
            selectRelatorio.innerHTML = '<option value="" disabled>-- Selecionar Qual Tipo de Relatório --</option>';
            opcoesRelatorio.forEach(op => {
                const opt = document.createElement('option');
                opt.value = op.value;
                opt.textContent = op.label;
                if (op.value === relatorio) opt.selected = true;
                selectRelatorio.appendChild(opt);
            });

            // Preencher select de Horários
            const selectHorario = document.querySelector('select[name="horario"]');
            selectHorario.innerHTML = '<option value="">-- Selecionar Horário --</option>';
            horarios.forEach(h => {
                const opt = document.createElement('option');
                opt.value = h.id;
                opt.textContent = h.nome;
                if (h.id == horario_id) opt.selected = true;
                selectHorario.appendChild(opt);
            });

            // Preencher select de Departamentos
            const selectDepartamento = document.querySelector('select[name="departamento"]');
            selectDepartamento.innerHTML = '<option value="">-- Selecionar Departamento --</option>';
            departamentos.forEach(d => {
                const opt = document.createElement('option');
                opt.value = d.id;
                opt.textContent = d.nome;
                if (d.id == departamento) opt.selected = true;
                selectDepartamento.appendChild(opt);
            });

            // Alterar texto do botão e abrir modal
            document.getElementById("botaoGuardar").textContent = "Atualizar";
            document.getElementById("modalFuncionario").style.display = "flex";
        }

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

        function validarEnvio() {
            const campoUtilizador = document.querySelector('input[name="utilizador"]');
            const nomeUtilizador = campoUtilizador.value.trim();

            const campoEditar = document.querySelector('input[name="editar_utilizador"]');
            const nomeOriginal = campoEditar.value.trim();

            const modoEdicao = nomeOriginal !== "";

            const nomeFoiAlterado = nomeOriginal !== nomeUtilizador;

            if ((modoEdicao && nomeFoiAlterado || !modoEdicao) &&
                utilizadoresExistentes.includes(nomeUtilizador)) {
                mostrarNotificacao('Já existe um funcionário com esse nome de utilizador.', 'erro');
                campoUtilizador.focus();
                return false;
            }

            return true;
        }



        function confirmarEliminacao(url) {
            const modal = document.getElementById("confirmarEliminacao");
            const link = document.getElementById("linkConfirmarEliminacao");
            link.href = url;
            modal.style.display = "flex";
        }

        function fecharConfirmacao() {
            document.getElementById("confirmarEliminacao").style.display = "none";
        }


    </script>


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
        <h1>Gestão de Funcionários</h1>
        <div class="centered" style="gap: 20px;">
            <button class="botao" onclick="abrirModal()">+ Novo Funcionário</button>
            <a href="painel.php" class="botao">Voltar</a>
        </div>

        <div id="modalFuncionario" class="modal">
            <div class="modal-content">
                <span class="close" onclick="fecharModal()">&times;</span>
                <h2>Funcionário</h2>
                <form method="post" onsubmit="return validarEnvio()">
                    <input type="hidden" name="editar_utilizador" value="">
                    <input type="number" name="numero" placeholder="Número *" required>
                    <input type="text" name="nome" placeholder="Nome *" required>
                    <input type="email" name="email" placeholder="Email">
                    <select name="horario">
                        <option value="">-- Selecionar Horário --</option>
                    </select>
                    <select name="departamento">
                        <option value="">-- Selecionar Departamento --</option>
                    </select>
                    <select name="relatorio" required>
                        <option value="utilizador">Utilizador</option>
                        <option value="todos">Todos</option>
                    </select>
                    <input type="text" name="utilizador" placeholder="Utilizador *" required id="campoUtilizador">
                    <input type="password" name="password" placeholder="Password *">
                    <div class="centered">
                        <button type="submit" id="botaoGuardar" name="criar" class="botao">Guardar</button>
                    </div>
                </form>
            </div>
        </div>

        <h3>Funcionários Existentes</h3>
        <form method="get" style="display: flex; justify-content: center; gap: 10px; margin-top: 10px;">
            <input type="text" name="pesquisa" placeholder="Procurar por número, nome ou email" style="width: 300px; padding: 10px; font-size: 1em;">
            <button type="submit" class="botao">Pesquisar</button>
        </form>

        <table>
            <thead>
                <tr>
                    <th>Número</th>
                    <th>Nome</th>
                    <th>Email</th>
                    <th>Horário</th>
                    <th>Departamento</th>
                    <th>Utilizador</th>
                    <th>Relatórios</th>
                    <th>Ativo</th>
                    <th>Criar Tarefa</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $funcionarios = $funcionarios ?? [];

                try {
                    $stmt = $ligacao->query("SELECT 
                            f.*, 
                            h.nome AS horario_nome,
                            d.nome AS departamento_nome
                        FROM 
                            funcionarios f
                        LEFT JOIN 
                            horarios h ON f.horario_id = h.id
                        LEFT JOIN 
                            departamento d ON f.departamento = d.id
                    ");
                    $funcionarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    $funcionarios = [];
                }
                
                $termo = isset($_GET['pesquisa']) ? trim($_GET['pesquisa']) : '';
                $lista = array_filter($funcionarios, function($f) use ($termo) {
                    if ($termo === '') return true;
                    return stripos($f['numero'], $termo) !== false ||
                        stripos($f['nome'], $termo) !== false ||
                        stripos($f['email'], $termo) !== false;
                });
                ?>
                <?php if (empty($lista)): ?>
                <tr><td colspan="7" style="text-align:center;">Nenhum funcionário encontrado.</td></tr>
                <?php else: ?>
                    <?php foreach ($lista as $f): ?>
                        <tr style="border-bottom:1px solid #ccc;">
                            <td><?php echo htmlspecialchars($f['numero']); ?></td>
                            <td><?php echo htmlspecialchars($f['nome']); ?></td>
                            <td><?php echo htmlspecialchars($f['email']); ?></td>
                            <td><?php echo isset($f['horario_nome']) ? htmlspecialchars($f['horario_nome']) : ''; ?></td>
                            <td><?php echo isset($f['departamento_nome']) ? htmlspecialchars($f['departamento_nome']) : ''; ?></td>
                            <td><?php echo htmlspecialchars($f['utilizador']); ?></td>
                            <td><?php echo htmlspecialchars($f['relatorio_acesso']); ?></td>
                            <td style="text-align:center;">
                                <input 
                                    type="checkbox" 
                                    <?php echo (isset($f['estado']) && $f['estado'] === 'ativo' ? 'checked' : ''); ?>
                                    onchange="atualizarEstadoFuncionario(this, '<?php echo $f['utilizador']; ?>')"
                                >
                            </td>
                            <td style="text-align:center;">
                                <input 
                                    type="checkbox" 
                                    <?php echo (isset($f['criar_tarefa']) && $f['criar_tarefa'] === 'ativo' ? 'checked' : ''); ?>
                                    onchange="atualizarCriarTarefaFuncionario(this, '<?php echo $f['utilizador']; ?>')"
                                >
                            </td>
                            <td>
                                <div style="display:flex; gap:6px;">
                                    <div class="acoes-botoes">
                                        <button class="botao" onclick="editarFuncionario(
                                        '<?php echo addslashes($f['numero']); ?>',
                                        '<?php echo addslashes($f['nome']); ?>',
                                        '<?php echo addslashes($f['email']); ?>',
                                        '<?php echo $f['horario_id']; ?>',
                                        '<?php echo $f['departamento']; ?>',
                                        '<?php echo $f['relatorio_acesso']; ?>',
                                        '<?php echo $f['utilizador']; ?>'
                                        )">Editar</button>
                                        <button class="botao botao-danger" onclick="confirmarEliminacao('<?php echo $f['utilizador']; ?>')">Eliminar</button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div id="confirmarEliminacao" class="modal">
    <div class="modal-content" style="max-width: 500px; text-align: center;">
        <span class="close" onclick="fecharConfirmacao()">&times;</span>
        <h2>Confirmar Eliminação</h2>
        <p>Tem a certeza que deseja eliminar este funcionário?</p>
        <p><strong>Esta ação é irreversível</strong> e poderá apagar registos associados.</p>
        <div style="display: flex; justify-content: center; gap: 20px; margin-top: 20px;">
        <button class="botao" onclick="fecharConfirmacao()">Cancelar</button>
        <form id="formEliminar" method="post" action="funcionarios.php">
            <input type="hidden" name="utilizador_a_eliminar" id="utilizadorEliminarInput">
            <button type="submit" name="confirmar_eliminar" class="botao botao-danger">Sim, eliminar</button>
        </form>
        </div>
    </div>
    </div>


<script>
    function confirmarEliminacao(utilizador) {
        document.getElementById("utilizadorEliminarInput").value = utilizador;
        document.getElementById("confirmarEliminacao").style.display = "flex";
    }

    function fecharConfirmacao() {
        document.getElementById("confirmarEliminacao").style.display = "none";
    }
</script>

<script>
    function atualizarEstadoFuncionario(checkbox, utilizador) {
    const novoEstado = checkbox.checked ? 1 : 0;

    fetch('atualizar_estado_funcionario.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `utilizador=${encodeURIComponent(utilizador)}&ativo=${novoEstado}`
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

<script>
    function atualizarCriarTarefaFuncionario(checkbox, utilizador) {
    const novoEstado = checkbox.checked ? 1 : 0;

    fetch('atualizar_criar_tarefa_funcionario.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `utilizador=${encodeURIComponent(utilizador)}&ativo=${novoEstado}`
    })
    .then(res => res.text())
    .then(res => {
        if (res.includes('sucesso')) {
            mostrarNotificacao("Criar tarefa atualizado com sucesso.", 'sucesso');
        } else {
            mostrarNotificacao("Erro ao atualizar criar tarefa.", 'erro');
            checkbox.checked = !checkbox.checked; // Reverter criar tarefa visual
        }
    })
    .catch(() => {
        mostrarNotificacao("Erro ao comunicar com o servidor.", 'erro');
        checkbox.checked = !checkbox.checked; // Reverter
    });
}
</script>

<?php if (!empty($mensagem)): ?>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    mostrarNotificacao(<?php echo json_encode($mensagem); ?>, <?php echo json_encode($tipo_mensagem); ?>);
  });
</script>
<?php endif; ?>

</body>
</html>
