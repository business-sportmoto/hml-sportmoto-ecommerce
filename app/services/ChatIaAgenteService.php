<?php
/**
 * app/services/ChatIaAgenteService.php
 *
 * O agente que lê um comentário do Instagram, entende do que se trata e responde
 * — no comentário e no direct — usando SÓ os dados do produto ligado à automação.
 *
 * A regra que organiza tudo aqui: **o modelo escreve, o banco informa.**
 * O texto é do modelo; todo número que aparece nele tem de existir no contexto
 * que nós montamos. Preço é a informação com maior custo de errar em público, e
 * um modelo de linguagem não tem como saber que a promoção venceu ontem.
 *
 * Reaproveita a camada de IA que já existe no projeto (IAOrchestrator): daí vêm
 * de graça o teto de gasto diário, o fallback entre modelos e o log de
 * roteamento. Cada resposta vira uma linha em `ia_geracoes` — que já tem
 * `produto_id` e `contexto` —, então o histórico e o custo ficam auditáveis.
 */
class ChatIaAgenteService
{
    /** Campos do produto que o agente pode usar. Estoque e prazo ficam de fora. */
    public const CAMPOS = ['nome', 'preco', 'descricao', 'ficha', 'compatibilidade'];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    // =========================================================================
    // CONTEXTO DO PRODUTO
    // =========================================================================

    /**
     * Monta o que o agente pode dizer sobre o produto.
     *
     * @param array $campos subconjunto de self::CAMPOS
     * @return array|null null quando o produto não existe ou está inativo —
     *                    responder sobre produto fora do ar é pior que não responder
     */
    public function contextoProduto(int $produtoId, array $campos = self::CAMPOS): ?array
    {
        $st = $this->db->prepare(
            "SELECT id, nome, slug, descricao_curta, descricao,
                    preco, preco_promo, promo_inicio, promo_fim, ativo
             FROM produtos WHERE id = :id LIMIT 1"
        );
        $st->execute([':id' => $produtoId]);
        $p = $st->fetch(PDO::FETCH_ASSOC);

        if (!$p || (int)$p['ativo'] !== 1) return null;

        $ctx = ['id' => (int)$p['id'], 'nome' => (string)$p['nome']];

        if (in_array('preco', $campos, true)) {
            $ctx['preco'] = $this->precoVigente($p);
        }
        if (in_array('descricao', $campos, true)) {
            $ctx['descricao'] = $this->limpar((string)($p['descricao_curta'] ?: $p['descricao']), 1200);
        }
        if (in_array('ficha', $campos, true)) {
            $ctx['ficha'] = $this->ficha($produtoId);
        }
        if (in_array('compatibilidade', $campos, true)) {
            $ctx['compatibilidade'] = $this->compatibilidade($produtoId);
        }

        return $ctx;
    }

    /**
     * Preço que vale AGORA.
     *
     * A promoção do produto tem janela de datas, e `promo_fim` é um DATE: vale
     * até o fim daquele dia. Comparar com NOW() sem isso derruba a promoção às
     * 00h01 do último dia — um dia inteiro de preço errado, para mais.
     *
     * @return array{valor:float, de:?float, em_promocao:bool, promo_ate:?string}
     */
    public function precoVigente(array $p): array
    {
        $cheio = (float)$p['preco'];
        $promo = $p['preco_promo'] !== null ? (float)$p['preco_promo'] : null;

        $vale = $promo !== null
             && $promo > 0
             && $promo < $cheio
             && ($p['promo_inicio'] === null || $p['promo_inicio'] <= date('Y-m-d'))
             && ($p['promo_fim']    === null || $p['promo_fim']    >= date('Y-m-d'));

        return [
            'valor'       => $vale ? $promo : $cheio,
            'de'          => $vale ? $cheio : null,
            'em_promocao' => $vale,
            'promo_ate'   => $vale && $p['promo_fim'] ? (string)$p['promo_fim'] : null,
        ];
    }

