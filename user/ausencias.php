<?php
session_start();

if (!isset($_SESSION['utilizador_logado'])) {
    header('Location: login.php');
    exit();
}

require_once '../config_bd.php';

$utilizador = $_SESSION["utilizador_logado"];

$mensagem = "";
$erro = "";

// Motivos disponíveis
try {
    $stmtMotivos = $ligacao->query("SELECT id, descricao FROM motivos_ausencia ORDER BY descricao");
    $motivos = $stmtMotivos->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $erro = "Erro ao carregar motivos: " . $e->getMessage();
}

// Submissão
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $datas = $_POST['datas'] ?? '';
    $motivoId = $_POST['motivo_id'] ?? '';

    if ($datas && $motivoId) {
        $datasSelecionadas = explode(",", $datas);

        try {
            $verificaStmt = $ligacao->prepare("
                SELECT COUNT(*) FROM ausencia_funcionarios 
                WHERE funcionario_utilizador = ? AND data_falta = ?
            ");
            $inserirStmt = $ligacao->prepare("
                INSERT INTO ausencia_funcionarios (funcionario_utilizador, data_falta, motivo_id) 
                VALUES (?, ?, ?)
            ");

            foreach ($datasSelecionadas as $data) {
                $verificaStmt->execute([$utilizador, $data]);
                $existe = $verificaStmt->fetchColumn();

                if ($existe == 0) {
                    $inserirStmt->execute([$utilizador, $data, $motivoId]);
                }
            }

            $_SESSION['mensagem_ausencia'] = "✅ Ausências registadas com sucesso.";
            header('Location: ausencias.php');
            exit();

        } catch (PDOException $e) {
            $erro = "❌ Erro ao registar: " . $e->getMessage();
        }
    } else {
        $erro = "❌ Seleciona pelo menos uma data e um motivo.";
    }
}


// Buscar TODAS as datas marcadas (para todos os meses)
$datasMarcadas = [];
try {
    $stmtDatas = $ligacao->prepare("
        SELECT af.data_falta, m.descricao AS motivo 
        FROM ausencia_funcionarios af 
        JOIN motivos_ausencia m ON af.motivo_id = m.id 
        WHERE af.funcionario_utilizador = ?
    ");

    $stmtDatas->execute([$utilizador]);
    $dadosAusencias = $stmtDatas->fetchAll(PDO::FETCH_ASSOC);
    $datasMarcadas = [];
    foreach ($dadosAusencias as $d) {
        $datasMarcadas[$d['data_falta']] = $d['motivo'];
    }

} catch (PDOException $e) {}

if (isset($_SESSION['mensagem_ausencia'])) {
    $mensagem = $_SESSION['mensagem_ausencia'];
    unset($_SESSION['mensagem_ausencia']);
}

?>




<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <title>Registar Ausências</title>
  <style>
    body {
        margin: 0;
        padding: 0;
        font-family: 'Segoe UI', Tahoma, sans-serif;
        background-color: #f8f9fa;
        color: #212529;
        height: 100vh;
        overflow: hidden;
        display: flex;
        justify-content: center;
        align-items: center;
        position: relative;
    }

    body::before {
        content: "";
        position: absolute;
        top: 50%;
        left: 50%;
        width: 70%;
        height: 70%;
        background-image: url('../logo_portos.jpg');
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
        background: white;
        padding: 30px 25px;
        border-radius: 16px;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        width: 420px;
        text-align: center;
    }
    h2 {
        font-size: 22px;
        margin-bottom: 15px;
    }

    .calendario-wrapper {
        margin-bottom: 20px;
    }

    .calendario-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        margin-bottom: 8px;
        flex-wrap: wrap;
    }

    .calendario-header button {
        background-color: #d4af37;
        border: none;
        border-radius: 6px;
        padding: 6px 14px;
        font-weight: bold;
        cursor: pointer;
        transition: background-color 0.3s ease;
    }

    .calendario-header button:hover {
        background-color: #c29b2a;
    }

    .dias-semana {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        text-align: center;
        font-weight: bold;
        margin-bottom: 8px;
        color: #444;
    }

    .calendario-grid, .dias-semana {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 4px;
    }

    .dia {
        padding: 10px 0;
        font-size: 13px;
        border: 1px solid #ccc;
        border-radius: 6px;
        cursor: pointer;
        background-color: white;
        transition: background 0.2s;
    }

    .dia:hover {
        background-color: #f2f2f2;
    }

    .dia.selecionado {
        background-color: #d4af37;
        font-weight: bold;
    }

    .dia.registado {
        background-color: #e0e0e0;
        color: #666;
        cursor: not-allowed;
    }

    form select {
        width: 100%;
        margin-top: 10px;
        padding: 6px 10px;
        font-size: 14px;
        border-radius: 6px;
    }

    button[type="submit"] {
        background-color: #d4af37;
        border: none;
        font-weight: bold;
        padding: 10px;
        font-size: 14px;
        border-radius: 8px;
        cursor: pointer;
        width: 100%;
        margin-top: 15px;
    }

    button[type="submit"], .limpar-btn {
        background-color: #d4af37;
        color: black;
        font-weight: bold;
        padding: 12px 24px;
        border: none;
        border-radius: 10px;
        cursor: pointer;
        font-size: 16px;
        width: 100%;
        margin-top: 12px;
        transition: background-color 0.3s ease;
    }

    button[type="submit"]:hover, .limpar-btn:hover {
        background-color: #c29b2a;
    }

    select {
        width: 100%;
        padding: 10px;
        border-radius: 8px;
        font-size: 15px;
        margin-top: 5px;
    }

    .legenda {
        margin-top: 15px;
        font-size: 14px;
        text-align: left;
        color: #555;
    }

    .legenda span {
        display: inline-block;
        margin-right: 15px;
        padding-left: 20px;
        position: relative;
    }

    .legenda .cor-registado::before,
    .legenda .cor-selecionado::before {
        content: '';
        width: 14px;
        height: 14px;
        border-radius: 4px;
        position: absolute;
        left: 0;
        top: 1px;
    }

    .cor-registado::before {
        background-color: #e0e0e0;
    }

    .cor-selecionado::before {
        background-color: #d4af37;
    }

    .btn-voltar, .btn-ausencias {
        font-size: 13px;
        background-color: #d4af37;
        border: none;
        padding: 6px 12px;
        border-radius: 6px;
        cursor: pointer;
        font-weight: bold;
        color: black;
        flex: 1;
        max-width: 120px;
        text-align: center;
    }

    .calendario-header select,
    .calendario-header button {
        padding: 4px 8px;
        font-size: 13px;
        border-radius: 6px;
    }
    

    .popup-ausencias {
        position: fixed;
        top: 50%;
        left: 50%;
        width: 300px;
        background: white;
        border-radius: 12px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.3);
        transform: translate(-50%, -50%);
        z-index: 1000;
        padding: 20px;
        text-align: center;
    }

    .popup-content {
        max-height: 70vh;
        overflow-y: auto;
        padding-right: 10px; /* para evitar que o scroll esconda conteúdo */
    }


    .popup-content h3 {
        margin-top: 0;
        font-size: 18px;
        margin-bottom: 10px;
    }

    .popup-content ul {
        list-style: none;
        padding-left: 0;
        max-height: 200px;
        overflow-y: auto;
        font-size: 14px;
        text-align: left;
    }

        #popup-ausencias {
        max-height: 80vh;
        overflow-y: auto;
    }


    .fechar-popup {
        position: absolute;
        top: 10px;
        right: 14px;
        font-size: 18px;
        cursor: pointer;
    }

    .navegacao {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .topo-acoes {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
    }

    .navegacao-meses {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 10px;
        margin-bottom: 12px;
    }

    .navegacao-meses select {
        padding: 6px 10px;
        font-size: 13px;
        border-radius: 6px;
        background-color: white;     /* fundo branco */
        border: 1px solid #ccc;
        font-weight: bold;
        color: #000;
    }

    .navegacao-meses button {
        padding: 6px 10px;
        font-size: 13px;
        border-radius: 6px;
        background-color: #d4af37;
        border: none;
        font-weight: bold;
        cursor: pointer;
        color: black;
    }

    .navegacao-meses button:hover {
        background-color: #c29b2a;
    }


    .toast {
        position: fixed;
        top: 20px;
        right: 20px;
        background-color: #28a745;
        color: white;
        padding: 14px 20px;
        border-radius: 8px;
        font-size: 15px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        z-index: 9999;
        opacity: 0;
        animation: fadeInOut 4s forwards;
    }

    @keyframes fadeInOut {
        0% { opacity: 0; transform: translateY(-10px); }
        10%, 90% { opacity: 1; transform: translateY(0); }
        100% { opacity: 0; transform: translateY(-10px); }
    }


    .tabela-ausencias {
        width: 100%;
        font-size: 14px;
        border-collapse: collapse;
    }

    .tabela-ausencias th,
    .tabela-ausencias td {
        padding: 8px 10px;
        text-align: center;
        border-bottom: 1px solid #ddd;
    }

    .tabela-ausencias thead th {
        background-color: #f8f8f8;
        font-weight: bold;
        position: sticky;
        top: 0;
        z-index: 1;
    }

    .tabela-ausencias tbody tr:hover {
        background-color: #f1f1f1;
    }

    .tabela-ausencias button {
        background-color: #dc3545;
        color: white;
        border: none;
        padding: 6px 12px;
        border-radius: 5px;
        cursor: pointer;
        font-size: 13px;
    }

    .tabela-ausencias button:hover {
        background-color: #bb2d3b;
    }

    .dia.hoje {
        background-color: #007bff;
        color: white;
        font-weight: bold;
        border: 2px solid #0056b3;
        cursor: not-allowed;
    }

    .barra-superior {
      width: 96%;
      display: flex;
      justify-content: flex-end;
      padding: 10px 20px;
      background-color: #ffffff;
      box-shadow: 0 1px 4px rgba(0,0,0,0.1);
      position: fixed;
      border-radius: 10px;
      top: 10px;
      left: 20px;
      right:20px;
    }

    .utilizador-info {
      display: flex;
      align-items: center;
      gap: 25px;
      font-weight: bold;
      font-size: 18px;
      margin-right: 30px;
      padding: 6px 14px;
    }

</style>
</head>
<body>
<div class="barra-superior">
    <div class="utilizador-info">
      <span class="nome-utilizador"><?= htmlspecialchars($utilizador) ?></span>
    </div>
</div>
<div class="container">

    <!-- Topo: Voltar e Ver Ausências -->
    <div class="topo-acoes">
        <button onclick="window.location.href='painel.php'" class="btn-voltar">← Voltar</button>
        <button type="button" class="btn-ausencias" id="btn-ver-ausencias">Ver Ausências</button>
    </div>

    <!-- Título -->
    <h2>Registar Ausência</h2>

    <!-- Navegação do calendário -->
    <div class="navegacao-meses">
        <button type="button" id="btn-mes-anterior">◀</button>
        <select id="mes-select"></select>
        <select id="ano-select"></select>
        <button type="button" id="btn-mes-seguinte">▶</button>
    </div>

    <h2>Registar Ausência</h2>
    <form method="post" action="ausencias.php">
        <label>Seleciona os dias de ausência:</label>
        <div class="calendario-wrapper">


            <div class="dias-semana">
                <div>Seg</div><div>Ter</div><div>Qua</div><div>Qui</div><div>Sex</div><div>Sáb</div><div>Dom</div>
            </div>

            <div id="calendario" class="calendario-grid"></div>
            <input type="hidden" name="datas" id="datas-selecionadas">
        </div>

        <label>Motivo:</label>
        <select name="motivo_id" required>
            <?php foreach ($motivos as $m): ?>
                <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['descricao']) ?></option>
            <?php endforeach; ?>
        </select>

        <button type="submit">Submeter</button>
    </form>
    <div id="popup-ausencias" class="popup-ausencias" style="display: none;">
        <div class="popup-content">
            <span class="fechar-popup" onclick="document.getElementById('popup-ausencias').style.display='none'">✕</span>
            <h3>Ausências registadas</h3>
            <table class="tabela-ausencias">
                <thead>
                    <tr style="text-align: left;">
                        <th>Data</th>
                        <th>Motivo</th>
                        <th>Ação</th>
                    </tr>
                </thead>
                <tbody id="tabela-ausencias"></tbody>
            </table>
        </div>
    </div>
