<?php
require_once '../config_bd.php';

$tarefaId = $_GET['tarefa_id'];

$query = "
  SELECT d.nome AS nome_departamento,
         dt.data_entrada,
         COALESCE(dt.data_saida, NOW()) AS data_saida,
         TIMESTAMPDIFF(SECOND, dt.data_entrada, COALESCE(dt.data_saida, NOW())) AS duracao_segundos
  FROM departamento_tarefa dt
  JOIN departamento d ON d.id = dt.departamento_id
  WHERE dt.tarefa_id = :tarefa_id
  ORDER BY dt.data_entrada ASC
";


$stmt = $ligacao->prepare($query);
$stmt->execute([':tarefa_id' => $tarefaId]);
$res = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($res);
?>