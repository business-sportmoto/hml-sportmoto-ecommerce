<?php
declare(strict_types=1);

/**
 * app/services/ChatEventoLojaService.php
 *
 * O despachante de eventos da loja — a ponte entre o que acontece no site e
 * o motor de fluxos do chat.
 *
 * O bloco `gatilho_evento_loja` existia no ChatNoRegistry e na paleta do
 * editor desde sempre, com o select de evento pronto. Mas nenhuma linha do
 * projeto o disparava: nada na loja iniciava fluxo. Este service é o produtor
 * que faltava, e serve qualquer evento — carrinho é só o primeiro.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * DUAS METADES, DE PROPÓSITO
 *
 *   emitir()             lado do produtor. Um INSERT e nada mais.
 *   processarPendentes() lado do worker. Resolve contato e inicia o fluxo.
 *
 * Separadas porque quem emite (cron do carrinho) não tem — nem precisa ter —
 * o motor de fluxos no autoloader. O projeto tem três autoloaders com listas
 * diferentes (ver 02-convencoes); o do carrinho não conhece `app/services/ia/`,
 * e um fluxo com bloco de IA morreria com `Class not found`. O chat-worker
 * conhece, e é lá que o processamento roda.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * POR QUE A ESPERA MORA AQUI E NÃO NUM BLOCO `esperar`
 *
 * `ChatFluxoMotor::iniciar()` chama `encerrarSessoesAbertas()`, que mata toda
 * sessão em ('ativo','dormindo','aguardando_resposta') daquele contato. Uma
 * sessão dormindo 20h dentro do fluxo seria morta por qualquer outro fluxo
 * que começasse no meio — a pessoa comenta num reel, manda um direct, cai
 * numa palavra-chave — e a mensagem do carrinho nunca sairia, em silêncio.
 *
 * Agendando o INÍCIO, a sessão nasce curta e nunca fica exposta. Blocos
 * `esperar` seguem valendo para passos curtos dentro do fluxo.
 *
 * @see sql/chat-evento-loja-migration.sql
 */
class ChatEventoLojaService
{
    /** Eventos que o editor oferece hoje (chat-fluxo.js, UI.gatilho_evento_loja). */
    public const EVENTOS = [
        'pedido_criado'       => 'Pedido criado',
        'carrinho_abandonado' => 'Carrinho abandonado',
        'produto_visto'       => 'Produto visto',
        'pedido_entregue'     => 'Pedido entregue',
    ];

    /** Uma linha em 'processando' mais velha que isto é dada como travada. */
    private const MINUTOS_TRAVADO = 10;

    /** Tentativas antes de desistir de um evento. */
    private const MAX_TENTATIVAS = 3;

    private PDO $db;
    private ?ChatContatoService $contatos = null;

    /** Cache por request — emitir em lote não deve reconsultar a config. */
    private static ?array $configCache = null;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    private function contatos(): ChatContatoService
    {
        return $this->contatos ??= new ChatContatoService($this->db);
    }

    // =========================================================================
    // EMISSÃO — lado do produtor
    // =========================================================================

