<?php
require_once '../config_bd.php';
session_start();
if (!isset($_SESSION["admin_logado"])) {
    header("Location: login.php");
    exit();
}
$utilizadorLogado = $_SESSION['admin_logado'] ?? '';
$acesso = '';
$departamentos = [];

// Verificando se foi enviado o formulário de adição de departamento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nome_departamento'])) {
    $nomeDepartamento = htmlspecialchars($_POST['nome_departamento']);
    $idDepartamento = $_POST['id_departamento'] ?? null;

    if (!empty($nomeDepartamento)) {
        if (!empty($idDepartamento)) {
            // É uma EDIÇÃO
            $query = "UPDATE departamento SET nome = ? WHERE id = ?";
            $stmt = $ligacao->prepare($query);
            $stmt->execute([$nomeDepartamento, $idDepartamento]);
        } else {
            // É uma ADIÇÃO
            $query = "INSERT INTO departamento (nome) VALUES (?)";
            $stmt = $ligacao->prepare($query);
            $stmt->execute([$nomeDepartamento]);
        }

        header("Location: departamentos.php");
        exit();
    }
}


// Buscar departamentos para preencher a lista
$queryDepartamentos = "SELECT id, nome FROM departamento";
$stmt = $ligacao->prepare($queryDepartamentos);
$stmt->execute();
$departamentosAssoc = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obter funcionários por departamento
$funcionariosPorDepartamento = [];
$queryFuncionariosPorDep = "SELECT utilizador, nome, departamento FROM funcionarios WHERE departamento != 0";
$stmtFuncDep = $ligacao->prepare($queryFuncionariosPorDep);
$stmtFuncDep->execute();
while ($row = $stmtFuncDep->fetch(PDO::FETCH_ASSOC)) {
    $depId = $row['departamento'];
    $funcionariosPorDepartamento[$depId][] = [
        'utilizador' => $row['utilizador'],
        'nome' => $row['nome']
    ];
}



// Buscar funcionários que não possuem departamento
$queryFuncionarios = "SELECT utilizador, nome FROM funcionarios WHERE departamento=0";
$stmtFunc = $ligacao->prepare($queryFuncionarios);
$stmtFunc->execute();
$funcionarios = $stmtFunc->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Relatório de Departamentos</title>
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
    padding: 40px 50px;  /* Maior padding */
    border-radius: 14px;
    box-shadow: 0 6px 18px rgba(0,0,0,0.25);
    width: 300px;  /* Aumentada a largura */
    max-width: 90%;
    text-align: center;
    position: relative;
    display: flex;
    flex-direction: column;
    justify-content: space-between;  /* Garantindo que o botão "Adicionar" fique no fundo */
    height: 250px; /* Definindo altura fixa */
}

.scroll-lista {
    max-height: 200px; /* Altura da lista rolável */
    overflow-y: auto;  /* Habilita rolagem vertical */
    text-align: left;
    margin-bottom: 10px;
    padding-right: 10px;  /* Adiciona espaço à direita para a rolagem */
}

.scroll-lista div {
    margin-bottom: 8px;
}

.scroll-lista button {
    background-color: #cfa728;
    color: white;
    border: none;
    padding: 5px 10px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: bold;
}

.scroll-lista button:hover {
    background-color: #b69020;
}

.modal-footer {
    margin-top: 20px;
    text-align: center;
}

.modal-footer button {
    padding: 10px 20px;
    background-color: #cfa728;
    color: white;
    font-weight: bold;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    transition: background-color 0.2s;
}

.modal-footer button:hover {
    background-color: #b69020;
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

.departamento-lista {
    text-align: left;
    padding: 0 20px;
    margin-bottom: 30px;
}

.linha-funcionarios {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin: 6px 0;
}

#modalSelecionarFuncionarios .modal-box {
    max-height: 80vh;
    overflow-y: auto;
}

.modal-box {
    animation: fadeInScale 0.3s ease-out;
}

