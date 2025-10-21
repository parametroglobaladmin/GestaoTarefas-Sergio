<?php
session_start();

header('Content-Type: application/json; charset=utf-8');

date_default_timezone_set('Europe/Lisbon');

require_once '../config_bd.php';

$timezone = new DateTimeZone('Europe/Lisbon');

function responder(array $dados, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

function temColuna(PDO $ligacao, string $tabela, string $coluna): bool
{
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

function normalizarHora(?string $hora, DateTimeZone $timezone): ?string
{
    if ($hora === null) {
        return null;
    }

    $hora = trim($hora);
    if ($hora === '') {
        return null;
    }

    $formatos = ['H:i:s', 'H:i'];
    foreach ($formatos as $formato) {
        $obj = DateTime::createFromFormat($formato, $hora, $timezone);
        if ($obj instanceof DateTime) {
            return $obj->format('H:i:s');
        }
    }

    return null;
}

if (!isset($_SESSION['utilizador_logado'])) {
    responder(['ok' => false, 'erro' => 'Utilizador não autenticado.'], 401);
}

$idParam = $_POST['id'] ?? null;
$horaInput = $_POST['hora'] ?? '';
$utilizadorSessao = $_SESSION['utilizador_logado'];

if (trim($horaInput) === '') {
    responder(['ok' => false, 'erro' => 'Hora inválida.'], 400);
}

$horaObj = DateTime::createFromFormat('H:i', $horaInput, $timezone)
    ?: DateTime::createFromFormat('H:i:s', $horaInput, $timezone);

if (!$horaObj instanceof DateTime) {
    responder(['ok' => false, 'erro' => 'Hora inválida.'], 400);
}

$horaFormatada = $horaObj->format('H:i:s');

try {
    $ligacao->beginTransaction();

    $temColunaId = temColuna($ligacao, 'funcionarios', 'id');
    $temColunaUtilizadorId = temColuna($ligacao, 'utilizador_entradaesaida', 'utilizador_id');

    $pendente = null;

    if ($temColunaId && $idParam !== null && $idParam !== '') {
        if ($temColunaUtilizadorId) {
            $stmt = $ligacao->prepare(
                "SELECT id, data, hora_entrada, utilizador
                   FROM utilizador_entradaesaida
                  WHERE utilizador_id = ? AND hora_saida IS NULL
                  ORDER BY data DESC, hora_entrada DESC
                  LIMIT 1 FOR UPDATE"
            );
            $stmt->execute([$idParam]);
        } else {
            $stmt = $ligacao->prepare(
                "SELECT ue.id, ue.data, ue.hora_entrada, ue.utilizador
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
            "SELECT id, data, hora_entrada, utilizador
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
        responder(['ok' => true]);
    }

    $dataEntrada = $pendente['data'];
    $utilizadorRegisto = $pendente['utilizador'];
    $horaEntradaNormalizada = normalizarHora($pendente['hora_entrada'] ?? null, $timezone);

    if (strcasecmp($utilizadorRegisto, $utilizadorSessao) !== 0) {
        $ligacao->rollBack();
        responder(['ok' => false, 'erro' => 'Operação não autorizada.'], 403);
    }

    if ($horaEntradaNormalizada !== null) {
        $entradaDateTime = DateTime::createFromFormat('Y-m-d H:i:s', $dataEntrada . ' ' . $horaEntradaNormalizada, $timezone);
        $saidaDateTime = DateTime::createFromFormat('Y-m-d H:i:s', $dataEntrada . ' ' . $horaFormatada, $timezone);

        if ($entradaDateTime instanceof DateTime && $saidaDateTime instanceof DateTime && $saidaDateTime < $entradaDateTime) {
            $ligacao->rollBack();
            responder(['ok' => false, 'erro' => 'A hora de saída não pode ser anterior à hora de entrada.'], 422);
        }
    }

    $dataRetornoCompleta = $dataEntrada . ' ' . $horaFormatada;

    $stmtAtualizaSaida = $ligacao->prepare(
        "UPDATE utilizador_entradaesaida
            SET hora_saida = ?
          WHERE id = ?"
    );
    $stmtAtualizaSaida->execute([$horaFormatada, $pendente['id']]);

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

    responder(['ok' => true, 'data' => $dataEntrada, 'horaEntrada' => $horaEntradaNormalizada, 'horaSaida' => $horaFormatada]);
} catch (PDOException $e) {
    if ($ligacao->inTransaction()) {
        $ligacao->rollBack();
    }

    responder(['ok' => false, 'erro' => 'Erro ao registar saída pendente.'], 500);
}
