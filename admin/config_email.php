<?php
require_once '../config_bd.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$erro = '';
$sucesso = '';
$dados = [
    'smtpServer' => '',
    'smtpPort' => '',
    'username' => '',
    'password' => '',
    'recipients' => '',
    'security' => '',
    'subject' => ''
];

// Carregar últimos dados da base
try {
    $stmt = $ligacao->prepare("SELECT * FROM configuracao_email ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($resultado) {
        $dados = [
            'smtpServer' => $resultado['servidor_smtp'],
            'smtpPort'   => $resultado['porta_smtp'],
            'username'   => $resultado['utilizador'],
            'password'   => $resultado['senha'],
            'recipients' => $resultado['destinatarios'],
            'security'   => $resultado['seguranca'],
            'subject'    => $resultado['assunto']
        ];
    }
} catch (PDOException $e) {
    $erro = "Erro ao carregar dados: " . $e->getMessage();
}

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // Atualizar $dados com o que veio do POST
    foreach ($dados as $key => $_) {
        if (!isset($_POST[$key]) || trim($_POST[$key]) === '') {
            $erro = 'Preencha todos os campos.';
            break;
        }
        $dados[$key] = trim($_POST[$key]);
    }

    if (!$erro) {
        if ($acao === 'salvar') {
            try {
                // Apaga todos os registos anteriores
                $ligacao->exec("DELETE FROM configuracao_email");
        
                // Insere novo registo
                $stmt = $ligacao->prepare("INSERT INTO configuracao_email 
                    (servidor_smtp, porta_smtp, utilizador, senha, destinatarios, seguranca, assunto)
                    VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $dados['smtpServer'],
                    $dados['smtpPort'],
                    $dados['username'],
                    $dados['password'],
                    $dados['recipients'],
                    $dados['security'],
                    $dados['subject']
                ]);
        
                // Redireciona com sucesso
                header("Location: config_email.php?sucesso=1");
                exit;
            } catch (PDOException $e) {
                $erro = "Erro ao salvar: " . $e->getMessage();
            }
        }        
        if ($acao === 'testar') {
            try {
                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->SMTPDebug = 0;
                $mail->Debugoutput = 'html';
        
                $mail->Host = $dados['smtpServer'];
                $mail->SMTPAuth = true;
                $mail->Username = $dados['username'];
                $mail->Password = $dados['password'];
        
                // Normaliza e aplica o protocolo de segurança
                $seguranca = strtolower(trim($dados['security']));
                if ($seguranca === 'ssl') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                    $mail->Port = 465;
                } elseif ($seguranca === 'tls') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port = 587;
                } else {
                    // fallback (pouco recomendado)
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port = 587;
                }
        
                // Substitui porta caso esteja definida explicitamente no form
                if (!empty($dados['smtpPort'])) {
                    $mail->Port = (int)$dados['smtpPort'];
                }
        
                $mail->setFrom($dados['username'], 'Teste SMTP');
        
                // Permite múltiplos destinatários separados por vírgula
                foreach (explode(',', $dados['recipients']) as $dest) {
                    $dest = trim($dest);
                    if (filter_var($dest, FILTER_VALIDATE_EMAIL)) {
                        $mail->addAddress($dest);
                    }
                }
        
                $mail->isHTML(true);
                $mail->Subject = $dados['subject'] ?: 'Teste SMTP';
                $mail->Body = 'Este é um email de teste enviado automaticamente pelo sistema de configuração de e-mail.';
        
                $mail->send();
                $sucesso = "Email de teste enviado com sucesso!";
            } catch (Exception $e) {
                $erro = "Erro ao enviar email. Verifique as credenciais ou tente novamente.";
            }
        }
        
    }
}
?>




