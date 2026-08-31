<?php
/**
 * app/services/ChatGatilhoService.php
 *
 * Decide o que fazer com uma mensagem recebida que NÃO é resposta a uma
 * pergunta em andamento. É o roteador de entrada do bot.
 *
 * ORDEM DE AVALIAÇÃO (a primeira que casar vence):
 *   1. palavra de opt-out          → descadastra e para
 *   2. gatilho de referência       → veio de link wa.me com código
 *   3. gatilho de palavra-chave    → por prioridade (menor número = antes)
 *   4. boas-vindas                 → só se é a primeira mensagem da vida
 *   5. resposta padrão             → a rede de segurança
 *
 * Boas-vindas vem DEPOIS das palavras-chave de propósito: se a primeira
 * mensagem da pessoa já é "quero comprar", atender o pedido é melhor do que
 * responder com uma saudação genérica.
 */
class ChatGatilhoService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    // =========================================================================
    // RESOLUÇÃO
    // =========================================================================

    /**
     * @param array $contato  contato hidratado
     * @param array $mensagem ['texto'=>..,'tipo'=>..,'referencia'=>..,'botao_id'=>..]
     * @return array{acao:string, gatilho:?array, motivo:string}
     *         acao: fluxo|mensagem|tag|humano|optout|nenhuma
     */
    public function resolver(array $contato, array $mensagem): array
    {
        $texto = trim((string)($mensagem['texto'] ?? ''));

        // ── 1. Opt-out ──
        if ($texto !== '' && $this->ehPalavraDeOptOut($texto)) {
            return ['acao' => 'optout', 'gatilho' => null, 'motivo' => 'palavra de opt-out'];
        }

        $gatilhos = $this->ativos();

        // ── 2. Referência (wa.me?text=... ou anúncio) ──
        $ref = trim((string)($mensagem['referencia'] ?? ''));
        if ($ref !== '') {
            foreach ($gatilhos as $g) {
                if ($g['tipo'] !== 'referencia') continue;
                if (strcasecmp(trim((string)$g['padrao']), $ref) === 0) {
                    return ['acao' => $g['acao'], 'gatilho' => $g, 'motivo' => "referência '$ref'"];
                }
            }
        }

        // ── 3. Palavra-chave ──
        if ($texto !== '') {
            foreach ($gatilhos as $g) {
                if ($g['tipo'] !== 'palavra_chave') continue;
                if ($this->casa($texto, (string)$g['padrao'], (string)$g['modo_match'])) {
                    return ['acao' => $g['acao'], 'gatilho' => $g, 'motivo' => "palavra-chave '{$g['padrao']}'"];
                }
            }
        }

        // ── 3b. Mídia sem texto ──
        if ($texto === '' && !empty($mensagem['tipo']) && $mensagem['tipo'] !== 'text') {
            foreach ($gatilhos as $g) {
                if ($g['tipo'] === 'midia') {
                    return ['acao' => $g['acao'], 'gatilho' => $g, 'motivo' => 'mídia recebida'];
                }
            }
        }

        // ── 4. Boas-vindas (primeira mensagem da vida do contato) ──
        if ((int)($contato['total_entrada'] ?? 0) <= 1) {
            foreach ($gatilhos as $g) {
                if ($g['tipo'] === 'boas_vindas') {
                    return ['acao' => $g['acao'], 'gatilho' => $g, 'motivo' => 'primeira mensagem'];
                }
            }
        }

        // ── 5. Padrão ──
        foreach ($gatilhos as $g) {
            if ($g['tipo'] === 'padrao') {
                return ['acao' => $g['acao'], 'gatilho' => $g, 'motivo' => 'resposta padrão'];
            }
        }

        return ['acao' => 'nenhuma', 'gatilho' => null, 'motivo' => 'nenhum gatilho casou'];
    }

    /**
     * Executa a ação decidida por resolver().
     * @return array{ok:bool, detalhe:string}
     */
    public function executar(array $decisao, array $contato, array $mensagem = []): array
    {
        $acao    = (string)($decisao['acao'] ?? 'nenhuma');
        $gatilho = $decisao['gatilho'] ?? null;

        $contatoId = (int)$contato['id'];
        $contatos  = new ChatContatoService($this->db);

        if ($acao === 'optout') {
            $contatos->optOut($contatoId, 'palavra-chave de descadastro');
            (new ChatEnvioService($this->db))->texto(
                $contatoId,
                'Pronto! Você não vai mais receber nossas mensagens. '
                . 'Se mudar de ideia, é só mandar *voltar* a qualquer momento.',
                ['origem' => 'gatilho']
            );
            return ['ok' => true, 'detalhe' => 'opt-out registrado'];
        }

        if (!$gatilho) return ['ok' => false, 'detalhe' => 'sem gatilho'];

        $this->registrarDisparo((int)$gatilho['id']);

        switch ($acao) {
            case 'fluxo':
                $fluxoId = (int)($gatilho['fluxo_id'] ?? 0);
                if ($fluxoId < 1) return ['ok' => false, 'detalhe' => 'gatilho sem fluxo'];

                $sessaoId = (new ChatFluxoMotor($this->db))->iniciar($fluxoId, $contatoId, [
                    '_gatilho_id'   => (int)$gatilho['id'],
                    '_gatilho_tipo' => $gatilho['tipo'],
                    'mensagem_recebida' => (string)($mensagem['texto'] ?? ''),
                ]);
                return $sessaoId
                    ? ['ok' => true,  'detalhe' => "fluxo $fluxoId iniciado (sessão $sessaoId)"]
                    : ['ok' => false, 'detalhe' => "fluxo $fluxoId não iniciou (pausado ou reentrada bloqueada)"];

            case 'mensagem':
                $texto = trim((string)($gatilho['mensagem'] ?? ''));
                if ($texto === '') return ['ok' => false, 'detalhe' => 'gatilho sem mensagem'];

                $r = (new ChatEnvioService($this->db))->texto($contatoId, $texto, [
                    'origem'    => 'gatilho',
                    'origem_id' => (int)$gatilho['id'],
                    'vars'      => $contatos->variaveis($contato),
                ]);
                return ['ok' => (bool)$r['ok'], 'detalhe' => $r['ok'] ? 'mensagem enviada' : (string)$r['erro']];

            case 'tag':
                $tagId = (int)($gatilho['tag_id'] ?? 0);
                if ($tagId < 1) return ['ok' => false, 'detalhe' => 'gatilho sem tag'];
                $contatos->aplicarTag($contatoId, $tagId);
                return ['ok' => true, 'detalhe' => "tag $tagId aplicada"];

            case 'humano':
                $conversas = new ChatConversaService($this->db);
                $cv = $conversas->obterPorContato($contatoId) ?: $conversas->garantir($contatoId);
                if (!empty($cv['id'])) {
                    $conversas->pausarBot((int)$cv['id']);
                    $conversas->mudarStatus((int)$cv['id'], 'pendente');
                }
                if (!empty($gatilho['mensagem'])) {
                    (new ChatEnvioService($this->db))->texto($contatoId, (string)$gatilho['mensagem'], [
                        'origem' => 'gatilho', 'origem_id' => (int)$gatilho['id'],
                    ]);
                }
                return ['ok' => true, 'detalhe' => 'encaminhado para atendimento humano'];
        }

        return ['ok' => false, 'detalhe' => "ação desconhecida: $acao"];
    }

    // =========================================================================
    // CASAMENTO
    // =========================================================================

    /**
     * O texto casa com o padrão?
     * $padrao aceita várias palavras separadas por vírgula — qualquer uma serve.
     */
    public function casa(string $texto, string $padrao, string $modo = 'contem'): bool
    {
        $texto = $this->normalizar($texto);
        if ($texto === '') return false;

        foreach (explode(',', $padrao) as $termoBruto) {
            $termo = trim($termoBruto);
            if ($termo === '') continue;

            if ($modo === 'regex') {
                // Delimitador # e o próprio padrão escapado contra quebra de sintaxe.
                // @ suprime warning de regex inválida vinda do formulário.
                if (@preg_match('#' . str_replace('#', '\#', $termo) . '#iu', $texto) === 1) return true;
                continue;
            }

            $t = $this->normalizar($termo);
            if ($t === '') continue;

            $bate = match ($modo) {
                'exato'  => $texto === $t,
                'comeca' => str_starts_with($texto, $t),
                default  => str_contains($texto, $t),   // 'contem'
            };
            if ($bate) return true;
        }
        return false;
    }

    /** minúsculas, sem acento, sem pontuação — "Olá!" e "ola" casam. */
    private function normalizar(string $t): string
    {
        $t = mb_strtolower(ChatContatoService::semAcento(trim($t)));
        $t = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $t) ?? $t;
        return trim(preg_replace('/\s+/', ' ', $t) ?? $t);
    }

    public function ehPalavraDeOptOut(string $texto): bool
    {
        $palavras = ChatConfig::lista('optout_palavras', ['sair', 'parar', 'cancelar', 'descadastrar', 'stop']);
        if (!$palavras) return false;
        // Exato: "não quero parar de comprar" não pode descadastrar ninguém
        return $this->casa($texto, implode(',', $palavras), 'exato');
    }

    // =========================================================================
    // CRUD
    // =========================================================================

    /** Ativos, já na ordem de avaliação. */
    public function ativos(): array
    {
        try {
            $st = $this->db->query(
                "SELECT * FROM chat_gatilhos WHERE ativo = 1 ORDER BY prioridade ASC, id ASC"
            );
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    public function listar(): array
    {
        $st = $this->db->query(
            "SELECT g.*, f.nome AS fluxo_nome, t.nome AS tag_nome, t.cor AS tag_cor
             FROM chat_gatilhos g
             LEFT JOIN chat_fluxos f ON f.id = g.fluxo_id
             LEFT JOIN chat_tags t ON t.id = g.tag_id
             ORDER BY g.ativo DESC, g.prioridade ASC, g.id ASC"
        );
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obter(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM chat_gatilhos WHERE id = :id LIMIT 1");
        $st->execute([':id' => $id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array{ok:bool, id?:int, erro?:string} */
    public function salvar(array $d, ?int $id = null): array
    {
        $nome = trim((string)($d['nome'] ?? ''));
        if ($nome === '') return ['ok' => false, 'erro' => 'Informe o nome do gatilho.'];

        $tipos  = ['palavra_chave', 'boas_vindas', 'padrao', 'referencia', 'midia', 'botao'];
        $modos  = ['exato', 'contem', 'comeca', 'regex'];
        $acoes  = ['fluxo', 'mensagem', 'tag', 'humano'];

        $tipo = in_array($d['tipo'] ?? '', $tipos, true) ? $d['tipo'] : 'palavra_chave';
        $modo = in_array($d['modo_match'] ?? '', $modos, true) ? $d['modo_match'] : 'contem';
        $acao = in_array($d['acao'] ?? '', $acoes, true) ? $d['acao'] : 'fluxo';

        $padrao = trim((string)($d['padrao'] ?? ''));
        if (in_array($tipo, ['palavra_chave', 'referencia'], true) && $padrao === '') {
            return ['ok' => false, 'erro' => 'Informe as palavras-chave ou o código de referência.'];
        }
        if ($modo === 'regex' && $padrao !== '' && @preg_match('#' . str_replace('#', '\#', $padrao) . '#iu', '') === false) {
            return ['ok' => false, 'erro' => 'A expressão regular informada é inválida.'];
        }

        $fluxoId = (int)($d['fluxo_id'] ?? 0) ?: null;
        $tagId   = (int)($d['tag_id'] ?? 0) ?: null;

        if ($acao === 'fluxo'    && !$fluxoId) return ['ok' => false, 'erro' => 'Selecione o fluxo a disparar.'];
        if ($acao === 'tag'      && !$tagId)   return ['ok' => false, 'erro' => 'Selecione a tag a aplicar.'];
        if ($acao === 'mensagem' && trim((string)($d['mensagem'] ?? '')) === '') {
            return ['ok' => false, 'erro' => 'Escreva a mensagem de resposta.'];
        }

        // Só pode haver um boas-vindas e uma resposta padrão ativos — dois
        // seriam duas mensagens simultâneas para o mesmo evento.
        if (in_array($tipo, ['boas_vindas', 'padrao'], true) && !empty($d['ativo'])) {
            $sql = "SELECT id FROM chat_gatilhos WHERE tipo = :t AND ativo = 1";
            $par = [':t' => $tipo];
            if ($id) { $sql .= " AND id <> :id"; $par[':id'] = $id; }
            $st = $this->db->prepare($sql . " LIMIT 1");
            $st->execute($par);
            if ($st->fetchColumn()) {
                $rotulo = $tipo === 'boas_vindas' ? 'boas-vindas' : 'resposta padrão';
                return ['ok' => false, 'erro' => "Já existe um gatilho de $rotulo ativo. Desative o outro primeiro."];
            }
        }

        $campos = [
            ':nome'  => mb_substr($nome, 0, 120),
            ':tipo'  => $tipo,
            ':pad'   => mb_substr($padrao, 0, 400) ?: null,
            ':modo'  => $modo,
            ':acao'  => $acao,
            ':fid'   => $fluxoId,
            ':msg'   => trim((string)($d['mensagem'] ?? '')) ?: null,
            ':tid'   => $tagId,
            ':prio'  => max(0, min(999, (int)($d['prioridade'] ?? 50))),
            ':ativo' => (int)!empty($d['ativo']),
            ':sff'   => (int)!empty($d['so_fora_fluxo']),
        ];

        try {
            if ($id) {
                $campos[':id'] = $id;
                $this->db->prepare(
                    "UPDATE chat_gatilhos SET
                        nome = :nome, tipo = :tipo, padrao = :pad, modo_match = :modo,
                        acao = :acao, fluxo_id = :fid, mensagem = :msg, tag_id = :tid,
                        prioridade = :prio, ativo = :ativo, so_fora_fluxo = :sff
                     WHERE id = :id"
                )->execute($campos);
                return ['ok' => true, 'id' => $id];
            }

            $this->db->prepare(
                "INSERT INTO chat_gatilhos
                    (nome, tipo, padrao, modo_match, acao, fluxo_id, mensagem, tag_id,
                     prioridade, ativo, so_fora_fluxo)
                 VALUES (:nome, :tipo, :pad, :modo, :acao, :fid, :msg, :tid, :prio, :ativo, :sff)"
            )->execute($campos);
            return ['ok' => true, 'id' => (int)$this->db->lastInsertId()];
        } catch (Throwable $e) {
            return ['ok' => false, 'erro' => 'Falha ao salvar: ' . $e->getMessage()];
        }
    }

    public function alternarAtivo(int $id): bool
    {
        $this->db->prepare("UPDATE chat_gatilhos SET ativo = 1 - ativo WHERE id = :id")
                 ->execute([':id' => $id]);
        return true;
    }

    public function excluir(int $id): bool
    {
        $this->db->prepare("DELETE FROM chat_gatilhos WHERE id = :id")->execute([':id' => $id]);
        return true;
    }

    private function registrarDisparo(int $id): void
    {
        try {
            $this->db->prepare(
                "UPDATE chat_gatilhos
                 SET total_disparos = total_disparos + 1, ultimo_disparo_em = NOW()
                 WHERE id = :id"
            )->execute([':id' => $id]);
        } catch (Throwable $e) {}
    }

    /**
     * Simulador: qual gatilho responderia a este texto?
     * Deixa o admin testar a régua sem mandar mensagem de verdade.
     */
    public function simular(string $texto, bool $primeiraMensagem = false): array
    {
        $contatoFake = ['id' => 0, 'total_entrada' => $primeiraMensagem ? 1 : 5];
        $d = $this->resolver($contatoFake, ['texto' => $texto, 'tipo' => 'text']);
        return [
            'acao'    => $d['acao'],
            'motivo'  => $d['motivo'],
            'gatilho' => $d['gatilho'] ? [
                'id'   => (int)$d['gatilho']['id'],
                'nome' => $d['gatilho']['nome'],
                'tipo' => $d['gatilho']['tipo'],
            ] : null,
        ];
    }
}