@keyframes fadeInScale {
    from {
        opacity: 0;
        transform: scale(0.9);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}

.scroll-lista {
    padding-left: 10px;
}

#modalSelecionarFuncionarios .modal-box {
    align-items: flex-start; /* força o conteúdo a alinhar à esquerda */
}
    </style>
</head>
<body>
<div class="container">
    <h1>Departamentos</h1>

    <div class="actions">
        <button style="margin-right: 10px;" onclick="window.location.href='painel.php'">← Voltar ao Painel</button>
        <button onclick="abrirModalAdicionar()">+ Adicionar Departamento</button>
    </div>

    <div id="modalAdicionarDepartamento" class="modal-overlay">
        <div class="modal-box">
            <button class="fechar-modal" onclick="fecharModalAdicionar()">×</button>
            <h2 style="text-align:left;">Adicionar Departamento</h2>

            <form method="POST" action="">
                <!-- Campo para nome do departamento -->
                <label for="nome_departamento">Nome do Departamento:</label><br>
                <input type="text" name="nome_departamento" id="nome_departamento" required style="width: 100%; box-sizing: border-box;"><br><br>

                <!-- Botão de Adicionar -->
                <div class="modal-footer" style="display: flex; justify-content: center; margin-left: 90px;">
                    <button type="submit">Adicionar</button>
                </div>
            </form>
        </div>
    </div>

    <div id="modalEditarDepartamento" class="modal-overlay">
        <div class="modal-box">
            <button class="fechar-modal" onclick="fecharModalEditar()">×</button>
            <h2 style="text-align:left;">Editar Departamento</h2>

            <form method="POST" action="">
                <input type="hidden" name="id_departamento" id="id_departamento_editar">

                <label for="nome_departamento">Nome do Departamento:</label><br>
                <input type="text" name="nome_departamento" id="nome_departamento" required style="width: 100%; box-sizing: border-box;"><br><br>

                <div class="modal-footer" style="display: flex; justify-content: center; margin-left: 90px;">
                    <button type="submit">Editar</button>
                </div>
            </form>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th></th>
                <th style="text-align: left;">Nome</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($departamentosAssoc as $dep): ?>
                <tr>
                    <td>
                        <button type="button" onclick="toggleDetalhes(<?= $dep['id'] ?>)">▼</button>
                    </td>
                    <td style="text-align: left;"><?= htmlspecialchars($dep['nome']) ?></td>
                    <td style="text-align: center;">
                        <button type="button" class="btn-danger"
                                onclick="abrirModalEliminarUnico(<?= $dep['id'] ?>, '<?= htmlspecialchars($dep['nome'], ENT_QUOTES) ?>')">
                            Eliminar
                        </button>
                        <button onclick="abrirModalEditar(<?= $dep['id'] ?>, '<?= htmlspecialchars($dep['nome'], ENT_QUOTES) ?>')" style="margin-right:-70px;">Editar</button>
                    </td>
                </tr>
                <tr id="detalhes-<?= $dep['id'] ?>" style="display: none; background-color: #fef9e7;">
                    <td colspan="6" style="padding: 10px 20px; text-align: left;">
                        <strong>Nome do Departamento:</strong> <?= htmlspecialchars($dep['nome']) ?><br>
                        <div class="linha-funcionarios">
                            <strong>Funcionários:</strong>
                            <button type="button" style="margin-right:60px;" onclick="adicionarFuncionarioDepartamento(<?= $dep['id'] ?>)">+</button>
                        </div>


                        <div id="lista-funcionarios-<?= $dep['id'] ?>" class="scroll-lista">
                            <?php if (!empty($funcionariosPorDepartamento[$dep['id']])): ?>
                                <?php foreach ($funcionariosPorDepartamento[$dep['id']] as $func): ?>
                                    <div class="funcionario-item" style="margin-left:30px;">
                                        <?= htmlspecialchars($func['nome']) ?>
                                        <button type="button"
                                            onclick="removerFuncionarioDepartamento('<?= $func['utilizador'] ?>', '<?= $dep['id'] ?>')"
                                            style="color: white; background-color: #831f14ff; border: none; padding: 5px 10px; border-radius: 6px; font-weight: bold; cursor: pointer;">
                                            &times;
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div><em>Sem funcionários atribuídos</em></div>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>