    /**
     * Enfileira um evento da loja.
     *
     * Idempotente pelo par (evento, origem_tipo, origem_id): chamar de novo
     * para o mesmo carrinho é no-op, não linha duplicada. O cron do carrinho
     * roda a cada 30 minutos e reencontra os mesmos registros — a garantia
     * precisa estar aqui, não na disciplina de quem chama.
     *
     * @param array $opts cliente_id, telefone, contexto (array),
     *                    atraso_h (float), agendado_para (Y-m-d H:i:s),
     *                    validade_h (float)
     * @return array{ok:bool, id:int, motivo:string}
     */
    public function emitir(string $evento, string $origemTipo, int $origemId, array $opts = []): array
    {
        $evento     = trim($evento);
        $origemTipo = trim($origemTipo);

        if ($evento === '' || $origemTipo === '' || $origemId < 1) {
            return ['ok' => false, 'id' => 0, 'motivo' => 'parâmetros inválidos'];
        }
        if (!isset(self::EVENTOS[$evento])) {
            // Whitelist: evento que o editor não oferece nunca casaria com
            // trigger nenhum, e ficaria acumulando linha pendente para sempre.
            return ['ok' => false, 'id' => 0, 'motivo' => "evento desconhecido: {$evento}"];
        }

        $clienteId = isset($opts['cliente_id']) ? (int)$opts['cliente_id'] : 0;
        $telefone  = preg_replace('/\D/', '', (string)($opts['telefone'] ?? ''));

        if ($clienteId < 1 && strlen((string)$telefone) < 10) {
            return ['ok' => false, 'id' => 0, 'motivo' => 'sem cliente nem telefone — não há a quem falar'];
        }

        $agendado = $this->quando($opts);
        $validade = (float)($opts['validade_h'] ?? $this->config('evento_loja_validade_h', 72));
        $expira   = $validade > 0
            ? date('Y-m-d H:i:s', strtotime($agendado) + (int)round($validade * 3600))
            : null;

        try {
            $st = $this->db->prepare(
                "INSERT INTO chat_eventos_loja
                    (evento, origem_tipo, origem_id, cliente_id, telefone,
                     contexto_json, agendado_para, expira_em)
                 VALUES (:ev, :ot, :oi, :cli, :tel, :ctx, :ag, :exp)"
            );
            $st->execute([
                ':ev'  => mb_substr($evento, 0, 40),
                ':ot'  => mb_substr($origemTipo, 0, 40),
                ':oi'  => $origemId,
                ':cli' => $clienteId > 0 ? $clienteId : null,
                ':tel' => $telefone !== '' ? mb_substr((string)$telefone, 0, 24) : null,
                ':ctx' => json_encode($opts['contexto'] ?? [], JSON_UNESCAPED_UNICODE),
                ':ag'  => $agendado,
                ':exp' => $expira,
            ]);

            return ['ok' => true, 'id' => (int)$this->db->lastInsertId(), 'motivo' => ''];

        } catch (PDOException $e) {
            // 23000/1062 = colisão do UNIQUE. NÃO é erro: é a idempotência
            // funcionando. Devolve ok=false para quem quiser contar, com
            // motivo próprio para não virar alarme no log.
            if ($e->getCode() === '23000') {
                return ['ok' => false, 'id' => 0, 'motivo' => 'já enfileirado'];
            }
            $this->logErro('emitir', $e);
            return ['ok' => false, 'id' => 0, 'motivo' => 'falha ao enfileirar'];
        }
    }

    /** Quando este evento deve virar fluxo. */
    private function quando(array $opts): string
    {
        if (!empty($opts['agendado_para'])) {
            $ts = strtotime((string)$opts['agendado_para']);
            if ($ts !== false) return date('Y-m-d H:i:s', $ts);
        }
        $atraso = isset($opts['atraso_h'])
            ? (float)$opts['atraso_h']
            : $this->config('evento_loja_atraso_h', 20);

        return date('Y-m-d H:i:s', time() + (int)round(max(0, $atraso) * 3600));
    }

    // =========================================================================
    // PROCESSAMENTO — lado do worker
    // =========================================================================