    /** Ficha técnica: característica → valor, já com unidade. */
    private function ficha(int $produtoId): array
    {
        $st = $this->db->prepare(
            "SELECT c.nome, c.unidade, pc.valor
             FROM produto_caracteristicas pc
             JOIN caracteristicas c ON c.id = pc.caracteristica_id
             WHERE pc.produto_id = :p AND c.ativo = 1
             ORDER BY c.ordem, c.nome"
        );
        $st->execute([':p' => $produtoId]);

        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $v = trim((string)$l['valor']);
            if ($v === '') continue;
            $out[(string)$l['nome']] = $v . ($l['unidade'] ? ' ' . $l['unidade'] : '');
        }
        return $out;
    }

    /**
     * Quais motos o produto serve.
     *
     * Numa loja de peças isto responde metade dos comentários ("serve na minha
     * Fazer?"), e é uma resposta que só o banco sabe dar.
     */
    private function compatibilidade(int $produtoId): array
    {
        try {
            $st = $this->db->prepare(
                "SELECT mo.nome AS montadora, mm.nome AS modelo, pc.ano_inicio, pc.ano_fim
                 FROM produto_compatibilidade pc
                 LEFT JOIN moto_montadoras mo ON mo.id = pc.montadora_id
                 LEFT JOIN moto_modelos    mm ON mm.id = pc.modelo_id
                 WHERE pc.produto_id = :p
                 ORDER BY mo.nome, mm.nome
                 LIMIT 40"
            );
            $st->execute([':p' => $produtoId]);
        } catch (Throwable $e) {
            return [];   // nomes de tabela de moto variam — sem isso o resto segue
        }

        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $nome = trim(($l['montadora'] ?? '') . ' ' . ($l['modelo'] ?? ''));
            if ($nome === '') continue;
            $anos = $l['ano_inicio'] ? ' ' . $l['ano_inicio'] . '–' . ($l['ano_fim'] ?: 'atual') : '';
            $out[] = $nome . $anos;
        }
        return $out;
    }

    private function limpar(string $html, int $max): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');
        return mb_strlen($t) > $max ? mb_substr($t, 0, $max - 1) . '…' : $t;
    }

    // =========================================================================
    // GUARDA DOS NÚMEROS
    // =========================================================================

    /**
     * O texto do modelo só pode conter números que existam no contexto.
     *
     * É a proteção contra o pior caso: o agente escrever "R$ 289" num produto de
     * R$ 389, em público, no seu perfil. Números pequenos (até 2 dígitos) passam
     * porque aparecem em prosa legítima — "2 anos de garantia", "3 cores".
     *
     * @return array{ok:bool, invasores:array} invasores = os números inventados
     */
    public function validarNumeros(string $texto, array $ctx): array
    {
        $permitidos = $this->numerosDo($ctx);

        // Vírgula e ponto viram nada: 1.299,90 · 1299.90 · 129990 comparam igual
        $achados = [];
        if (preg_match_all('/\d[\d.,]*\d|\d/u', $texto, $m)) $achados = $m[0];

        $invasores = [];
        foreach ($achados as $bruto) {
            $n = preg_replace('/\D/', '', $bruto);
            if ($n === '' || strlen($n) <= 2) continue;          // prosa comum
            if (in_array(ltrim($n, '0'), $permitidos, true)) continue;
            $invasores[] = $bruto;
        }

        return ['ok' => $invasores === [], 'invasores' => array_values(array_unique($invasores))];
    }

    /** Todo número que o contexto autoriza, normalizado só para dígitos. */
    private function numerosDo(array $ctx): array
    {
        $planos = [];
        array_walk_recursive($ctx, function ($v) use (&$planos) { $planos[] = (string)$v; });

        $out = [];
        foreach ($planos as $v) {
            if (preg_match_all('/\d[\d.,]*\d|\d/u', $v, $m)) {
                foreach ($m[0] as $bruto) {
                    $n = ltrim(preg_replace('/\D/', '', $bruto), '0');
                    if ($n !== '') $out[] = $n;
                }
            }
        }

        // O preço também vale sem os centavos: "R$ 389" para 389.00
        if (isset($ctx['preco']['valor'])) {
            foreach ([$ctx['preco']['valor'], $ctx['preco']['de'] ?? null] as $p) {
                if ($p === null) continue;
                $out[] = (string)(int)$p;
                $out[] = ltrim(str_replace('.', '', number_format((float)$p, 2, '.', '')), '0');
            }
        }
        return array_values(array_unique(array_filter($out)));
    }

    // =========================================================================
    // PROMPT
    // =========================================================================

    /** O contexto em texto, do jeito que o modelo lê melhor. */
    public function contextoEmTexto(array $ctx): string
    {
        $l = ['PRODUTO: ' . $ctx['nome']];

        if (isset($ctx['preco'])) {
            $p = $ctx['preco'];
            $l[] = $p['em_promocao']
                ? 'PREÇO: R$ ' . number_format($p['valor'], 2, ',', '.')
                  . ' (em promoção, de R$ ' . number_format((float)$p['de'], 2, ',', '.') . ')'
                  . ($p['promo_ate'] ? ' — promoção até ' . date('d/m/Y', strtotime($p['promo_ate'])) : '')
                : 'PREÇO: R$ ' . number_format($p['valor'], 2, ',', '.');
        }
        if (!empty($ctx['descricao']))       $l[] = 'DESCRIÇÃO: ' . $ctx['descricao'];
        if (!empty($ctx['ficha'])) {
            $f = [];
            foreach ($ctx['ficha'] as $k => $v) $f[] = "$k: $v";
            $l[] = 'FICHA TÉCNICA: ' . implode(' · ', $f);
        }
        if (!empty($ctx['compatibilidade'])) {
            $l[] = 'SERVE EM: ' . implode(' · ', $ctx['compatibilidade']);
        }
        return implode("\n", $l);
    }

    // =========================================================================
    // RESPOSTA
    // =========================================================================

    /**
     * Responde a pergunta a partir do contexto do produto.
     *
     * @param array $opts publico (bool), tom, contato_id, fluxo_id
     * @return array{ok:bool, direct:string, publico:?string, motivo:string}
     */
    public function responder(string $pergunta, array $ctxProduto, array $opts = []): array
    {
        $pergunta = trim(mb_substr($pergunta, 0, 500));
        if ($pergunta === '') return $this->naoSei('pergunta vazia');

        // O direct é a resposta que importa; o público é o convite.
        $direct = $this->gerar($pergunta, $ctxProduto, false, $opts);
        if ($direct === null) return $this->naoSei('modelo não respondeu');
        if ($this->ehNaoSei($direct)) return $this->naoSei('fora do que o produto responde');

        $v = $this->validarNumeros($direct, $ctxProduto);
        if (!$v['ok']) {
            // Número inventado no direct derruba a resposta inteira. Reescrever
            // seria adivinhar qual parte está certa.
            $this->logar('ia: número fora do contexto no direct', [
                'invasores' => $v['invasores'], 'produto_id' => $ctxProduto['id'] ?? null,
            ]);
            return $this->naoSei('número fora do contexto: ' . implode(', ', $v['invasores']));
        }

        $publico = null;
        if (!empty($opts['publico'])) {
            $p = $this->gerar($pergunta, $ctxProduto, true, $opts);
            if ($p !== null && !$this->ehNaoSei($p)) {
                // No público a régua é mais dura: número nenhum, nem os certos.
                // Preço em comentário aberto vira print e sobrevive à promoção.
                $publico = preg_match('/\d{3,}/u', $p) ? null : mb_substr(trim($p), 0, 160);
            }
        }

        return ['ok' => true, 'direct' => trim($direct), 'publico' => $publico, 'motivo' => ''];
    }

    private function naoSei(string $motivo): array
    {
        return ['ok' => false, 'direct' => '', 'publico' => null, 'motivo' => $motivo];
    }

    /** O modelo tem uma saída explícita para "não dá para responder com isto". */
    private function ehNaoSei(string $t): bool
    {
        return stripos($t, 'NAO_SEI') !== false || stripos($t, 'NÃO_SEI') !== false;
    }

    /**
     * Uma chamada ao modelo, passando pelo IAOrchestrator.
     *
     * Cada resposta vira uma linha em `ia_geracoes` — de onde saem, de graça, o
     * teto de gasto diário, o fallback entre modelos e o custo por resposta.
     * `produto_id` já existe na tabela, então o histórico fica ligado ao produto.
     */
    private function gerar(string $pergunta, array $ctx, bool $publico, array $opts): ?string
    {
        $prompt = $this->montarPrompt($pergunta, $ctx, $publico, $opts);

        return $this->gerarTexto($prompt, $publico, $opts + [
            'produto_id' => $ctx['id'] ?? null,
            'pergunta'   => $pergunta,
        ]);
    }

    /** O prompt inteiro, separado da chamada — dá para ler e testar sozinho. */
    public function montarPrompt(string $pergunta, array $ctx, bool $publico, array $opts = []): string
    {
        return $this->instrucao($publico)
                . (!empty($opts['tom']) ? "\nTOM DA MARCA: " . trim((string)$opts['tom']) . "\n" : '')
                . "\n=== DADOS DO PRODUTO ===\n" . $this->contextoEmTexto($ctx)
                . "\n=== PERGUNTA DO CLIENTE ===\n" . $pergunta;
    }

    /**
     * A chamada ao modelo.
     *
     * `protected` de propósito: é a costura por onde o teste troca o modelo
     * por um dublê e exercita cada caminho de decisão sem gastar token nem
     * depender da rede. Testar a DECISÃO é o que importa aqui — a redação
     * do modelo muda a cada chamada e não dá para afirmar nada sobre ela.
     */
    protected function gerarTexto(string $prompt, bool $publico, array $opts): ?string
    {
        $pergunta = (string)($opts['pergunta'] ?? '');

        try {
            $st = $this->db->prepare(
                "INSERT INTO ia_geracoes
                    (uuid, produto_id, capacidade, formato, prompt_final, contexto,
                     status, criado_em)
                 VALUES (:u, :p, 'texto', :f, :pf, :ctx, 'processando', NOW())"
            );
            $st->execute([
                ':u'   => $this->uuid(),
                ':p'   => (int)($opts['produto_id'] ?? 0) ?: null,
                ':f'   => $publico ? 'ig_comentario' : 'ig_direct',
                ':pf'  => $prompt,
                ':ctx' => json_encode([
                    'pergunta'   => $pergunta,
                    'contato_id' => $opts['contato_id'] ?? null,
                    'fluxo_id'   => $opts['fluxo_id'] ?? null,
                ], JSON_UNESCAPED_UNICODE),
            ]);
            $geracaoId = (int)$this->db->lastInsertId();

            $ger = $this->db->query("SELECT * FROM ia_geracoes WHERE id = $geracaoId")->fetch(PDO::FETCH_ASSOC);
            $res = (new IAOrchestrator())->executarTexto($ger, ['modelo_id' => null]);

            $texto = trim((string)($res->texto ?? ''));
            $bom   = $res->ok && $texto !== '';

            // Custo e tokens fecham na mesma linha: sem isso o teto diário do
            // IACustoService nunca enxerga o que o atendimento gastou.
            $this->db->prepare(
                "UPDATE ia_geracoes
                 SET status = :s, resultado_texto = :t, erro = :e,
                     modelo_id = :mid, provedor_codigo = :prov, modelo_codigo = :mcod,
                     tokens_in = :ti, tokens_out = :to, tempo_ms = :ms,
                     custo_real_usd = :custo, concluido_em = NOW()
                 WHERE id = :id"
            )->execute([
                ':s'     => $bom ? 'concluida' : 'falhou',
                ':t'     => $texto ?: null,
                ':e'     => $bom ? null : mb_substr((string)($res->erro ?? 'sem texto'), 0, 600),
                ':mid'   => $res->modeloId,
                ':prov'  => $res->provedorCodigo,
                ':mcod'  => $res->modeloCodigo,
                ':ti'    => $res->tokensIn,
                ':to'    => $res->tokensOut,
                ':ms'    => $res->tempoMs,
                ':custo' => $res->custoRealUsd,
                ':id'    => $geracaoId,
            ]);

            return $bom ? $texto : null;

        } catch (Throwable $e) {
            // Modelo fora do ar não pode derrubar o fluxo: cai na porta nao_sabe
            $this->logar('ia: falha ao gerar resposta', ['erro' => $e->getMessage()]);
            return null;
        }
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private function logar(string $msg, array $ctx): void
    {
        if (!class_exists('LogService')) return;
        try { LogService::warning($msg, $ctx, 'chat'); } catch (Throwable $e) {}
    }

    /**
     * Instrução do sistema.
     *
     * "Não invente" sozinho não segura um modelo — por isso existe a
     * validarNumeros(). Mas dizer explicitamente o que fazer quando NÃO souber
     * é o que faz ele usar a saída de escape em vez de improvisar.
     */
    public function instrucao(bool $publico): string
    {
        $base = "Você responde clientes de uma loja de peças e acessórios de moto, em português do Brasil.\n"
              . "Responda APENAS com o que estiver nos DADOS DO PRODUTO abaixo.\n"
              . "Se a pergunta não puder ser respondida com esses dados, responda exatamente: NAO_SEI\n"
              . "Nunca invente preço, prazo, estoque ou compatibilidade.\n"
              . "Nunca prometa desconto, frete grátis ou condição de pagamento.\n";

        return $publico
            ? $base . "Este texto vai como RESPOSTA PÚBLICA no comentário do Instagram.\n"
                    . "Máximo 140 caracteres, tom leve, 1 emoji no máximo.\n"
                    . "NÃO escreva valores nem números: convide a pessoa para o direct, "
                    . "onde a resposta completa será enviada.\n"
                    . "Varie a forma a cada resposta, mas fale do que a pessoa perguntou.\n"
            : $base . "Este texto vai no DIRECT. Máximo 600 caracteres, direto ao ponto, "
                    . "sem saudação longa. Pode usar os valores dos dados.\n";
    }
}