</div>

<div id="modalConfirmarRemocao" class="modal-overlay">
  <div class="modal-box" style="height: auto; padding: 25px 35px; width: 350px;">
    <button class="fechar-modal" onclick="fecharModalRemocao()">×</button>
    <h3 style="margin-bottom: 10px;">Remover Funcionário</h3>
    <p id="textoConfirmarRemocao" style="margin-bottom: 20px;">Tem certeza que deseja remover este funcionário do departamento?</p>
    <div style="display: flex; justify-content: space-between;">
      <button id="btnConfirmarRemocao" class="btn-danger">Sim</button>
      <button onclick="fecharModalRemocao()">Não</button>
    </div>
  </div>
</div>



<script>

    function adicionarFuncionarioDepartamento(departamentoId) {
        document.getElementById('inputDepartamentoId').value = departamentoId;
        document.getElementById('modalSelecionarFuncionarios').style.display = 'flex';
    }

    function fecharModalSelecionarFuncionarios() {
        document.getElementById('modalSelecionarFuncionarios').style.display = 'none';
    }


    function toggleDetalhes(id) {
        const linhaDetalhes = document.getElementById('detalhes-' + id);
        if (linhaDetalhes.style.display === 'none' || linhaDetalhes.style.display === '') {
            linhaDetalhes.style.display = 'table-row';
        } else {
            linhaDetalhes.style.display = 'none';
        }
    }


    function adicionarFuncionario(funcionarioId) {
        // Obter o nome do funcionário
        var funcionarioNome = document.getElementById("funcionarioNome" + funcionarioId).innerText;

        // Criar o novo elemento de funcionário com o nome e botão de remoção
        var novoFuncionario = document.createElement('div');
        novoFuncionario.classList.add('funcionario-box');
        novoFuncionario.innerHTML = `
            <label>${funcionarioNome}</label>
            <button type="button" onclick="removerFuncionario(this)">-</button>
        `;

        // Adicionar o novo funcionário ao topo da lista
        var listaFuncionarios = document.getElementById('listaFuncionarios');
        listaFuncionarios.insertBefore(novoFuncionario, listaFuncionarios.firstChild);

        // Opcionalmente, pode esconder o botão + do funcionário que já foi adicionado
        var funcionarioBox = document.getElementById('funcionarioBox' + funcionarioId);
        funcionarioBox.querySelector('button').style.display = 'none';  // Esconde o botão +
    }

    function removerFuncionario(botao) {
        // Remove o funcionário da lista
        botao.parentElement.remove();

        // Opcionalmente, pode reexibir o botão + do funcionário removido
        var funcionarioId = botao.parentElement.querySelector('label').id.replace('funcionarioNome', '');
        var funcionarioBox = document.getElementById('funcionarioBox' + funcionarioId);
        funcionarioBox.querySelector('button').style.display = 'inline';  // Exibe o botão +
    }



    function abrirModalAdicionar() {
        document.getElementById('modalAdicionarDepartamento').style.display = 'flex';
    }

    function fecharModalAdicionar() {
        document.getElementById('modalAdicionarDepartamento').style.display = 'none';
    }


    let departamentoAEliminarId = null;

    function abrirModalEliminarUnico(id, nome) {
        departamentoAEliminarId = id;
        document.getElementById('textoModalEliminacao').innerHTML = `
            Tem a certeza que pretende eliminar o departamento <strong>"${nome}"</strong>?<br><br><br>
            Todos os funcionários atribuídos voltarão a ficar sem departamento.
        `;
        document.getElementById('modalConfirmarEliminacao').style.display = 'flex';
    }



    function fecharModalConfirmarEliminacao() {
        departamentoAEliminarId = null;
        document.getElementById('modalConfirmarEliminacao').style.display = 'none';
    }

    function confirmarEliminacaoDepartamento() {
        if (departamentoAEliminarId !== null) {
            window.location.href = 'eliminar_departamento.php?id=' + departamentoAEliminarId;
        }
    }

    let funcionarioIdParaRemover = null;
    let departamentoIdParaRemover = null;

    function removerFuncionarioDepartamento(funcionarioId, departamentoId) {
        funcionarioIdParaRemover = funcionarioId;
        departamentoIdParaRemover = departamentoId;
        document.getElementById('modalConfirmarRemocao').style.display = 'flex';
    }

    function fecharModalRemocao() {
        funcionarioIdParaRemover = null;
        departamentoIdParaRemover = null;
        document.getElementById('modalConfirmarRemocao').style.display = 'none';
    }

    document.getElementById('btnConfirmarRemocao').addEventListener('click', function () {
        fetch('remover_funcionario_departamento.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: `funcionario_id=${funcionarioIdParaRemover}&departamento_id=${departamentoIdParaRemover}`
        })
        .then(response => response.text())
        .then(data => {
            if (data.trim() === "ok") {
                location.reload();
            } else {
                alert("Erro ao remover funcionário: " + data);
            }
        })
        .catch(error => {
            console.error("Erro na requisição:", error);
            alert("Erro de comunicação com o servidor.");
        });

        fecharModalRemocao();
    });


    function abrirModalEditar(id, nome) {
        document.getElementById('id_departamento_editar').value = id;
        document.getElementById('nome_departamento').value = nome;
        document.getElementById('modalEditarDepartamento').style.display = 'flex';
    }


    function fecharModalEditar() {
        document.getElementById('modalEditarDepartamento').style.display = 'none';
    }