<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
    <link rel="stylesheet" href="../style.css">
    <style>
        form {
            width: 100%;
            max-width: 900px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 30px;
            align-items: center;
        }


        .form-group {
            display: flex;
            flex-direction: column;
            width: 100%;
            max-width: 100%;
            text-align: left;
        }

        .form-group label {
            margin-bottom: 5px;
            font-weight: 600;
            color: #343a40;
        }

        .form-group small {
            font-size: 0.9em;
            color: #6c757d;
        }

        .form-group input[type="radio"] {
            margin-right: 5px;
        }

        .form-group div {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        .form-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 30px;
            justify-content: center;
            width: 100%;
            max-width: 100%;
        }

        .form-column {
            flex: 1;
            min-width: 280px;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .subject-center {
            align-items: center;
            text-align: center;
        }

        .subject-center label {
            display: block;
            margin-bottom: 8px;
        }

        .subject-center input {
            width: 800px; /* ou 450px se quiser mais */
            margin: 0 auto;
        }
        .seguranca-opcoes {
            display: flex;
            gap: 20px;
            margin-top: 10px;
        }

        /* Esconde os radio buttons */
        .seguranca-opcoes input[type="radio"] {
            display: none;
        }

        /* Botões estilizados */
        .radio-btn {
            padding: 12px 30px;
            border: 2px solid #ccc;
            border-radius: 20px;
            background-color: white;
            color: #212529;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
        }

        /* Quando selecionado */
        .seguranca-opcoes input[type="radio"]:checked + .radio-btn {
            background-color: #d4af37;
            border-color: #d4af37;
            color: black;
        }
        .botao {
            margin-top: 40px; /* cria espaço acima do botão */
        }
        .campo {
            width: 100%;
            padding: 8px;
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;
        }

        .container {
            padding-top: 0;
            margin-top: 0;
        }
        .botoes {
            display: flex;
            gap: 20px;
            justify-content: center;
            margin-top: 40px;
        }

        .btn {
            flex: 1;
            text-align: center;
            padding: 15px 0;
            background-color: #d4af37;
            color: black;
            font-weight: bold;
            border-radius: 12px;
            text-decoration: none;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
            transition: background-color 0.3s ease;
            min-width: 200px;
            max-width: 250px;
        }

        .btn:hover {
            background-color: #c49b2e;
        }
        
        .botoes .btn {
            flex: 1;
            text-align: center;
            padding: 15px 0;
            background-color: #d4af37;
            color: black;
            font-weight: bold;
            border-radius: 12px;
            text-decoration: none;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
            transition: background-color 0.3s ease;
            min-width: 200px;
            max-width: 250px;
            border: none;
            font-size: 16px;
            display: inline-block;
        }

        /* Uniformiza o hover */
        .botoes .btn:hover {
            background-color: #c49b2e;
            cursor: pointer;
        }

        /* Garante que o botão submit não herde estilos inesperados */
        .botoes button.btn {
            appearance: none;
            -webkit-appearance: none;
        }

        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            background-color: #d4edda;
            color: #155724;
            padding: 15px 20px;
            border-left: 5px solid #28a745;
            border-radius: 5px;
            box-shadow: 0 3px 6px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            z-index: 9999;
            min-width: 280px;
        }

        .toast.erro {
            background-color: #f8d7da;
            color: #721c24;
            border-left-color: #dc3545;
        }

        .toast .fechar {
            background: none;
            border: none;
            color: inherit;
            font-size: 20px;
            line-height: 20px;
            cursor: pointer;
        }

        

    </style>



<script>
    document.addEventListener('DOMContentLoaded', function () {
        const portInput = document.getElementById('smtpPort');

        const sslRadio = document.getElementById('ssl');
        const tlsRadio = document.getElementById('tls');
        const noneRadio = document.getElementById('none');

        function atualizarPorta() {
            if (sslRadio.checked) {
                portInput.value = '465';
            } else if (tlsRadio.checked) {
                portInput.value = '587';
            } else if (noneRadio && noneRadio.checked) {
                portInput.value = '25';
            }
        }

        sslRadio.addEventListener('change', atualizarPorta);
        tlsRadio.addEventListener('change', atualizarPorta);
        if (noneRadio) noneRadio.addEventListener('change', atualizarPorta);

        // Atualiza na primeira carga (ex: recarregado com valor do banco)
        atualizarPorta();
    });
