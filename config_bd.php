
<?php
// Ficheiro de configuração da base de dados (ligação fixa ao MySQL do XAMPP)

$DB_SERVIDOR = 'localhost';
$DB_UTILIZADOR = 'root';
$DB_PASSWORD = '';
$DB_NOME = 'gestaotarefas';

try {
    $ligacao = new PDO("mysql:host=$DB_SERVIDOR;dbname=$DB_NOME", $DB_UTILIZADOR, $DB_PASSWORD);
    $ligacao->exec("SET time_zone = '+01:00'");
    $ligacao->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erro ao ligar à base de dados: " . $e->getMessage());
}
?>