</script>


<div id="modalSelecionarFuncionarios" class="modal-overlay">
    <div class="modal-box" style="width: 400px; max-height: 80vh; overflow-y: hidden; display: flex; flex-direction: column;">
        <button class="fechar-modal" onclick="fecharModalSelecionarFuncionarios()">×</button>
        <h3 style="text-align:left; margin-bottom: 10px;">Selecionar Funcionários</h3>
        
        <form id="formSelecionarFuncionarios" method="POST" action="atribuir_departamento_funcionarios.php" style="flex-grow: 1; display: flex; flex-direction: column;">
            <input type="hidden" name="departamento_id" id="inputDepartamentoId">

            <!-- Lista de funcionários alinhada à esquerda -->
            <div class="scroll-lista" style="flex-grow: 1; overflow-y: auto; margin-bottom: 20px;">
                <div style="display: flex; flex-direction: column; align-items: flex-start;">
                    <?php foreach ($funcionarios as $func): ?>
                        <label style="margin-bottom: 8px;">
                            <input type="checkbox" name="funcionarios[]" value="<?= htmlspecialchars($func['utilizador']) ?>">
                            <?= htmlspecialchars($func['nome']) ?>
                        </label>
                    <?php endforeach; ?>
                    <?php if (empty($funcionarios)): ?>
                        <em>Sem funcionários disponíveis</em>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Botão alinhado à esquerda -->
            <div class="modal-footer" style="margin-top: auto;">
                <button type="submit" style="margin-left: 0;">Atribuir</button>
            </div>
        </form>
    </div>
</div>




<div id="modalConfirmarEliminacao" class="modal-overlay">
  <div class="modal-box">
    <button class="fechar-modal" onclick="fecharModalConfirmarEliminacao()">×</button>
    <p id="textoModalEliminacao" style="font-weight: bold;">Tem a certeza que pretende eliminar?</p>
    <div style="margin-top: 20px; display: flex; justify-content: space-between;">
      <button class="botao-modal btn-danger" onclick="confirmarEliminacaoDepartamento()">Sim</button>
      <button class="botao-modal" onclick="fecharModalConfirmarEliminacao()">Não</button>
    </div>
  </div>
</div>



</body>
</html>