</script>


    
</head>

<script>
    setTimeout(() => {
        const toast = document.getElementById('toast');
        if (toast) toast.style.display = 'none';
    }, 5000);
</script>

<body>
    <div class="container">
        <h1>Configuração de Email</h1>
        
        <?php
            if (isset($_GET['sucesso']) && $_GET['sucesso'] == 1) {
                $sucesso = "Configuração salva com sucesso!";
            }
        ?>

        <?php if (!empty($sucesso) || !empty($erro)): ?>
            <div id="toast" class="toast <?= !empty($erro) ? 'erro' : 'sucesso' ?>">
                <span><?= htmlspecialchars($sucesso ?: $erro) ?></span>
                <button class="fechar" onclick="document.getElementById('toast').style.display='none'">×</button>
            </div>
        <?php endif; ?>


        <form method="POST" action="config_email.php">
            <div class="form-grid">
                    <!-- Coluna Esquerda -->
                    <div class="form-column">
                        <div class="form-group">
                            <label for="smtpServer">Servidor SMTP:</label>
                            <input type="text" id="smtpServer" name="smtpServer" class="campo" value="<?= htmlspecialchars($dados['smtpServer']) ?>"
                            >
                        </div>

                        <div class="form-group">
                            <label for="username">Utilizador:</label>
                            <input type="email" id="username" name="username" class="campo" value="<?= htmlspecialchars($dados['username']) ?>"
                            >
                        </div>

                        <div class="form-group">
                            <label for="recipients">Destinatários: <small>(separados por vírgula)</small></label>
                            <input type="text" id="recipients" name="recipients" class="campo" value="<?= htmlspecialchars($dados['recipients']) ?>"
                            >
                        </div>
                    </div>

                    <!-- Coluna Direita -->
                    <div class="form-column">
                        <div class="form-group">
                            <label for="password">Senha:</label>
                            <input type="password" id="password" name="password" class="campo"
                            value="<?= htmlspecialchars($dados['password']) ?>">
                        </div>

                        <div class="form-group">
                            <label for="smtpPort">Porta SMTP:</label>
                            <input type="text" id="smtpPort" name="smtpPort" class="campo" readonly
                                value="<?= htmlspecialchars($dados['smtpPort']) ?>">
                        </div>

                        <div class="form-group">
                        <label>Segurança:</label>
                        <div class="seguranca-opcoes">
                        <input type="radio" id="ssl" name="security" value="SSL"
                        <?= $dados['security'] === 'SSL' ? 'checked' : '' ?>>
                        <label for="ssl" class="radio-btn">SSL</label>

                        <input type="radio" id="tls" name="security" value="TLS"
                        <?= $dados['security'] === 'TLS' ? 'checked' : '' ?>>
                        <label for="tls" class="radio-btn">TLS</label>

                        <input type="radio" id="none" name="security" value="Nenhum"
                        <?= $dados['security'] === 'Nenhum' ? 'checked' : '' ?>>
                        <label for="none" class="radio-btn">Nenhum</label>

                        </div>
                    </div>
                    </div>
                </div>

                <div class="form-group subject-center">
                    <label for="subject">Assunto:</label>
                    <input type="text" id="subject" name="subject" class="campo"
                    value="<?= htmlspecialchars($dados['subject']) ?>" required>
                </div>

                <div class="botoes">
                    <button type="submit" name="acao" value="salvar" class="btn">Salvar Configuração</button>
                    <button type="submit" name="acao" value="testar" class="btn">Testar Envio</button>
                    <a href="painel.php" class="btn">Voltar</a>
                </div>

            </form>
    </div>
</body>
</html>