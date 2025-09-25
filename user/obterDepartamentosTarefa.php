<?php
require_once '../config_bd.php';
header('Content-Type: application/json; charset=utf-8');

$tarefaId = (int)($_GET['tarefa_id'] ?? 0);
if ($tarefaId <= 0) {
  echo json_encode([]);
  exit;
}

/*
  Traz os intervalos da tarefa por departamento + duração (s)
*/
$sql = "
  SELECT 
    dt.departamento_id,
    d.nome AS nome_departamento,
    dt.data_entrada,
    COALESCE(dt.data_saida, NOW()) AS data_saida,
    TIMESTAMPDIFF(SECOND, dt.data_entrada, COALESCE(dt.data_saida, NOW())) AS duracao_segundos
  FROM departamento_tarefa dt
  JOIN departamento d ON d.id = dt.departamento_id
  WHERE dt.tarefa_id = :tarefa
  ORDER BY dt.data_entrada ASC
";
$stmt = $ligacao->prepare($sql);
$stmt->execute([':tarefa' => $tarefaId]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));

?>