</div>

<script>



function atualizarTabelaAusencias() {
    const tabela = document.getElementById("tabela-ausencias");
    tabela.innerHTML = "";

    const hoje = new Date();
    hoje.setHours(0, 0, 0, 0);

    const datas = Object.keys(datasMarcadas)
        .filter(data => new Date(data) >= hoje)
        .sort((a, b) => new Date(a) - new Date(b));

    if (datas.length === 0) {
        tabela.innerHTML = "<tr><td colspan='3'>Sem ausências futuras.</td></tr>";
    } else {
        datas.forEach(data => {
            const motivo = datasMarcadas[data];
            const tr = document.createElement("tr");

            const tdData = document.createElement("td");
            tdData.textContent = new Date(data).toLocaleDateString('pt-PT');

            const tdMotivo = document.createElement("td");
            tdMotivo.textContent = motivo;

            const tdAcao = document.createElement("td");
            const btn = document.createElement("button");
            btn.textContent = "Remover";
            btn.onclick = () => removerAusencia(data);
            tdAcao.appendChild(btn);

            tr.appendChild(tdData);
            tr.appendChild(tdMotivo);
            tr.appendChild(tdAcao);
            tabela.appendChild(tr);
        });
    }
}




document.querySelector("form").addEventListener("submit", function(e) {
    const botao = this.querySelector("button[type='submit']");
    botao.disabled = true;
    botao.textContent = "A submeter...";
});


