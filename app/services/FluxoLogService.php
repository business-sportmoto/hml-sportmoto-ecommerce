<?php
/**
 * app/services/FluxoLogService.php
 *
 * Observabilidade do motor: registra cada passo das jornadas e serve as
 * leituras agregadas (contadores dos balões, timeline, KPIs, purge).
 *
 * REGRA DE OURO: o log NUNCA derruba o motor. Toda escrita é try/catch total
 * e falha em silêncio — uma jornada de cliente vale mais que uma linha de log.
 *
 * É log de DOMÍNIO (consultável, vira tela), separado do LogService (que é
 * log técnico de aplicação).
 */
class FluxoLogService
{
    // ─────────────────────────────────────────────────────────────────────────
    // ESCRITA (chamada pelo motor)
    // ─────────────────────────────────────────────────────────────────────────

    /** Marco de início de jornada. */
    public static function inicio(PDO $db, int $execId, int $fluxoId, int $versao, ?int $clienteId, string $tipoTrigger): void
    {
        self::gravar($db, $execId, $fluxoId, $versao, $clienteId, '__inicio', $tipoTrigger, 'iniciada', null, 0);
    }

    /** Um passo executado (a porta pela qual o nó saiu, ou marcador especial). */
    public static function passo(
        PDO $db, array $exec, string $noChave, string $tipoNo,
        string $porta, ?string $detalhe, float $duracaoMs
    ): void {
        // Portas internas viram nomes legíveis no log
        $mapa = [
            '__dormir'          => '__dormir',
            '__encerrar'        => 'saida',
            '__erro'            => '__erro',
            '__aguardar_evento' => '__aguardar',
        ];
        $porta = $mapa[$porta] ?? $porta;

        self::gravar(
            $db,
            (int)($exec['id'] ?? 0),
            (int)($exec['fluxo_id'] ?? 0),
            (int)($exec['versao'] ?? 0),
            isset($exec['cliente_id']) && $exec['cliente_id'] !== null ? (int)$exec['cliente_id'] : null,
            $noChave,
            $tipoNo,
            $porta,
            $detalhe,
            (int)round(max(0, min(65000, $duracaoMs)))
        );
    }

    /** Marco de fim de jornada (porta = status final: concluido/saiu/erro). */
    public static function fim(PDO $db, array $exec, string $status): void
    {
        $detalhe = null;
        if ($status === 'erro' && !empty($exec['erro_detalhe'])) {
            $detalhe = mb_substr((string)$exec['erro_detalhe'], 0, 200);
        }
        self::gravar(
            $db,
            (int)($exec['id'] ?? 0),
            (int)($exec['fluxo_id'] ?? 0),
            (int)($exec['versao'] ?? 0),
            isset($exec['cliente_id']) && $exec['cliente_id'] !== null ? (int)$exec['cliente_id'] : null,
            '__fim',
            '__fim',
            $status,
            $detalhe,
            0
        );
    }

