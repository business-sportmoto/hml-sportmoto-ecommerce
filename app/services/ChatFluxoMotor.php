<?php
/**
 * app/services/ChatFluxoMotor.php
 *
 * Executor do grafo conversacional.
 *
 * DIFERENÇA PARA O FluxoMotor (automação v2): lá o motor é 100% dirigido por
 * worker — tudo acontece em background, em minutos. Aqui a conversa é síncrona:
 * quando o contato responde, o webhook chama entregarResposta() e o fluxo anda
 * NA HORA. O worker cobre só o que é temporal (esperas e timeouts).
 *
 * CICLO DE VIDA DE UMA SESSÃO:
 *   ativo ──► executa nós até parar
 *      │
 *      ├─► dormindo             (nó esperar)          → worker acorda
 *      ├─► aguardando_resposta  (botões/lista/pergunta) → webhook acorda
 *      ├─► concluido | saiu | erro                     → fim
 *
 * REENTRADA: config_json do fluxo aceita
 *   {"reentrada":"nunca"|"sempre"|"apos_dias:N"}
 * O padrão é "sempre" — ao contrário do motor v2, onde o padrão é "nunca".
 * Num chatbot, bloquear reentrada por padrão significa que o cliente manda
 * "menu" na segunda vez e o bot fica mudo, que é sempre um bug.
 */
class ChatFluxoMotor
{
    /** Passos máximos por rodada — proteção anti-loop. */
    private const MAX_PASSOS = 40;

    private PDO $db;
    private ChatExecCtx $ctx;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();