const calendario = document.getElementById("calendario");
const datasSelecionadas = new Set();
const inputHidden = document.getElementById("datas-selecionadas");
const mesSelect = document.getElementById("mes-select");
const anoSelect = document.getElementById("ano-select");

const datasMarcadas = <?= json_encode($datasMarcadas,JSON_UNESCAPED_UNICODE) ?>;


let mesAtual = new Date().getMonth();
let anoAtual = new Date().getFullYear();

const meses = [
    "janeiro", "fevereiro", "março", "abril", "maio", "junho",
    "julho", "agosto", "setembro", "outubro", "novembro", "dezembro"
];

// Preencher selects
for (let i = 0; i < 12; i++) {
    const opt = document.createElement("option");
    opt.value = i;
    opt.textContent = meses[i].charAt(0).toUpperCase() + meses[i].slice(1);
    mesSelect.appendChild(opt);
}

const anoAtualReal = new Date().getFullYear();
for (let y = anoAtualReal; y <= anoAtualReal + 5; y++) {
    const opt = document.createElement("option");
    opt.value = y;
    opt.textContent = y;
    anoSelect.appendChild(opt);
}

mesSelect.value = mesAtual;
anoSelect.value = anoAtual;

// Renderizar calendário
function renderizarCalendario(mes, ano) {
    calendario.innerHTML = "";

    const primeiroDia = new Date(ano, mes, 1);
    const ultimoDia = new Date(ano, mes + 1, 0);
    const diaSemana = (primeiroDia.getDay() + 6) % 7;

    // Dias vazios até o primeiro dia da semana
    for (let i = 0; i < diaSemana; i++) {
        const vazio = document.createElement("div");
        calendario.appendChild(vazio);
    }

    // Dias do mês
    for (let dia = 1; dia <= ultimoDia.getDate(); dia++) {
        const data = new Date(ano, mes, dia);
        const dataStr = data.getFullYear() + "-" +
                        String(data.getMonth() + 1).padStart(2, '0') + "-" +
                        String(data.getDate()).padStart(2, '0');

        const div = document.createElement("div");
        div.classList.add("dia");
        div.textContent = dia;

        const hoje = new Date();
        hoje.setHours(0, 0, 0, 0);


        if (data.toDateString() === hoje.toDateString()) {
            div.classList.add("hoje");
            div.title = "Hoje";
        } else if (data.getTime() <= hoje.getTime() || datasMarcadas[dataStr]) {
            div.classList.add("registado");
            div.title = datasMarcadas[dataStr]
                ? "Motivo: " + datasMarcadas[dataStr]
                : "Dia indisponível";
        } else {
            div.addEventListener("click", () => {
                div.classList.toggle("selecionado");
                if (datasSelecionadas.has(dataStr)) {
                    datasSelecionadas.delete(dataStr);
                } else {
                    datasSelecionadas.add(dataStr);
                }
                inputHidden.value = Array.from(datasSelecionadas).join(",");
            });
        }

        calendario.appendChild(div);
    }

}

