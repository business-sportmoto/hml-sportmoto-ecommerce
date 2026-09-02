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
