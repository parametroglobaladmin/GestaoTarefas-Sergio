<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../config_bd.php';

if (!isset($_SESSION['utilizador_logado'])) {
    http_response_code(401);
    echo json_encode(['temPendentes' => false, 'erro' => 'Utilizador não autenticado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$idParam = $_GET['id'] ?? null;
$utilizadorSessao = $_SESSION['utilizador_logado'];

function temColuna(PDO $ligacao, string $tabela, string $coluna): bool {
    static $cache = [];
    $cacheKey = $tabela . '.' . $coluna;

    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $stmt = $ligacao->prepare("SHOW COLUMNS FROM `$tabela` LIKE ?");
    $stmt->execute([$coluna]);
    $cache[$cacheKey] = $stmt->fetch(PDO::FETCH_ASSOC) !== false;

    return $cache[$cacheKey];
}

try {
    $temColunaId = temColuna($ligacao, 'funcionarios', 'id');
    $temColunaUtilizadorId = temColuna($ligacao, 'utilizador_entradaesaida', 'utilizador_id');

    $registo = null;

    if ($temColunaId && $idParam !== null && $idParam !== '') {
        if ($temColunaUtilizadorId) {
            $stmt = $ligacao->prepare(
                "SELECT id, data, hora_entrada FROM utilizador_entradaesaida
                 WHERE utilizador_id = ? AND hora_saida IS NULL
                 ORDER BY data DESC, hora_entrada DESC
                 LIMIT 1"
            );
            $stmt->execute([$idParam]);
        } else {
            $stmt = $ligacao->prepare(
                "SELECT ue.id, ue.data, ue.hora_entrada
                   FROM utilizador_entradaesaida ue
                   INNER JOIN funcionarios f ON f.utilizador = ue.utilizador
                  WHERE f.id = ? AND ue.hora_saida IS NULL
                  ORDER BY ue.data DESC, ue.hora_entrada DESC
                  LIMIT 1"
            );
            $stmt->execute([$idParam]);
        }

        $registo = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$registo) {
        $stmtFallback = $ligacao->prepare(
            "SELECT id, data, hora_entrada
               FROM utilizador_entradaesaida
              WHERE utilizador = ? AND hora_saida IS NULL
              ORDER BY data DESC, hora_entrada DESC
              LIMIT 1"
        );
        $stmtFallback->execute([$utilizadorSessao]);
        $registo = $stmtFallback->fetch(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'temPendentes' => (bool) $registo,
        'data' => $registo['data'] ?? null,
        'horaEntrada' => $registo['hora_entrada'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'temPendentes' => false,
        'erro' => 'Erro ao verificar saídas pendentes.'
    ], JSON_UNESCAPED_UNICODE);
}
