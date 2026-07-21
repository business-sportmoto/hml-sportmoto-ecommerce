<?php
/**
 * app/services/FluxoAdminService.php
 *
 * Administração dos fluxos: salvar rascunho (versão 0), validar o grafo
 * e publicar (snapshot imutável em versao_publicada+1).
 *
 * FORMATO JSON do grafo (editor da Fase 1; o canvas da Fase 2 gera o mesmo):
 * {
 *   "nos": [
 *     {"chave":"t1","tipo":"trigger_evento","config":{"evento":"produto_visto","min_ocorrencias":2,"janela_dias":7}},
 *     {"chave":"c1","tipo":"cond_aceita_marketing","config":{"canal":"email"}},
 *     {"chave":"e1","tipo":"esperar","config":{"horas":1}},
 *     {"chave":"a1","tipo":"acao_email","config":{"template_id":21}},
 *     {"chave":"fim","tipo":"encerrar","config":{}}
 *   ],
 *   "conexoes": [
 *     {"de":"t1","porta":"saida","para":"c1"},
 *     {"de":"c1","porta":"true","para":"e1"},
 *     {"de":"c1","porta":"false","para":"fim"},
 *     {"de":"e1","porta":"saida","para":"a1"}
 *   ]
 * }
 */
class FluxoAdminService
{
    /** @var PDO */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        if (!class_exists('FluxoNoRegistry', false)) {
            $base = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2);
            require_once $base . '/app/services/FluxoNoRegistry.php';
        }
    }

    // =========================================================================
    // LEITURA
    // =========================================================================

    public function listar(): array
    {
        $st = $this->db->query(
            "SELECT f.*,
                (SELECT COUNT(*) FROM fluxo_execucoes e
                 WHERE e.fluxo_id = f.id AND e.status IN ('ativo','dormindo')) AS em_andamento,
                (SELECT COUNT(*) FROM fluxo_execucoes e
                 WHERE e.fluxo_id = f.id AND e.status = 'concluido') AS concluidas
             FROM fluxo_v2 f
             WHERE f.status <> 'arquivado'
             ORDER BY f.id DESC"
        );
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function carregar(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM fluxo_v2 WHERE id=:id LIMIT 1");
        $st->execute([':id' => $id]);
        $fluxo = $st->fetch(PDO::FETCH_ASSOC);
        if (!$fluxo) return null;

        $fluxo['grafo'] = $this->exportarGrafo($id, 0); // rascunho
        return $fluxo;
    }

    /** Exporta nós+conexões de uma versão no formato JSON do editor. */
    public function exportarGrafo(int $fluxoId, int $versao): array
    {
        $stN = $this->db->prepare(
            "SELECT chave, tipo_no, config_json, pos_x, pos_y
             FROM fluxo_nos WHERE fluxo_id=:f AND versao=:v ORDER BY id ASC"
        );
        $stN->execute([':f' => $fluxoId, ':v' => $versao]);
        $nos = [];
        foreach ($stN->fetchAll(PDO::FETCH_ASSOC) as $n) {
            $nos[] = [
                'chave'  => $n['chave'],
                'tipo'   => $n['tipo_no'],
                'config' => json_decode($n['config_json'] ?? '{}', true) ?: new stdClass(),
                'pos'    => [(int)$n['pos_x'], (int)$n['pos_y']],
            ];
        }

        $stC = $this->db->prepare(
            "SELECT no_origem, porta, no_destino FROM fluxo_conexoes
             WHERE fluxo_id=:f AND versao=:v ORDER BY id ASC"
        );
        $stC->execute([':f' => $fluxoId, ':v' => $versao]);
        $conexoes = [];
        foreach ($stC->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $conexoes[] = ['de' => $c['no_origem'], 'porta' => $c['porta'], 'para' => $c['no_destino']];
        }

        return ['nos' => $nos, 'conexoes' => $conexoes];
    }

    // =========================================================================
    // ESCRITA
    // =========================================================================

    /** Cria o cabeçalho de um fluxo novo (rascunho vazio). */
    public function criar(string $nome, ?string $descricao = null): int
    {
        $st = $this->db->prepare(
            "INSERT INTO fluxo_v2 (nome, descricao, status) VALUES (:n,:d,'rascunho')"
        );
        $st->execute([':n' => mb_substr(trim($nome), 0, 120), ':d' => $descricao]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Salva o grafo no RASCUNHO (versão 0). Substitui tudo.
     * @return array{ok:bool, erros:array}
     */
    public function salvarRascunho(int $fluxoId, array $grafo, ?array $meta = null): array
    {
        $val = $this->validar($grafo);
        // Rascunho pode ser salvo com erros (trabalho em progresso) —
        // mas erros de ESTRUTURA (chave duplicada, tipo inexistente) bloqueiam.
        if (!empty($val['fatais'])) {
            return ['ok' => false, 'erros' => $val['fatais']];
        }

        $this->db->beginTransaction();
        try {
            if ($meta !== null) {
                $sets = []; $params = [':id' => $fluxoId];
                foreach (['nome','descricao','prioridade'] as $c) {
                    if (array_key_exists($c, $meta)) { $sets[] = "$c=:$c"; $params[":$c"] = $meta[$c]; }
                }
                if (array_key_exists('config', $meta)) {
                    $sets[] = "config_json=:cfg";
                    $params[':cfg'] = json_encode($meta['config'], JSON_UNESCAPED_UNICODE);
                }
                if ($sets) {
                    $this->db->prepare("UPDATE fluxo_v2 SET " . implode(',', $sets) . " WHERE id=:id")
                             ->execute($params);
                }
            }

            $this->db->prepare("DELETE FROM fluxo_nos WHERE fluxo_id=:f AND versao=0")
                     ->execute([':f' => $fluxoId]);
                     LogService::debug('era pra deletar o nó:'.$fluxoId, $val);
            $this->db->prepare("DELETE FROM fluxo_conexoes WHERE fluxo_id=:f AND versao=0")
                     ->execute([':f' => $fluxoId]);

            $insN = $this->db->prepare(
                "INSERT INTO fluxo_nos (fluxo_id, versao, chave, tipo_no, config_json, pos_x, pos_y)
                 VALUES (:f,0,:k,:t,:c,:x,:y)"
            );
            foreach ($grafo['nos'] as $n) {
                $pos = $n['pos'] ?? [0, 0];
                $insN->execute([
                    ':f' => $fluxoId,
                    ':k' => mb_substr((string)$n['chave'], 0, 40),
                    ':t' => (string)$n['tipo'],
                    ':c' => json_encode($n['config'] ?? [], JSON_UNESCAPED_UNICODE),
                    ':x' => (int)($pos[0] ?? 0),
                    ':y' => (int)($pos[1] ?? 0),
                ]);
            }

            $insC = $this->db->prepare(
                "INSERT INTO fluxo_conexoes (fluxo_id, versao, no_origem, porta, no_destino)
                 VALUES (:f,0,:o,:p,:d)"
            );
            foreach ($grafo['conexoes'] as $c) {
                $insC->execute([
                    ':f' => $fluxoId,
                    ':o' => (string)$c['de'],
                    ':p' => (string)($c['porta'] ?? 'saida'),
                    ':d' => (string)$c['para'],
                ]);
            }

            $this->db->commit();
            return ['ok' => true, 'erros' => $val['erros']]; // avisos não-fatais
        } catch (Throwable $e) {
            $this->db->rollBack();
            return ['ok' => false, 'erros' => ['Falha ao salvar: ' . $e->getMessage()]];
        }
    }

    /**
     * Publica: valida o rascunho por completo e cria snapshot imutável.
     * @return array{ok:bool, versao?:int, erros:array}
     */
    public function publicar(int $fluxoId): array
    {
        $grafo = $this->exportarGrafo($fluxoId, 0);
        $val   = $this->validar($grafo);
        $todos = array_merge($val['fatais'], $val['erros']);
        if (!empty($todos)) {
            return ['ok' => false, 'erros' => $todos];
        }

        $this->db->beginTransaction();
        try {
            $st = $this->db->prepare("SELECT versao_publicada FROM fluxo_v2 WHERE id=:id FOR UPDATE");
            $st->execute([':id' => $fluxoId]);
            $atual = (int)$st->fetchColumn();
            $nova  = $atual + 1;

            // Copia rascunho → versão nova
            $this->db->prepare(
                "INSERT INTO fluxo_nos (fluxo_id, versao, chave, tipo_no, config_json, pos_x, pos_y)
                 SELECT fluxo_id, :v, chave, tipo_no, config_json, pos_x, pos_y
                 FROM fluxo_nos WHERE fluxo_id=:f AND versao=0"
            )->execute([':v' => $nova, ':f' => $fluxoId]);

            $this->db->prepare(
                "INSERT INTO fluxo_conexoes (fluxo_id, versao, no_origem, porta, no_destino)
                 SELECT fluxo_id, :v, no_origem, porta, no_destino
                 FROM fluxo_conexoes WHERE fluxo_id=:f AND versao=0"
            )->execute([':v' => $nova, ':f' => $fluxoId]);

            $this->db->prepare(
                "UPDATE fluxo_v2 SET versao_publicada=:v, status='publicado' WHERE id=:id"
            )->execute([':v' => $nova, ':id' => $fluxoId]);

            $this->db->commit();

            if (class_exists('LogService')) {
                try { LogService::audit('fluxo_publicado', ['fluxo_id' => $fluxoId, 'versao' => $nova]); } catch (Throwable $e) {}
            }
            return ['ok' => true, 'versao' => $nova, 'erros' => []];
        } catch (Throwable $e) {
            $this->db->rollBack();
            return ['ok' => false, 'erros' => ['Falha ao publicar: ' . $e->getMessage()]];
        }
    }

    public function mudarStatus(int $fluxoId, string $status): bool
    {
        if (!in_array($status, ['publicado','pausado','arquivado'], true)) return false;
        // 'publicado' exige versão publicada existente
        if ($status === 'publicado') {
            $st = $this->db->prepare("SELECT versao_publicada FROM fluxo_v2 WHERE id=:id");
            $st->execute([':id' => $fluxoId]);
            if ((int)$st->fetchColumn() < 1) return false;
        }
        $this->db->prepare("UPDATE fluxo_v2 SET status=:s WHERE id=:id")
                 ->execute([':s' => $status, ':id' => $fluxoId]);
        return true;
    }

    // =========================================================================
    // VALIDAÇÃO DO GRAFO
    // =========================================================================

    /**
     * @return array{fatais:array, erros:array}
     *   fatais → estrutura quebrada (nem rascunho salva)
     *   erros  → bloqueiam PUBLICAR (mas rascunho pode salvar)
     */
    public function validar(array $grafo): array
    {
        $fatais = [];
        $erros  = [];

        $nos      = $grafo['nos'] ?? [];
        $conexoes = $grafo['conexoes'] ?? [];

        if (!is_array($nos) || !is_array($conexoes)) {
            return ['fatais' => ['JSON inválido: esperado {nos:[], conexoes:[]}'], 'erros' => []];
        }

        // ── Estrutura dos nós ──
        $porChave = [];
        foreach ($nos as $i => $n) {
            $chave = (string)($n['chave'] ?? '');
            $tipo  = (string)($n['tipo']  ?? '');
            if ($chave === '') { $fatais[] = "nó #$i sem 'chave'"; continue; }
            if (isset($porChave[$chave])) { $fatais[] = "chave duplicada: '$chave'"; continue; }
            if (!FluxoNoRegistry::existe($tipo)) { $fatais[] = "nó '$chave': tipo '$tipo' não existe"; continue; }
            $porChave[$chave] = $tipo;
        }
        if ($fatais) return ['fatais' => $fatais, 'erros' => []];

        // ── Exatamente 1 trigger ──
        $triggers = array_keys(array_filter($porChave, fn($t) => FluxoNoRegistry::ehTrigger($t)));
        if (count($triggers) !== 1) {
            $erros[] = 'o fluxo precisa de exatamente 1 nó trigger (tem ' . count($triggers) . ')';
        }

        // ── Conexões referenciam nós existentes e portas declaradas ──
        $adj = []; // origem → [destinos]
        foreach ($conexoes as $i => $c) {
            $de    = (string)($c['de'] ?? '');
            $para  = (string)($c['para'] ?? '');
            $porta = (string)($c['porta'] ?? 'saida');
            if (!isset($porChave[$de]))   { $erros[] = "conexão #$i: origem '$de' não existe"; continue; }
            if (!isset($porChave[$para])) { $erros[] = "conexão #$i: destino '$para' não existe"; continue; }
            $portasDoTipo = FluxoNoRegistry::obter($porChave[$de])->portas();
            if (!in_array($porta, $portasDoTipo, true)) {
                $erros[] = "conexão #$i: nó '$de' ({$porChave[$de]}) não tem porta '$porta'";
                continue;
            }
            $adj[$de][] = $para;
        }

        // ── Alcançabilidade a partir do trigger (sem órfãos) ──
        if (count($triggers) === 1) {
            $visitados = [];
            $fila = [$triggers[0]];
            while ($fila) {
                $atual = array_pop($fila);
                if (isset($visitados[$atual])) continue;
                $visitados[$atual] = true;
                foreach ($adj[$atual] ?? [] as $d) $fila[] = $d;
            }
            foreach ($porChave as $chave => $tipo) {
                if (!isset($visitados[$chave])) {
                    $erros[] = "nó '$chave' é órfão (inalcançável a partir do trigger)";
                }
            }
        }

        // ── Ciclo sem 'esperar' no caminho = loop infinito ──
        foreach ($this->encontrarCiclos($adj) as $ciclo) {
            $temEspera = false;
            foreach ($ciclo as $chave) {
                if (($porChave[$chave] ?? '') === 'esperar') { $temEspera = true; break; }
            }
            if (!$temEspera) {
                $erros[] = 'ciclo sem nó "esperar" no caminho: ' . implode(' → ', $ciclo)
                         . ' (loop infinito de ações)';
            }
        }

        return ['fatais' => [], 'erros' => $erros];
    }

    /** DFS com pilha de recursão para listar ciclos (simplificado, 1 por back-edge). */
    private function encontrarCiclos(array $adj): array
    {
        $ciclos = [];
        $estado = []; // 0=nunca, 1=na pilha, 2=feito
        $pilha  = [];

        $dfs = function ($u) use (&$dfs, &$estado, &$pilha, &$ciclos, $adj) {
            $estado[$u] = 1;
            $pilha[] = $u;
            foreach ($adj[$u] ?? [] as $v) {
                if (($estado[$v] ?? 0) === 0) {
                    $dfs($v);
                } elseif (($estado[$v] ?? 0) === 1) {
                    // back-edge: extrai o ciclo da pilha
                    $idx = array_search($v, $pilha, true);
                    if ($idx !== false) $ciclos[] = array_slice($pilha, $idx);
                }
            }
            array_pop($pilha);
            $estado[$u] = 2;
        };

        foreach (array_keys($adj) as $u) {
            if (($estado[$u] ?? 0) === 0) $dfs($u);
        }
        return $ciclos;
    }
}