// Eventos dos selects
mesSelect.addEventListener("change", () => {
    mesAtual = parseInt(mesSelect.value);
    atualizarMesesDisponiveis();
});

anoSelect.addEventListener("change", () => {
    anoAtual = parseInt(anoSelect.value);
    atualizarMesesDisponiveis();
});

document.getElementById("btn-mes-anterior").addEventListener("click", () => {
    const hoje = new Date();
    const mesReal = hoje.getMonth();
    const anoReal = hoje.getFullYear();

    if (anoAtual < anoReal || (anoAtual === anoReal && mesAtual <= mesReal)) {
        return; // já está no limite
    }

    if (mesAtual === 0) {
        mesAtual = 11;
        anoAtual--;
    } else {
        mesAtual--;
    }

    mesSelect.value = mesAtual;
    anoSelect.value = anoAtual;
    atualizarMesesDisponiveis();
});


document.getElementById("btn-mes-seguinte").addEventListener("click", () => {
    if (mesAtual === 11) {
        mesAtual = 0;
        anoAtual++;
    } else {
        mesAtual++;
    }
    mesSelect.value = mesAtual;
    anoSelect.value = anoAtual;
    atualizarMesesDisponiveis();
});


function atualizarMesesDisponiveis() {
    const anoSelecionado = parseInt(anoSelect.value);
    const mesAtualReal = new Date().getMonth();
    mesSelect.innerHTML = "";

    const inicio = (anoSelecionado === anoAtualReal) ? mesAtualReal : 0;
    for (let i = inicio; i < 12; i++) {
        const opt = document.createElement("option");
        opt.value = i;
        opt.textContent = meses[i].charAt(0).toUpperCase() + meses[i].slice(1);
        mesSelect.appendChild(opt);
    }

    // Corrige o valor se estiver fora do intervalo
    if (anoSelecionado === anoAtualReal && mesAtual < mesAtualReal) {
        mesAtual = mesAtualReal;
    }
    mesSelect.value = mesAtual;
    renderizarCalendario(mesAtual, anoSelecionado);
}