    private static function gravar(
        PDO $db, int $execId, int $fluxoId, int $versao, ?int $clienteId,
        string $noChave, string $tipoNo, string $porta, ?string $detalhe, int $durMs
    ): void {
        try {
            $st = $db->prepare(
                "INSERT INTO fluxo_passos_log
                 (execucao_id, fluxo_id, versao, cliente_id, no_chave, tipo_no, porta, detalhe, duracao_ms)
                 VALUES (:e, :f, :v, :c, :no, :t, :p, :d, :ms)"
            );
            $st->execute([
                ':e'  => $execId,
                ':f'  => $fluxoId,
                ':v'  => $versao,
                ':c'  => $clienteId,
                ':no' => mb_substr($noChave, 0, 40),
                ':t'  => mb_substr($tipoNo, 0, 40),
                ':p'  => mb_substr($porta, 0, 24),
                ':d'  => $detalhe !== null ? mb_substr($detalhe, 0, 200) : null,
                ':ms' => $durMs,
            ]);
        } catch (Throwable $e) {
            // silêncio: log não derruba o motor
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LEITURA (chamada pelo admin)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Contadores por nó da versão publicada — o que os balões do canvas exibem.
     * @return array no_chave => [total, portas{porta:n}, ms_medio, erros[]]
     */
    public static function statsPorNo(PDO $db, int $fluxoId, int $versao): array
    {
        $out = [];
        try {
            $st = $db->prepare(
                "SELECT no_chave, porta, COUNT(*) AS n, AVG(duracao_ms) AS ms
                 FROM fluxo_passos_log
                 WHERE fluxo_id = :f AND versao = :v AND SUBSTR(no_chave, 1, 2) <> '__'
                 GROUP BY no_chave, porta"
            );
            $st->execute([':f' => $fluxoId, ':v' => $versao]);

            $somaMs = []; $somaN = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ch = $r['no_chave'];
                if (!isset($out[$ch])) $out[$ch] = ['total' => 0, 'portas' => [], 'ms_medio' => 0, 'erros' => []];
                $n = (int)$r['n'];
                $out[$ch]['total'] += $n;
                $out[$ch]['portas'][$r['porta']] = ($out[$ch]['portas'][$r['porta']] ?? 0) + $n;
                $somaMs[$ch] = ($somaMs[$ch] ?? 0) + ((float)$r['ms'] * $n);
                $somaN[$ch]  = ($somaN[$ch]  ?? 0) + $n;
            }
            foreach ($out as $ch => $_) {
                $out[$ch]['ms_medio'] = $somaN[$ch] > 0 ? (int)round($somaMs[$ch] / $somaN[$ch]) : 0;
            }

            // Últimos erros por nó (para o painel lateral)
            $st = $db->prepare(
                "SELECT no_chave, detalhe, criado_em
                 FROM fluxo_passos_log
                 WHERE fluxo_id = :f AND versao = :v AND porta = '__erro'
                 ORDER BY id DESC LIMIT 30"
            );
            $st->execute([':f' => $fluxoId, ':v' => $versao]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ch = $r['no_chave'];
                if (isset($out[$ch]) && count($out[$ch]['erros']) < 3) {
                    $out[$ch]['erros'][] = ['detalhe' => $r['detalhe'], 'quando' => $r['criado_em']];
                }
            }
        } catch (Throwable $e) {}
        return $out;
    }

    /**
     * Timeline geral, paginada por cursor (id descendente).
     * Filtros: fluxo_id, so_erros, cliente_id.
     */
    public static function atividade(PDO $db, array $filtros = [], int $limite = 50, int $antesDe = 0): array
    {
        $itens = [];
        try {
            $limite = max(10, min(200, $limite));
            $where  = [];
            $params = [];

            if ($antesDe > 0)                { $where[] = 'l.id < :cursor';     $params[':cursor'] = $antesDe; }
            if (!empty($filtros['fluxo_id'])){ $where[] = 'l.fluxo_id = :fid';  $params[':fid'] = (int)$filtros['fluxo_id']; }
            if (!empty($filtros['cliente_id'])) { $where[] = 'l.cliente_id = :cid'; $params[':cid'] = (int)$filtros['cliente_id']; }
            if (!empty($filtros['so_erros'])){ $where[] = "l.porta = '__erro'"; }

            $sql = "SELECT l.*, f.nome AS fluxo_nome, u.nome AS cliente_nome
                    FROM fluxo_passos_log l
                    LEFT JOIN fluxo_v2 f ON f.id = l.fluxo_id
                    LEFT JOIN clientes c ON c.id = l.cliente_id
                    LEFT JOIN usuarios u ON u.id = c.usuario_id"
                 . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
                 . " ORDER BY l.id DESC LIMIT {$limite}";

            $st = $db->prepare($sql);
            foreach ($params as $k => $v) $st->bindValue($k, $v, PDO::PARAM_INT);
            $st->execute();
            $itens = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
        return $itens;
    }

    /** KPIs do topo da tela de atividade. */
    public static function kpis(PDO $db): array
    {
        $k = ['iniciadas_hoje' => 0, 'envios_hoje' => 0, 'erros_24h' => 0,
              'ativas' => 0, 'dormindo' => 0, 'aguardando' => 0];
        try {
            $hoje = date('Y-m-d 00:00:00');
            $k['iniciadas_hoje'] = (int)self::col($db,
                "SELECT COUNT(*) FROM fluxo_passos_log WHERE no_chave='__inicio' AND criado_em >= :h", [':h' => $hoje]);
            $k['envios_hoje'] = (int)self::col($db,
                "SELECT COUNT(*) FROM fluxo_passos_log
                 WHERE tipo_no IN ('acao_email','acao_whatsapp','acao_notificacao')
                   AND porta = 'saida' AND detalhe IS NULL AND criado_em >= :h", [':h' => $hoje]);
            $k['erros_24h'] = (int)self::col($db,
                "SELECT COUNT(*) FROM fluxo_passos_log WHERE porta='__erro'
                   AND criado_em >= :d", [':d' => date('Y-m-d H:i:s', time() - 86400)]);

            $st = $db->query("SELECT status, COUNT(*) n FROM fluxo_execucoes
                              WHERE status IN ('ativo','dormindo','aguardando_evento') GROUP BY status");
            foreach (($st ? $st->fetchAll(PDO::FETCH_ASSOC) : []) as $r) {
                if ($r['status'] === 'ativo')             $k['ativas']     = (int)$r['n'];
                if ($r['status'] === 'dormindo')          $k['dormindo']   = (int)$r['n'];
                if ($r['status'] === 'aguardando_evento') $k['aguardando'] = (int)$r['n'];
            }
        } catch (Throwable $e) {}
        return $k;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PURGE (1×/dia, chamado pelo worker)
    // ─────────────────────────────────────────────────────────────────────────

    /** Apaga logs além da retenção. Roda no máximo 1×/dia. @return int apagados */
    public static function purgarSeDevido(PDO $db): int
    {
        try {
            $hoje = date('Y-m-d');
            $ultimo = (string)self::col($db,
                "SELECT valor FROM fluxo_motor_config WHERE chave='fluxo_log_purge_ultimo'", []);
            if ($ultimo === $hoje) return 0;

            $dias = (int)self::col($db,
                "SELECT valor FROM fluxo_motor_config WHERE chave='fluxo_log_retencao_dias'", []);
            if ($dias < 7) $dias = 90; // proteção contra config quebrada

            $corte = date('Y-m-d H:i:s', strtotime("-{$dias} days"));
            $total = 0;
            // Em lotes, para não segurar lock em tabelas grandes
            for ($i = 0; $i < 20; $i++) {
                $st = $db->prepare("DELETE FROM fluxo_passos_log WHERE criado_em < :c LIMIT 5000");
                $st->execute([':c' => $corte]);
                $n = $st->rowCount();
                $total += $n;
                if ($n < 5000) break;
            }

            $db->prepare(
                "INSERT INTO fluxo_motor_config (chave, valor) VALUES ('fluxo_log_purge_ultimo', :v)
                 ON DUPLICATE KEY UPDATE valor = :v2"
            )->execute([':v' => $hoje, ':v2' => $hoje]);

            return $total;
        } catch (Throwable $e) {
            return 0;
        }
    }

    private static function col(PDO $db, string $sql, array $params)
    {
        try {
            $st = $db->prepare($sql);
            $st->execute($params);
            $v = $st->fetchColumn();
            return $v === false ? null : $v;
        } catch (Throwable $e) { return null; }
    }
}
