<?php
require_once __DIR__ . '/../config_bd.php';
require_once __DIR__ . '/../vendor/autoload.php'; // PHPMailer

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function enviar_email_personalizado($destinatario, $assunto, $mensagem) {
    global $ligacao;

    try {
        $stmt = $ligacao->prepare("SELECT * FROM configuracao_email ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $dados = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$dados) {
            echo "⚠️ Configuração de email não encontrada!<br>";
            return false;
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $dados['servidor_smtp'];
        $mail->SMTPAuth = true;
        $mail->Username = $dados['utilizador'];
        $mail->Password = $dados['senha'];

        // Segurança
        $seguranca = strtolower(trim($dados['seguranca']));
        if ($seguranca === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port = 465;
        } elseif ($seguranca === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;
        }

        // Porta personalizada
        if (!empty($dados['porta_smtp'])) {
            $mail->Port = (int)$dados['porta_smtp'];
        }

        $mail->setFrom($dados['utilizador'], 'Gestão de Tarefas');
        $mail->addAddress($destinatario);

        $mail->isHTML(false);
        $mail->Subject = $assunto;
        $mail->Body    = $mensagem;

        $mail->send();
        echo "✅ Email enviado para $destinatario<br>";
        return true;

    } catch (Exception $e) {
        echo "❌ Erro ao enviar email: {$mail->ErrorInfo}<br>";
        return false;
    }
}
?>
