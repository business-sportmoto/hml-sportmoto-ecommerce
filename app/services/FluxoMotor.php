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
                  'sairam' => 0, 'erros' => 0];
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

            $config = json_decode($no['config_json'] ?? '{}', true) ?: [];
            $porta  = $handler->executar($exec, $config, $this->db);

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
