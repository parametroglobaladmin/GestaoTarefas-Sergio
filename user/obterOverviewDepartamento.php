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

    $sanitizeUid = function ($u): ?string {
        $u = trim((string)$u);
        if ($u === '' || strtoupper($u) === 'EM ESPERA') return null;
        return $u; // manter string (acrómino ou número em string)
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
            $deRaw   = (string)($r['de']   ?? '');
            $paraRaw = (string)($r['para'] ?? '');

            // No JSON de saída queremos ver "EM ESPERA" se vier vazio
            $trans[] = [
                'de'   => $deRaw,
                'para' => $paraRaw !== '' ? $paraRaw : 'EM ESPERA',
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
                $deRaw   = (string)($r['de']   ?? '');
                $paraRaw = (string)($r['para'] ?? '');
                $dia  = (string)($r['dia']  ?? '');
                $hora = (string)($r['hora'] ?? '');

                $trans[] = [
                    'de'   => $deRaw,
                    'para' => $paraRaw !== '' ? $paraRaw : 'EM ESPERA',
                    'data' => $dia,
                    'hora' => $hora,
                ];
            }

        } catch (Throwable $e2) {
            // Se ambas falharem, $trans fica vazio.
        }
    }

    // ---------- 3.b) Pausas dessa tarefa no período ----------
    $pausas = [];
    $sqlPausas = "
        SELECT
            p.funcionario AS uid,
            COALESCE(f.nome, CONCAT('Utilizador #', p.funcionario)) AS nome,
            mp.codigo AS tipo_pausa,
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

        $stSeg = $ligacao->prepare("SELECT TIMESTAMPDIFF(SECOND, :a, :b)");
        $stSeg->execute([':a' => $ini, ':b' => $fim]);
        $seg = (int)$stSeg->fetchColumn();

        if ($seg <= 0) continue;

        $pausas[] = [
            'id'          => (string)$row['uid'],            // <- manter STRING
            'nome'        => (string)$row['nome'],
            'tipo'        => (string)($row['tipo_pausa'] ?? ''),
            'inicio'      => $ini,
            'fim'         => $fim,
            'duracao_fmt' => $fmt($seg),
        ];
    }

    // ---------- 3.c) Tempo líquido por utilizador ----------
    $overlapSeconds = function(string $a1, string $a2, string $b1, string $b2): int {
        $x1 = strtotime($a1); $x2 = strtotime($a2);
        $y1 = strtotime($b1); $y2 = strtotime($b2);
        if ($x1 === false || $x2 === false || $y1 === false || $y2 === false) return 0;
        $start = max($x1, $y1);
        $end   = min($x2, $y2);
        return max(0, $end - $start);
    };

    // 1) Construir segmentos de tempo por utilizador (a partir das transições)
    $segmentos = [];
    $timeline = [];
    foreach ($trans as $t) {
        $ts = $t['data'] . ' ' . $t['hora'];
        $timeline[] = [
            'ts'   => $ts,
            'de'   => $sanitizeUid($t['de']),
            'para' => $sanitizeUid($t['para']), // 'EM ESPERA' vira null aqui
        ];
    }
    usort($timeline, fn($a,$b) => strcmp($a['ts'], $b['ts']));

    if (!empty($timeline)) {
        // [primeiraEntrada -> primeira transição] = 'de' da primeira (se válido)
        $first = $timeline[0];
        if ($first['de'] !== null) {
            $segmentos[] = ['uid'=>$first['de'], 'ini'=>$primeiraEntrada, 'fim'=>$first['ts']];
        }

        // entre transições = 'para' da transição anterior (se válido)
        for ($i=0; $i < count($timeline)-1; $i++) {
            $cur  = $timeline[$i];
            $next = $timeline[$i+1];
            if ($cur['para'] !== null) {
                $segmentos[] = ['uid'=>$cur['para'], 'ini'=>$cur['ts'], 'fim'=>$next['ts']];
            }
        }

        // [última transição -> ultimaSaida] = 'para' da última (se válido)
        $last = $timeline[count($timeline)-1];
        if ($last['para'] !== null) {
            $segmentos[] = ['uid'=>$last['para'], 'ini'=>$last['ts'], 'fim'=>$ultimaSaida];
        }
    }

    // Se não houve segmentos válidos pelas transições, usar utilizadores de departamento_tarefa
    if (empty($segmentos)) {
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

        foreach ($uids as $uid) {
            $uidStr = $sanitizeUid($uid);
            if ($uidStr !== null) {
                $segmentos[] = ['uid'=>$uidStr, 'ini'=>$primeiraEntrada, 'fim'=>$ultimaSaida];
            }
        }
    }

    // 2) Pausas agrupadas por utilizador (keys string)
    $pausasByUser = [];
    foreach ($pausas as $p) {
        $u = (string)$p['id'];
        $pausasByUser[$u][] = ['ini'=>$p['inicio'], 'fim'=>$p['fim']];
    }

    // 3) Janelas de trabalho dos utilizadores (entrada/saída)
    $uidsSet = [];
    foreach ($segmentos as $s) $uidsSet[(string)$s['uid']] = true;
    $uidsList = array_keys($uidsSet);

    $workByUser = [];
    if (!empty($uidsList)) {
        $ph = implode(',', array_fill(0, count($uidsList), '?'));
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
                    $u = (string)$w['uid'];
                    $workByUser[$u][] = ['ini'=>$w['ini'], 'fim'=>$w['fim']];
                    $encontrou = true;
                }
                if ($encontrou) break;
            } catch (Throwable $e) {
                // tenta o próximo par de colunas
            }
        }
    }

    // 4) Agregar tempos por utilizador (keys string)
    $agg = [];
    foreach ($segmentos as $seg) {
        $uid = (string)$seg['uid'];
        $ini = $seg['ini'];
        $fim = $seg['fim'];

        if (!isset($agg[$uid])) $agg[$uid] = ['work'=>0, 'pausas'=>0];

        if (!empty($workByUser[$uid])) {
            foreach ($workByUser[$uid] as $w) {
                $agg[$uid]['work'] += $overlapSeconds($ini, $fim, $w['ini'], $w['fim']);
            }
        }

        if (!empty($pausasByUser[$uid])) {
            foreach ($pausasByUser[$uid] as $p) {
                $agg[$uid]['pausas'] += $overlapSeconds($ini, $fim, $p['ini'], $p['fim']);
            }
        }
    }

    // 5) Nomes dos utilizadores (map por string)
    $nomesById = [];
    if (!empty($uidsList)) {
        $ph = implode(',', array_fill(0, count($uidsList), '?'));
        $stN = $ligacao->prepare("SELECT utilizador AS id, nome FROM funcionarios WHERE utilizador IN ($ph)");
        $stN->execute($uidsList);
        while ($r = $stN->fetch(PDO::FETCH_ASSOC)) {
            $nomesById[(string)$r['id']] = (string)$r['nome'];
        }
    }
    // 🔹 Agrupar pausas por utilizador
    $pausasPorUser = [];
    foreach ($pausas as $p) {
        $uid = (string)$p['id'];
        if (!isset($pausasPorUser[$uid])) {
            $pausasPorUser[$uid] = [
                'nome' => $p['nome'],
                'pausas' => []
            ];
        }
        $pausasPorUser[$uid]['pausas'][] = [
            'tipo' => $p['tipo'],
            'inicio' => $p['inicio'],
            'fim' => $p['fim'],
            'duracao_fmt' => $p['duracao_fmt']
        ];
    }


        // 6) Construir tabela final
    $funcionarios = [];
    foreach ($agg as $uid => $vals) {
        $work  = (int)$vals['work'];
        $pause = (int)$vals['pausas'];
        $liq   = max(0, $work - $pause);
        $funcionarios[] = [
            'id'                 => (string)$uid,
            'nome'               => $nomesById[$uid] ?? ("Utilizador #".$uid),
            'total_trabalho_fmt' => $fmt($work),
            'total_fmt'          => $fmt($work), // compat frontend
            'pausas_fmt'         => $fmt($pause),
            'liquido_fmt'        => $fmt($liq),
        ];
    }

    // 🔹 FILTRAR funcionários com todos os tempos a 0
    $funcionarios = array_filter($funcionarios, function($f) {
        return !(
            $f['total_trabalho_fmt'] === '0h 0m 0s' &&
            $f['pausas_fmt'] === '0h 0m 0s' &&
            $f['liquido_fmt'] === '0h 0m 0s'
        );
    });

    // ---------- 7) Saída JSON ----------
    echo json_encode([
        'resumo' => [
            'tempo_total_fmt' => $fmt($segTotal),
            'primeira_entrada'=> $primeiraEntrada,
            'ultima_saida'    => $ultimaSaida,
        ],
        'transicoes'   => $trans,        // mantém "EM ESPERA" apenas para exibição
        'pausas'       => $pausasPorUser,
        'funcionarios' => array_values($funcionarios) // reindexar para evitar chaves perdidas
    ]);


} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['erro' => $e->getMessage()]);
}
