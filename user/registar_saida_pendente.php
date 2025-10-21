<?php
session_start();
require_once '../config_bd.php';

date_default_timezone_set('Europe/Lisbon');

if (!isset($_SESSION['utilizador_logado'])) {
    http_response_code(401);
    echo 'Utilizador não autenticado.';
    exit;
}

$idParam = $_POST['id'] ?? null;
$horaInput = $_POST['hora'] ?? '';
$utilizadorSessao = $_SESSION['utilizador_logado'];

if (trim($horaInput) === '') {
    http_response_code(400);
    echo 'Hora inválida.';
    exit;
}

$horaObj = DateTime::createFromFormat('H:i', $horaInput, new DateTimeZone('Europe/Lisbon'));
if (!$horaObj) {
    http_response_code(400);
    echo 'Hora inválida.';
    exit;
}

$horaFormatada = $horaObj->format('H:i:s');

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
    $ligacao->beginTransaction();

    $temColunaId = temColuna($ligacao, 'funcionarios', 'id');
    $temColunaUtilizadorId = temColuna($ligacao, 'utilizador_entradaesaida', 'utilizador_id');

    $pendente = null;

    if ($temColunaId && $idParam !== null && $idParam !== '') {
        if ($temColunaUtilizadorId) {
            $stmt = $ligacao->prepare(
                "SELECT id, data, utilizador
                   FROM utilizador_entradaesaida
                  WHERE utilizador_id = ? AND hora_saida IS NULL
                  ORDER BY data DESC, hora_entrada DESC
                  LIMIT 1 FOR UPDATE"
            );
            $stmt->execute([$idParam]);
        } else {
            $stmt = $ligacao->prepare(
                "SELECT ue.id, ue.data, ue.utilizador
                   FROM utilizador_entradaesaida ue
                   INNER JOIN funcionarios f ON f.utilizador = ue.utilizador
                  WHERE f.id = ? AND ue.hora_saida IS NULL
                  ORDER BY ue.data DESC, ue.hora_entrada DESC
                  LIMIT 1 FOR UPDATE"
            );
            $stmt->execute([$idParam]);
        }

        $pendente = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$pendente) {
        $stmtFallback = $ligacao->prepare(
            "SELECT id, data, utilizador
               FROM utilizador_entradaesaida
              WHERE utilizador = ? AND hora_saida IS NULL
              ORDER BY data DESC, hora_entrada DESC
              LIMIT 1 FOR UPDATE"
        );
        $stmtFallback->execute([$utilizadorSessao]);
        $pendente = $stmtFallback->fetch(PDO::FETCH_ASSOC);
    }

    if (!$pendente) {
        $ligacao->commit();
        echo 'ok';
        exit;
    }

    $dataEntrada = $pendente['data'];
    $utilizadorRegisto = $pendente['utilizador'];
    $dataRetornoCompleta = $dataEntrada . ' ' . $horaFormatada;

    if (strcasecmp($utilizadorRegisto, $utilizadorSessao) !== 0) {
        $ligacao->rollBack();
        http_response_code(403);
        echo 'Operação não autorizada.';
        exit;
    }

    $stmtAtualizaSaida = $ligacao->prepare(
        "UPDATE utilizador_entradaesaida
            SET hora_saida = ?
          WHERE id = ?"
    );
    $stmtAtualizaSaida->execute([$dataRetornoCompleta, $pendente['id']]);

    $stmtPausas = $ligacao->prepare(
        "SELECT id, tarefa_id, motivo_id
           FROM pausas_tarefas
          WHERE funcionario = ? AND DATE(data_pausa) = ? AND data_retorno IS NULL"
    );
    $stmtPausas->execute([$utilizadorRegisto, $dataEntrada]);
    $pausas = $stmtPausas->fetchAll(PDO::FETCH_ASSOC);

    if ($pausas) {
        $stmtInsereTemporaria = $ligacao->prepare(
            "INSERT INTO pausas_temporarias (tarefa_id, utilizador, tipo_original, data_backup)
             VALUES (?, ?, ?, '00:00:00')"
        );

        $stmtAtualizaPausa = $ligacao->prepare(
            "UPDATE pausas_tarefas
                SET data_retorno = ?, tempo_pausa = TIMEDIFF(?, data_pausa)
              WHERE id = ?"
        );

        foreach ($pausas as $pausa) {
            $stmtInsereTemporaria->execute([
                $pausa['tarefa_id'],
                $utilizadorRegisto,
                $pausa['motivo_id']
            ]);

            $stmtAtualizaPausa->execute([
                $dataRetornoCompleta,
                $dataRetornoCompleta,
                $pausa['id']
            ]);
        }
    }

    $ligacao->commit();
    echo 'ok';
} catch (PDOException $e) {
    if ($ligacao->inTransaction()) {
        $ligacao->rollBack();
    }
    http_response_code(500);
    echo 'Erro ao registar saída pendente: ' . $e->getMessage();
}
