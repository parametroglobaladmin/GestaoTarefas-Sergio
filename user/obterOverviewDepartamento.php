<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

try {
    require_once '../config_bd.php';
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);

    $tarefaId = isset($_GET['tarefa_id']) ? (int)$_GET['tarefa_id'] : 0;
    $depId    = isset($_GET['departamento_id']) ? (int)$_GET['departamento_id'] : 0;

    if ($tarefaId <= 0 || $depId <= 0) {
        http_response_code(400);
        echo json_encode(['erro' => 'Parâmetros inválidos (tarefa_id / departamento_id).']);
        exit;
    }

    // ---------- helpers ----------
    $fmt = function (int $seg): string {
        $h = intdiv($seg, 3600);
        $m = intdiv($seg % 3600, 60);
        $s = $seg % 60;
        return "{$h}h {$m}m {$s}s";
    };

    // ---------- 1) Janelas desta tarefa neste departamento ----------
    $sqlJan = "
        SELECT data_entrada, COALESCE(data_saida, NOW()) AS data_saida
        FROM departamento_tarefa
        WHERE tarefa_id = :t AND departamento_id = :d
        ORDER BY data_entrada ASC
    ";
    $stJan = $ligacao->prepare($sqlJan);
    $stJan->execute([':t' => $tarefaId, ':d' => $depId]);
    $janelas = $stJan->fetchAll(PDO::FETCH_ASSOC);

    if (!$janelas) {
        echo json_encode([
            'resumo' => ['tempo_total_fmt' => '0h 0m 0s', 'primeira_entrada' => '-', 'ultima_saida' => '-'],
            'funcionarios' => [], 'pausas' => [], 'transicoes' => []
        ]);
        exit;
    }

    $primeiraEntrada = $janelas[0]['data_entrada'];
    $ultimaSaida     = $janelas[count($janelas)-1]['data_saida'];

    // ---------- 2) Tempo total no departamento ----------
    $stTot = $ligacao->prepare("
        SELECT SUM(TIMESTAMPDIFF(SECOND, data_entrada, COALESCE(data_saida, NOW()))) AS seg
        FROM departamento_tarefa
        WHERE tarefa_id = :t AND departamento_id = :d
    ");
    $stTot->execute([':t' => $tarefaId, ':d' => $depId]);
    $segTotal = (int)($stTot->fetchColumn() ?: 0);

    // ---------- 3) Transições desta tarefa no período do departamento ----------
    $trans = [];

    // 3.1) Tenta com coluna DATETIME: dataHora_transicao
    try {
        $sqlTr = "
            SELECT utilizador_antigo AS de, utilizador_novo AS para, dataHora_transicao AS ts
            FROM transicao_tarefas
            WHERE tarefa_id = :t
              AND dataHora_transicao BETWEEN :ini AND :fim
            ORDER BY dataHora_transicao ASC
        ";
        $stTr = $ligacao->prepare($sqlTr);
        $stTr->execute([
            ':t'   => $tarefaId,
            ':ini' => $primeiraEntrada,
            ':fim' => $ultimaSaida
        ]);

        while ($r = $stTr->fetch(PDO::FETCH_ASSOC)) {
            $ts = (string)$r['ts'];
            $trans[] = [
                'de'   => (string)($r['de']   ?? ''),
                'para' => (string)($r['para'] ?? ''),
                'data' => substr($ts, 0, 10),
                'hora' => substr($ts, 11, 8),
            ];
        }
    } catch (Throwable $e1) {
        // 3.2) Fallback: colunas separadas DIA+HORA
        try {
            $sqlTr2 = "
                SELECT utilizador_antigo AS de, utilizador_novo AS para, dia, hora
                FROM transicao_tarefas
                WHERE tarefa_id = :t
                  AND TIMESTAMP(dia, hora) BETWEEN :ini AND :fim
                ORDER BY dia ASC, hora ASC
            ";
            $stTr2 = $ligacao->prepare($sqlTr2);
            $stTr2->execute([
                ':t'   => $tarefaId,
                ':ini' => $primeiraEntrada,
                ':fim' => $ultimaSaida
            ]);

            while ($r = $stTr2->fetch(PDO::FETCH_ASSOC)) {
                $trans[] = [
                    'de'   => (string)($r['de']   ?? ''),
                    'para' => (string)($r['para'] ?? ''),
                    'data' => (string)($r['dia']  ?? ''),
                    'hora' => (string)($r['hora'] ?? ''),
                ];
            }
        } catch (Throwable $e2) {
            // Se ambas falharem, $trans fica vazio.
        }
    }

    // ---------- 3.b) Pausas dessa tarefa no período (uma linha por pausa com utilizador) ----------
    $pausas = [];
    $sqlPausas = "
        SELECT
            p.funcionario AS uid,
            COALESCE(f.nome, CONCAT('Utilizador #', p.funcionario)) AS nome,
            mp.tipo AS tipo_pausa,
            GREATEST(p.data_pausa, :ini) AS inicio,
            LEAST(COALESCE(p.data_retorno, NOW()), :fim) AS fim
        FROM pausas_tarefas p
        LEFT JOIN funcionarios   f  ON f.utilizador = p.funcionario
        LEFT JOIN motivos_pausa  mp ON mp.id = p.motivo_id
        WHERE p.tarefa_id = :t
          AND EXISTS (
              SELECT 1
              FROM departamento_tarefa dt
              WHERE dt.tarefa_id = p.tarefa_id
                AND dt.departamento_id = :d
                AND p.data_pausa <= COALESCE(dt.data_saida, NOW())
                AND COALESCE(p.data_retorno, NOW()) >= dt.data_entrada
          )
          AND COALESCE(p.data_retorno, NOW()) > :ini
          AND p.data_pausa < :fim
        ORDER BY uid ASC, inicio ASC
    ";
    $stPausas = $ligacao->prepare($sqlPausas);
    $stPausas->execute([
        ':t'   => $tarefaId,
        ':d'   => $depId,
        ':ini' => $primeiraEntrada,
        ':fim' => $ultimaSaida
    ]);

    while ($row = $stPausas->fetch(PDO::FETCH_ASSOC)) {
        $ini = (string)$row['inicio'];
        $fim = (string)$row['fim'];

        // calcular segundos da pausa já recortada ao intervalo
        $stSeg = $ligacao->prepare("SELECT TIMESTAMPDIFF(SECOND, :a, :b)");
        $stSeg->execute([':a' => $ini, ':b' => $fim]);
        $seg = (int)$stSeg->fetchColumn();

        if ($seg <= 0) continue;

        $pausas[] = [
            'id'          => (int)$row['uid'],
            'nome'        => (string)$row['nome'],
            'tipo'        => (string)($row['tipo_pausa'] ?? ''),
            'inicio'      => $ini,
            'fim'         => $fim,
            'duracao_fmt' => $fmt($seg),
        ];
    }

    // ---------- 3.c) Tempo líquido por utilizador no departamento ----------
    // Interseção de intervalos (strings datetime -> segundos)
    $overlapSeconds = function(string $a1, string $a2, string $b1, string $b2): int {
        $x1 = strtotime($a1); $x2 = strtotime($a2);
        $y1 = strtotime($b1); $y2 = strtotime($b2);
        if ($x1 === false || $x2 === false || $y1 === false || $y2 === false) return 0;
        $start = max($x1, $y1);
        $end   = min($x2, $y2);
        return max(0, $end - $start);
    };

    // 1) Construir segmentos por utilizador com base nas transições
    $segmentos = [];
    $timeline = [];
    foreach ($trans as $t) {
        $timeline[] = [
            'ts'   => $t['data'] . ' ' . $t['hora'],
            'de'   => $t['de'],
            'para' => $t['para'],
        ];
    }
    usort($timeline, function($a,$b){ return strcmp($a['ts'], $b['ts']); });

    if (!empty($timeline)) {
        // [primeiraEntrada -> primeira transição] = 'de' da primeira
        $first = $timeline[0];
        $uidDe = (int)$first['de'];
        $segmentos[] = ['uid'=>$uidDe, 'ini'=>$primeiraEntrada, 'fim'=>$first['ts']];

        // entre transições = 'para' da transição anterior
        for ($i=0; $i < count($timeline)-1; $i++) {
            $cur  = $timeline[$i];
            $next = $timeline[$i+1];
            $uidPara = (int)$cur['para'];
            $segmentos[] = ['uid'=>$uidPara, 'ini'=>$cur['ts'], 'fim'=>$next['ts']];
        }

        // [última transição -> ultimaSaida] = 'para' da última
        $last = $timeline[count($timeline)-1];
        $uidParaLast = (int)$last['para'];
        $segmentos[] = ['uid'=>$uidParaLast, 'ini'=>$last['ts'], 'fim'=>$ultimaSaida];
    } else {
        // Sem transições: usar utilizadores de departamento_tarefa
        $sqlUsersDT = "
            SELECT DISTINCT utilizador AS uid
            FROM departamento_tarefa
            WHERE tarefa_id = :t AND departamento_id = :d
              AND COALESCE(data_saida, NOW()) > :ini
              AND data_entrada < :fim
        ";
        $stUDT = $ligacao->prepare($sqlUsersDT);
        $stUDT->execute([
            ':t'=>$tarefaId, ':d'=>$depId, ':ini'=>$primeiraEntrada, ':fim'=>$ultimaSaida
        ]);
        $uids = $stUDT->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($uids)) {
            foreach ($uids as $uid) {
                $segmentos[] = ['uid'=>(int)$uid, 'ini'=>$primeiraEntrada, 'fim'=>$ultimaSaida];
            }
        } else {
            $segmentos = [];
        }
    }

    // 2) Pausas por utilizador (no intervalo do departamento)
    $pausasByUser = [];
    foreach ($pausas as $p) {
        $u = (int)$p['id'];
        $pausasByUser[$u][] = ['ini'=>$p['inicio'], 'fim'=>$p['fim']];
    }

    // 3) Janelas de trabalho (entrada/saída) dos utilizadores envolvidos
    $uidsSet = [];
    foreach ($segmentos as $s) $uidsSet[(int)$s['uid']] = true;
    $uidsList = array_keys($uidsSet);

    $workByUser = [];
    if (!empty($uidsList)) {
        $ph = implode(',', array_fill(0, count($uidsList), '?'));
        // Tentativas de nomes de colunas prováveis
        $attempts = [
            ['data_entrada', 'data_saida'],
            ['entrada', 'saida'],
            ['hora_entrada', 'hora_saida'],
            ['inicio', 'fim'],
            ['dataEntrada', 'dataSaida'],
        ];

        $paramsBase = $uidsList;
        $paramsBase[] = $primeiraEntrada;
        $paramsBase[] = $ultimaSaida;

        foreach ($attempts as [$colIn, $colOut]) {
            try {
                $sqlWork = "
                    SELECT utilizador AS uid, $colIn AS ini, COALESCE($colOut, NOW()) AS fim
                    FROM utilizador_entradaesaida
                    WHERE utilizador IN ($ph)
                      AND COALESCE($colOut, NOW()) > ?
                      AND $colIn < ?
                    ORDER BY uid ASC, $colIn ASC
                ";
                $stW = $ligacao->prepare($sqlWork);
                $stW->execute($paramsBase);

                $encontrou = false;
                while ($w = $stW->fetch(PDO::FETCH_ASSOC)) {
                    $u = (int)$w['uid'];
                    $workByUser[$u][] = ['ini'=>$w['ini'], 'fim'=>$w['fim']];
                    $encontrou = true;
                }
                if ($encontrou) { break; } // esta tentativa funcionou
            } catch (Throwable $e) {
                // tenta o próximo par de colunas
            }
        }
    }

    // 4) Agregar tempos por utilizador
    $agg = []; // uid => ['work'=>seg, 'pausas'=>seg]
    foreach ($segmentos as $seg) {
        $uid = (int)$seg['uid'];
        $ini = $seg['ini'];
        $fim = $seg['fim'];

        if (!isset($agg[$uid])) $agg[$uid] = ['work'=>0, 'pausas'=>0];

        // 4.1) Tempo de trabalho = soma das interseções [ini,fim] com janelas de trabalho
        if (!empty($workByUser[$uid])) {
            foreach ($workByUser[$uid] as $w) {
                $agg[$uid]['work'] += $overlapSeconds($ini, $fim, $w['ini'], $w['fim']);
            }
        }

        // 4.2) Pausas = soma das interseções [ini,fim] com pausas do utilizador
        if (!empty($pausasByUser[$uid])) {
            foreach ($pausasByUser[$uid] as $p) {
                $agg[$uid]['pausas'] += $overlapSeconds($ini, $fim, $p['ini'], $p['fim']);
            }
        }
    }

    // 5) Nomes dos utilizadores
    $nomesById = [];
    if (!empty($uidsList)) {
        $ph = implode(',', array_fill(0, count($uidsList), '?'));
        $stN = $ligacao->prepare("SELECT utilizador AS id, nome FROM funcionarios WHERE utilizador IN ($ph)");
        $stN->execute($uidsList);
        while ($r = $stN->fetch(PDO::FETCH_ASSOC)) {
            $nomesById[(int)$r['id']] = (string)$r['nome'];
        }
    }

    // 6) Tabela funcionarios
    $funcionarios = [];
    foreach ($agg as $uid => $vals) {
        $work  = (int)$vals['work'];
        $pause = (int)$vals['pausas'];
        $liq   = max(0, $work - $pause);
        $funcionarios[] = [
            'id'                 => (int)$uid,
            'nome'               => $nomesById[$uid] ?? ("Utilizador #".$uid),
            'total_trabalho_fmt' => $fmt($work),
            'total_fmt'          => $fmt($work), // <- compat com frontend
            'pausas_fmt'         => $fmt($pause),
            'liquido_fmt'        => $fmt($liq),
        ];
    }

    // ---------- 7) Saída ----------
    echo json_encode([
        'resumo' => [
            'tempo_total_fmt' => $fmt($segTotal),
            'primeira_entrada'=> $primeiraEntrada,
            'ultima_saida'    => $ultimaSaida, // <- sem acento
        ],
        'transicoes'   => $trans,
        'pausas'       => $pausas,
        'funcionarios' => $funcionarios
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['erro' => $e->getMessage()]);
}
    