        // As classes de nó vivem dentro do registry; o autoload não as acha
        if (!class_exists('ChatNoRegistry', false)) {
            $base = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2);
            require_once $base . '/app/services/ChatNoRegistry.php';
        }
        $this->ctx = new ChatExecCtx($this->db);
    }

    // =========================================================================
    // INÍCIO
    // =========================================================================

    /**
     * Inicia uma sessão de fluxo para um contato e já executa o primeiro trecho.
     *
     * @return int|null id da sessão, ou null se o fluxo não está apto / reentrada bloqueada
     */
    public function iniciar(int $fluxoId, int $contatoId, array $contexto = [], ?int $conversaId = null): ?int
    {
        try {
            $fluxo = $this->carregarFluxo($fluxoId);
            if (!$fluxo || $fluxo['status'] !== 'publicado' || (int)$fluxo['versao_publicada'] < 1) {
                return null;
            }
            $versao = (int)$fluxo['versao_publicada'];

            if (!$this->reentradaPermitida($fluxo, $contatoId)) return null;

            // Nó de entrada = o trigger da versão publicada
            $trigger = $this->acharTrigger($fluxoId, $versao);
            if (!$trigger) return null;

            if ($conversaId === null) {
                // O canal precisa vir do contato. Sem isto, um fluxo disparado
                // por comentário do Instagram amarrava a sessão a uma conversa
                // de WhatsApp — criando uma conversa fantasma por contato do IG.
                $canal = 'whatsapp';
                try {
                    $stC = $this->db->prepare("SELECT canal FROM chat_contatos WHERE id = :id LIMIT 1");
                    $stC->execute([':id' => $contatoId]);
                    $canal = (string)($stC->fetchColumn() ?: 'whatsapp');
                } catch (Throwable $e) { /* fica no padrão */ }

                $cv = $this->ctx->conversas->obterPorContato($contatoId, $canal)
                   ?: $this->ctx->conversas->garantir($contatoId, $canal);
                $conversaId = (int)($cv['id'] ?? 0) ?: null;
            }

            // Uma sessão nova substitui a anterior: duas jornadas simultâneas
            // no mesmo WhatsApp viram duas mensagens intercaladas e confusão.
            $this->encerrarSessoesAbertas($contatoId, 'substituida por novo fluxo');

            $st = $this->db->prepare(
                "INSERT INTO chat_sessoes
                    (fluxo_id, versao, contato_id, conversa_id, no_atual, status, contexto_json)
                 VALUES (:f, :v, :c, :cv, :no, 'ativo', :ctx)"
            );
            $st->execute([
                ':f'   => $fluxoId,
                ':v'   => $versao,
                ':c'   => $contatoId,
                ':cv'  => $conversaId,
                ':no'  => $trigger['chave'],
                ':ctx' => json_encode($contexto, JSON_UNESCAPED_UNICODE),
            ]);
            $sessaoId = (int)$this->db->lastInsertId();
            if ($sessaoId < 1) return null;

            $this->db->prepare("UPDATE chat_fluxos SET total_iniciados = total_iniciados + 1 WHERE id = :id")
                     ->execute([':id' => $fluxoId]);

            $this->logPasso($sessaoId, $fluxoId, $versao, $contatoId, '__inicio', $trigger['tipo_no'], 'inicio');

            // Anda imediatamente — o contato está do outro lado esperando
            $this->processar($sessaoId);

            return $sessaoId;
        } catch (Throwable $e) {
            $this->logErro('iniciar', $e);
            return null;
        }
    }

    // =========================================================================
    // ENTREGA DE RESPOSTA (chamado pelo webhook)
    // =========================================================================

    /**
     * O contato respondeu. Se houver sessão esperando, injeta a resposta e
     * continua a jornada na hora.
     *
     * @param array $resposta ['tipo'=>'texto|botao|lista','texto'=>..,'id'=>..,'titulo'=>..]
     * @return bool true se alguma sessão consumiu a resposta
     */
    public function entregarResposta(int $contatoId, array $resposta): bool
    {
        try {
            $st = $this->db->prepare(
                "SELECT * FROM chat_sessoes
                 WHERE contato_id = :c AND status = 'aguardando_resposta'
                 ORDER BY id DESC LIMIT 1"
            );
            $st->execute([':c' => $contatoId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) return false;

            $contexto = json_decode($row['contexto_json'] ?? '{}', true) ?: [];
            $contexto['_resposta_' . $row['no_atual']] = $resposta;

            // Claim: só reativa se ainda estiver esperando (evita corrida com
            // o worker resolvendo o timeout no mesmo instante)
            $up = $this->db->prepare(
                "UPDATE chat_sessoes
                 SET status = 'ativo', aguardando_ate = NULL, contexto_json = :ctx
                 WHERE id = :id AND status = 'aguardando_resposta'"
            );
            $up->execute([
                ':ctx' => json_encode($contexto, JSON_UNESCAPED_UNICODE),
                ':id'  => (int)$row['id'],
            ]);
            if ($up->rowCount() === 0) return false;

            $this->processar((int)$row['id']);
            return true;
        } catch (Throwable $e) {
            $this->logErro('entregarResposta', $e);
            return false;
        }
    }

    /** Existe sessão aguardando resposta deste contato? */
    public function temSessaoAguardando(int $contatoId): bool
    {
        try {
            $st = $this->db->prepare(
                "SELECT 1 FROM chat_sessoes
                 WHERE contato_id = :c AND status = 'aguardando_resposta' LIMIT 1"
            );
            $st->execute([':c' => $contatoId]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    public function temSessaoAtiva(int $contatoId): bool
    {
        try {
            $st = $this->db->prepare(
                "SELECT 1 FROM chat_sessoes
                 WHERE contato_id = :c AND status IN ('ativo','dormindo','aguardando_resposta') LIMIT 1"
            );
            $st->execute([':c' => $contatoId]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    public function encerrarSessoesAbertas(int $contatoId, string $motivo = 'encerrada'): int
    {
        try {
            $st = $this->db->prepare(
                "UPDATE chat_sessoes SET status = 'saiu', erro_detalhe = :m
                 WHERE contato_id = :c AND status IN ('ativo','dormindo','aguardando_resposta')"
            );
            $st->execute([':m' => mb_substr($motivo, 0, 200), ':c' => $contatoId]);
            return $st->rowCount();
        } catch (Throwable $e) {
            return 0;
        }
    }

    // =========================================================================
    // WORKER
    // =========================================================================

    /** Sessões que acordaram (esperar) ou ficaram ativas por algum motivo. */
    public function processarProntas(int $limite = 100, int $maxSegundos = 100): array
    {
        $stats  = ['processadas' => 0, 'concluidas' => 0, 'dormindo' => 0,
                   'aguardando' => 0, 'saiu' => 0, 'erros' => 0];
        $inicio = time();

        try {
            $st = $this->db->prepare(
                "SELECT id FROM chat_sessoes
                 WHERE status = 'ativo'
                    OR (status = 'dormindo' AND dormir_ate IS NOT NULL AND dormir_ate <= NOW())
                 ORDER BY id ASC
                 LIMIT " . max(1, min(500, $limite))
            );
            $st->execute();

            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
                if ((time() - $inicio) >= $maxSegundos) break;
                $r = $this->processar((int)$id);
                $stats['processadas']++;
                $stats[$r] = ($stats[$r] ?? 0) + 1;
            }
        } catch (Throwable $e) {
            $this->logErro('processarProntas', $e);
        }
        return $stats;
    }

    /**
     * Sessões que esperavam resposta e estouraram o prazo.
     * Injeta uma "resposta de timeout" — o nó decide para onde ir.
     */
    public function resolverTimeouts(int $limite = 200): int
    {
        $n = 0;
        try {
            $st = $this->db->prepare(
                "SELECT id, no_atual, contexto_json FROM chat_sessoes
                 WHERE status = 'aguardando_resposta'
                   AND aguardando_ate IS NOT NULL AND aguardando_ate <= NOW()
                 ORDER BY id ASC LIMIT " . max(1, min(1000, $limite))
            );
            $st->execute();

            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $ctx = json_decode($row['contexto_json'] ?? '{}', true) ?: [];
                $ctx['_resposta_' . $row['no_atual']] = ['tipo' => 'timeout'];

                $up = $this->db->prepare(
                    "UPDATE chat_sessoes
                     SET status = 'ativo', aguardando_ate = NULL, contexto_json = :ctx
                     WHERE id = :id AND status = 'aguardando_resposta'"
                );
                $up->execute([
                    ':ctx' => json_encode($ctx, JSON_UNESCAPED_UNICODE),
                    ':id'  => (int)$row['id'],
                ]);
                if ($up->rowCount() === 0) continue;

                $this->processar((int)$row['id']);
                $n++;
            }
        } catch (Throwable $e) {
            $this->logErro('resolverTimeouts', $e);
        }
        return $n;
    }

    // =========================================================================
    // EXECUÇÃO DE UMA SESSÃO
    // =========================================================================

    /** @return string concluidas|dormindo|aguardando|saiu|erros */
    public function processar(int $sessaoId): string
    {
        try {
            $st = $this->db->prepare("SELECT * FROM chat_sessoes WHERE id = :id LIMIT 1");
            $st->execute([':id' => $sessaoId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) return 'erros';

            if (!in_array($row['status'], ['ativo', 'dormindo'], true)) {
                return $row['status'] === 'aguardando_resposta' ? 'aguardando' : 'concluidas';
            }

            // Claim otimista — dois workers não podem andar a mesma sessão.
            //
            // `passos = passos + 1` não é decorativo: o PDO do MySQL conta
            // linhas ALTERADAS, não casadas. Sem um valor que muda de verdade,
            // uma sessão que já está 'ativo' com dormir_ate NULL devolveria
            // rowCount 0 e o claim falharia contra si mesmo.
            $claim = $this->db->prepare(
                "UPDATE chat_sessoes
                 SET status = 'ativo', dormir_ate = NULL, passos = passos + 1
                 WHERE id = :id AND status IN ('ativo','dormindo')"
            );
            $claim->execute([':id' => $sessaoId]);
            if ($claim->rowCount() === 0) return 'erros';

            return $this->caminhar($row);
        } catch (Throwable $e) {
            $this->logErro('processar', $e);
            return 'erros';
        }
    }

    private function caminhar(array $row): string
    {
        $sessao = [
            'id'             => (int)$row['id'],
            'fluxo_id'       => (int)$row['fluxo_id'],
            'versao'         => (int)$row['versao'],
            'contato_id'     => (int)$row['contato_id'],
            'conversa_id'    => $row['conversa_id'] !== null ? (int)$row['conversa_id'] : null,
            'no_atual'       => (string)$row['no_atual'],
            'contexto'       => json_decode($row['contexto_json'] ?? '{}', true) ?: [],
            'dormir_ate'     => null,
            'aguardando_ate' => null,
            'erro_detalhe'   => null,
        ];

        // Contato some (excluído/anonimizado) → não há para quem falar
        $contato = $this->ctx->contatos->obter($sessao['contato_id']);
        if (!$contato) {
            $sessao['erro_detalhe'] = 'contato inexistente';
            $this->finalizar($sessao, 'erro');
            return 'erros';
        }
        $this->ctx->contato = $contato;
        $this->ctx->vars    = array_merge(
            $this->ctx->contatos->variaveis($contato),
            // valores públicos do contexto viram {{vars}} do fluxo
            array_filter($sessao['contexto'], fn($k) => $k[0] !== '_', ARRAY_FILTER_USE_KEY)
        );

        // Opt-out durante a jornada encerra na hora
        if ((int)$contato['optin'] !== 1 || (int)$contato['bloqueado'] === 1) {
            $sessao['erro_detalhe'] = 'contato sem permissão de envio';
            $this->finalizar($sessao, 'saiu');
            return 'saiu';
        }

        $nos      = $this->carregarNos($sessao['fluxo_id'], $sessao['versao']);
        $conexoes = $this->carregarConexoes($sessao['fluxo_id'], $sessao['versao']);

        $passos = 0;
        while ($passos++ < self::MAX_PASSOS) {
            $chave = $sessao['no_atual'];
            $no    = $nos[$chave] ?? null;

            if (!$no) {
                $sessao['erro_detalhe'] = "nó '$chave' não existe na v{$sessao['versao']}";
                $this->finalizar($sessao, 'erro');
                return 'erros';
            }

            $handler = ChatNoRegistry::obter($no['tipo_no']);
            if (!$handler) {
                $sessao['erro_detalhe'] = "tipo '{$no['tipo_no']}' desconhecido";
                $this->finalizar($sessao, 'erro');
                return 'erros';
            }

            $config = json_decode($no['config_json'] ?? '{}', true) ?: [];
            $t0     = microtime(true);

            try {
                $porta = $handler->executar($sessao, $config, $this->ctx);
            } catch (Throwable $e) {
                $sessao['erro_detalhe'] = mb_substr($e->getMessage(), 0, 200);
                $porta = ChatNo::ERRO;
            }

            $this->logPasso(
                $sessao['id'], $sessao['fluxo_id'], $sessao['versao'], $sessao['contato_id'],
                $chave, $no['tipo_no'], $porta,
                $porta === ChatNo::ERRO ? mb_substr((string)$sessao['erro_detalhe'], 0, 200) : null,
                (int)round((microtime(true) - $t0) * 1000)
            );

            // ── Retornos especiais ──
            if ($porta === ChatNo::DORMIR) {
                $this->salvar($sessao, 'dormindo');
                return 'dormindo';
            }
            if ($porta === ChatNo::AGUARDAR) {
                $this->salvar($sessao, 'aguardando_resposta');
                return 'aguardando';
            }
            if ($porta === ChatNo::ENCERRAR) {
                $this->finalizar($sessao, 'concluido');
                return 'concluidas';
            }
            if ($porta === ChatNo::ERRO) {
                $this->finalizar($sessao, 'erro');
                return 'erros';
            }
            if ($porta === ChatNo::PULAR) {
                $destino = (int)($sessao['contexto']['_pular_para'] ?? 0);
                $this->finalizar($sessao, 'concluido');
                if ($destino > 0) {
                    $herdado = array_filter($sessao['contexto'], fn($k) => $k[0] !== '_', ARRAY_FILTER_USE_KEY);
                    $this->iniciar($destino, $sessao['contato_id'], $herdado, $sessao['conversa_id']);
                }
                return 'concluidas';
            }

            // ── Porta normal ──
            $destino = $conexoes[$chave][$porta] ?? null;
            if ($destino === null) {
                // Porta sem ligação = fim natural daquele braço
                $this->finalizar($sessao, 'concluido');
                return 'concluidas';
            }
            $sessao['no_atual'] = $destino;
        }

        // Estourou o orçamento de passos: dorme 1 min e retoma (anti-loop)
        $sessao['dormir_ate'] = date('Y-m-d H:i:s', time() + 60);
        $this->salvar($sessao, 'dormindo');
        return 'dormindo';
    }

    // =========================================================================
    // PERSISTÊNCIA
    // =========================================================================

    private function salvar(array $sessao, string $status): void
    {
        $this->db->prepare(
            "UPDATE chat_sessoes
             SET no_atual = :no, status = :st, dormir_ate = :du, aguardando_ate = :aa,
                 contexto_json = :ctx, erro_detalhe = :err
             WHERE id = :id"
        )->execute([
            ':no'  => $sessao['no_atual'],
            ':st'  => $status,
            ':du'  => $sessao['dormir_ate'],
            ':aa'  => $sessao['aguardando_ate'],
            ':ctx' => json_encode($sessao['contexto'], JSON_UNESCAPED_UNICODE),
            ':err' => $sessao['erro_detalhe'],
            ':id'  => $sessao['id'],
        ]);
    }

    private function finalizar(array $sessao, string $status): void
    {
        $this->db->prepare(
            "UPDATE chat_sessoes
             SET status = :st, contexto_json = :ctx, erro_detalhe = :err,
                 dormir_ate = NULL, aguardando_ate = NULL
             WHERE id = :id"
        )->execute([
            ':st'  => $status,
            ':ctx' => json_encode($sessao['contexto'], JSON_UNESCAPED_UNICODE),
            ':err' => $sessao['erro_detalhe'],
            ':id'  => $sessao['id'],
        ]);

        if ($status === 'concluido') {
            $this->db->prepare("UPDATE chat_fluxos SET total_concluidos = total_concluidos + 1 WHERE id = :id")
                     ->execute([':id' => $sessao['fluxo_id']]);
        }

        $this->logPasso(
            $sessao['id'], $sessao['fluxo_id'], $sessao['versao'], $sessao['contato_id'],
            '__fim', null, $status, $sessao['erro_detalhe'] ? mb_substr((string)$sessao['erro_detalhe'], 0, 200) : null
        );
    }

    // =========================================================================
    // CARREGAMENTO
    // =========================================================================

    private function carregarFluxo(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM chat_fluxos WHERE id = :id LIMIT 1");
        $st->execute([':id' => $id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function acharTrigger(int $fluxoId, int $versao): ?array
    {
        $st = $this->db->prepare(
            "SELECT chave, tipo_no FROM chat_fluxo_nos WHERE fluxo_id = :f AND versao = :v"
        );
        $st->execute([':f' => $fluxoId, ':v' => $versao]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $no) {
            if (ChatNoRegistry::ehTrigger($no['tipo_no'])) return $no;
        }
        return null;
    }

    /** @return array<string,array> chave → nó */
    private function carregarNos(int $fluxoId, int $versao): array
    {
        $st = $this->db->prepare(
            "SELECT chave, tipo_no, config_json FROM chat_fluxo_nos WHERE fluxo_id = :f AND versao = :v"
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
            "SELECT no_origem, porta, no_destino FROM chat_fluxo_conexoes
             WHERE fluxo_id = :f AND versao = :v"
        );
        $st->execute([':f' => $fluxoId, ':v' => $versao]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $out[$c['no_origem']][$c['porta']] = $c['no_destino'];
        }
        return $out;
    }

    // =========================================================================
    // REENTRADA
    // =========================================================================

    private function reentradaPermitida(array $fluxo, int $contatoId): bool
    {
        $cfg  = json_decode($fluxo['config_json'] ?? '{}', true) ?: [];
        $modo = (string)($cfg['reentrada'] ?? 'sempre');

        try {
            if ($modo === 'nunca') {
                $st = $this->db->prepare(
                    "SELECT 1 FROM chat_sessoes WHERE fluxo_id = :f AND contato_id = :c LIMIT 1"
                );
                $st->execute([':f' => $fluxo['id'], ':c' => $contatoId]);
                return !$st->fetchColumn();
            }

            if (str_starts_with($modo, 'apos_dias:')) {
                $dias = max(1, (int)substr($modo, 10));
                $st = $this->db->prepare(
                    "SELECT 1 FROM chat_sessoes
                     WHERE fluxo_id = :f AND contato_id = :c
                       AND criado_em > DATE_SUB(NOW(), INTERVAL $dias DAY)
                     LIMIT 1"
                );
                $st->execute([':f' => $fluxo['id'], ':c' => $contatoId]);
                return !$st->fetchColumn();
            }

            return true;   // 'sempre'
        } catch (Throwable $e) {
            return false;  // na dúvida, não duplica jornada
        }
    }

    // =========================================================================
    // LOG
    // =========================================================================

    private function logPasso(
        int $sessaoId, int $fluxoId, int $versao, ?int $contatoId,
        string $noChave, ?string $tipoNo, string $porta,
        ?string $detalhe = null, ?int $duracaoMs = null
    ): void {
        try {
            $this->db->prepare(
                "INSERT INTO chat_sessao_log
                    (sessao_id, fluxo_id, versao, contato_id, no_chave, tipo_no, porta, detalhe, duracao_ms)
                 VALUES (:s, :f, :v, :c, :n, :t, :p, :d, :ms)"
            )->execute([
                ':s'  => $sessaoId,
                ':f'  => $fluxoId,
                ':v'  => $versao,
                ':c'  => $contatoId,
                ':n'  => mb_substr($noChave, 0, 40),
                ':t'  => $tipoNo ? mb_substr($tipoNo, 0, 40) : null,
                ':p'  => mb_substr($porta, 0, 24),
                ':d'  => $detalhe,
                ':ms' => $duracaoMs !== null ? min(65535, max(0, $duracaoMs)) : null,
            ]);
        } catch (Throwable $e) {}
    }

    private function logErro(string $onde, Throwable $e): void
    {
        if (!class_exists('LogService')) return;
        try { LogService::error("ChatFluxoMotor::$onde: " . $e->getMessage(), [], 'chat'); }
        catch (Throwable $x) {}
    }

    // =========================================================================
    // ESTATÍSTICAS (balões do canvas)
    // =========================================================================

    public function statsPorNo(int $fluxoId, int $versao): array
    {
        try {
            $st = $this->db->prepare(
                "SELECT no_chave, porta, COUNT(*) AS n
                 FROM chat_sessao_log
                 WHERE fluxo_id = :f AND versao = :v AND no_chave NOT LIKE '\\_\\_%'
                 GROUP BY no_chave, porta"
            );
            $st->execute([':f' => $fluxoId, ':v' => $versao]);

            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $chave = $r['no_chave'];
                $out[$chave]['total'] = ($out[$chave]['total'] ?? 0) + (int)$r['n'];
                $out[$chave]['portas'][$r['porta']] = (int)$r['n'];
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }
}
