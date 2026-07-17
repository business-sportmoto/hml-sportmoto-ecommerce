<?php
/**
 * app/services/FluxoMotor.php
 *
 * Executor do grafo. Para cada execução pronta:
 *   1. Verifica exit conditions do fluxo (comprou → sai)
 *   2. Executa o nó atual via registry → recebe a porta
 *   3. Segue a conexão até o próximo nó
 *   4. Repete até DORMIR / ENCERRAR / ERRO (máx MAX_PASSOS_CICLO por rodada)
 *
 * Requer: require_once de FluxoNoRegistry.php (feito no construtor —
 * as classes de nó vivem lá dentro e o autoload não as encontraria).
 */
class FluxoMotor
{
    /** Passos máximos por execução por ciclo (proteção anti-loop) */
    private const MAX_PASSOS_CICLO = 50;

    /** @var PDO */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        // Garante as classes de nó no contexto (vivem dentro do registry)
        if (!class_exists('FluxoNoRegistry', false)) {
            $base = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2);
            require_once $base . '/app/services/FluxoNoRegistry.php';
        }
    }

    // =========================================================================
    // ENTRADA
    // =========================================================================

    /**
     * Inicia uma execução num fluxo publicado.
     *
     * @param int         $fluxoId
     * @param int|null    $clienteId
     * @param string|null $visitanteToken (anônimo)
     * @param array       $contexto       produto_id, _email, ...
     * @return int|null   id da execução ou null (fluxo inapto / reentrada bloqueada)
     */
    public function iniciarExecucao(
        int $fluxoId, ?int $clienteId, ?string $visitanteToken = null, array $contexto = []
    ): ?int {
        try {
            $fluxo = $this->carregarFluxo($fluxoId);
            if (!$fluxo || $fluxo['status'] !== 'publicado' || (int)$fluxo['versao_publicada'] < 1) {
                return null;
            }
            $versao = (int)$fluxo['versao_publicada'];

            if (!$this->reentradaPermitida($fluxo, $clienteId, $visitanteToken)) {
                return null;
            }

            // Nó de entrada = o trigger da versão publicada
            $st = $this->db->prepare(
                "SELECT chave, tipo_no FROM fluxo_nos
                 WHERE fluxo_id=:f AND versao=:v"
            );
            $st->execute([':f' => $fluxoId, ':v' => $versao]);
            $trigger = null;
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $no) {
                if (FluxoNoRegistry::ehTrigger($no['tipo_no'])) { $trigger = $no['chave']; break; }
            }
            if (!$trigger) return null;

            $ins = $this->db->prepare(
                "INSERT INTO fluxo_execucoes
                 (fluxo_id, versao, cliente_id, visitante_token, no_atual, status, contexto_json)
                 VALUES (:f,:v,:c,:t,:no,'ativo',:ctx)"
            );
            $ins->execute([
                ':f'   => $fluxoId,
                ':v'   => $versao,
                ':c'   => $clienteId,
                ':t'   => $visitanteToken,
                ':no'  => $trigger,
                ':ctx' => json_encode($contexto, JSON_UNESCAPED_UNICODE),
            ]);
            return (int)$this->db->lastInsertId() ?: null;

        } catch (Throwable $e) {
            $this->logErro('iniciarExecucao', $e);
            return null;
        }
    }

    // =========================================================================
    // PROCESSAMENTO (chamado pelo worker)
    // =========================================================================

    /**
     * Processa execuções prontas (ativas ou que acordaram).
     * @return array estatísticas
     */
    public function processarExecucoes(int $limite = 100, int $maxSegundos = 120): array
    {
        $stats = ['processadas' => 0, 'concluidas' => 0, 'dormindo' => 0,
                  'sairam' => 0, 'erros' => 0, 'aguardando' => 0];
        $inicio = time();

        try {
            $st = $this->db->prepare(
                "SELECT * FROM fluxo_execucoes
                 WHERE status = 'ativo'
                    OR (status = 'dormindo' AND dormir_ate IS NOT NULL AND dormir_ate <= NOW())
                 ORDER BY id ASC
                 LIMIT " . max(1, min(500, $limite))
            );
            $st->execute();
            $execucoes = $st->fetchAll(PDO::FETCH_ASSOC);

            foreach ($execucoes as $row) {
                if ((time() - $inicio) >= $maxSegundos) break;
                $resultado = $this->processarUma($row);
                $stats['processadas']++;
                $stats[$resultado] = ($stats[$resultado] ?? 0) + 1;
            }
        } catch (Throwable $e) {
            $this->logErro('processarExecucoes', $e);
        }

        return $stats;
    }

    /**
     * Caminha uma execução no grafo até parar.
     * @return string 'concluidas'|'dormindo'|'sairam'|'erros'
     */
    private function processarUma(array $row): string
    {
        // Claim otimista (evita corrida entre workers)
        $claim = $this->db->prepare(
            "UPDATE fluxo_execucoes SET status='ativo', dormir_ate=NULL
             WHERE id=:id AND status IN ('ativo','dormindo')"
        );
        $claim->execute([':id' => $row['id']]);
        if ($claim->rowCount() === 0 && $row['status'] !== 'ativo') return 'erros';

        $exec = [
            'id'         => (int)$row['id'],
            'fluxo_id'   => (int)$row['fluxo_id'],
            'versao'     => (int)$row['versao'],
            'cliente_id' => $row['cliente_id'] !== null ? (int)$row['cliente_id'] : null,
            'no_atual'   => (string)$row['no_atual'],
            'contexto'   => json_decode($row['contexto_json'] ?? '{}', true) ?: [],
            'criado_em'  => $row['criado_em'],
            'dormir_ate' => null,
            'erro_detalhe' => null,
            'evento_aguardado' => null,   // ← Fase 3A
            'timeout_em'       => null,   // ← Fase 3A
        ];

        $fluxo    = $this->carregarFluxo($exec['fluxo_id']);
        $fluxoCfg = json_decode($fluxo['config_json'] ?? '{}', true) ?: [];
        $nos      = $this->carregarNos($exec['fluxo_id'], $exec['versao']);
        $conexoes = $this->carregarConexoes($exec['fluxo_id'], $exec['versao']);

        // ── Exit conditions: evento de saída desde que entrou? ──
        if ($this->deveSair($fluxoCfg, $exec)) {
            $this->finalizar($exec, 'saiu');
            return 'sairam';
        }

        $passos = 0;
        while ($passos++ < self::MAX_PASSOS_CICLO) {
            $chave = $exec['no_atual'];
            $no    = $nos[$chave] ?? null;
            if (!$no) {
                $exec['erro_detalhe'] = "nó '$chave' não existe na v{$exec['versao']}";
                $this->finalizar($exec, 'erro');
                return 'erros';
            }

            $handler = FluxoNoRegistry::obter($no['tipo_no']);
            if (!$handler) {
                $exec['erro_detalhe'] = "tipo '{$no['tipo_no']}' desconhecido";
                $this->finalizar($exec, 'erro');
                return 'erros';
            }

            // $config = json_decode($no['config_json'] ?? '{}', true) ?: [];
            // $porta  = $handler->executar($exec, $config, $this->db);
            $config = json_decode($no['config_json'] ?? '{}', true) ?: [];

            // ── Frequency capping (Fase 3B) ──
            // Se o nó é de envio e o cliente estourou o teto da semana, pula o
            // envio e segue pela porta 'saida' (não trava a jornada).
            $canalEnvio = FluxoGuard::canalDoNo($no['tipo_no']);
            $cid        = (int)($exec['cliente_id'] ?? 0);

            if ($canalEnvio !== null && $cid > 0 && FluxoGuard::capAtingido($cid, $canalEnvio, $this->db)) {
                if (class_exists('LogService')) {
                    try {
                        LogService::info('fluxo: envio pulado por cap', [
                            'cliente_id' => $cid, 'canal' => $canalEnvio, 'fluxo_id' => $exec['fluxo_id'],
                        ]);
                    } catch (Throwable $x) {}
                }
                $porta = 'saida';
            } else {
                $porta = $handler->executar($exec, $config, $this->db);
                // Só conta se o envio de fato saiu (porta 'saida'); DORMIR/ERRO não contam
                if ($canalEnvio !== null && $cid > 0 && $porta === 'saida') {
                    FluxoGuard::registrarEnvio($cid, $canalEnvio, (int)$exec['fluxo_id'], $this->db);
                }
            }

            // ── Retornos especiais ──
            if ($porta === FluxoNo::DORMIR) {
                $this->salvar($exec, 'dormindo');
                return 'dormindo';
            }
            if ($porta === FluxoNo::ENCERRAR) {
                $this->finalizar($exec, 'concluido');
                return 'concluidas';
            }
            if ($porta === FluxoNo::ERRO) {
                $this->finalizar($exec, 'erro');
                return 'erros';
            }

            if ($porta === FluxoNo::AGUARDAR_EVENTO) {
                $this->salvarAguardandoEvento($exec);
                return 'aguardando';
            }

            // ── Porta normal: segue a conexão ──
            $destino = $conexoes[$chave][$porta] ?? null;
            if ($destino === null) {
                // Porta sem conexão = fim natural do caminho
                $this->finalizar($exec, 'concluido');
                return 'concluidas';
            }
            $exec['no_atual'] = $destino;
        }

        // Estourou passos num ciclo — dorme 1 min e continua depois (anti-loop)
        $exec['dormir_ate'] = date('Y-m-d H:i:s', time() + 60);
        $this->salvar($exec, 'dormindo');
        return 'dormindo';
    }

    // =========================================================================
    // GUARD-RAILS
    // =========================================================================

    /** Exit conditions: config {"sair_se_eventos":["pedido_criado"]} */
    private function deveSair(array $fluxoCfg, array $exec): bool
    {
        $eventos = $fluxoCfg['sair_se_eventos'] ?? [];
        if (empty($eventos) || !is_array($eventos)) return false;
        $cid = $exec['cliente_id'];
        if (!$cid) return false;

        try {
            $in = implode(',', array_fill(0, count($eventos), '?'));
            $st = $this->db->prepare(
                "SELECT 1 FROM eventos
                 WHERE cliente_id = ? AND tipo IN ($in) AND criado_em > ?
                 LIMIT 1"
            );
            $st->execute(array_merge([$cid], array_values($eventos), [$exec['criado_em']]));
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Reentrada: "nunca" (default) | "sempre" | "apos_dias:N" */
    private function reentradaPermitida(array $fluxo, ?int $clienteId, ?string $token): bool
    {
        $cfg  = json_decode($fluxo['config_json'] ?? '{}', true) ?: [];
        $modo = (string)($cfg['reentrada'] ?? 'nunca');

        $where  = "fluxo_id = :f AND ";
        $params = [':f' => $fluxo['id']];
        if ($clienteId) { $where .= "cliente_id = :c";      $params[':c'] = $clienteId; }
        elseif ($token) { $where .= "visitante_token = :t"; $params[':t'] = $token; }
        else return true;

        try {
            if ($modo === 'sempre') {
                // Só bloqueia se já houver uma execução EM ANDAMENTO
                $st = $this->db->prepare(
                    "SELECT 1 FROM fluxo_execucoes
                     WHERE $where AND status IN ('ativo','dormindo','aguardando_evento') LIMIT 1"
                );
                $st->execute($params);
                return !$st->fetchColumn();
            }

            if (strpos($modo, 'apos_dias:') === 0) {
                $dias = max(1, (int)substr($modo, 10));
                $st = $this->db->prepare(
                    "SELECT 1 FROM fluxo_execucoes
                     WHERE $where AND (
                        status IN ('ativo','dormindo','aguardando_evento')
                        OR criado_em > DATE_SUB(NOW(), INTERVAL $dias DAY)
                     ) LIMIT 1"
                );
                $st->execute($params);
                return !$st->fetchColumn();
            }

            // 'nunca': 1 execução por pessoa por fluxo, para sempre
            $st = $this->db->prepare("SELECT 1 FROM fluxo_execucoes WHERE $where LIMIT 1");
            $st->execute($params);
            return !$st->fetchColumn();

        } catch (Throwable $e) {
            return false; // na dúvida, não duplica
        }
    }

    // =========================================================================
    // PERSISTÊNCIA / CARREGAMENTO
    // =========================================================================

    private function salvar(array $exec, string $status): void
    {
        $this->db->prepare(
            "UPDATE fluxo_execucoes
             SET no_atual=:no, status=:st, dormir_ate=:du,
                 contexto_json=:ctx, erro_detalhe=:err,
                 passos_executados = passos_executados + 1
             WHERE id=:id"
        )->execute([
            ':no'  => $exec['no_atual'],
            ':st'  => $status,
            ':du'  => $exec['dormir_ate'],
            ':ctx' => json_encode($exec['contexto'], JSON_UNESCAPED_UNICODE),
            ':err' => $exec['erro_detalhe'],
            ':id'  => $exec['id'],
        ]);
    }

    private function finalizar(array $exec, string $status): void
    {
        $this->db->prepare(
            "UPDATE fluxo_execucoes
             SET status=:st, contexto_json=:ctx, erro_detalhe=:err, dormir_ate=NULL
             WHERE id=:id"
        )->execute([
            ':st'  => $status,
            ':ctx' => json_encode($exec['contexto'], JSON_UNESCAPED_UNICODE),
            ':err' => $exec['erro_detalhe'],
            ':id'  => $exec['id'],
        ]);
    }

     /** Persiste uma execução em espera por evento (status aguardando_evento). */
    private function salvarAguardandoEvento(array $exec): void
    {
        $this->db->prepare(
            "UPDATE fluxo_execucoes
             SET no_atual=:no, status='aguardando_evento',
                 evento_aguardado=:ev, timeout_em=:to, dormir_ate=NULL,
                 contexto_json=:ctx, passos_executados = passos_executados + 1
             WHERE id=:id"
        )->execute([
            ':no'  => $exec['no_atual'],
            ':ev'  => $exec['evento_aguardado'] ?? null,
            ':to'  => $exec['timeout_em'] ?? null,
            ':ctx' => json_encode($exec['contexto'], JSON_UNESCAPED_UNICODE),
            ':id'  => $exec['id'],
        ]);
    }

    /**
     * Fase de resolução das esperas por evento — chamada pelo worker ANTES de
     * processarExecucoes(). Para cada execução em 'aguardando_evento':
     *   - evento ocorreu na janela [desde, timeout_em]  → reativa via porta 'evento'
     *   - timeout_em já passou (e o evento não veio)     → reativa via porta 'timeout'
     * A reativação apenas marca a porta no contexto e volta status='ativo'; o
     * nó esperar_evento, ao rodar de novo, lê o marcador e segue.
     *
     * @return int quantas foram resolvidas
     */
    public function resolverEsperasEvento(int $limite = 300): int
    {
        $resolvidas = 0;
        try {
            $st = $this->db->prepare(
                "SELECT id, fluxo_id, cliente_id, visitante_token, no_atual,
                        evento_aguardado, timeout_em, contexto_json, criado_em
                 FROM fluxo_execucoes
                 WHERE status = 'aguardando_evento'
                 ORDER BY id ASC
                 LIMIT " . max(1, min(1000, $limite))
            );
            $st->execute();

            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $chave = (string)$row['no_atual'];
                $ctx   = json_decode($row['contexto_json'] ?? '{}', true) ?: [];
                $spec  = $ctx['_ee_spec_' . $chave] ?? null;

                // Sem spec no contexto (dado antigo/corrompido): usa as colunas
                if (!is_array($spec)) {
                    $spec = [
                        'evento'        => $row['evento_aguardado'],
                        'entidade_tipo' => null,
                        'entidade_id'   => null,
                        'desde'         => $row['criado_em'],
                        'timeout_em'    => $row['timeout_em'],
                        'observavel'    => ($row['cliente_id'] !== null || !empty($row['visitante_token'])) ? 1 : 0,
                    ];
                }

                $via = null;
                if (!empty($spec['observavel']) && $this->checarEventoNoIntervalo($row, $spec)) {
                    $via = 'evento';
                } elseif (!empty($spec['timeout_em']) && strtotime((string)$spec['timeout_em']) <= time()) {
                    $via = 'timeout';
                }
                if ($via === null) continue;

                $ctx['_ee_resolvido_' . $chave] = $via;
                $this->db->prepare(
                    "UPDATE fluxo_execucoes
                     SET status='ativo', evento_aguardado=NULL, timeout_em=NULL,
                         dormir_ate=NULL, contexto_json=:ctx
                     WHERE id=:id AND status='aguardando_evento'"
                )->execute([
                    ':ctx' => json_encode($ctx, JSON_UNESCAPED_UNICODE),
                    ':id'  => $row['id'],
                ]);
                $resolvidas++;
            }
        } catch (Throwable $e) {
            $this->logErro('resolverEsperasEvento', $e);
        }
        return $resolvidas;
    }

    /** O evento aguardado ocorreu em (desde, timeout_em], para este sujeito? */
    private function checarEventoNoIntervalo(array $row, array $spec): bool
    {
        $evento = (string)($spec['evento'] ?? '');
        if ($evento === '') return false;

        $sql = "SELECT 1 FROM eventos
                WHERE tipo = :t AND criado_em > :desde AND criado_em <= :ate";
        $params = [
            ':t'     => $evento,
            ':desde' => (string)$spec['desde'],
            ':ate'   => (string)$spec['timeout_em'],
        ];

        if ($row['cliente_id'] !== null) {
            $sql .= " AND cliente_id = :c";
            $params[':c'] = (int)$row['cliente_id'];
        } elseif (!empty($row['visitante_token'])) {
            $sql .= " AND visitante_token = :v";
            $params[':v'] = $row['visitante_token'];
        } else {
            return false;
        }

        if (!empty($spec['entidade_tipo'])) {
            $sql .= " AND entidade_tipo = :et";
            $params[':et'] = $spec['entidade_tipo'];
        }
        if (!empty($spec['entidade_id'])) {
            $sql .= " AND entidade_id = :ei";
            $params[':ei'] = (int)$spec['entidade_id'];
        }
        $sql .= " LIMIT 1";

        try {
            $st = $this->db->prepare($sql);
            foreach ($params as $k => $v) {
                $st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $st->execute();
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }


    private function carregarFluxo(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM fluxo_v2 WHERE id=:id LIMIT 1");
        $st->execute([':id' => $id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array<string,array> chave → nó */
    private function carregarNos(int $fluxoId, int $versao): array
    {
        $st = $this->db->prepare(
            "SELECT * FROM fluxo_nos WHERE fluxo_id=:f AND versao=:v"
        );
        $st->execute([':f' => $fluxoId, ':v' => $versao]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $n) $out[$n['chave']] = $n;
        return $out;
    }

    /** @return array<string,array<string,string>> origem → porta → destino */
    private function carregarConexoes(int $fluxoId, int $versao): array
    {
        $st = $this->db->prepare(
            "SELECT no_origem, porta, no_destino FROM fluxo_conexoes
             WHERE fluxo_id=:f AND versao=:v"
        );
        $st->execute([':f' => $fluxoId, ':v' => $versao]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $out[$c['no_origem']][$c['porta']] = $c['no_destino'];
        }
        return $out;
    }

    private function logErro(string $onde, Throwable $e): void
    {
        if (class_exists('LogService')) {
            try { LogService::error("FluxoMotor::$onde: " . $e->getMessage()); } catch (Throwable $x) {}
        }
    }
}