    /**
     * Consome a fila: eventos cuja hora chegou viram sessão de fluxo.
     *
     * @return array{iniciados:int, descartados:int, falhas:int, destravados:int}
     */
    public function processarPendentes(int $limite = 50): array
    {
        $out = ['iniciados' => 0, 'descartados' => 0, 'falhas' => 0,
                'destravados' => $this->destravarPresos()];

        $limite = max(1, min(200, $limite));

        $st = $this->db->prepare(
            "SELECT id FROM chat_eventos_loja
             WHERE status = 'pendente' AND agendado_para <= NOW()
             ORDER BY agendado_para ASC, id ASC
             LIMIT {$limite}"
        );
        $st->execute();
        $ids = array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC), 'id'));

        foreach ($ids as $id) {
            $ev = $this->reivindicar($id);
            if ($ev === null) continue;   // outro worker levou

            try {
                $r = $this->processarUm($ev);
            } catch (Throwable $e) {
                $this->logErro('processarUm#' . $id, $e);
                $r = ['status' => 'falhou', 'motivo' => mb_substr($e->getMessage(), 0, 180)];
            }

            $this->concluir($ev, $r);

            if ($r['status'] === 'concluido')  $out['iniciados']++;
            elseif ($r['status'] === 'descartado') $out['descartados']++;
            else $out['falhas']++;
        }

        return $out;
    }

    /**
     * Toma posse da linha. O UPDATE condicional é a fonte de verdade — dois
     * workers podem ter selecionado o mesmo id, só um consegue mudar o status.
     * Mesmo padrão do capturar() da Central de Recuperação.
     */
    private function reivindicar(int $id): ?array
    {
        $st = $this->db->prepare(
            "UPDATE chat_eventos_loja
             SET status = 'processando', tentativas = tentativas + 1, processado_em = NOW()
             WHERE id = :id AND status = 'pendente'"
        );
        $st->execute([':id' => $id]);
        if ($st->rowCount() === 0) return null;

        $sel = $this->db->prepare("SELECT * FROM chat_eventos_loja WHERE id = :id LIMIT 1");
        $sel->execute([':id' => $id]);
        return $sel->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Um evento. Devolve o desfecho — quem grava é o concluir().
     *
     * @return array{status:string, motivo:string, fluxo_id?:int, sessao_id?:int, contato_id?:int}
     */
    private function processarUm(array $ev): array
    {
        // 1. Ainda vale? Carrinho de três dias atrás não merece a mensagem
        //    que faria sentido no mesmo dia.
        if (!empty($ev['expira_em']) && strtotime((string)$ev['expira_em']) < time()) {
            return ['status' => 'descartado', 'motivo' => 'evento expirou antes de ser processado'];
        }

        // 2. Existe fluxo publicado escutando este evento?
        $fluxo = $this->fluxoParaEvento((string)$ev['evento']);
        if (!$fluxo) {
            return ['status' => 'descartado',
                    'motivo' => 'nenhum fluxo publicado escuta ' . $ev['evento']];
        }

        // 3. Quem é a pessoa, no vocabulário do chat?
        $contato = $this->resolverContato($ev);
        if (!$contato) {
            return ['status' => 'descartado', 'motivo' => 'não foi possível resolver o contato'];
        }

        // 4. Ela quer receber? Opt-out vale mais que qualquer automação.
        $pode = $this->contatos()->podeReceber($contato);
        if (empty($pode['ok'])) {
            return ['status' => 'descartado', 'motivo' => $pode['motivo'],
                    'contato_id' => (int)$contato['id']];
        }

        // 5. Inicia. O contexto do evento vira o contexto da sessão, então os
        //    blocos leem {{valor}}, {{link}} etc. sem campo novo.
        $contexto = json_decode((string)($ev['contexto_json'] ?? '{}'), true);
        if (!is_array($contexto)) $contexto = [];
        $contexto['evento']      = $ev['evento'];
        $contexto['origem_tipo'] = $ev['origem_tipo'];
        $contexto['origem_id']   = (int)$ev['origem_id'];

        $sessaoId = (new ChatFluxoMotor($this->db))->iniciar(
            (int)$fluxo['id'], (int)$contato['id'], $contexto
        );

        if ($sessaoId === null) {
            // iniciar() devolve null por reentrada bloqueada, fluxo sem
            // trigger ou versão despublicada — nenhum deles melhora tentando
            // de novo, então é descarte e não falha.
            return ['status' => 'descartado',
                    'motivo' => 'o motor recusou iniciar (reentrada ou fluxo inválido)',
                    'fluxo_id' => (int)$fluxo['id'], 'contato_id' => (int)$contato['id']];
        }

        $this->avisarOrigem($ev, $fluxo, $contato);

        return ['status' => 'concluido', 'motivo' => '',
                'fluxo_id' => (int)$fluxo['id'], 'sessao_id' => $sessaoId,
                'contato_id' => (int)$contato['id']];
    }

    /**
     * Conta para quem produziu o evento que a automação assumiu o caso.
     *
     * Bloco próprio com try/catch: é efeito colateral pós-conclusão. A sessão
     * de fluxo já existe e já andou; uma falha ao registrar não pode virar
     * falha do evento e provocar retentativa — que mandaria a mensagem de novo.
     */
    private function avisarOrigem(array $ev, array $fluxo, array $contato): void
    {
        if ((string)$ev['origem_tipo'] !== 'carrinho_recuperacao') return;
        if (!class_exists('CarrinhoRecuperacaoService')) return;

        try {
            (new CarrinhoRecuperacaoService())->registrarDaAutomacao(
                (int)$ev['origem_id'],
                'fluxo_iniciado',
                'Automação assumiu — fluxo "' . $fluxo['nome'] . '" por '
                    . ((string)($contato['canal'] ?? 'whatsapp') === 'instagram' ? 'Instagram' : 'WhatsApp'),
                ['fluxo_id' => (int)$fluxo['id'], 'contato_id' => (int)$contato['id']]
            );
        } catch (Throwable $e) {
            $this->logErro('avisarOrigem', $e);
        }
    }

    /** Grava o desfecho. Falha volta para 'pendente' enquanto houver tentativa. */
    private function concluir(array $ev, array $r): void
    {
        $status = $r['status'];

        if ($status === 'falhou' && (int)$ev['tentativas'] < self::MAX_TENTATIVAS) {
            $status = 'pendente';   // devolve à fila; agendado_para não muda
        }

        $this->db->prepare(
            "UPDATE chat_eventos_loja
             SET status = :s, motivo = :m, fluxo_id = :f, sessao_id = :ses,
                 contato_id = :c, processado_em = NOW()
             WHERE id = :id"
        )->execute([
            ':s'   => $status,
            ':m'   => $r['motivo'] !== '' ? mb_substr($r['motivo'], 0, 180) : null,
            ':f'   => $r['fluxo_id']   ?? null,
            ':ses' => $r['sessao_id']  ?? null,
            ':c'   => $r['contato_id'] ?? ($ev['contato_id'] ?? null),
            ':id'  => (int)$ev['id'],
        ]);
    }

    /**
     * Linhas presas em 'processando' voltam para a fila.
     *
     * O `processado_em IS NULL` é explícito de propósito: comparação com NULL
     * é NULL, e NULL nunca é verdadeiro — uma linha sem timestamp sumiria das
     * duas pontas e ficaria presa para sempre. Foi exatamente o ponto cego do
     * watchdog da IA (ver ia-arquitetura-e-contratos §4).
     */
    public function destravarPresos(): int
    {
        $st = $this->db->prepare(
            "UPDATE chat_eventos_loja
             SET status = 'pendente',
                 motivo = 'retomado após processamento travado'
             WHERE status = 'processando'
               AND (processado_em IS NULL
                    OR processado_em < DATE_SUB(NOW(), INTERVAL :min MINUTE))"
        );
        $st->execute([':min' => self::MINUTOS_TRAVADO]);
        return $st->rowCount();
    }

    // =========================================================================
    // RESOLUÇÃO
    // =========================================================================

    /**
     * O fluxo publicado que escuta este evento.
     *
     * O vínculo mora no `config_json` do nó de trigger — o operador escolhe o
     * evento dentro do bloco, no editor. Não há tabela de amarração e nem tela
     * nova: desenhar o fluxo já é configurá-lo.
     *
     * Empate resolve por `prioridade` ASC (menor vem antes), a mesma convenção
     * dos gatilhos de palavra-chave.
     */
    public function fluxoParaEvento(string $evento): ?array
    {
        $st = $this->db->prepare(
            "SELECT f.id, f.nome, f.prioridade, n.config_json
             FROM chat_fluxos f
             JOIN chat_fluxo_nos n
               ON n.fluxo_id = f.id
              AND n.versao   = f.versao_publicada
              AND n.tipo_no  = 'gatilho_evento_loja'
             WHERE f.status = 'publicado'
               AND f.versao_publicada > 0
             ORDER BY f.prioridade ASC, f.id ASC"
        );
        $st->execute();

        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            $cfg = json_decode((string)($linha['config_json'] ?? '{}'), true);
            if (!is_array($cfg)) continue;
            if (trim((string)($cfg['evento'] ?? '')) === $evento) {
                return ['id' => (int)$linha['id'], 'nome' => (string)$linha['nome']];
            }
        }
        return null;
    }

    /**
     * Do evento para o contato do chat.
     *
     * Ordem deliberada:
     *   1. contato já vinculado ao cliente — preserva histórico e janela
     *   2. telefone — cria o contato se ainda não existir
     *
     * A resolução acontece AQUI, no processamento, e não na emissão: entre
     * emitir e disparar podem passar 20 horas, e nesse intervalo a pessoa pode
     * ter virado contato por outro caminho (mandou um direct, respondeu uma
     * campanha). Resolver na emissão congelaria uma resposta velha.
     */
    public function resolverContato(array $ev): ?array
    {
        $clienteId = (int)($ev['cliente_id'] ?? 0);

        if ($clienteId > 0) {
            // Mais de um canal para o mesmo cliente? O mais recentemente ativo
            // é onde a conversa está viva. O fluxo tem cond_canal para ramificar.
            $st = $this->db->prepare(
                "SELECT * FROM chat_contatos
                 WHERE cliente_id = :c AND bloqueado = 0
                 ORDER BY ultima_entrada_em DESC, id DESC
                 LIMIT 1"
            );
            $st->execute([':c' => $clienteId]);
            if ($contato = $st->fetch(PDO::FETCH_ASSOC)) return $contato;
        }

        $telefone = preg_replace('/\D/', '', (string)($ev['telefone'] ?? ''));
        if (strlen((string)$telefone) < 10) return null;

        try {
            $contato = $this->contatos()->garantir((string)$telefone, [
                'cliente_id' => $clienteId > 0 ? $clienteId : null,
                'origem'     => 'loja',
                'origem_ref' => (string)$ev['evento'],
            ]);
            return !empty($contato['id']) ? $contato : null;
        } catch (Throwable $e) {
            $this->logErro('resolverContato', $e);
            return null;
        }
    }

    // =========================================================================
    // CONSULTA (para telas e diagnóstico)
    // =========================================================================

    /** Situação da fila por evento e status. */
    public function resumo(): array
    {
        return $this->db->query(
            "SELECT evento, status, COUNT(*) AS total,
                    MIN(agendado_para) AS proximo
             FROM chat_eventos_loja
             GROUP BY evento, status
             ORDER BY evento, status"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** O evento de uma origem específica, se houver. */
    public function porOrigem(string $origemTipo, int $origemId): array
    {
        $st = $this->db->prepare(
            "SELECT * FROM chat_eventos_loja
             WHERE origem_tipo = :ot AND origem_id = :oi
             ORDER BY id DESC"
        );
        $st->execute([':ot' => $origemTipo, ':oi' => $origemId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Config vigente com fallback, no mesmo padrão do CarrinhoRecuperacaoService:
     * banco → default do chamador. O try/catch cobre o deploy em que o código
     * novo sobe antes da migração — o despachante não para por config ausente.
     */
    private function config(string $chave, float $default): float
    {
        if (self::$configCache === null) {
            self::$configCache = [];
            try {
                foreach ($this->db->query("SELECT chave, valor FROM recuperacao_config")
                                  ->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    self::$configCache[$r['chave']] = (float)$r['valor'];
                }
            } catch (Throwable $e) { /* migração pendente — defaults */ }
        }
        return self::$configCache[$chave] ?? $default;
    }

    private function logErro(string $onde, Throwable $e): void
    {
        if (class_exists('LogService')) {
            try {
                LogService::error("ChatEventoLoja::{$onde}: " . $e->getMessage(), [], 'chat');
                return;
            } catch (Throwable $x) { /* cai no error_log */ }
        }
        error_log("[ChatEventoLoja] {$onde}: " . $e->getMessage());
    }
}
