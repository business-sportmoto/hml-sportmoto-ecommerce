<?php
/**
 * app/services/ChatFluxoAdminService.php
 *
 * Administração dos fluxos conversacionais: rascunho (versão 0), validação e
 * publicação (snapshot imutável em versao_publicada+1).
 *
 * Mesmo contrato do FluxoAdminService — inclusive o formato do grafo — para
 * que o canvas seja um só componente atendendo os dois motores:
 *
 * {
 *   "nos": [{"chave":"t1","tipo":"gatilho_palavra","config":{...},"pos":[x,y]}],
 *   "conexoes": [{"de":"t1","porta":"saida","para":"m1"}]
 * }
 */
class ChatFluxoAdminService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
        if (!class_exists('ChatNoRegistry', false)) {
            $base = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2);
            require_once $base . '/app/services/ChatNoRegistry.php';
        }
    }

    // =========================================================================
    // LEITURA
    // =========================================================================

    public function listar(bool $incluirArquivados = false): array
    {
        $where = $incluirArquivados ? '' : "WHERE f.status <> 'arquivado'";
        $st = $this->db->query(
            "SELECT f.*,
                (SELECT COUNT(*) FROM chat_sessoes s
                 WHERE s.fluxo_id = f.id AND s.status IN ('ativo','dormindo','aguardando_resposta')) AS em_andamento,
                (SELECT COUNT(*) FROM chat_sessoes s
                 WHERE s.fluxo_id = f.id AND s.status = 'concluido') AS concluidas,
                (SELECT COUNT(*) FROM chat_sessoes s
                 WHERE s.fluxo_id = f.id AND s.status = 'erro') AS com_erro,
                (SELECT COUNT(*) FROM chat_gatilhos g WHERE g.fluxo_id = f.id AND g.ativo = 1) AS gatilhos
             FROM chat_fluxos f
             $where
             ORDER BY f.id DESC"
        );
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Só os publicados — alimenta os selects de gatilho e campanha. */
    public function listarPublicados(): array
    {
        return $this->db->query(
            "SELECT id, nome, status, versao_publicada FROM chat_fluxos
             WHERE status = 'publicado' AND versao_publicada >= 1
             ORDER BY nome"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function carregar(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM chat_fluxos WHERE id = :id LIMIT 1");
        $st->execute([':id' => $id]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        if (!$f) return null;

        $f['grafo'] = $this->exportarGrafo($id, 0);
        return $f;
    }

    public function exportarGrafo(int $fluxoId, int $versao): array
    {
        $stN = $this->db->prepare(
            "SELECT chave, tipo_no, config_json, pos_x, pos_y
             FROM chat_fluxo_nos WHERE fluxo_id = :f AND versao = :v ORDER BY id ASC"
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
            "SELECT no_origem, porta, no_destino FROM chat_fluxo_conexoes
             WHERE fluxo_id = :f AND versao = :v ORDER BY id ASC"
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

    public function criar(string $nome, ?string $descricao = null, ?int $criadoPor = null): int
    {
        $st = $this->db->prepare(
            "INSERT INTO chat_fluxos (nome, descricao, status, criado_por, config_json)
             VALUES (:n, :d, 'rascunho', :u, :c)"
        );
        $st->execute([
            ':n' => mb_substr(trim($nome), 0, 120),
            ':d' => $descricao ? mb_substr($descricao, 0, 300) : null,
            ':u' => $criadoPor,
            ':c' => json_encode(['reentrada' => 'sempre'], JSON_UNESCAPED_UNICODE),
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function duplicar(int $fluxoId, ?int $criadoPor = null): ?int
    {
        $orig = $this->carregar($fluxoId);
        if (!$orig) return null;

        $novoId = $this->criar($orig['nome'] . ' (cópia)', $orig['descricao'], $criadoPor);

        // Copia o rascunho; se não houver, cai para a versão publicada — senão
        // duplicar um fluxo publicado sem rascunho geraria um fluxo vazio.
        $grafo = $orig['grafo'];
        if (empty($grafo['nos']) && (int)$orig['versao_publicada'] > 0) {
            $grafo = $this->exportarGrafo($fluxoId, (int)$orig['versao_publicada']);
        }

        $this->salvarRascunho($novoId, $grafo, ['config' => json_decode($orig['config_json'] ?? '{}', true) ?: []]);
        return $novoId;
    }

    /** @return array{ok:bool, erros:array} */
    public function salvarRascunho(int $fluxoId, array $grafo, ?array $meta = null): array
    {
        $val = $this->validar($grafo);
        if (!empty($val['fatais'])) {
            return ['ok' => false, 'erros' => $val['fatais']];
        }

        $this->db->beginTransaction();
        try {
            if ($meta !== null) {
                $sets = []; $params = [':id' => $fluxoId];
                foreach (['nome', 'descricao', 'prioridade'] as $c) {
                    if (array_key_exists($c, $meta)) { $sets[] = "$c = :$c"; $params[":$c"] = $meta[$c]; }
                }
                if (array_key_exists('config', $meta)) {
                    $sets[] = "config_json = :cfg";
                    $params[':cfg'] = json_encode($meta['config'], JSON_UNESCAPED_UNICODE);
                }
                if ($sets) {
                    $this->db->prepare("UPDATE chat_fluxos SET " . implode(', ', $sets) . " WHERE id = :id")
                             ->execute($params);
                }
            }

            $this->db->prepare("DELETE FROM chat_fluxo_nos WHERE fluxo_id = :f AND versao = 0")
                     ->execute([':f' => $fluxoId]);
            $this->db->prepare("DELETE FROM chat_fluxo_conexoes WHERE fluxo_id = :f AND versao = 0")
                     ->execute([':f' => $fluxoId]);

            $insN = $this->db->prepare(
                "INSERT INTO chat_fluxo_nos (fluxo_id, versao, chave, tipo_no, config_json, pos_x, pos_y)
                 VALUES (:f, 0, :k, :t, :c, :x, :y)"
            );
            foreach (($grafo['nos'] ?? []) as $n) {
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
                "INSERT INTO chat_fluxo_conexoes (fluxo_id, versao, no_origem, porta, no_destino)
                 VALUES (:f, 0, :o, :p, :d)"
            );
            foreach (($grafo['conexoes'] ?? []) as $c) {
                $insC->execute([
                    ':f' => $fluxoId,
                    ':o' => (string)$c['de'],
                    ':p' => (string)($c['porta'] ?? 'saida'),
                    ':d' => (string)$c['para'],
                ]);
            }

            $this->db->commit();
            return ['ok' => true, 'erros' => $val['erros']];   // avisos não-fatais
        } catch (Throwable $e) {
            $this->db->rollBack();
            return ['ok' => false, 'erros' => ['Falha ao salvar: ' . $e->getMessage()]];
        }
    }

    /** @return array{ok:bool, versao?:int, erros:array} */
    public function publicar(int $fluxoId): array
    {
        $grafo = $this->exportarGrafo($fluxoId, 0);
        $val   = $this->validar($grafo);
        $todos = array_merge($val['fatais'], $val['erros']);
        if ($todos) return ['ok' => false, 'erros' => $todos];

        $this->db->beginTransaction();
        try {
            $st = $this->db->prepare("SELECT versao_publicada FROM chat_fluxos WHERE id = :id FOR UPDATE");
            $st->execute([':id' => $fluxoId]);
            $nova = (int)$st->fetchColumn() + 1;

            $this->db->prepare(
                "INSERT INTO chat_fluxo_nos (fluxo_id, versao, chave, tipo_no, config_json, pos_x, pos_y)
                 SELECT fluxo_id, :v, chave, tipo_no, config_json, pos_x, pos_y
                 FROM chat_fluxo_nos WHERE fluxo_id = :f AND versao = 0"
            )->execute([':v' => $nova, ':f' => $fluxoId]);

            $this->db->prepare(
                "INSERT INTO chat_fluxo_conexoes (fluxo_id, versao, no_origem, porta, no_destino)
                 SELECT fluxo_id, :v, no_origem, porta, no_destino
                 FROM chat_fluxo_conexoes WHERE fluxo_id = :f AND versao = 0"
            )->execute([':v' => $nova, ':f' => $fluxoId]);

            $this->db->prepare(
                "UPDATE chat_fluxos SET versao_publicada = :v, status = 'publicado' WHERE id = :id"
            )->execute([':v' => $nova, ':id' => $fluxoId]);

            $this->db->commit();

            if (class_exists('LogService')) {
                try { LogService::audit('chat_fluxo_publicado', ['fluxo_id' => $fluxoId, 'versao' => $nova]); }
                catch (Throwable $e) {}
            }
            return ['ok' => true, 'versao' => $nova, 'erros' => []];
        } catch (Throwable $e) {
            $this->db->rollBack();
            return ['ok' => false, 'erros' => ['Falha ao publicar: ' . $e->getMessage()]];
        }
    }

    public function mudarStatus(int $fluxoId, string $status): bool
    {
        if (!in_array($status, ['publicado', 'pausado', 'arquivado'], true)) return false;

        if ($status === 'publicado') {
            $st = $this->db->prepare("SELECT versao_publicada FROM chat_fluxos WHERE id = :id");
            $st->execute([':id' => $fluxoId]);
            if ((int)$st->fetchColumn() < 1) return false;
        }

        $this->db->prepare("UPDATE chat_fluxos SET status = :s WHERE id = :id")
                 ->execute([':s' => $status, ':id' => $fluxoId]);

        // Pausar/arquivar precisa parar as jornadas em curso, senão o fluxo
        // "desligado" continua conversando com quem já estava dentro.
        if (in_array($status, ['pausado', 'arquivado'], true)) {
            $this->db->prepare(
                "UPDATE chat_sessoes SET status = 'saiu', erro_detalhe = 'fluxo :s'
                 WHERE fluxo_id = :id AND status IN ('ativo','dormindo','aguardando_resposta')"
            )->execute([':s' => $status, ':id' => $fluxoId]);
        }
        return true;
    }

    public function excluir(int $fluxoId): bool
    {
        try {
            // CASCADE cuida de nós, conexões e sessões
            $this->db->prepare("DELETE FROM chat_fluxos WHERE id = :id")->execute([':id' => $fluxoId]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    // =========================================================================
    // VALIDAÇÃO
    // =========================================================================

    /**
     * @return array{fatais:array, erros:array}
     *   fatais → estrutura quebrada (nem rascunho salva)
     *   erros  → bloqueiam publicar (rascunho salva mesmo assim)
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

        // ── Estrutura ──
        $porChave = [];
        foreach ($nos as $i => $n) {
            $chave = (string)($n['chave'] ?? '');
            $tipo  = (string)($n['tipo']  ?? '');
            if ($chave === '')                  { $fatais[] = "nó #$i sem 'chave'"; continue; }
            if (isset($porChave[$chave]))       { $fatais[] = "chave duplicada: '$chave'"; continue; }
            if (!ChatNoRegistry::existe($tipo)) { $fatais[] = "nó '$chave': tipo '$tipo' não existe"; continue; }
            $porChave[$chave] = $tipo;
        }
        if ($fatais) return ['fatais' => $fatais, 'erros' => []];

        if (!$porChave) {
            return ['fatais' => [], 'erros' => ['o fluxo está vazio']];
        }

        // ── Exatamente 1 trigger ──
        $triggers = array_keys(array_filter($porChave, fn($t) => ChatNoRegistry::ehTrigger($t)));
        if (count($triggers) !== 1) {
            $erros[] = 'o fluxo precisa de exatamente 1 gatilho de entrada (tem ' . count($triggers) . ')';
        }

        // ── Conexões válidas ──
        $adj = [];
        foreach ($conexoes as $i => $c) {
            $de    = (string)($c['de'] ?? '');
            $para  = (string)($c['para'] ?? '');
            $porta = (string)($c['porta'] ?? 'saida');

            if (!isset($porChave[$de]))   { $erros[] = "conexão #$i: origem '$de' não existe"; continue; }
            if (!isset($porChave[$para])) { $erros[] = "conexão #$i: destino '$para' não existe"; continue; }

            $portas = ChatNoRegistry::obter($porChave[$de])->portas();
            if (!in_array($porta, $portas, true)) {
                $erros[] = "conexão #$i: '$de' ({$porChave[$de]}) não tem a saída '$porta'";
                continue;
            }
            $adj[$de][] = $para;
        }

        // ── Alcançabilidade ──
        if (count($triggers) === 1) {
            $visitados = [];
            $fila = [$triggers[0]];
            while ($fila) {
                $u = array_pop($fila);
                if (isset($visitados[$u])) continue;
                $visitados[$u] = true;
                foreach ($adj[$u] ?? [] as $d) $fila[] = $d;
            }
            foreach ($porChave as $chave => $tipo) {
                if (!isset($visitados[$chave])) {
                    $erros[] = "bloco '$chave' está solto (não é alcançável a partir do gatilho)";
                }
            }
        }

        // ── Ciclo sem pausa = loop de mensagens ──
        // Num chatbot isso é pior que no motor v2: viraria spam instantâneo no
        // WhatsApp do cliente e risco real de bloqueio do número pela Meta.
        foreach ($this->encontrarCiclos($adj) as $ciclo) {
            $temPausa = false;
            foreach ($ciclo as $chave) {
                $tipo = $porChave[$chave] ?? '';
                if ($tipo === 'esperar' || ChatNoRegistry::ehPergunta($tipo)) { $temPausa = true; break; }
            }
            if (!$temPausa) {
                $erros[] = 'ciclo sem espera nem pergunta: ' . implode(' → ', $ciclo)
                         . ' — isso dispararia mensagens em loop';
            }
        }

        // ── Avisos por tipo de nó ──
        foreach ($nos as $n) {
            $chave = (string)($n['chave'] ?? '');
            $tipo  = (string)($n['tipo'] ?? '');
            $cfg   = (array)($n['config'] ?? []);

            switch ($tipo) {
                case 'msg_texto':
                    if (trim((string)($cfg['texto'] ?? '')) === '') {
                        $erros[] = "bloco '$chave': mensagem de texto vazia";
                    }
                    break;

                case 'msg_botoes':
                    $bt = array_filter((array)($cfg['botoes'] ?? []), fn($b) => trim((string)($b['titulo'] ?? '')) !== '');
                    if (!$bt) $erros[] = "bloco '$chave': nenhum botão configurado";
                    foreach ($bt as $b) {
                        if (mb_strlen(trim((string)$b['titulo'])) > 20) {
                            $erros[] = "bloco '$chave': botão \"{$b['titulo']}\" passa de 20 caracteres (limite da Meta)";
                        }
                    }
                    break;

                case 'msg_lista':
                    $n2 = 0;
                    foreach ((array)($cfg['secoes'] ?? []) as $s) {
                        foreach ((array)($s['linhas'] ?? []) as $l) {
                            if (trim((string)($l['titulo'] ?? '')) !== '') $n2++;
                        }
                    }
                    if ($n2 === 0)  $erros[] = "bloco '$chave': lista sem opções";
                    if ($n2 > 10)   $erros[] = "bloco '$chave': lista com $n2 opções (a Meta aceita no máximo 10)";
                    break;

                case 'msg_template':
                    if (trim((string)($cfg['nome'] ?? '')) === '') {
                        $erros[] = "bloco '$chave': template sem nome";
                    }
                    break;

                case 'msg_midia':
                case 'msg_botao_url':
                    if (trim((string)($cfg['url'] ?? '')) === '') {
                        $erros[] = "bloco '$chave': URL não informada";
                    }
                    break;

                case 'esperar_resposta':
                    if (trim((string)($cfg['salvar_em'] ?? '')) === '') {
                        $erros[] = "bloco '$chave': informe em qual campo salvar a resposta";
                    }
                    break;

                case 'cond_tem_tag':
                case 'acao_tag':
                    if ((int)($cfg['tag_id'] ?? 0) < 1) {
                        $erros[] = "bloco '$chave': nenhuma tag selecionada";
                    }
                    break;

                case 'ir_para_fluxo':
                    if ((int)($cfg['fluxo_id'] ?? 0) < 1) {
                        $erros[] = "bloco '$chave': fluxo de destino não selecionado";
                    }
                    break;

                case 'acao_webhook':
                    if (!preg_match('#^https?://#i', (string)($cfg['url'] ?? ''))) {
                        $erros[] = "bloco '$chave': URL do webhook precisa começar com http:// ou https://";
                    }
                    break;
            }

            // Pergunta sem nenhuma saída ligada trava a conversa para sempre
            if (ChatNoRegistry::ehPergunta($tipo)) {
                $temSaida = false;
                foreach ($conexoes as $c) {
                    if ((string)($c['de'] ?? '') === $chave) { $temSaida = true; break; }
                }
                if (!$temSaida) {
                    $erros[] = "bloco '$chave' faz uma pergunta mas nenhuma saída está ligada — a conversa morre ali";
                }
            }
        }

        return ['fatais' => [], 'erros' => array_values(array_unique($erros))];
    }

    /** DFS com pilha de recursão — 1 ciclo por back-edge. */
    private function encontrarCiclos(array $adj): array
    {
        $ciclos = [];
        $estado = [];
        $pilha  = [];

        $dfs = function ($u) use (&$dfs, &$estado, &$pilha, &$ciclos, $adj) {
            $estado[$u] = 1;
            $pilha[] = $u;
            foreach ($adj[$u] ?? [] as $v) {
                if (($estado[$v] ?? 0) === 0) {
                    $dfs($v);
                } elseif (($estado[$v] ?? 0) === 1) {
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

    // =========================================================================
    // ATIVIDADE
    // =========================================================================

    /** Timeline paginada por cursor — a tela de atividade do módulo. */
    public function atividade(array $filtros = [], int $limite = 50, int $antesDe = 0): array
    {
        $w = ["l.no_chave IN ('__inicio','__fim')"];
        $p = [];

        if (!empty($filtros['fluxo_id']))   { $w[] = "l.fluxo_id = :f";   $p[':f']  = (int)$filtros['fluxo_id']; }
        if (!empty($filtros['contato_id'])) { $w[] = "l.contato_id = :c"; $p[':c']  = (int)$filtros['contato_id']; }
        if (!empty($filtros['so_erros']))   { $w[] = "l.porta = 'erro'"; }
        if ($antesDe > 0)                   { $w[] = "l.id < :a";        $p[':a']  = $antesDe; }

        $sql = "SELECT l.*, f.nome AS fluxo_nome,
                       ct.nome AS contato_nome, ct.nome_perfil, ct.wa_id
                FROM chat_sessao_log l
                LEFT JOIN chat_fluxos f ON f.id = l.fluxo_id
                LEFT JOIN chat_contatos ct ON ct.id = l.contato_id
                WHERE " . implode(' AND ', $w) . "
                ORDER BY l.id DESC
                LIMIT " . max(1, min(200, $limite));

        $st = $this->db->prepare($sql);
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function kpis(): array
    {
        $out = ['sessoes_hoje' => 0, 'ativas' => 0, 'concluidas_hoje' => 0, 'erros_hoje' => 0];
        try {
            $out['sessoes_hoje'] = (int)$this->db->query(
                "SELECT COUNT(*) FROM chat_sessoes WHERE DATE(criado_em) = CURDATE()"
            )->fetchColumn();
            $out['ativas'] = (int)$this->db->query(
                "SELECT COUNT(*) FROM chat_sessoes WHERE status IN ('ativo','dormindo','aguardando_resposta')"
            )->fetchColumn();
            $out['concluidas_hoje'] = (int)$this->db->query(
                "SELECT COUNT(*) FROM chat_sessoes WHERE status = 'concluido' AND DATE(atualizado_em) = CURDATE()"
            )->fetchColumn();
            $out['erros_hoje'] = (int)$this->db->query(
                "SELECT COUNT(*) FROM chat_sessoes WHERE status = 'erro' AND DATE(atualizado_em) = CURDATE()"
            )->fetchColumn();
        } catch (Throwable $e) {}
        return $out;
    }
}
