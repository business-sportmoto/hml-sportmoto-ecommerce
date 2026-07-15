<?php
/**
 * app/services/FluxoTriggerService.php
 *
 * Detector de triggers: varre os eventos NOVOS do stream (cursor em
 * fluxo_motor_config) e inicia execuções nos fluxos publicados cujo
 * trigger_evento casa com o evento.
 *
 * Config do trigger_evento:
 *   {"evento":"produto_visto",        ← tipo do evento no stream
 *    "entidade_tipo":"produto",       ← opcional: exige entidade
 *    "min_ocorrencias":2,             ← opcional: só dispara na N-ésima vez
 *    "janela_dias":7,                 ←   (janela da contagem)
 *    "apenas_logados":true}           ← default true
 *
 * O contexto da execução nasce do evento: entidade produto → produto_id;
 * o contexto_json do evento (q da busca etc.) entra com as chaves originais.
 */
class FluxoTriggerService
{
    /** @var PDO */
    private $db;
    /** @var FluxoMotor */
    private $motor;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        if (!class_exists('FluxoNoRegistry', false)) {
            $base = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2);
            require_once $base . '/app/services/FluxoNoRegistry.php';
        }
        $this->motor = new FluxoMotor();
    }

    /**
     * Processa eventos novos desde o cursor.
     * @return array estatísticas
     */
    public function detectar(int $loteMax = 2000): array
    {
        $stats = ['eventos_lidos' => 0, 'execucoes_iniciadas' => 0];

        try {
            $cursor = (int)$this->getConfig('trigger_cursor_evento_id', '0');

            // Índice: triggers ativos por tipo de evento
            $triggers = $this->carregarTriggersPublicados();
            if (empty($triggers)) {
                // Ainda avança o cursor para não acumular backlog eterno
                $this->avancarCursorParaTopo($cursor, $loteMax, $stats);
                return $stats;
            }

            $st = $this->db->prepare(
                "SELECT * FROM eventos WHERE id > :c ORDER BY id ASC LIMIT " . max(100, $loteMax)
            );
            $st->bindValue(':c', $cursor, PDO::PARAM_INT);
            $st->execute();
            $eventos = $st->fetchAll(PDO::FETCH_ASSOC);

            $ultimoId = $cursor;
            foreach ($eventos as $ev) {
                $ultimoId = (int)$ev['id'];
                $stats['eventos_lidos']++;

                $lista = $triggers[$ev['tipo']] ?? [];
                foreach ($lista as $t) {
                    if ($this->casa($t['config'], $ev)) {
                        $ctx = $this->contextoDoEvento($ev);
                        $id = $this->motor->iniciarExecucao(
                            (int)$t['fluxo_id'],
                            $ev['cliente_id'] !== null ? (int)$ev['cliente_id'] : null,
                            $ev['visitante_token'] ?: null,
                            $ctx
                        );
                        if ($id) $stats['execucoes_iniciadas']++;
                    }
                }
            }

            if ($ultimoId > $cursor) {
                $this->setConfig('trigger_cursor_evento_id', (string)$ultimoId);
            }
        } catch (Throwable $e) {
            if (class_exists('LogService')) {
                try { LogService::error('FluxoTriggerService::detectar: ' . $e->getMessage()); } catch (Throwable $x) {}
            }
        }

        return $stats;
    }

    // =========================================================================

    /** @return array<string, array<int, array{fluxo_id:int, config:array}>> evento → triggers */
    private function carregarTriggersPublicados(): array
    {
        $st = $this->db->query(
            "SELECT f.id AS fluxo_id, f.versao_publicada, n.config_json
             FROM fluxo_v2 f
             JOIN fluxo_nos n ON n.fluxo_id = f.id
                             AND n.versao = f.versao_publicada
                             AND n.tipo_no = 'trigger_evento'
             WHERE f.status = 'publicado' AND f.versao_publicada >= 1"
        );
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cfg = json_decode($r['config_json'] ?? '{}', true) ?: [];
            $evento = (string)($cfg['evento'] ?? '');
            if ($evento === '') continue;
            $out[$evento][] = ['fluxo_id' => (int)$r['fluxo_id'], 'config' => $cfg];
        }
        return $out;
    }

    /** O evento satisfaz a config do trigger? */
    private function casa(array $cfg, array $ev): bool
    {
        // Logados apenas (default true)
        $apenasLogados = array_key_exists('apenas_logados', $cfg)
            ? (bool)$cfg['apenas_logados'] : true;
        if ($apenasLogados && $ev['cliente_id'] === null) return false;

        // Entidade exigida
        if (!empty($cfg['entidade_tipo']) && $ev['entidade_tipo'] !== $cfg['entidade_tipo']) {
            return false;
        }

        // Mínimo de ocorrências na janela (ex: "2ª visita ao mesmo produto em 7d")
        $min = (int)($cfg['min_ocorrencias'] ?? 1);
        if ($min > 1) {
            $dias = max(1, (int)($cfg['janela_dias'] ?? 7));
            $sql = "SELECT COUNT(*) FROM eventos
                    WHERE tipo = :t AND id <= :id
                      AND criado_em > DATE_SUB(NOW(), INTERVAL :d DAY)";
            $params = [':t' => $ev['tipo'], ':id' => (int)$ev['id'], ':d' => $dias];

            if ($ev['cliente_id'] !== null) {
                $sql .= " AND cliente_id = :c";      $params[':c'] = (int)$ev['cliente_id'];
            } else {
                $sql .= " AND visitante_token = :v"; $params[':v'] = $ev['visitante_token'];
            }
            if ($ev['entidade_id'] !== null) {
                $sql .= " AND entidade_tipo <=> :et AND entidade_id = :ei";
                $params[':et'] = $ev['entidade_tipo'];
                $params[':ei'] = (int)$ev['entidade_id'];
            }
            try {
                $st = $this->db->prepare($sql);
                foreach ($params as $k => $v) {
                    $st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
                }
                $st->execute();
                if ((int)$st->fetchColumn() < $min) return false;
                // Dispara EXATAMENTE na N-ésima (na N+1 a reentrada do fluxo decide)
            } catch (Throwable $e) {
                return false;
            }
        }

        return true;
    }

    /** Traduz o evento em contexto de execução. */
    private function contextoDoEvento(array $ev): array
    {
        $ctx = ['_evento_id' => (int)$ev['id'], '_evento_tipo' => $ev['tipo']];

        if ($ev['entidade_tipo'] === 'produto' && $ev['entidade_id'] !== null) {
            $ctx['produto_id'] = (int)$ev['entidade_id'];
        }
        if ($ev['entidade_tipo'] === 'categoria' && $ev['entidade_id'] !== null) {
            $ctx['categoria_id'] = (int)$ev['entidade_id'];
        }
        $evCtx = json_decode($ev['contexto_json'] ?? '{}', true);
        if (is_array($evCtx)) {
            foreach ($evCtx as $k => $v) {
                if (is_scalar($v)) $ctx[$k] = $v;
            }
        }
        return $ctx;
    }

    private function avancarCursorParaTopo(int $cursor, int $lote, array &$stats): void
    {
        $st = $this->db->prepare(
            "SELECT MAX(id) FROM (SELECT id FROM eventos WHERE id > :c ORDER BY id ASC LIMIT $lote) x"
        );
        $st->bindValue(':c', $cursor, PDO::PARAM_INT);
        $st->execute();
        $max = (int)$st->fetchColumn();
        if ($max > $cursor) {
            $this->setConfig('trigger_cursor_evento_id', (string)$max);
            $stats['eventos_lidos'] = $max - $cursor;
        }
    }

    private function getConfig(string $chave, string $default): string
    {
        try {
            $st = $this->db->prepare("SELECT valor FROM fluxo_motor_config WHERE chave=:k");
            $st->execute([':k' => $chave]);
            $v = $st->fetchColumn();
            return $v !== false && $v !== null ? (string)$v : $default;
        } catch (Throwable $e) { return $default; }
    }

    private function setConfig(string $chave, string $valor): void
    {
        $this->db->prepare(
            "INSERT INTO fluxo_motor_config (chave, valor) VALUES (:k,:v)
             ON DUPLICATE KEY UPDATE valor = :v2"
        )->execute([':k' => $chave, ':v' => $valor, ':v2' => $valor]);
    }
}
