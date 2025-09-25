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
    $detectCol = function(PDO $pdo, string $table, array $candidates): ?string {
        if (!$candidates) return null;
        $in = implode(",", array_fill(0, count($candidates), "?"));
        $sql = "SELECT COLUMN_NAME 
                FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                  AND TABLE_NAME = ? 
                  AND COLUMN_NAME IN ($in)";
        $st  = $pdo->prepare($sql);
        $st->execute(array_merge([$table], $candidates));
        $found = $st->fetchAll(PDO::FETCH_COLUMN);
        foreach ($candidates as $c) if (in_array($c, $found, true)) return $c;
        return null;
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
            'funcionarios' => [], 'pausasPorTipo' => [], 'transicoes' => []
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

    // ---------- detetar colunas ----------
    // possível coluna de utilizador em departamento_tarefa
    $dtUserCol = $detectCol($ligacao, 'departamento_tarefa',
        ['utilizador_id','funcionario_id','id_funcionario','user_id','numero','id_user','id_colaborador','colaborador_id']
    );
    // possível coluna de utilizador em pausas_tarefas
    $pUserCol = $detectCol($ligacao, 'pausas_tarefas',
        ['utilizador_id','funcionario_id','id_funcionario','user_id','numero','id_user','id_colaborador','colaborador_id']
    );

    // chave e nome em funcionarios
    $funcKeyCol  = $detectCol($ligacao, 'funcionarios', ['numero','id','id_funcionario','id_func','codigo']) ?? 'id';
    $funcNameCol = $detectCol($ligacao, 'funcionarios', ['nome','funcionario','acronimo']) ?? 'nome';

    // ---------- 3) Funcionários (tempo bruto por utilizador) ----------
    $funcs = [];
    if ($dtUserCol !== null) {
        // caminho A: temos utilizador na tabela departamento_tarefa
        $sqlFunc = "
            SELECT 
              dt.`$dtUserCol` AS uid,
              COALESCE(f.`$funcNameCol`, CONCAT('Utilizador #', dt.`$dtUserCol`)) AS nome,
              SUM(TIMESTAMPDIFF(SECOND, dt.data_entrada, COALESCE(dt.data_saida, NOW()))) AS seg
            FROM departamento_tarefa dt
            LEFT JOIN funcionarios f 
              ON f.`$funcKeyCol` = dt.`$dtUserCol`
            WHERE dt.tarefa_id = :t AND dt.departamento_id = :d
            GROUP BY uid, nome
            ORDER BY seg DESC
        ";
        $stFunc = $ligacao->prepare($sqlFunc);
        $stFunc->execute([':t' => $tarefaId, ':d' => $depId]);
        $funcs = $stFunc->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($pUserCol !== null) {
        // caminho B (fallback): inferir utilizadores a partir das pausas no intervalo do departamento
        $sqlFuncFallback = "
            SELECT 
              p.`$pUserCol` AS uid,
              COALESCE(f.`$funcNameCol`, CONCAT('Utilizador #', p.`$pUserCol`)) AS nome,
              0 AS seg
            FROM pausas_tarefas p
            LEFT JOIN funcionarios f ON f.`$funcKeyCol` = p.`$pUserCol`
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
            GROUP BY uid, nome
            ORDER BY nome ASC
        ";
        $stFF = $ligacao->prepare($sqlFuncFallback);
        $stFF->execute([':t' => $tarefaId, ':d' => $depId, ':ini' => $primeiraEntrada, ':fim' => $ultimaSaida]);
        $funcs = $stFF->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // sem forma de identificar utilizadores
        $funcs = [];
    }

    // ---------- 4) Pausas por tipo dentro do depto ----------
    $stP = $ligacao->prepare("
        SELECT mp.tipo,
               COUNT(*) AS qtd,
               SUM(
                 CASE 
                   WHEN GREATEST(p.data_pausa, :ini) < LEAST(COALESCE(p.data_retorno, NOW()), :fim)
                   THEN TIMESTAMPDIFF(
                          SECOND,
                          GREATEST(p.data_pausa, :ini),
                          LEAST(COALESCE(p.data_retorno, NOW()), :fim)
                        )
                   ELSE 0
                 END
               ) AS seg_total
        FROM pausas_tarefas p
        JOIN motivos_pausa mp ON mp.id = p.motivo_id
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
        GROUP BY mp.tipo
        ORDER BY seg_total DESC
    ");
    $stP->execute([
        ':t' => $tarefaId, ':d' => $depId,
        ':ini' => $primeiraEntrada, ':fim' => $ultimaSaida
    ]);
    $pausasTipo = [];
    while ($r = $stP->fetch(PDO::FETCH_ASSOC)) {
        $seg = (int)($r['seg_total'] ?? 0);
        if ($seg <= 0 && (int)$r['qtd'] === 0) continue;
        $pausasTipo[] = [
            'tipo' => $r['tipo'],
            'qtd'  => (int)$r['qtd'],
            'total_fmt' => $fmt($seg)
        ];
    }

    // ---------- 5) Pausas por utilizador (para líquido) ----------
    $pausasPorUser = [];
    if ($pUserCol !== null) {
        $stPF = $ligacao->prepare("
            SELECT p.`$pUserCol` AS uid,
                   SUM(
                     CASE 
                       WHEN GREATEST(p.data_pausa, :ini) < LEAST(COALESCE(p.data_retorno, NOW()), :fim)
                       THEN TIMESTAMPDIFF(
                              SECOND,
                              GREATEST(p.data_pausa, :ini),
                              LEAST(COALESCE(p.data_retorno, NOW()), :fim)
                            )
                       ELSE 0
                     END
                   ) AS seg
            FROM pausas_tarefas p
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
            GROUP BY uid
        ");
        $stPF->execute([
            ':t' => $tarefaId, ':d' => $depId,
            ':ini' => $primeiraEntrada, ':fim' => $ultimaSaida
        ]);
        foreach ($stPF->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pausasPorUser[(int)$row['uid']] = (int)($row['seg'] ?? 0);
        }
    }

        // ---------- 6) Transições dentro do período deste departamento ----------
    // detetar nomes de colunas em transicao_tarefas
    $trFromCol = $detectCol($ligacao, 'transicao_tarefas', [
        'de','origem','de_departamento','depto_de','de_utilizador','de_user','depto_origem'
    ]);
    $trToCol = $detectCol($ligacao, 'transicao_tarefas', [
        'para','destino','para_departamento','depto_para','para_utilizador','para_user','depto_destino'
    ]);

    // data/hora podem estar numa coluna datetime única ou separadas (dia/hora)
    $trDTCol   = $detectCol($ligacao, 'transicao_tarefas', ['data_transicao','datahora','timestamp']);
    $trDateCol = $detectCol($ligacao, 'transicao_tarefas', ['dia','data','data_dia']);
    $trTimeCol = $detectCol($ligacao, 'transicao_tarefas', ['hora','time','data_hora']);

    $trans = [];

    // Se não houver nenhuma forma de data, não conseguimos filtrar por período – devolvemos vazio
    if ($trDTCol === null && ($trDateCol === null || $trTimeCol === null)) {
        $trans = [];
    } else {
        if ($trDTCol !== null) {
            // Caso 1: há uma coluna datetime (ex.: data_transicao)
            $sqlTr = "
                SELECT
                    ".($trFromCol ? "`$trFromCol`" : "NULL")." AS col_de,
                    ".($trToCol   ? "`$trToCol`"   : "NULL")." AS col_para,
                    `$trDTCol` AS ts
                FROM transicao_tarefas
                WHERE tarefa_id = :t
                  AND `$trDTCol` BETWEEN :ini AND :fim
                ORDER BY `$trDTCol` ASC
            ";
            $stTr = $ligacao->prepare($sqlTr);
            $stTr->execute([':t'=>$tarefaId, ':ini'=>$primeiraEntrada, ':fim'=>$ultimaSaida]);

            while ($r = $stTr->fetch(PDO::FETCH_ASSOC)) {
                $ts = (string)$r['ts'];
                $d  = substr($ts, 0, 10);
                $h  = substr($ts, 11, 8);
                $trans[] = [
                    'de'   => $r['col_de'] ?? '',
                    'para' => $r['col_para'] ?? '',
                    'data' => $d,
                    'hora' => $h
                ];
            }
        } else {
            // Caso 2: colunas separadas de data e hora (ex.: dia + hora)
            // Construímos um timestamp virtual para filtrar
            $sqlTr = "
                SELECT
                    ".($trFromCol ? "`$trFromCol`" : "NULL")." AS col_de,
                    ".($trToCol   ? "`$trToCol`"   : "NULL")." AS col_para,
                    `$trDateCol` AS dia_col,
                    `$trTimeCol` AS hora_col,
                    TIMESTAMP(`$trDateCol`, `$trTimeCol`) AS ts
                FROM transicao_tarefas
                WHERE tarefa_id = :t
                  AND TIMESTAMP(`$trDateCol`, `$trTimeCol`) BETWEEN :ini AND :fim
                ORDER BY ts ASC
            ";
            $stTr = $ligacao->prepare($sqlTr);
            $stTr->execute([':t'=>$tarefaId, ':ini'=>$primeiraEntrada, ':fim'=>$ultimaSaida]);

            while ($r = $stTr->fetch(PDO::FETCH_ASSOC)) {
                $trans[] = [
                    'de'   => $r['col_de'] ?? '',
                    'para' => $r['col_para'] ?? '',
                    'data' => (string)$r['dia_col'],
                    'hora' => (string)$r['hora_col']
                ];
            }
        }
    }


    // ---------- 7) Montar funcionários ----------
    $funcOut = [];
    foreach ($funcs as $f) {
        $uid      = (int)$f['uid'];
        $nome     = (string)($f['nome'] ?? ("Utilizador #".$uid));
        $segBruto = (int)($f['seg'] ?? 0);
        $segPause = (int)($pausasPorUser[$uid] ?? 0);
        $segLiq   = max(0, $segBruto - $segPause);

        $funcOut[] = [
            'id'         => $uid,
            'nome'       => $nome,
            'total_fmt'  => $fmt($segBruto),
            'pausas_fmt' => $fmt($segPause),
            'liquido_fmt'=> $fmt($segLiq),
        ];
    }

    echo json_encode([
        'resumo' => [
            'tempo_total_fmt' => $fmt($segTotal),
            'primeira_entrada'=> $primeiraEntrada,
            'ultima_saida'    => $ultimaSaida,
        ],
        'funcionarios'  => $funcOut,
        'pausasPorTipo' => $pausasTipo,
        'transicoes'    => $trans
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['erro' => $e->getMessage()]);
}