// Inicializar
atualizarMesesDisponiveis();

// Função para mostrar popup com as datas
document.getElementById("btn-ver-ausencias").addEventListener("click", () => {
    atualizarTabelaAusencias();
    document.getElementById("popup-ausencias").style.display = "block";
});



document.addEventListener("DOMContentLoaded", function() {
    const btnConfirmar = document.getElementById("btn-confirmar-remover");
    if (btnConfirmar) {
        btnConfirmar.addEventListener("click", function () {
            if (!dataParaRemover) return;

            btnConfirmar.disabled = true;

            fetch('remover_ausencia.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `data=${encodeURIComponent(dataParaRemover)}`
            })
            .then(resp => resp.text())
            .then(res => {
                delete datasMarcadas[dataParaRemover];
                atualizarTabelaAusencias();
                renderizarCalendario(mesAtual, anoAtual);
                fecharModalRemover();
                showToast("✅ Ausência removida com sucesso.", true);
            })
            .catch(err => {
                fecharModalRemover();
                showToast("❌ Erro ao remover ausência.", false);
            })
            .finally(() => {
                btnConfirmar.disabled = false;
            });
        });
    }
});





let dataParaRemover = null;

function removerAusencia(data) {
    dataParaRemover = data;
    const dataFormatada = new Date(data).toLocaleDateString('pt-PT');
    document.getElementById('modal-remover-texto').textContent = `Deseja remover a ausência de ${dataFormatada}?`;
    document.getElementById('modal-remover').style.display = 'block';
}

function fecharModalRemover() {
    document.getElementById('modal-remover').style.display = 'none';
    dataParaRemover = null;
}


function showToast(msg, isSuccess) {
    const toast = document.getElementById("toast");
    const toastMsg = document.getElementById("toast-msg");
    toastMsg.textContent = msg;
    toast.style.backgroundColor = isSuccess ? "#28a745" : "#dc3545";
    toast.style.display = "block";

    setTimeout(() => {
        toast.style.display = "none";
    }, 4000);
}




document.addEventListener("DOMContentLoaded", function() {
    <?php if ($mensagem): ?>
        showToast("<?= htmlspecialchars($mensagem) ?>", true);
    <?php elseif ($erro): ?>
        showToast("<?= htmlspecialchars($erro) ?>", false);
    <?php endif; ?>
});


// Fechar popup ao clicar fora da caixa
window.addEventListener("click", function(event) {
    const popup = document.getElementById("popup-ausencias");
    if (event.target === popup) {
        popup.style.display = "none";
    }
});


</script>

<div id="toast" class="toast" style="display: none;">
  <span id="toast-msg"></span>
</div>


<div id="modal-remover" style="display:none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
    background: white; border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); padding: 20px; z-index: 2000; width: 300px; text-align: center;">
    <p id="modal-remover-texto" style="margin-bottom: 20px;"></p>
    <button id="btn-confirmar-remover" style="background-color: #dc3545; color: white; border: none; padding: 8px 14px; border-radius: 6px; cursor: pointer; margin-right: 10px;">Remover</button>
    <button onclick="fecharModalRemover()" style="background-color: #ccc; border: none; padding: 8px 14px; border-radius: 6px; cursor: pointer;">Cancelar</button>
</div>

</body>


</html>